<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Тільки POST']);
    exit;
}

session_start();

try {
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Введіть логін і пароль']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id, name, role, photo_url, password_hash, is_active FROM masters WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $master = $stmt->fetch();
    
    if (!$master) {
        echo json_encode(['success' => false, 'error' => 'Невірний логін або пароль']);
        exit;
    }
    
    if ($master['is_active'] != 1) {
        echo json_encode(['success' => false, 'error' => 'Обліковий запис деактивовано']);
        exit;
    }
    
    if (empty($master['password_hash']) || !password_verify($password, $master['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Невірний логін або пароль']);
        exit;
    }
    
    // Логін успішний
    $_SESSION['master_id'] = (int)$master['id'];
    $_SESSION['master_name'] = $master['name'];
    $_SESSION['master_login_time'] = time();
    
    $pdo->prepare("UPDATE masters SET last_login = NOW() WHERE id = ?")->execute([$master['id']]);
    
    echo json_encode([
        'success' => true,
        'master' => [
            'id' => (int)$master['id'],
            'name' => $master['name'],
            'role' => $master['role'],
            'photo_url' => $master['photo_url'],
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка сервера']);
}