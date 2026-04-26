<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$url = trim($input['url'] ?? '');

// Тільки файли з /uploads/ — захист
if (!preg_match('#^/uploads/[a-z0-9_\-]+/[a-zA-Z0-9_\-\.]+$#i', $url)) {
    jsonError('Невірний шлях');
}

$path = __DIR__ . '/../..' . $url;

if (file_exists($path) && is_file($path)) {
    @unlink($path);
}

jsonOk(['message' => 'Видалено']);