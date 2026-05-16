<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/google_api.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = googleGetDb();
    $row = $pdo->query("SELECT id, google_email, google_name, connected_at, expires_at, refresh_token FROM google_oauth ORDER BY id DESC LIMIT 1")->fetch();
    
    if (!$row || empty($row['refresh_token'])) {
        jsonOk(['connected' => false]);
    }
    
    jsonOk([
        'connected' => true,
        'email' => $row['google_email'],
        'name' => $row['google_name'],
        'connected_at' => $row['connected_at'],
    ]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}