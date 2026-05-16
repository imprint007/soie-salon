<?php
/**
 * Google OAuth конфігурація
 * Ключі зберігаються в таблиці settings, не в коді
 */

function googleConfigLoad() {
    static $config = null;
    if ($config !== null) return $config;
    
    $dbConfig = require __DIR__ . '/database.php';
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $keys = ['google_client_id', 'google_client_secret', 'google_redirect_uri'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $defaultRedirect = 'https://' . $domain . '/api/admin/google-oauth-callback.php';
    
    $config = [
        'client_id' => $settings['google_client_id'] ?? '',
        'client_secret' => $settings['google_client_secret'] ?? '',
        'redirect_uri' => $settings['google_redirect_uri'] ?? $defaultRedirect,
        'scope' => 'https://www.googleapis.com/auth/calendar openid email profile',
        'access_type' => 'offline',
        'prompt' => 'consent',
    ];
    
    return $config;
}

function googleIsConfigured() {
    $c = googleConfigLoad();
    return !empty($c['client_id']) && !empty($c['client_secret']);
}