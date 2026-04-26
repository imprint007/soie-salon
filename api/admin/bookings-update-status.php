<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
$status = trim($input['status'] ?? '');

$validStatuses = ['pending', 'confirmed', 'done', 'cancelled'];
if ($id <= 0) jsonError('Невірний ID');
if (!in_array($status, $validStatuses)) jsonError('Невірний статус');

try {
    $pdo = getDb();
    $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?")->execute([$status, $id]);
    jsonOk(['message' => 'Статус оновлено']);
} catch (Throwable $e) {
    jsonError('Помилка БД', 500);
}