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
    
    // Параметри: рік і місяць (1-12)
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    
    // Валідація
    if ($year < 2025 || $year > 2030) {
        echo json_encode(['success' => false, 'error' => 'Невірний рік']);
        exit;
    }
    if ($month < 1 || $month > 12) {
        echo json_encode(['success' => false, 'error' => 'Невірний місяць']);
        exit;
    }
    
    // Завантажуємо налаштування
    $settingsKeys = [
        'working_hours_mon','working_hours_tue','working_hours_wed','working_hours_thu',
        'working_hours_fri','working_hours_sat','working_hours_sun',
        'booking_advance_days', 'booking_min_hours', 'timezone'
    ];
    $placeholders = str_repeat('?,', count($settingsKeys) - 1) . '?';
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingsKeys);
    $settings = [];
    foreach ($stmt->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    
    $advanceDays = (int)($settings['booking_advance_days'] ?? 60);
    $minHours = (int)($settings['booking_min_hours'] ?? 3);
    $timezone = $settings['timezone'] ?? 'Europe/Kyiv';
    
    date_default_timezone_set($timezone);
    
    // Парсимо робочі дні
    $workingDays = []; // ['mon' => true/false, ...]
    $dayKeys = ['mon','tue','wed','thu','fri','sat','sun'];
    foreach ($dayKeys as $d) {
        $raw = $settings['working_hours_' . $d] ?? '{}';
        $data = json_decode($raw, true) ?: [];
        $workingDays[$d] = empty($data['closed']);
    }
    
    // Зараз
    $now = new DateTime('now');
    $minBookingTime = (clone $now)->modify("+{$minHours} hours");
    $maxBookingDate = (clone $now)->modify("+{$advanceDays} days");
    
    // Перший і останній день місяця
    $firstDay = new DateTime("$year-$month-01");
    $lastDay = (clone $firstDay)->modify('last day of this month');
    $daysInMonth = (int)$lastDay->format('d');
    
    // Перший день тижня (ISO 1 = Понеділок, 7 = Неділя)
    $firstDayOfWeek = (int)$firstDay->format('N'); // 1-7
    
    // Зміщення в календарній сітці: скільки порожніх клітин до 1 числа
    $offsetBefore = $firstDayOfWeek - 1;
    
    // Збираємо дні
    $days = [];
    
    // Хвостові дні попереднього місяця (для повноти сітки)
    if ($offsetBefore > 0) {
        $prev = (clone $firstDay)->modify('-1 day');
        $prevLast = (int)$prev->format('d');
        $startPrev = $prevLast - $offsetBefore + 1;
        $prevMonth = (int)$prev->format('n');
        $prevYear = (int)$prev->format('Y');
        for ($d = $startPrev; $d <= $prevLast; $d++) {
            $days[] = [
                'day' => $d,
                'date' => sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $d),
                'is_other_month' => true,
                'is_available' => false,
                'is_closed' => true,
                'is_past' => true,
                'is_today' => false,
                'is_too_far' => false,
            ];
        }
    }
    
    // Поточний місяць
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = new DateTime("$year-$month-$d");
        $dayKey = $dayKeys[(int)$date->format('N') - 1];
        $dateStr = $date->format('Y-m-d');
        
        $isPast = $date < (new DateTime($now->format('Y-m-d')));
        $isToday = $dateStr === $now->format('Y-m-d');
        $isClosed = !$workingDays[$dayKey];
        $isTooFar = $date > $maxBookingDate;
        
        // Якщо сьогодні — перевіряємо чи ще є слот після minHours
        $todayHasTime = true;
        if ($isToday) {
            $endOfToday = new DateTime($date->format('Y-m-d') . ' 23:59:59');
            if ($minBookingTime > $endOfToday) {
                $todayHasTime = false;
            }
        }
        
        $isAvailable = !$isPast && !$isClosed && !$isTooFar && $todayHasTime;
        
        $days[] = [
            'day' => $d,
            'date' => $dateStr,
            'is_other_month' => false,
            'is_available' => $isAvailable,
            'is_closed' => $isClosed,
            'is_past' => $isPast,
            'is_today' => $isToday,
            'is_too_far' => $isTooFar,
        ];
    }
    
    // Доповнюємо до повної сітки (6 рядків × 7 = 42 клітини)
    $totalCells = count($days);
    $needed = ceil($totalCells / 7) * 7;
    if ($needed < 42 && $totalCells % 7 !== 0) {
        $needed = ceil($totalCells / 7) * 7;
    }
    
    $next = (clone $lastDay)->modify('+1 day');
    $nextDay = 1;
    while (count($days) < $needed) {
        $days[] = [
            'day' => $nextDay,
            'date' => $next->format('Y-m-d'),
            'is_other_month' => true,
            'is_available' => false,
            'is_closed' => true,
            'is_past' => false,
            'is_today' => false,
            'is_too_far' => true,
        ];
        $next->modify('+1 day');
        $nextDay++;
    }
    
    // Метадані місяця
    $monthNamesUa = [
        1 => 'Січень', 2 => 'Лютий', 3 => 'Березень', 4 => 'Квітень',
        5 => 'Травень', 6 => 'Червень', 7 => 'Липень', 8 => 'Серпень',
        9 => 'Вересень', 10 => 'Жовтень', 11 => 'Листопад', 12 => 'Грудень'
    ];
    
    // Чи можна листати назад/вперед
    $minMonth = (int)$now->format('Y') * 12 + (int)$now->format('n');
    $currentMonth = $year * 12 + $month;
    $maxMonth = (int)$maxBookingDate->format('Y') * 12 + (int)$maxBookingDate->format('n');
    
    echo json_encode([
        'success' => true,
        'year' => $year,
        'month' => $month,
        'month_name' => $monthNamesUa[$month],
        'days' => $days,
        'can_go_prev' => $currentMonth > $minMonth,
        'can_go_next' => $currentMonth < $maxMonth,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка: ' . $e->getMessage()]);
}