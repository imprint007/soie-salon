<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/../config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function requireMaster() {
    if (empty($_SESSION['master_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Не залогінений']);
        exit;
    }
    if (time() - ($_SESSION['master_login_time'] ?? 0) > 8 * 3600) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Сесія завершена']);
        exit;
    }
}

function getMasterId() {
    return (int)($_SESSION['master_id'] ?? 0);
}

function jsonOk($data = []) {
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}