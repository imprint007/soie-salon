<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $sortOrder = (int)($input['sort_order'] ?? 0);
    
    if (empty($name)) jsonError('Назва обовʼязкова');
    
    if ($id > 0) {
        $pdo->prepare("UPDATE product_categories SET name=?, sort_order=? WHERE id=?")
            ->execute([$name, $sortOrder, $id]);
    } else {
        $pdo->prepare("INSERT INTO product_categories (name, sort_order) VALUES (?, ?)")
            ->execute([$name, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
    }
    
    jsonOk(['id' => $id]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}