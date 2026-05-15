<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (empty($_SESSION['master_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не залогінений']);
    exit;
}

// Перевірка таймауту (8 годин)
if (time() - ($_SESSION['master_login_time'] ?? 0) > 8 * 3600) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Сесія завершена']);
    exit;
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $stmt = $pdo->prepare("SELECT id, name, role, photo_url, phone, email, experience_years, bio, is_active FROM masters WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['master_id']]);
    $master = $stmt->fetch();
    
    if (!$master || $master['is_active'] != 1) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Не знайдено']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'master' => [
            'id' => (int)$master['id'],
            'name' => $master['name'],
            'role' => $master['role'],
            'photo_url' => $master['photo_url'],
            'phone' => $master['phone'],
            'email' => $master['email'],
            'experience_years' => (int)$master['experience_years'],
            'bio' => $master['bio'],
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка']);
}