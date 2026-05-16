<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $clientId = trim($input['google_client_id'] ?? '');
    $clientSecret = $input['google_client_secret'] ?? null; // null = не міняти
    $redirectUri = trim($input['google_redirect_uri'] ?? '');
    
    if (empty($clientId)) jsonError('Введіть Client ID');
    if (empty($redirectUri)) jsonError('Введіть Redirect URI');
    
    // client_id
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['google_client_id', $clientId]);
    
    // redirect_uri
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['google_redirect_uri', $redirectUri]);
    
    // client_secret тільки якщо передано непусте
    if (!empty($clientSecret)) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute(['google_client_secret', $clientSecret]);
    }
    
    jsonOk();
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}