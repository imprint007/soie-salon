<?php
/**
 * API: вихід з адмінки
 * URL: /api/admin/logout.php
 * Метод: POST
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_start();
$_SESSION = [];
session_destroy();

echo json_encode(['success' => true, 'message' => 'Ви вийшли']);