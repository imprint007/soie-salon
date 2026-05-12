<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Тільки POST']);
    exit;
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Читаємо JSON-тіло
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Валідація обовʼязкових полів
    $name = trim($input['client_name'] ?? '');
    $phone = trim($input['client_phone'] ?? '');
    $email = trim($input['client_email'] ?? '');
    $comment = trim($input['client_comment'] ?? '');
    $serviceId = $input['service_id'] ?? null;
    $packageId = $input['package_id'] ?? null;
    $masterId = (int)($input['master_id'] ?? 0);
    $bookingDate = trim($input['booking_date'] ?? '');
    $bookingTime = trim($input['booking_time'] ?? '');
    $totalPrice = (int)($input['total_price'] ?? 0);
    $duration = (int)($input['duration_min'] ?? 0);
    $selectedOptions = $input['selected_options'] ?? [];
    
    // Перевірки
    if (empty($name)) { jsonFail('Введіть імʼя'); }
    if (empty($phone)) { jsonFail('Введіть телефон'); }
    if (empty($email)) { jsonFail('Введіть email'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonFail('Невірний email'); }
    if (empty($bookingDate)) { jsonFail('Оберіть дату'); }
    if (empty($bookingTime)) { jsonFail('Оберіть час'); }
    if ($totalPrice <= 0) { jsonFail('Невірна сума'); }
    if ($duration <= 0) { jsonFail('Невірна тривалість'); }
    if (empty($serviceId) && empty($packageId)) { jsonFail('Оберіть послугу або пакет'); }
    
    // Якщо service_id має префікс "pkg_" — це насправді пакет
    $isPackage = false;
    $realServiceId = null;
    if (is_string($serviceId) && strpos($serviceId, 'pkg_') === 0) {
        $packageId = (int)substr($serviceId, 4);
        $isPackage = true;
    } elseif (!empty($packageId)) {
        $isPackage = true;
        $packageId = (int)$packageId;
    } else {
        $realServiceId = (int)$serviceId;
    }
    
    // Депозит з налаштувань
    $deposit = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'deposit_amount'")->fetchColumn() ?: 600;
    $prefix = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'booking_code_prefix'")->fetchColumn() ?: 'UC';
    
    // Генеруємо унікальний код брони
    $code = $prefix . '-' . date('Y') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    
    // Якщо пакет — для service_id ставимо NULL (бронь привʼязана до пакета)
    // Зберігаємо склад опцій як JSON
    $optionsJson = !empty($selectedOptions) ? json_encode($selectedOptions, JSON_UNESCAPED_UNICODE) : null;
    
    // Запис у БД
    $stmt = $pdo->prepare("
        INSERT INTO bookings 
        (booking_code, service_id, master_id, client_name, client_phone, client_email, client_comment, 
         booking_date, booking_time, duration_min, total_price, deposit_amount, deposit_paid, 
         selected_options, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        $code,
        $realServiceId,
        $masterId > 0 ? $masterId : null,
        $name,
        $phone,
        $email,
        $comment,
        $bookingDate,
        $bookingTime,
        $duration,
        $totalPrice,
        $deposit,
        $optionsJson,
    ]);
    
    $bookingId = (int)$pdo->lastInsertId();
    
    // Відповідь клієнту
    echo json_encode([
        'success' => true,
        'booking' => [
            'id' => $bookingId,
            'code' => $code,
            'deposit' => $deposit,
            'total' => $totalPrice,
            'date' => $bookingDate,
            'time' => $bookingTime,
        ],
        'message' => 'Бронювання створено'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Помилка: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function jsonFail($msg) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}