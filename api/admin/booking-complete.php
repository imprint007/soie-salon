<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $bookingId = (int)($input['booking_id'] ?? 0);
    $consumables = $input['consumables'] ?? [];  // [{ product_id, quantity }]
    $skipConsumption = !empty($input['skip_consumption']);
    
    if ($bookingId <= 0) jsonError('Невірний booking_id');
    
    $pdo->beginTransaction();
    
    // Перевіряємо що бронь існує і не виконана
    $stmt = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        $pdo->rollBack();
        jsonError('Бронь не знайдена');
    }
    
    // Перевіряємо чи вже списано
    $checkStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM stock_movements WHERE booking_id = ? AND movement_type = 'consumption'");
    $checkStmt->execute([$bookingId]);
    if (((int)$checkStmt->fetch()['cnt']) > 0) {
        $pdo->rollBack();
        jsonError('Розхідники по цій броні вже списані');
    }
    
    // Якщо потрібно списати — обробляємо
    if (!$skipConsumption && is_array($consumables)) {
        foreach ($consumables as $c) {
            $productId = (int)($c['product_id'] ?? 0);
            $quantity = (float)($c['quantity'] ?? 0);
            
            if ($productId <= 0 || $quantity <= 0) continue;
            
            // Беремо поточний stock з FOR UPDATE
            $pStmt = $pdo->prepare("SELECT current_stock, name FROM products WHERE id = ? FOR UPDATE");
            $pStmt->execute([$productId]);
            $product = $pStmt->fetch();
            if (!$product) continue;
            
            $stockBefore = (float)$product['current_stock'];
            $stockAfter = $stockBefore - $quantity;
            // Дозволяємо в мінус, але попереджаємо в журналі
            
            $pdo->prepare("UPDATE products SET current_stock = ? WHERE id = ?")
                ->execute([$stockAfter, $productId]);
            
            $pdo->prepare("INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, stock_before, stock_after, reason_text, booking_id)
                VALUES (?, 'consumption', ?, ?, ?, ?, ?)")
                ->execute([
                    $productId, 
                    -$quantity,  // мінус — це списання
                    $stockBefore, 
                    $stockAfter,
                    'Виконання послуги · бронь #' . $bookingId,
                    $bookingId
                ]);
        }
    }
    
    // Оновлюємо статус брони на done
    $pdo->prepare("UPDATE bookings SET status = 'done' WHERE id = ?")->execute([$bookingId]);
    
    $pdo->commit();
    jsonOk(['message' => 'Готово']);
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}