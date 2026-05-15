<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($input['name'] ?? '');
    $icon = trim($input['icon'] ?? '');
    
    if (empty($name)) jsonError('Назва обовʼязкова');
    if (strlen($name) > 100) jsonError('Назва занадто довга');
    
    // Перевіряємо що такої категорії ще нема
    $check = $pdo->prepare("SELECT id FROM master_expense_categories WHERE master_id = ? AND name = ?");
    $check->execute([$masterId, $name]);
    if ($check->fetch()) jsonError('Така категорія вже існує');
    
    $pdo->prepare("INSERT INTO master_expense_categories (master_id, name, icon) VALUES (?, ?, ?)")
        ->execute([$masterId, $name, $icon ?: null]);
    
    jsonOk(['id' => $pdo->lastInsertId()]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}