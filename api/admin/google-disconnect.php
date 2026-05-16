<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/google_api.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    googleDisconnect();
    jsonOk();
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}