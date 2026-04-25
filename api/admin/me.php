<?php
/**
 * API: повернути дані поточного адміна (якщо залогінений)
 * URL: /api/admin/me.php
 * Метод: GET
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

session_start();

// Чи є сесія?
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизовано']);
    exit;
}

// Сесії живе певний час — обмежимо до 8 годин
$max_age = 8 * 3600; // 8 годин у секундах
if (time() - ($_SESSION['logged_in_at'] ?? 0) > $max_age) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Сесія завершилась']);
    exit;
}

// Все добре — повертаємо дані
echo json_encode([
    'success' => true,
    'admin' => [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'],
        'role' => $_SESSION['admin_role'],
    ],
], JSON_UNESCAPED_UNICODE);