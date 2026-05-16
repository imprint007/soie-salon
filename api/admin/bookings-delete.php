<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/google_booking_sync.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
if ($id <= 0) jsonError('Невірний ID');

// Видаляємо подію з Google перед видаленням брони
try {
    googleSyncBookingDelete($id);
} catch (Throwable $gErr) {
    error_log('Google delete error: ' . $gErr->getMessage());
}

try {
    $pdo = getDb();
    $pdo->prepare("DELETE FROM bookings WHERE id = ?")->execute([$id]);
    jsonOk(['message' => 'Видалено']);
} catch (Throwable $e) {
    jsonError('Помилка БД', 500);
}