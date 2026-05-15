<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $id = (int)($input['id'] ?? 0);
    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $category = trim($input['category'] ?? '');
    $description = trim($input['description'] ?? '');
    $amount = (float)($input['amount'] ?? 0);
    
    // Якщо це закупка товару
    $productId = !empty($input['product_id']) ? (int)$input['product_id'] : null;
    $productQuantity = !empty($input['product_quantity']) ? (float)$input['product_quantity'] : null;
    
    if ($amount <= 0) jsonError('Введіть суму');
    if (empty($expenseDate)) jsonError('Введіть дату');
    
    // Якщо це закупка — перевіряємо
    if ($productId) {
        if (!$productQuantity || $productQuantity <= 0) {
            jsonError('Введіть кількість товару');
        }
        // Перевіряємо що товар існує
        $check = $pdo->prepare("SELECT id, name, current_stock FROM products WHERE id = ?");
        $check->execute([$productId]);
        $product = $check->fetch();
        if (!$product) jsonError('Товар не знайдено');
        
        // Категорія завжди "Закупка товарів"
        if (empty($category)) $category = 'Закупка товарів';
        // Опис автоматично — назва товару
        if (empty($description)) $description = $product['name'];
    }
    
    $pdo->beginTransaction();
    
    if ($id > 0) {
        // Редагування — поки що НЕ дозволяємо змінювати закупки
        // (бо складна логіка зі скасуванням попереднього руху)
        $oldStmt = $pdo->prepare("SELECT product_id FROM expenses WHERE id = ?");
        $oldStmt->execute([$id]);
        $oldExpense = $oldStmt->fetch();
        
        if ($oldExpense && $oldExpense['product_id']) {
            $pdo->rollBack();
            jsonError('Закупка вже зафіксована на складі. Видаліть і створіть нову.');
        }
        
        $pdo->prepare("UPDATE expenses SET expense_date=?, category=?, description=?, amount=? WHERE id=?")
            ->execute([$expenseDate, $category, $description, $amount, $id]);
    } else {
        $pdo->prepare("INSERT INTO expenses (expense_date, category, description, amount, product_id, product_quantity) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$expenseDate, $category, $description, $amount, $productId, $productQuantity]);
        $id = (int)$pdo->lastInsertId();
        
        // Якщо це закупка — оновлюємо склад
        if ($productId) {
            $stockBefore = (float)$product['current_stock'];
            $stockAfter = $stockBefore + $productQuantity;
            $costPerUnit = round($amount / $productQuantity, 2);
            
            // Оновлюємо залишок
            $pdo->prepare("UPDATE products SET current_stock = ?, cost_price = ? WHERE id = ?")
                ->execute([$stockAfter, $costPerUnit, $productId]);
            
            // Запис в журнал
            $pdo->prepare("INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, stock_before, stock_after, cost_per_unit, reason_text, expense_id)
                VALUES (?, 'purchase', ?, ?, ?, ?, ?, ?)")
                ->execute([$productId, $productQuantity, $stockBefore, $stockAfter, $costPerUnit, 
                          'Закупка: ' . $description, $id]);
        }
    }
    
    $pdo->commit();
    jsonOk(['id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}