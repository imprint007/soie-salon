<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('Невірний ID');
    
    $pdo = getDb();
    $pdo->prepare("DELETE FROM master_schedule_exceptions WHERE id = ?")->execute([$id]);
    
    jsonOk(['message' => 'Видалено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}