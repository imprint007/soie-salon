<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
if ($id <= 0) jsonError('Невірний ID');

try {
    $pdo = getDb();
    $pdo->prepare("DELETE FROM packages WHERE id = ?")->execute([$id]);
    jsonOk(['message' => 'Видалено']);
} catch (Throwable $e) {
    jsonError('Помилка БД', 500);
}