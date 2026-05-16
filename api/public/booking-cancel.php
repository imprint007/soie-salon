<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Тільки POST']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = trim($input['code'] ?? '');
    
    if (empty($code)) {
        echo json_encode(['success' => false, 'error' => 'Невірний код']);
        exit;
    }
    
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Перевіряємо налаштування
    $allowCancel = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_client_cancel'")->fetchColumn();
    if (!$allowCancel) {
        echo json_encode(['success' => false, 'error' => 'Відміна броні через сайт заборонена. Зателефонуйте в салон.']);
        exit;
    }
    
    $cancelHours = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cancel_hours_before'")->fetchColumn() ?: 72);
    
    // Беремо бронь
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_code = ?");
    $stmt->execute([$code]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        echo json_encode(['success' => false, 'error' => 'Бронь не знайдена']);
        exit;
    }
    
    if ($booking['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'Бронь вже відмінена']);
        exit;
    }
    
    if ($booking['status'] === 'done') {
        echo json_encode(['success' => false, 'error' => 'Не можна відмінити виконану бронь']);
        exit;
    }
    
    // Перевіряємо часовий ліміт
    $bookingTimestamp = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
    $hoursUntil = ($bookingTimestamp - time()) / 3600;
    
    if ($hoursUntil < $cancelHours) {
        echo json_encode([
            'success' => false, 
            'error' => "Відмінити можна не пізніше ніж за $cancelHours годин. Залишилось " . round($hoursUntil, 1) . " год. Зателефонуйте в салон."
        ]);
        exit;
    }
    
    // Відміняємо
    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$booking['id']]);
    
    // Видаляємо з Google Calendar
    try {
        require_once __DIR__ . '/../lib/google_booking_sync.php';
        googleSyncBookingDelete($booking['id']);
    } catch (Throwable $gErr) {
        error_log('Google sync error: ' . $gErr->getMessage());
    }
    
    // Email уведомления
    try {
        require_once __DIR__ . '/../lib/mailer.php';
        
        $salonKeys = ['site_name','phone','email_for_notifications','email_signature'];
        $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (" . str_repeat('?,', count($salonKeys) - 1) . "?)");
        $stmtS->execute($salonKeys);
        $salonData = [];
        foreach ($stmtS->fetchAll() as $r) $salonData[$r['setting_key']] = $r['setting_value'];
        
        $salonName = $salonData['site_name'] ?? 'Salon';
        
        // Лист клієнту
        $clientSubject = "Вашу бронь $code відмінено";
        $clientBody = "<p>Доброго дня, {$booking['client_name']}!</p>";
        $clientBody .= "<p>Вашу бронь №{$code} було успішно відмінено за вашим запитом.</p>";
        $clientBody .= "<p>Якщо це сталось помилково — зателефонуйте в салон: " . ($salonData['phone'] ?? '') . "</p>";
        $clientBody .= "<p>" . htmlspecialchars($salonName) . "</p>";
        
        sendEmail($booking['client_email'], $booking['client_name'], $clientSubject, $clientBody);
        
        // Лист адміну
        if (!empty($salonData['email_for_notifications'])) {
            $adminSubject = "Клієнт відмінив бронь $code";
            $adminBody = "<p>Клієнт {$booking['client_name']} ({$booking['client_phone']}) відмінив бронь №{$code}.</p>";
            $adminBody .= "<p>Дата: " . date('d.m.Y', $bookingTimestamp) . " о " . date('H:i', $bookingTimestamp) . "</p>";
            
            sendEmail($salonData['email_for_notifications'], 'Адмін', $adminSubject, $adminBody);
        }
    } catch (Throwable $emailErr) {
        error_log('Cancel email error: ' . $emailErr->getMessage());
    }
    
    echo json_encode(['success' => true, 'message' => 'Бронь відмінено'], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}