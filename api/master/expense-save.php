<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $id = (int)($input['id'] ?? 0);
    $categoryId = !empty($input['category_id']) ? (int)$input['category_id'] : null;
    $expenseDate = $input['expense_date'] ?? date('Y-m-d');
    $description = trim($input['description'] ?? '');
    $amount = (float)($input['amount'] ?? 0);
    
    if ($amount <= 0) jsonError('Введіть суму');
    if (empty($expenseDate)) jsonError('Введіть дату');
    
    // Берёмо назву категорії для збереження
    $categoryName = null;
    if ($categoryId) {
        $check = $pdo->prepare("SELECT name FROM master_expense_categories WHERE id = ? AND master_id = ?");
        $check->execute([$categoryId, $masterId]);
        $row = $check->fetch();
        if ($row) {
            $categoryName = $row['name'];
        } else {
            $categoryId = null;
        }
    }
    
    if ($id > 0) {
        $pdo->prepare("UPDATE master_expenses SET category_id=?, category_name=?, expense_date=?, description=?, amount=? WHERE id=? AND master_id=?")
            ->execute([$categoryId, $categoryName, $expenseDate, $description, $amount, $id, $masterId]);
    } else {
        $pdo->prepare("INSERT INTO master_expenses (master_id, category_id, category_name, expense_date, description, amount) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$masterId, $categoryId, $categoryName, $expenseDate, $description, $amount]);
        $id = (int)$pdo->lastInsertId();
    }
    
    jsonOk(['id' => $id]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}