<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('Невірний id');
    
    $pdo->prepare("DELETE FROM master_expense_categories WHERE id = ? AND master_id = ?")
        ->execute([$id, $masterId]);
    
    jsonOk();
} catch (Throwable $e) {
    jsonError('Помилка', 500);
}