<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $bookingId = (int)($input['booking_id'] ?? 0);
    $photoUrl = trim($input['photo_url'] ?? '');
    $photoType = $input['photo_type'] ?? 'result';
    $caption = trim($input['caption'] ?? '');
    
    if ($bookingId <= 0) jsonError('Невірний booking_id');
    if (empty($photoUrl)) jsonError('Вкажіть URL фото');
    
    // Беремо client_id
    $clientId = (int)$pdo->prepare("SELECT client_id FROM bookings WHERE id = ?")->execute([$bookingId]) 
        ? $pdo->query("SELECT client_id FROM bookings WHERE id = $bookingId")->fetchColumn() : 0;
    
    if ($clientId <= 0) jsonError('Клієнт не привʼязаний до брони');
    
    $pdo->prepare("INSERT INTO client_photos (client_id, booking_id, photo_url, photo_type, caption) VALUES (?, ?, ?, ?, ?)")
        ->execute([$clientId, $bookingId, $photoUrl, $photoType, $caption]);
    
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}