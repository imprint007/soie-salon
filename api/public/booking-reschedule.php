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
    $newDate = trim($input['new_date'] ?? '');
    $newTime = trim($input['new_time'] ?? '');
    $newMasterId = !empty($input['new_master_id']) ? (int)$input['new_master_id'] : null;
    
    if (empty($code) || empty($newDate) || empty($newTime)) {
        echo json_encode(['success' => false, 'error' => 'Введіть всі дані']);
        exit;
    }
    
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Перевіряємо налаштування
    $allowReschedule = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_client_reschedule'")->fetchColumn();
    if (!$allowReschedule) {
        echo json_encode(['success' => false, 'error' => 'Перенесення через сайт заборонено. Зателефонуйте в салон.']);
        exit;
    }
    
    $rescheduleHours = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'reschedule_hours_before'")->fetchColumn() ?: 48);
    $maxReschedules = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'max_reschedules'")->fetchColumn() ?: 2);
    
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
        echo json_encode(['success' => false, 'error' => 'Не можна перенести виконану бронь']);
        exit;
    }
    
    // Перевіряємо що ще не пізно
    $bookingTimestamp = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
    $hoursUntil = ($bookingTimestamp - time()) / 3600;
    
    if ($hoursUntil < $rescheduleHours) {
        echo json_encode([
            'success' => false, 
            'error' => "Переносити можна не пізніше ніж за $rescheduleHours годин. Залишилось " . round($hoursUntil, 1) . " год. Зателефонуйте в салон."
        ]);
        exit;
    }
    
    // Перевіряємо ліміт перенесень
    $rescheduleCount = (int)$booking['reschedule_count'] ?? 0;
    if ($rescheduleCount >= $maxReschedules) {
        echo json_encode([
            'success' => false, 
            'error' => "Ви вже переносили цю бронь $maxReschedules раз. Більше не можна. Зателефонуйте в салон."
        ]);
        exit;
    }
    
    // Перевіряємо що нова дата не в минулому
    $newTimestamp = strtotime("$newDate $newTime");
    if ($newTimestamp <= time()) {
        echo json_encode(['success' => false, 'error' => 'Нова дата/час у минулому']);
        exit;
    }
    
    // Перевіряємо мінімальний час до візиту
    $minHours = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'booking_min_hours'")->fetchColumn() ?: 3);
    $newHoursUntil = ($newTimestamp - time()) / 3600;
    if ($newHoursUntil < $minHours) {
        echo json_encode(['success' => false, 'error' => "Мінімум $minHours год до візиту"]);
        exit;
    }
    
    // Перевіряємо щоб слот був вільний у цього або іншого майстра
    $masterId = $newMasterId ?? (int)$booking['master_id'];
    $duration = (int)$booking['duration_min'];
    
    // Дата початку нової брони
    $newStartTime = $newTime . ':00';
    $newEndTimestamp = $newTimestamp + ($duration * 60);
    $newEndTime = date('H:i:s', $newEndTimestamp);
    
    // Чи є перекриваюча бронь у цього майстра (виключаючи поточну)
    $conflictStmt = $pdo->prepare("
        SELECT id, booking_code FROM bookings
        WHERE id != ?
          AND master_id = ?
          AND booking_date = ?
          AND status NOT IN ('cancelled')
          AND (
              (booking_time <= ? AND ADDTIME(booking_time, SEC_TO_TIME(duration_min * 60)) > ?)
              OR (booking_time < ? AND ADDTIME(booking_time, SEC_TO_TIME(duration_min * 60)) >= ?)
              OR (booking_time >= ? AND booking_time < ?)
          )
    ");
    $conflictStmt->execute([
        $booking['id'], $masterId, $newDate,
        $newStartTime, $newStartTime,
        $newEndTime, $newEndTime,
        $newStartTime, $newEndTime
    ]);
    
    if ($conflictStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Цей час вже зайнято. Оберіть інший.']);
        exit;
    }
    
    // Зберігаємо стару дату для email
    $oldDate = $booking['booking_date'];
    $oldTime = substr($booking['booking_time'], 0, 5);
    
    // ОНОВЛЮЄМО
    $pdo->prepare("UPDATE bookings 
        SET booking_date = ?, booking_time = ?, master_id = ?, 
            reschedule_count = COALESCE(reschedule_count, 0) + 1
        WHERE id = ?")
        ->execute([$newDate, $newStartTime, $masterId, $booking['id']]);
    
    // Sync Google Calendar
    try {
        require_once __DIR__ . '/../lib/google_booking_sync.php';
        googleSyncBookingUpdate($booking['id']);
    } catch (Throwable $gErr) {
        error_log('Google sync error: ' . $gErr->getMessage());
    }
    
    // Email уведомления
    try {
        require_once __DIR__ . '/../lib/mailer.php';
        
        $salonKeys = ['site_name','phone','email_for_notifications'];
        $sStmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (" . str_repeat('?,', count($salonKeys) - 1) . "?)");
        $sStmt->execute($salonKeys);
        $salonData = [];
        foreach ($sStmt->fetchAll() as $r) $salonData[$r['setting_key']] = $r['setting_value'];
        
        $salonName = $salonData['site_name'] ?? 'Salon';
        $manageUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/manage.html?code=' . urlencode($code);
        
        // Лист клієнту
        $clientSubject = "Вашу бронь $code перенесено";
        $clientBody = "<p>Доброго дня, {$booking['client_name']}!</p>";
        $clientBody .= "<p>Вашу бронь №{$code} успішно перенесено:</p>";
        $clientBody .= "<p><b>Було:</b> " . date('d.m.Y', strtotime($oldDate)) . " о $oldTime</p>";
        $clientBody .= "<p><b>Тепер:</b> " . date('d.m.Y', strtotime($newDate)) . " о $newTime</p>";
        $clientBody .= "<p><a href='$manageUrl'>Управляти бронею</a></p>";
        $clientBody .= "<p>" . htmlspecialchars($salonName) . "</p>";
        
        sendEmail($booking['client_email'], $booking['client_name'], $clientSubject, $clientBody);
        
        // Лист адміну
        if (!empty($salonData['email_for_notifications'])) {
            $adminSubject = "Клієнт переніс бронь $code";
            $adminBody = "<p>Клієнт {$booking['client_name']} ({$booking['client_phone']}) переніс бронь №{$code}.</p>";
            $adminBody .= "<p><b>Було:</b> " . date('d.m.Y', strtotime($oldDate)) . " о $oldTime</p>";
            $adminBody .= "<p><b>Тепер:</b> " . date('d.m.Y', strtotime($newDate)) . " о $newTime</p>";
            
            sendEmail($salonData['email_for_notifications'], 'Адмін', $adminSubject, $adminBody);
        }
    } catch (Throwable $emailErr) {
        error_log('Reschedule email error: ' . $emailErr->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Бронь перенесено',
        'new_date' => $newDate,
        'new_time' => $newTime,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}