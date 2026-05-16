<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

try {
    $code = trim($_GET['code'] ?? '');
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
    
    // Завантажуємо бронь з повними даними
    $stmt = $pdo->prepare("
        SELECT 
            b.id, b.booking_code, b.client_name, b.client_phone, b.client_email,
            b.client_comment, b.booking_date, b.booking_time, b.duration_min,
            b.total_price, b.deposit_amount, b.deposit_paid, b.selected_options,
            b.selected_options_text, b.status, b.created_at,
            s.id AS service_id, s.name AS service_name, s.image_url AS service_image,
            m.id AS master_id, m.name AS master_name, m.photo_url AS master_photo, m.role AS master_role
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN masters m ON m.id = b.master_id
        WHERE b.booking_code = ?
    ");
    $stmt->execute([$code]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        echo json_encode(['success' => false, 'error' => 'Бронь не знайдена']);
        exit;
    }
    
    // Парсимо опції
    $options = [];
    if (!empty($booking['selected_options'])) {
        $parsed = json_decode($booking['selected_options'], true);
        if (is_array($parsed)) $options = $parsed;
    }
    $booking['selected_options'] = $options;
    
    // Налаштування 
    $settingsKeys = [
        'cancel_hours_before', 'reschedule_hours_before',
        'allow_client_cancel', 'allow_client_reschedule',
        'site_name', 'phone', 'address'
    ];
    $placeholders = implode(',', array_fill(0, count($settingsKeys), '?'));
    $sStmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $sStmt->execute($settingsKeys);
    $settings = [];
    foreach ($sStmt->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    
    // Розрахуємо чи можна змінювати/відміняти
    $bookingDateTime = $booking['booking_date'] . ' ' . $booking['booking_time'];
    $bookingTimestamp = strtotime($bookingDateTime);
    $hoursUntilBooking = ($bookingTimestamp - time()) / 3600;
    
    $cancelAllowed = !empty($settings['allow_client_cancel']) 
        && $booking['status'] !== 'cancelled' 
        && $booking['status'] !== 'done'
        && $hoursUntilBooking >= (int)($settings['cancel_hours_before'] ?? 72);
    
    $rescheduleAllowed = !empty($settings['allow_client_reschedule']) 
        && $booking['status'] !== 'cancelled' 
        && $booking['status'] !== 'done'
        && $hoursUntilBooking >= (int)($settings['reschedule_hours_before'] ?? 48);
    
    echo json_encode([
        'success' => true,
        'booking' => $booking,
        'permissions' => [
            'can_cancel' => $cancelAllowed,
            'can_reschedule' => $rescheduleAllowed,
            'hours_until_booking' => round($hoursUntilBooking, 1),
            'cancel_hours_required' => (int)($settings['cancel_hours_before'] ?? 72),
            'reschedule_hours_required' => (int)($settings['reschedule_hours_before'] ?? 48),
        ],
        'salon' => [
            'name' => $settings['site_name'] ?? 'Salon',
            'phone' => $settings['phone'] ?? '',
            'address' => $settings['address'] ?? '',
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}