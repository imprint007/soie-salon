<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('Невірний ID');
    
    // Клієнт
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) jsonError('Клієнта не знайдено');
    
    // Бронювання з деталями
    $bStmt = $pdo->prepare("
        SELECT b.id, b.booking_code, b.booking_date, b.booking_time, b.duration_min,
               b.total_price, b.deposit_amount, b.status, b.selected_options,
               b.client_comment, b.created_at,
               s.name AS service_name, s.image_url AS service_image,
               m.name AS master_name, m.photo_url AS master_photo
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN masters m ON m.id = b.master_id
        WHERE b.client_id = ?
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
    $bStmt->execute([$id]);
    $bookings = $bStmt->fetchAll();
    
    // Для кожної брони — списані товари
    foreach ($bookings as &$booking) {
        $booking['selected_options'] = !empty($booking['selected_options']) 
            ? json_decode($booking['selected_options'], true) 
            : [];
        
        $smStmt = $pdo->prepare("
            SELECT sm.quantity, sm.stock_before, sm.stock_after,
                   p.name AS product_name, p.unit
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            WHERE sm.booking_id = ? AND sm.movement_type = 'consumption'
        ");
        $smStmt->execute([$booking['id']]);
        $booking['consumables'] = $smStmt->fetchAll();
    }
    unset($booking);
    
    // Довільні поля
    $fStmt = $pdo->query("SELECT * FROM client_custom_fields WHERE is_active = 1 ORDER BY sort_order");
    $fields = $fStmt->fetchAll();
    
    $vStmt = $pdo->prepare("SELECT field_id, field_value FROM client_field_values WHERE client_id = ?");
    $vStmt->execute([$id]);
    $values = [];
    foreach ($vStmt->fetchAll() as $v) {
        $values[$v['field_id']] = $v['field_value'];
    }
    
    // Фото
    $pStmt = $pdo->prepare("
        SELECT cp.*, b.booking_code, b.booking_date
        FROM client_photos cp
        LEFT JOIN bookings b ON b.id = cp.booking_id
        WHERE cp.client_id = ?
        ORDER BY cp.created_at DESC
    ");
    $pStmt->execute([$id]);
    $photos = $pStmt->fetchAll();
    
    jsonOk([
        'client' => $client,
        'bookings' => $bookings,
        'custom_fields' => $fields,
        'field_values' => $values,
        'photos' => $photos,
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}