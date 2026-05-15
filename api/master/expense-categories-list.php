<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $stmt = $pdo->prepare("SELECT id, name, icon FROM master_expense_categories WHERE master_id = ? ORDER BY sort_order, name");
    $stmt->execute([$masterId]);
    
    jsonOk(['categories' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    jsonError('Помилка', 500);
}