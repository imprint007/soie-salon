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
    $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
    $stmt->execute([$id]);
    jsonOk(['message' => 'Видалено']);
} catch (PDOException $e) {
    jsonError('Помилка БД', 500);
}