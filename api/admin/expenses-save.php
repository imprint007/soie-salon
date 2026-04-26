<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
$expense_date = trim($input['expense_date'] ?? '');
$category = trim($input['category'] ?? '');
$description = trim($input['description'] ?? '');
$amount = (int)($input['amount'] ?? 0);

if (empty($expense_date)) jsonError('Дата обовʼязкова');
if (empty($category)) jsonError('Категорія обовʼязкова');
if ($amount <= 0) jsonError('Невірна сума');

try {
    $pdo = getDb();
    if ($id > 0) {
        $pdo->prepare("UPDATE expenses SET expense_date=?, category=?, description=?, amount=? WHERE id=?")
            ->execute([$expense_date, $category, $description, $amount, $id]);
    } else {
        $pdo->prepare("INSERT INTO expenses (expense_date, category, description, amount) VALUES (?, ?, ?, ?)")
            ->execute([$expense_date, $category, $description, $amount]);
        $id = (int)$pdo->lastInsertId();
    }
    jsonOk(['id' => $id, 'message' => 'Збережено']);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}