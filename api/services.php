<?php
/**
 * API: список послуг
 * URL: /api/services.php
 *
 * Метод: GET
 * Відповідь: JSON масив послуг
 */

// Дозволяємо запити з нашого сайту (CORS)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// Підключаємо налаштування БД
$config = require __DIR__ . '/config/database.php';

try {
    // Підключення до бази даних через PDO
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // SQL-запит: дістаємо всі активні послуги
    $sql = "SELECT id, name, description, base_price, duration_min, image_url, category 
            FROM services 
            WHERE is_active = TRUE 
            ORDER BY id";
    
    $stmt = $pdo->query($sql);
    $services = $stmt->fetchAll();

    // Віддаємо JSON
    echo json_encode([
        'success' => true,
        'count'   => count($services),
        'data'    => $services,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    // У випадку помилки — повертаємо її як JSON
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Помилка бази даних: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}