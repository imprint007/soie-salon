<?php
/**
 * Хелпер для клієнтського Telegram-бота
 */

function botGetToken() {
    static $token = null;
    if ($token) return $token;
    
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'client_bot_token'");
    $stmt->execute();
    $token = $stmt->fetchColumn() ?: '';
    return $token;
}

function botGetDb() {
    static $pdo = null;
    if ($pdo) return $pdo;
    
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function botGetSetting($key, $default = '') {
    $pdo = botGetDb();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn() ?: $default;
}

function botApiCall($method, $params = []) {
    $token = botGetToken();
    if (!$token) return ['ok' => false, 'error' => 'No token'];
    
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true) ?: ['ok' => false];
}

function botSendMessage($chatId, $text, $keyboard = null, $parseMode = 'HTML') {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
    ];
    
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    
    return botApiCall('sendMessage', $params);
}

function botSendPhoto($chatId, $photoUrl, $caption = '', $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'photo' => $photoUrl,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];
    
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    
    return botApiCall('sendPhoto', $params);
}

function botAnswerCallback($callbackId, $text = '') {
    return botApiCall('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
    ]);
}

function botEditMessage($chatId, $messageId, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    
    if ($keyboard) {
        $params['reply_markup'] = $keyboard;
    }
    
    return botApiCall('editMessageText', $params);
}

function botRegisterUser($tgUser) {
    $pdo = botGetDb();
    
    $stmt = $pdo->prepare("INSERT INTO bot_users (telegram_user_id, telegram_username, first_name, last_name) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            telegram_username = VALUES(telegram_username),
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            updated_at = NOW()");
    
    $stmt->execute([
        $tgUser['id'],
        $tgUser['username'] ?? null,
        $tgUser['first_name'] ?? null,
        $tgUser['last_name'] ?? null,
    ]);
    
    // Повертаємо bot_user
    $stmt = $pdo->prepare("SELECT * FROM bot_users WHERE telegram_user_id = ?");
    $stmt->execute([$tgUser['id']]);
    return $stmt->fetch();
}

function botGetMainMenu() {
    $webAppUrl = botGetSetting('client_bot_webapp_url', 'https://curls.servicehelp.com.ua');
    $salonName = botGetSetting('site_name', 'Unique Curls');
    
    return [
        'inline_keyboard' => [
            [
                ['text' => '✨ Записатися', 'web_app' => ['url' => $webAppUrl]],
            ],
            [
                ['text' => '💬 Питання майстру', 'callback_data' => 'ask_master'],
                ['text' => '📋 Мої записи', 'callback_data' => 'my_bookings'],
            ],
            [
                ['text' => '📖 Догляд за волоссям', 'callback_data' => 'articles'],
            ],
            [
                ['text' => '📍 Як нас знайти', 'callback_data' => 'contacts'],
                ['text' => '⭐️ Про салон', 'callback_data' => 'about'],
            ],
        ]
    ];
}

function botSendVenue($chatId, $lat, $lng, $title, $address, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'latitude' => $lat,
        'longitude' => $lng,
        'title' => $title,
        'address' => $address,
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return botApiCall('sendVenue', $params);
}

function botSendLocation($chatId, $lat, $lng, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'latitude' => $lat,
        'longitude' => $lng,
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return botApiCall('sendLocation', $params);
}