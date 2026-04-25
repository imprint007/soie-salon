<?php
/**
 * API: вхід адміністратора
 * URL: /api/admin/login.php
 * Метод: POST
 * Тіло запиту (JSON): { "username": "admin", "password": "..." }
 */

// Налаштування CORS і відповіді
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Обробка preflight-запитів CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Приймаємо тільки POST-запити
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Тільки POST']);
    exit;
}

// Стартуємо сесію
session_start();

// Читаємо тіло запиту
$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

// Базова валідація
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Введіть логін та пароль']);
    exit;
}

// Підключаємо БД
$config = require __DIR__ . '/../config/database.php';

try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Шукаємо адміна (prepared statement — захист від SQL-інʼєкцій!)
    $stmt = $pdo->prepare("SELECT id, username, password_hash, full_name, role FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    // Перевіряємо: знайшли + пароль збігається
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        // Спеціально мовчимо «що саме не так» — щоб не допомагати взломщикам
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Невірний логін або пароль']);
        exit;
    }

    // Успіх! Зберігаємо в сесії
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['logged_in_at'] = time();

    // Оновлюємо last_login в БД
    $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);

    echo json_encode([
        'success' => true,
        'admin' => [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'full_name' => $admin['full_name'],
            'role' => $admin['role'],
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка бази']);
    error_log('Login error: ' . $e->getMessage()); // в логи сервера, не клиенту
}