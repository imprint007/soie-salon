<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
    
    $settings = [];
    foreach ($rows as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    
    jsonOk(['data' => $settings]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}