<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $productId = (int)($input['product_id'] ?? 0);
    $newStock = (float)($input['new_stock'] ?? 0);
    $reason = trim($input['reason'] ?? 'Ручне коригування');
    
    if ($productId <= 0) jsonError('Невірний product_id');
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ? FOR UPDATE");
    $stmt->execute([$productId]);
    $current = $stmt->fetch();
    if (!$current) {
        $pdo->rollBack();
        jsonError('Товар не знайдено');
    }
    
    $stockBefore = (float)$current['current_stock'];
    $delta = $newStock - $stockBefore;
    
    $pdo->prepare("UPDATE products SET current_stock = ? WHERE id = ?")
        ->execute([$newStock, $productId]);
    
    $pdo->prepare("INSERT INTO stock_movements 
            (product_id, movement_type, quantity, stock_before, stock_after, reason_text)
        VALUES (?, 'adjustment', ?, ?, ?, ?)")
        ->execute([$productId, $delta, $stockBefore, $newStock, $reason]);
    
    $pdo->commit();
    jsonOk(['new_stock' => $newStock]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}