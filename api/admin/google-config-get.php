<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('google_client_id', 'google_client_secret', 'google_redirect_uri')");
    $stmt->execute();
    
    $result = [
        'google_client_id' => '',
        'google_client_secret_set' => false,
        'google_redirect_uri' => '',
    ];
    
    foreach ($stmt->fetchAll() as $row) {
        if ($row['setting_key'] === 'google_client_secret') {
            // Не повертаємо сам секрет, тільки чи він заданий
            $result['google_client_secret_set'] = !empty($row['setting_value']);
        } else {
            $result[$row['setting_key']] = $row['setting_value'] ?? '';
        }
    }
    
    // Дефолтний redirect URI
    if (empty($result['google_redirect_uri'])) {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $result['google_redirect_uri'] = 'https://' . $domain . '/api/admin/google-oauth-callback.php';
    }
    
    jsonOk($result);
} catch (Throwable $e) {
    jsonError('Помилка', 500);
}