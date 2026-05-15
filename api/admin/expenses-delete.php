<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('Невірний id');
    
    $pdo->beginTransaction();
    
    // Перевіряємо чи це була закупка
    $stmt = $pdo->prepare("SELECT product_id, product_quantity FROM expenses WHERE id = ?");
    $stmt->execute([$id]);
    $expense = $stmt->fetch();
    
    if ($expense && $expense['product_id'] && $expense['product_quantity']) {
        // Це була закупка — повертаємо назад зі складу
        $productId = (int)$expense['product_id'];
        $quantity = (float)$expense['product_quantity'];
        
        $pStmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ? FOR UPDATE");
        $pStmt->execute([$productId]);
        $product = $pStmt->fetch();
        
        if ($product) {
            $stockBefore = (float)$product['current_stock'];
            $stockAfter = max(0, $stockBefore - $quantity);
            
            $pdo->prepare("UPDATE products SET current_stock = ? WHERE id = ?")
                ->execute([$stockAfter, $productId]);
            
            $pdo->prepare("INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, stock_before, stock_after, reason_text, expense_id)
                VALUES (?, 'return', ?, ?, ?, 'Скасування закупки', ?)")
                ->execute([$productId, -$quantity, $stockBefore, $stockAfter, $id]);
        }
    }
    
    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
    
    $pdo->commit();
    jsonOk();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}