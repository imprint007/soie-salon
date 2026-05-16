<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    if ($bookingId <= 0) jsonError('Невірний booking_id');
    
    // Завантажуємо бронь
    $stmt = $pdo->prepare("SELECT b.id, b.service_id, b.selected_options, b.status,
                                  s.name AS service_name
                           FROM bookings b
                           LEFT JOIN services s ON s.id = b.service_id
                           WHERE b.id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if (!$booking) jsonError('Бронь не знайдена');
    
    // Перевіряємо чи вже списано
    $checkStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM stock_movements WHERE booking_id = ? AND movement_type = 'consumption'");
    $checkStmt->execute([$bookingId]);
    $alreadyConsumed = ((int)$checkStmt->fetch()['cnt']) > 0;
    
    // Збираємо очікувані розхідники
    $consumables = []; // [product_id => quantity]
    
    // 1. Базові з послуги
    $svcStmt = $pdo->prepare("SELECT sc.product_id, sc.quantity, p.name, p.unit, p.current_stock
                              FROM service_consumables sc
                              JOIN products p ON p.id = sc.product_id
                              WHERE sc.service_id = ?");
    $svcStmt->execute([$booking['service_id']]);
    foreach ($svcStmt->fetchAll() as $sc) {
        $pid = (int)$sc['product_id'];
        if (!isset($consumables[$pid])) {
            $consumables[$pid] = [
                'product_id' => $pid,
                'product_name' => $sc['name'],
                'unit' => $sc['unit'],
                'current_stock' => (float)$sc['current_stock'],
                'quantity' => 0,
                'sources' => []
            ];
        }
        $consumables[$pid]['quantity'] += (float)$sc['quantity'];
        $consumables[$pid]['sources'][] = 'базовий';
    }
    
    // 2. Додаткові від обраних опцій
    $selectedOpts = !empty($booking['selected_options']) ? json_decode($booking['selected_options'], true) : [];
    if (is_array($selectedOpts)) {
        $optIds = array_filter(array_map(fn($o) => (int)($o['id'] ?? 0), $selectedOpts));
        if (!empty($optIds)) {
            $placeholders = implode(',', array_fill(0, count($optIds), '?'));
            $optStmt = $pdo->prepare("SELECT oc.option_id, oc.product_id, oc.quantity_delta, 
                                            so.option_name, p.name, p.unit, p.current_stock
                                     FROM option_consumables oc
                                     JOIN products p ON p.id = oc.product_id
                                     JOIN service_options so ON so.id = oc.option_id
                                     WHERE oc.option_id IN ($placeholders)");
            $optStmt->execute($optIds);
            foreach ($optStmt->fetchAll() as $oc) {
                $pid = (int)$oc['product_id'];
                if (!isset($consumables[$pid])) {
                    $consumables[$pid] = [
                        'product_id' => $pid,
                        'product_name' => $oc['name'],
                        'unit' => $oc['unit'],
                        'current_stock' => (float)$oc['current_stock'],
                        'quantity' => 0,
                        'sources' => []
                    ];
                }
                $consumables[$pid]['quantity'] += (float)$oc['quantity_delta'];
                $consumables[$pid]['sources'][] = 'опція: ' . $oc['option_name'];
            }
        }
    }
    
    // Перетворюємо в масив, не дозволяємо мінус
    $result = [];
    foreach ($consumables as $c) {
        $c['quantity'] = max(0, round($c['quantity'], 3));
        $c['shortage'] = max(0, $c['quantity'] - $c['current_stock']);
        $c['sources'] = implode(' + ', array_unique($c['sources']));
        $result[] = $c;
    }
    
    jsonOk([
        'booking_id' => $bookingId,
        'service_name' => $booking['service_name'],
        'current_status' => $booking['status'],
        'already_consumed' => $alreadyConsumed,
        'consumables' => $result
    ]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}