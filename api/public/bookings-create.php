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

function jsonFail($msg) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
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
    
    // Валідація
    if (empty($name)) jsonFail('Введіть імʼя');
    if (empty($phone)) jsonFail('Введіть телефон');
    if (empty($email)) jsonFail('Введіть email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonFail('Невірний email');
    if (empty($bookingDate)) jsonFail('Оберіть дату');
    if (empty($bookingTime)) jsonFail('Оберіть час');
    if ($totalPrice <= 0) jsonFail('Невірна сума');
    if ($duration <= 0) jsonFail('Невірна тривалість');
    if (empty($serviceId) && empty($packageId)) jsonFail('Оберіть послугу або пакет');
    
    // Послуга чи пакет
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
    
    // Депозит враховуємо тільки якщо увімкнений
$depositRequired = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'deposit_required'")->fetchColumn();
$deposit = 0;
if ($depositRequired === '1') {
    $deposit = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'deposit_amount'")->fetchColumn();
}
    $prefix = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'booking_code_prefix'")->fetchColumn() ?: 'UC';
    
    $code = $prefix . '-' . date('Y') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $optionsJson = !empty($selectedOptions) ? json_encode($selectedOptions, JSON_UNESCAPED_UNICODE) : null;
    
    // Запис у БД
    $stmt = $pdo->prepare("
    INSERT INTO bookings 
    (booking_code, service_id, master_id, client_id, client_name, client_phone, client_email, client_comment, 
     booking_date, booking_time, duration_min, total_price, deposit_amount, deposit_paid, 
     selected_options, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $code,
        $realServiceId,
        $masterId > 0 ? $masterId : null,
        $clientId,
        $name, $phone, $email, $comment,
        $bookingDate, $bookingTime, $duration,
        $totalPrice, $deposit, $optionsJson,
    ]);

    // Автоматично створюємо/знаходимо клієнта
        $clientId = null;
        try {
            $clientStmt = $pdo->prepare("INSERT INTO clients (name, phone, email) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    name = IF(VALUES(name) != '', VALUES(name), name),
                    email = IF(VALUES(email) != '', VALUES(email), email),
                    id = LAST_INSERT_ID(id)");
            $clientStmt->execute([$name, $phone, $email]);
            $clientId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('Client auto-create error: ' . $e->getMessage());
        }

    $bookingId = (int)$pdo->lastInsertId();
    
// ============================================
// НАГАДУВАННЯ В TELEGRAM (24г і 2г до візиту)
// ============================================
try {
    $bookingDateTime = new DateTime("{$bookingDate} {$bookingTime}", new DateTimeZone('Europe/Kyiv'));
    
    // Нагадування за 24 години
    $remind24 = (clone $bookingDateTime)->modify('-24 hours');
    // Нагадування за 2 години
    $remind2 = (clone $bookingDateTime)->modify('-2 hours');
    
    $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    
    // Знаходимо bot_user по телефону
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $last10 = substr($cleanPhone, -10);
    
    $buStmt = $pdo->prepare("SELECT id, telegram_user_id FROM bot_users WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE ? LIMIT 1");
    $buStmt->execute(['%' . $last10 . '%']);
    $botUser = $buStmt->fetch();
    
    if ($botUser && $botUser['telegram_user_id']) {
        $remStmt = $pdo->prepare("INSERT INTO bot_reminders (booking_id, bot_user_id, telegram_user_id, remind_at, reminder_type) VALUES (?, ?, ?, ?, ?)");
        
        // 24г — тільки якщо ще є час
        if ($remind24 > $now) {
            $remStmt->execute([$bookingId, $botUser['id'], $botUser['telegram_user_id'], $remind24->format('Y-m-d H:i:s'), '24h']);
        }
        
        // 2г — тільки якщо ще є час
        if ($remind2 > $now) {
            $remStmt->execute([$bookingId, $botUser['id'], $botUser['telegram_user_id'], $remind2->format('Y-m-d H:i:s'), '2h']);
        }
    }
} catch (Throwable $remErr) {
    error_log('Reminder create error: ' . $remErr->getMessage());
}

    // ============================================
// GOOGLE CALENDAR SYNC
// ============================================
try {
    require_once __DIR__ . '/../lib/google_booking_sync.php';
    googleSyncBookingCreate($bookingId);
} catch (Throwable $gErr) {
    error_log('Google sync error: ' . $gErr->getMessage());
}

    // ============================================
    // EMAIL УВЕДОМЛЕНИЯ
    // ============================================
    $emailLog = ['client' => null, 'admin' => null];
    
    try {
        require_once __DIR__ . '/../lib/mailer.php';
        
        // Назва послуги
        $serviceName = 'Послуга';
        if ($realServiceId) {
            $svc = $pdo->prepare("SELECT name FROM services WHERE id = ?");
            $svc->execute([$realServiceId]);
            $svcRow = $svc->fetch();
            if ($svcRow) $serviceName = $svcRow['name'];
        } elseif ($isPackage && $packageId) {
            $pkg = $pdo->prepare("SELECT name FROM packages WHERE id = ?");
            $pkg->execute([$packageId]);
            $pkgRow = $pkg->fetch();
            if ($pkgRow) $serviceName = $pkgRow['name'] . ' (пакет)';
        }
        
        // Майстер
        $masterName = 'Будь-який';
        if ($masterId > 0) {
            $mst = $pdo->prepare("SELECT name FROM masters WHERE id = ?");
            $mst->execute([$masterId]);
            $mstRow = $mst->fetch();
            if ($mstRow) $masterName = $mstRow['name'];
        }
        
        // Опції в назві
        if (!empty($selectedOptions) && is_array($selectedOptions)) {
            $optNames = [];
            foreach ($selectedOptions as $opt) {
                if (!empty($opt['name'])) $optNames[] = $opt['name'];
            }
            if (!empty($optNames)) $serviceName .= ' · ' . implode(', ', $optNames);
        }
        
        // Дата
        $dateObj = new DateTime($bookingDate);
        $dayNamesUa = ['Неділя','Понеділок','Вівторок','Середа','Четвер','Пʼятниця','Субота'];
        $monthNamesUa = ['', 'січня','лютого','березня','квітня','травня','червня','липня','серпня','вересня','жовтня','листопада','грудня'];
        $dateFormatted = $dayNamesUa[(int)$dateObj->format('w')] . ', ' . (int)$dateObj->format('d') . ' ' . $monthNamesUa[(int)$dateObj->format('n')];
        
        // Час
        [$h, $m] = explode(':', $bookingTime);
        $endMinutes = (int)$h * 60 + (int)$m + $duration;
        $endTime = sprintf('%02d:%02d', floor($endMinutes / 60) % 24, $endMinutes % 60);
        $timeFormatted = substr($bookingTime, 0, 5) . ' — ' . $endTime;
        
        // Дані салону
        $salonKeys = ['site_name','phone','address','email_for_notifications','email_template_client_subject','email_template_admin_subject','email_signature'];
        $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (" . str_repeat('?,', count($salonKeys) - 1) . "?)");
        $stmtS->execute($salonKeys);
        $salonData = [];
        foreach ($stmtS->fetchAll() as $r) $salonData[$r['setting_key']] = $r['setting_value'];
        
        $salonName = $salonData['site_name'] ?? 'Salon';
        $salonPhone = $salonData['phone'] ?? '';
        $salonAddress = $salonData['address'] ?? '';
        $adminEmail = $salonData['email_for_notifications'] ?? '';
        $signature = $salonData['email_signature'] ?? '';
        
        $manageUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/manage.html?code=' . urlencode($code);
        $payable = max(0, $totalPrice - $deposit);
        
        // ----- ЛИСТ КЛІЄНТУ -----
        $clientSubject = $salonData['email_template_client_subject'] ?? 'Ваш візит підтверджено';
        $clientSubject = renderEmailTemplate($clientSubject, ['code' => $code, 'name' => $name]);
        
        $clientBody = emailTemplateClientBooking([
            'name' => $name,
            'code' => $code,
            'service' => $serviceName,
            'master' => $masterName,
            'date' => $dateFormatted,
            'time' => $timeFormatted,
            'total' => number_format($totalPrice, 0, '.', ' '),
            'deposit' => number_format($deposit, 0, '.', ' '),
            'payable' => number_format($payable, 0, '.', ' '),
            'manage_url' => $manageUrl,
            'salon_name' => $salonName,
            'salon_phone' => $salonPhone,
            'salon_address' => $salonAddress,
            'signature' => $signature,
        ]);
        
        $emailLog['client'] = sendEmail($email, $name, $clientSubject, $clientBody);
        
        // ----- ЛИСТ АДМІНУ -----
        if (!empty($adminEmail)) {
            $adminSubject = $salonData['email_template_admin_subject'] ?? 'Нова заявка #{code}';
            $adminSubject = renderEmailTemplate($adminSubject, [
                'code' => $code, 'name' => $name,
                'date' => $dateFormatted, 'time' => substr($bookingTime, 0, 5),
                'service' => $serviceName, 'master' => $masterName,
            ]);
            
            $optionsHtml = '';
            if (!empty($selectedOptions) && is_array($selectedOptions)) {
                $optionsHtml = '<h2 style="margin:24px 0 16px;font-size:18px;">Опції</h2><ul style="font-size:14px;color:#444;">';
                foreach ($selectedOptions as $opt) {
                    $optName = htmlspecialchars($opt['name'] ?? '');
                    $optPrice = (int)($opt['price'] ?? 0);
                    $optionsHtml .= "<li>{$optName}" . ($optPrice > 0 ? " (+{$optPrice} ₴)" : "") . "</li>";
                }
                $optionsHtml .= '</ul>';
            }
            
            $commentHtml = '';
            if (!empty($comment)) {
                $commentHtml = $comment; // просто текст коментаря
            }
            
            $adminBody = emailTemplateAdminBooking([
                'code' => $code,
                'client_name' => $name, 'client_phone' => $phone, 'client_email' => $email,
                'client_comment' => $commentHtml,
                'service' => $serviceName, 'master' => $masterName,
                'date' => $dateFormatted, 'time' => $timeFormatted,
                'total' => number_format($totalPrice, 0, '.', ' '),
                'duration' => floor($duration / 60) . ' год ' . ($duration % 60) . ' хв',
                'options_html' => $optionsHtml,
            ]);
            
            $emailLog['admin'] = sendEmail($adminEmail, 'Адмін', $adminSubject, $adminBody);
        }
    } catch (Throwable $emailErr) {
        error_log('Email send error: ' . $emailErr->getMessage());
        $emailLog['error'] = $emailErr->getMessage();
    }
    
    // ============================================
    // TELEGRAM УВЕДОМЛЕНИЕ
    // ============================================
    try {
        require_once __DIR__ . '/../lib/telegram_notify.php';
        
        $tgServiceName = $serviceName;
        $tgMasterName = $masterName;
        
        // Форматуємо дату красивіше
        $tgDateObj = new DateTime($bookingDate);
        $dayNamesUa = ['Неділя','Понеділок','Вівторок','Середа','Четвер','Пʼятниця','Субота'];
        $monthNamesUa = ['', 'січня','лютого','березня','квітня','травня','червня','липня','серпня','вересня','жовтня','листопада','грудня'];
        $tgDateFormatted = $dayNamesUa[(int)$tgDateObj->format('w')] . ', ' . (int)$tgDateObj->format('d') . ' ' . $monthNamesUa[(int)$tgDateObj->format('n')];
        
        $tgMessage = "✨ <b>Нова бронь!</b>\n\n";
        $tgMessage .= "💇‍♀️ <b>Послуга:</b> $tgServiceName\n";
        $tgMessage .= "👩 <b>Клієнт:</b> $name\n";
        $tgMessage .= "📞 <b>Телефон:</b> $phone\n";
        $tgMessage .= "📅 <b>Дата:</b> $tgDateFormatted\n";
        $tgMessage .= "⏰ <b>Час:</b> " . substr($bookingTime, 0, 5) . "\n";
        $tgMessage .= "💇 <b>Майстер:</b> $tgMasterName\n";
        $tgMessage .= "💵 <b>Сума:</b> " . number_format($totalPrice, 0, '.', ' ') . " ₴\n";
        $tgMessage .= "🔖 <b>Код:</b> $code";

        if (!empty($comment)) {
            $tgMessage .= "\n💬 <b>Коментар:</b> $comment";
        }
        
        telegramSendMessage($tgMessage);
        
    } catch (Throwable $tgErr) {
        error_log('Telegram send error: ' . $tgErr->getMessage());
    }

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
        'email' => $emailLog,
        'message' => 'Бронювання створено'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Помилка: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}