<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/google_api.php';
setupAdminApi();
requireAdmin();

// Генеруємо випадковий state для CSRF захисту
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$url = googleAuthUrl($state);

// Перенаправляємо
header('Location: ' . $url);
exit;