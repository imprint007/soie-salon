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
    
    // Параметри
    $date = trim($_GET['date'] ?? '');
    $serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
    $packageId = isset($_GET['package_id']) ? (int)$_GET['package_id'] : 0;
    $duration = (int)($_GET['duration'] ?? 60);
    
    // Валідація
    if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'error' => 'Невірна дата']);
        exit;
    }
    if ($duration <= 0 || $duration > 600) {
        echo json_encode(['success' => false, 'error' => 'Невірна тривалість']);
        exit;
    }
    
    // Завантажуємо налаштування
    $settingsKeys = [
        'working_hours_mon','working_hours_tue','working_hours_wed','working_hours_thu',
        'working_hours_fri','working_hours_sat','working_hours_sun',
        'time_slot_minutes', 'booking_min_hours', 'timezone'
    ];
    $placeholders = str_repeat('?,', count($settingsKeys) - 1) . '?';
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingsKeys);
    $settings = [];
    foreach ($stmt->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    
    $slotMinutes = (int)($settings['time_slot_minutes'] ?? 30);
    $minHours = (int)($settings['booking_min_hours'] ?? 3);
    $timezone = $settings['timezone'] ?? 'Europe/Kyiv';
    date_default_timezone_set($timezone);
    
    // День тижня для обраної дати
    $dateObj = new DateTime($date);
    $dayKeys = ['mon','tue','wed','thu','fri','sat','sun'];
    $dayKey = $dayKeys[(int)$dateObj->format('N') - 1];
    
    $hoursRaw = $settings['working_hours_' . $dayKey] ?? '{}';
    $hoursData = json_decode($hoursRaw, true) ?: [];
    
    if (!empty($hoursData['closed'])) {
        echo json_encode([
            'success' => true,
            'date' => $date,
            'closed' => true,
            'message' => 'Салон не працює в цей день',
            'slots' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $openTime = $hoursData['open'] ?? '10:00';
    $closeTime = $hoursData['close'] ?? '21:00';
    
    // Парсимо години в хвилини від початку дня
    $openMinutes = timeToMinutes($openTime);
    $closeMinutes = timeToMinutes($closeTime);
    
    // Мінімальний час бронювання
    $now = new DateTime('now');
    $isToday = $date === $now->format('Y-m-d');
    $minBookingTime = (clone $now)->modify("+{$minHours} hours");
    $minBookingMinutesToday = $isToday ? (int)$minBookingTime->format('H') * 60 + (int)$minBookingTime->format('i') : 0;
    
    // Підходящі майстри
    if ($packageId > 0) {
        // Для пакета — усі активні майстри
        $masters = $pdo->query("SELECT id, name, photo_url, role FROM masters WHERE is_active = 1")->fetchAll();
    } else if ($serviceId > 0) {
        // Майстри що виконують цю послугу
        $stmt = $pdo->prepare("
            SELECT m.id, m.name, m.photo_url, m.role
            FROM masters m
            JOIN master_services ms ON ms.master_id = m.id
            WHERE m.is_active = 1 AND ms.service_id = ?
        ");
        $stmt->execute([$serviceId]);
        $masters = $stmt->fetchAll();
    } else {
        $masters = $pdo->query("SELECT id, name, photo_url, role FROM masters WHERE is_active = 1")->fetchAll();
    }
    
    if (empty($masters)) {
        echo json_encode([
            'success' => true,
            'date' => $date,
            'closed' => false,
            'message' => 'Немає майстрів які виконують цю послугу',
            'slots' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Існуючі брони на цей день
    $stmt = $pdo->prepare("
        SELECT id, master_id, booking_time, duration_min
        FROM bookings
        WHERE booking_date = ? AND status IN ('pending', 'confirmed')
    ");
    $stmt->execute([$date]);
    $existingBookings = $stmt->fetchAll();
    
    // Будуємо мапу: master_id => [{start, end}]
    $busyMap = [];
    foreach ($existingBookings as $b) {
        if (empty($b['master_id'])) continue;
        $mid = (int)$b['master_id'];
        $startMin = timeToMinutes($b['booking_time']);
        $endMin = $startMin + (int)$b['duration_min'];
        if (!isset($busyMap[$mid])) $busyMap[$mid] = [];
        $busyMap[$mid][] = ['start' => $startMin, 'end' => $endMin];
    }
    
    // Генеруємо слоти
    $slots = [];
    $current = $openMinutes;
    
    while ($current + $duration <= $closeMinutes) {
        $slotEnd = $current + $duration;
        $timeStr = minutesToTime($current);
        $endStr = minutesToTime($slotEnd);
        
        // Чи в минулому?
        if ($isToday && $current < $minBookingMinutesToday) {
            $slots[] = [
                'time' => $timeStr,
                'end_time' => $endStr,
                'available' => false,
                'reason' => 'past',
                'master_id' => null,
                'master_name' => null,
                'master_photo' => null,
            ];
            $current += $slotMinutes;
            continue;
        }
        
        // Шукаємо вільного майстра
        $freeMasters = [];
        foreach ($masters as $m) {
            $mid = (int)$m['id'];
            $busy = $busyMap[$mid] ?? [];
            
            $isFree = true;
            foreach ($busy as $b) {
                // Чи перетинається слот з зайнятим часом?
                if ($current < $b['end'] && $slotEnd > $b['start']) {
                    $isFree = false;
                    break;
                }
            }
            
            if ($isFree) {
                $freeMasters[] = $m;
            }
        }
        
        if (empty($freeMasters)) {
            $slots[] = [
                'time' => $timeStr,
                'end_time' => $endStr,
                'available' => false,
                'reason' => 'no_master',
                'master_id' => null,
                'master_name' => null,
                'master_photo' => null,
            ];
        } else {
            // Перший вільний — за замовчуванням
            $picked = $freeMasters[0];
            
            // Масив усіх вільних майстрів
            $availableMasters = [];
            foreach ($freeMasters as $fm) {
                $availableMasters[] = [
                    'id' => (int)$fm['id'],
                    'name' => $fm['name'],
                    'photo' => $fm['photo_url'],
                    'role' => $fm['role'],
                ];
            }
            
            $slots[] = [
                'time' => $timeStr,
                'end_time' => $endStr,
                'available' => true,
                'reason' => null,
                'master_id' => (int)$picked['id'],
                'master_name' => $picked['name'],
                'master_photo' => $picked['photo_url'],
                'master_role' => $picked['role'],
                'free_masters_count' => count($freeMasters),
                'available_masters' => $availableMasters,
            ];
        }
        
        $current += $slotMinutes;
    }
    
    echo json_encode([
        'success' => true,
        'date' => $date,
        'closed' => false,
        'open_time' => $openTime,
        'close_time' => $closeTime,
        'slot_minutes' => $slotMinutes,
        'duration' => $duration,
        'slots' => $slots,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка: ' . $e->getMessage()]);
}

function timeToMinutes($time) {
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
}

function minutesToTime($minutes) {
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
}