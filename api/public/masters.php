<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Можна фільтрувати по послузі
    $serviceId = (int)($_GET['service_id'] ?? 0);
    
    if ($serviceId > 0) {
        // Майстри які виконують цю послугу
        $stmt = $pdo->prepare("
            SELECT m.id, m.name, m.role, m.photo_url, m.experience_years
            FROM masters m
            JOIN master_services ms ON ms.master_id = m.id
            WHERE m.is_active = 1 AND ms.service_id = ?
            ORDER BY m.sort_order, m.id
        ");
        $stmt->execute([$serviceId]);
    } else {
        // Усі активні майстри
        $stmt = $pdo->query("
            SELECT id, name, role, photo_url, experience_years
            FROM masters
            WHERE is_active = 1
            ORDER BY sort_order, id
        ");
    }
    
    $masters = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $masters], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка']);
}