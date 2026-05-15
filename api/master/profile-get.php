<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $stmt = $pdo->prepare("SELECT id, name, role, phone, email, bio, photo_url, experience_years, username FROM masters WHERE id = ? LIMIT 1");
    $stmt->execute([$masterId]);
    $master = $stmt->fetch();
    
    if (!$master) jsonError('Не знайдено', 404);
    
    jsonOk(['profile' => $master]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}