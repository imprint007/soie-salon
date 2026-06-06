<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $fields = $pdo->query("SELECT * FROM client_custom_fields ORDER BY sort_order ASC")->fetchAll();
    jsonOk(['data' => $fields]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}