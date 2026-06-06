<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $clientId = (int)($input['client_id'] ?? 0);
    $photoUrl = trim($input['photo_url'] ?? '');
    $caption = trim($input['caption'] ?? '');
    $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
    $photoType = $input['photo_type'] ?? 'result';
    
    if ($clientId <= 0) jsonError('Невірний client_id');
    if (empty($photoUrl)) jsonError('Вкажіть URL фото');
    
    $validTypes = ['before', 'after', 'result', 'other'];
    if (!in_array($photoType, $validTypes)) $photoType = 'result';
    
    $pdo->prepare("INSERT INTO client_photos (client_id, photo_url, caption, booking_id, photo_type) VALUES (?, ?, ?, ?, ?)")
        ->execute([$clientId, $photoUrl, $caption, $bookingId, $photoType]);
    
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}