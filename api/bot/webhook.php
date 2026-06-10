<?php
/**
 * Webhook клієнтського Telegram-бота
 */

require_once __DIR__ . '/bot-helper.php';

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200);
    exit('ok');
}

try {
    if (isset($update['message'])) {
        handleMessage($update['message']);
    }
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
    }
} catch (Throwable $e) {
    error_log('Bot webhook error: ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';

// ============================================
// ПОВІДОМЛЕННЯ
// ============================================
function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $user = $message['from'] ?? [];
    $botUser = botRegisterUser($user);

    if ($text === '/start' || $text === '/menu') {
        sendWelcome($chatId, $user);
        return;
    }
    if ($text === '/help') {
        sendHelp($chatId);
        return;
    }
    if ($text === '/link') {
        $tgId = $user['id'] ?? '—';
        botSendMessage($chatId,
            "🔗 <b>Ваш Telegram ID:</b>\n\n<code>{$tgId}</code>\n\n" .
            "Скопіюйте цей номер і передайте адміністратору салону.\n" .
            "Він додасть його у ваш профіль і ви будете отримувати питання від клієнтів.",
            botGetMainMenu()
        );
        return;
    }

    $state = getUserState($chatId);

    if ($state === 'awaiting_question') {
        handleQuestionText($chatId, $text, $botUser, null);
        return;
    }
    if (strpos($state, 'awaiting_question_to_') === 0) {
        $masterId = (int)str_replace('awaiting_question_to_', '', $state);
        handleQuestionText($chatId, $text, $botUser, $masterId);
        return;
    }
    if ($state === 'awaiting_phone') {
        handlePhoneInput($chatId, $text, $botUser);
        return;
    }
    botSendMessage($chatId, "Оберіть дію з меню 👇", botGetMainMenu());
}

// ============================================
// CALLBACK
// ============================================
function handleCallback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data = $callback['data'] ?? '';
    $user = $callback['from'] ?? [];
    $callbackId = $callback['id'];

    botAnswerCallback($callbackId);

    if ($data === 'main_menu') {
        safeBotEdit($chatId, $messageId, getWelcomeText($user), botGetMainMenu());
        return;
    }
    if ($data === 'ask_master') {
        showMastersList($chatId, $messageId);
        return;
    }
    if (strpos($data, 'ask_to_') === 0) {
        startQuestion($chatId, $messageId, (int)str_replace('ask_to_', '', $data));
        return;
    }
    if ($data === 'ask_all') {
        startQuestion($chatId, $messageId, 0);
        return;
    }
    if ($data === 'articles') {
        showArticlesList($chatId, $messageId);
        return;
    }
    if (strpos($data, 'article_') === 0) {
        showArticle($chatId, $messageId, (int)str_replace('article_', '', $data));
        return;
    }
    if ($data === 'change_phone') {
        changePhone($chatId, $messageId);
        return;
    }
    if ($data === 'my_bookings') {
        startMyBookings($chatId, $messageId);
        return;
    }
    if ($data === 'send_phone') {
        requestPhone($chatId, $messageId);
        return;
    }
    if (strpos($data, 'manage_') === 0) {
        $code = str_replace('manage_', '', $data);
        showBookingManage($chatId, $messageId, $code);
        return;
    }
    if ($data === 'contacts') {
        showContacts($chatId, $messageId);
        return;
    }
    if ($data === 'about') {
        showAbout($chatId, $messageId);
        return;
    }
    if (strpos($data, 'confirm_visit_') === 0) {
        botAnswerCallback($callbackId, '✅ Дякуємо! Чекаємо на вас!');
        return;
    }
}

function changePhone($chatId, $messageId) {
    $pdo = botGetDb();
    $pdo->prepare("UPDATE bot_users SET phone = NULL WHERE telegram_user_id = ?")->execute([$chatId]);
    setUserState($chatId, 'awaiting_phone');
    
    safeBotEdit($chatId, $messageId,
        "📋 <b>Мої записи</b>\n\nВведіть номер телефону яким ви записувались:\n\nНаприклад: <code>+380671234567</code>",
        ['inline_keyboard' => [[['text' => '✕ Скасувати', 'callback_data' => 'main_menu']]]]
    );
}

// ============================================
// ХЕЛПЕР — безпечне редагування повідомлення
// ============================================
function safeBotEdit($chatId, $messageId, $text, $keyboard) {
    $result = botEditMessage($chatId, $messageId, $text, $keyboard);
    if (!$result || empty($result['ok'])) {
        botApiCall('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        botSendMessage($chatId, $text, $keyboard);
    }
}

// ============================================
// /START
// ============================================
function getWelcomeText($user) {
    $name = $user['first_name'] ?? 'друже';
    $salonName = botGetSetting('site_name', 'Unique Curls');
    return "✨ <b>Вітаємо, {$name}!</b>\n\n" .
           "Ласкаво просимо до <b>{$salonName}</b> 💇‍♀️\n\n" .
           "Тут ви можете:\n" .
           "▸ Записатися на послугу\n" .
           "▸ Задати питання майстру\n" .
           "▸ Переглянути поради з догляду\n" .
           "▸ Керувати своїми записами\n\n" .
           "Оберіть дію 👇";
}

function sendWelcome($chatId, $user) {
    botSendMessage($chatId, getWelcomeText($user), botGetMainMenu());
}

function sendHelp($chatId) {
    botSendMessage($chatId,
        "📌 <b>Команди бота:</b>\n\n/start — Головне меню\n/menu — Показати меню\n/help — Ця довідка\n/link — Ваш Telegram ID\n\nАбо просто натисніть кнопку нижче 👇",
        botGetMainMenu()
    );
}

// ============================================
// ПИТАННЯ МАЙСТРУ
// ============================================
function showMastersList($chatId, $messageId) {
    $pdo = botGetDb();
    $masters = $pdo->query("SELECT id, name, role FROM masters WHERE is_active = 1")->fetchAll();

    $keyboard = [];
    if (!empty($masters)) {
        foreach ($masters as $m) {
            $keyboard[] = [['text' => "💇 {$m['name']} · {$m['role']}", 'callback_data' => 'ask_to_' . $m['id']]];
        }
        $keyboard[] = [['text' => '📢 Запитати всіх', 'callback_data' => 'ask_all']];
    }
    $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main_menu']];

    $text = empty($masters) ? "На жаль, майстрів поки немає 😔" : "💬 <b>Оберіть майстра</b>\n\nКому хочете задати питання?";
    safeBotEdit($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

function startQuestion($chatId, $messageId, $masterId) {
    $state = $masterId > 0 ? "awaiting_question_to_{$masterId}" : "awaiting_question";
    setUserState($chatId, $state);

    $pdo = botGetDb();
    $masterName = 'всім майстрам';
    if ($masterId > 0) {
        $stmt = $pdo->prepare("SELECT name FROM masters WHERE id = ?");
        $stmt->execute([$masterId]);
        $masterName = $stmt->fetchColumn() ?: 'майстру';
    }

    safeBotEdit($chatId, $messageId,
        "✏️ <b>Напишіть ваше питання</b>\n\nВаше повідомлення буде надіслано {$masterName}.\n\nПросто напишіть текст у відповідь 👇",
        ['inline_keyboard' => [[['text' => '✕ Скасувати', 'callback_data' => 'main_menu']]]]
    );
}

function handleQuestionText($chatId, $text, $botUser, $masterId = null) {
    $pdo = botGetDb();

    $stmt = $pdo->prepare("INSERT INTO bot_questions (bot_user_id, telegram_user_id, master_id, question_text) VALUES (?, ?, ?, ?)");
    $stmt->execute([$botUser['id'], $botUser['telegram_user_id'], $masterId, $text]);
    setUserState($chatId, '');

    $userName = trim(($botUser['first_name'] ?? '') . ' ' . ($botUser['last_name'] ?? ''));
    $userLink = !empty($botUser['telegram_username']) ? "@{$botUser['telegram_username']}" : $userName;

    $msg = "💬 <b>Питання від клієнта</b>\n\n👤 {$userName} ({$userLink})\n";

    if ($masterId) {
        $stmt = $pdo->prepare("SELECT name, telegram_chat_id FROM masters WHERE id = ?");
        $stmt->execute([$masterId]);
        $master = $stmt->fetch();
        $msg .= "👉 Кому: " . ($master['name'] ?? 'майстру') . "\n\n❓ {$text}";
        if (!empty($master['telegram_chat_id'])) {
            botSendMessage($master['telegram_chat_id'], $msg . "\n\n<i>Відповісти можна в адмінці</i>");
        }
    } else {
        $msg .= "👉 Кому: всім майстрам\n\n❓ {$text}";
        $allMasters = $pdo->query("SELECT telegram_chat_id FROM masters WHERE is_active = 1 AND telegram_chat_id IS NOT NULL AND telegram_chat_id != ''")->fetchAll();
        foreach ($allMasters as $am) {
            botSendMessage($am['telegram_chat_id'], $msg . "\n\n<i>Відповісти можна в адмінці</i>");
        }
    }

    // Загальний чат
    $notifyToken = botGetSetting('telegram_bot_token', '');
    $notifyChatId = botGetSetting('telegram_chat_id', '');
    if ($notifyToken && $notifyChatId) {
        $url = "https://api.telegram.org/bot{$notifyToken}/sendMessage";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['chat_id' => $notifyChatId, 'text' => $msg, 'parse_mode' => 'HTML']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    botSendMessage($chatId, "✅ <b>Питання надіслано!</b>\n\nМайстер відповість вам якнайшвидше. Очікуйте 💬", botGetMainMenu());
}

// ============================================
// СТАТТІ
// ============================================
function showArticlesList($chatId, $messageId) {
    $pdo = botGetDb();
    $articles = $pdo->query("SELECT id, title FROM bot_articles WHERE is_published = 1 ORDER BY sort_order, id DESC")->fetchAll();

    $keyboard = [];
    if (!empty($articles)) {
        foreach ($articles as $a) {
            $keyboard[] = [['text' => "📄 {$a['title']}", 'callback_data' => 'article_' . $a['id']]];
        }
    }
    $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main_menu']];

    $text = empty($articles) ? "📖 Статті скоро зʼявляться!" : "📖 <b>Догляд за волоссям</b>\n\nОберіть статтю:";
    safeBotEdit($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

function showArticle($chatId, $messageId, $articleId) {
    $pdo = botGetDb();
    $stmt = $pdo->prepare("SELECT * FROM bot_articles WHERE id = ? AND is_published = 1");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch();

    if (!$article) {
        safeBotEdit($chatId, $messageId, "Стаття не знайдена 😔", ['inline_keyboard' => [[['text' => '← До статей', 'callback_data' => 'articles']]]]);
        return;
    }

    $backKb = ['inline_keyboard' => [
        [['text' => '← До статей', 'callback_data' => 'articles']],
        [['text' => '🏠 Головне меню', 'callback_data' => 'main_menu']],
    ]];

    botApiCall('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);

    $webAppUrl = botGetSetting('client_bot_webapp_url', 'https://curls.servicehelp.com.ua');

    // ІНСТРУКЦІЯ
    if (($article['article_type'] ?? '') === 'instruction' && !empty($article['steps'])) {
        $steps = json_decode($article['steps'], true) ?: [];

        if (!empty($article['image_url'])) {
            $img = $article['image_url'];
            if (strpos($img, 'http') !== 0) $img = $webAppUrl . $img;
            botSendPhoto($chatId, $img, "📋 <b>{$article['title']}</b>" . (!empty($article['excerpt']) ? "\n\n{$article['excerpt']}" : ''));
        } else {
            botSendMessage($chatId, "📋 <b>{$article['title']}</b>" . (!empty($article['excerpt']) ? "\n\n{$article['excerpt']}" : ''));
        }

        $total = count($steps);
        foreach ($steps as $i => $step) {
            $n = $i + 1;
            $st = "<b>Крок {$n} з {$total}. " . ($step['title'] ?? '') . "</b>";
            if (!empty($step['text'])) $st .= "\n\n{$step['text']}";

            if (!empty($step['photo'])) {
                $pUrl = $step['photo'];
                if (strpos($pUrl, 'http') !== 0) $pUrl = $webAppUrl . $pUrl;
                botSendPhoto($chatId, $pUrl, $st);
            } else {
                botSendMessage($chatId, $st);
            }
            usleep(300000);
        }

        botSendMessage($chatId, "✅ <b>Готово!</b> Всього {$total} кроків.", $backKb);
        return;
    }

    // ЗВИЧАЙНА СТАТТЯ
    if (!empty($article['image_url'])) {
        $img = $article['image_url'];
        if (strpos($img, 'http') !== 0) $img = $webAppUrl . $img;
        botSendPhoto($chatId, $img, "📖 <b>{$article['title']}</b>");
    } else {
        botSendMessage($chatId, "📖 <b>{$article['title']}</b>");
    }

    // Фото статті
    $articlePhotos = !empty($article['photos']) ? json_decode($article['photos'], true) : [];
    foreach ($articlePhotos as $ap) {
        $pUrl = $ap['url'] ?? '';
        if (empty($pUrl)) continue;
        if (strpos($pUrl, 'http') !== 0) $pUrl = $webAppUrl . $pUrl;
        botSendPhoto($chatId, $pUrl, $ap['caption'] ?? '');
        usleep(200000);
    }

    // Текст
    $content = $article['content'] ?? '';
    if (!empty($content)) {
        if (mb_strlen($content) > 4000) {
            $chunks = str_split($content, 3900);
            foreach ($chunks as $chunk) {
                botSendMessage($chatId, $chunk);
                usleep(200000);
            }
        } else {
            botSendMessage($chatId, $content);
        }
    }

    botSendMessage($chatId, "—", $backKb);
}

// ============================================
// КОНТАКТИ / ПРО САЛОН
// ============================================
function showContacts($chatId, $messageId) {
    $phone = botGetSetting('phone', '');
    $address = botGetSetting('address', '');
    $city = botGetSetting('city', '');
    $googleMaps = botGetSetting('google_maps_url', '');
    $instagram = botGetSetting('social_instagram', '');
    $lat = botGetSetting('salon_latitude', '');
    $lng = botGetSetting('salon_longitude', '');
    $salonName = botGetSetting('site_name', 'Unique Curls');

    $fullAddress = $address . ($city ? ", {$city}" : '');

    // Кнопки
    $buttons = [];
    
    if ($lat && $lng) {
        $routeUrl = "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}";
        $buttons[] = [['text' => '🗺 Прокласти маршрут', 'url' => $routeUrl]];
    } elseif ($googleMaps) {
        $buttons[] = [['text' => '🗺 Відкрити на карті', 'url' => $googleMaps]];
    }
    
    if ($instagram) {
        $igUrl = strpos($instagram, 'http') === 0 ? $instagram : "https://instagram.com/{$instagram}";
        $buttons[] = [['text' => '📸 Instagram', 'url' => $igUrl]];
    }
    $buttons[] = [['text' => '← Назад', 'callback_data' => 'main_menu']];

    $keyboard = ['inline_keyboard' => $buttons];

    // Текст контактів
    $text = "📍 <b>Як нас знайти</b>\n\n";
    if ($fullAddress) $text .= "🏠 {$fullAddress}\n";
    if ($phone) $text .= "📞 <code>{$phone}</code> (натисніть щоб скопіювати)\n";
    if ($instagram) $text .= "📸 Instagram\n";

    // Спершу пробуємо відправити локацію
    if ($lat && $lng) {
        botApiCall('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        
        // Відправляємо локацію
        botSendLocation($chatId, (float)$lat, (float)$lng);
        usleep(200000);
        
        // Потім текст з кнопками
        botSendMessage($chatId, $text, $keyboard);
    } else {
        // Без координат — просто редагуємо повідомлення
        safeBotEdit($chatId, $messageId, $text, $keyboard);
    }
}
function showAbout($chatId, $messageId) {
    $salonName = botGetSetting('site_name', 'Unique Curls');
    $tagline = botGetSetting('hero_tagline', '');
    $desc = botGetSetting('hero_description', '');

    $text = "⭐️ <b>{$salonName}</b>\n\n";
    if ($tagline) $text .= "<i>{$tagline}</i>\n\n";
    if ($desc) $text .= $desc;
    if (empty($tagline) && empty($desc)) $text .= "Ваш салон краси 💇‍♀️";

    safeBotEdit($chatId, $messageId, $text, ['inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'main_menu']]]]);
}

// ============================================
// СТАН КОРИСТУВАЧА
// ============================================
function getUserState($chatId) {
    try {
        $pdo = botGetDb();
        $stmt = $pdo->prepare("SELECT state FROM bot_users WHERE telegram_user_id = ?");
        $stmt->execute([$chatId]);
        return $stmt->fetchColumn() ?: '';
    } catch (Throwable $e) {
        return '';
    }
}

function setUserState($chatId, $state) {
    try {
        $pdo = botGetDb();
        $pdo->prepare("UPDATE bot_users SET state = ? WHERE telegram_user_id = ?")->execute([$state, $chatId]);
    } catch (Throwable $e) {
        error_log('setUserState error: ' . $e->getMessage());
    }
}

// ============================================
// МОЇ ЗАПИСИ
// ============================================
function startMyBookings($chatId, $messageId) {
    $pdo = botGetDb();
    
    // Перевіряємо чи є збережений телефон
    $stmt = $pdo->prepare("SELECT phone FROM bot_users WHERE telegram_user_id = ?");
    $stmt->execute([$chatId]);
    $phone = $stmt->fetchColumn();
    
    if ($phone) {
        // Телефон є — одразу шукаємо броні
        botApiCall('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        showUserBookings($chatId, $phone);
    } else {
        // Просимо телефон
        setUserState($chatId, 'awaiting_phone');
        safeBotEdit($chatId, $messageId,
            "📋 <b>Мої записи</b>\n\nЩоб знайти ваші бронювання, напишіть номер телефону яким ви записувались.\n\nНаприклад: <code>+380671234567</code>",
            ['inline_keyboard' => [[['text' => '✕ Скасувати', 'callback_data' => 'main_menu']]]]
        );
    }
}

function handlePhoneInput($chatId, $text, $botUser) {
    $phone = preg_replace('/[^0-9+]/', '', $text);
    
    if (strlen($phone) < 10) {
        botSendMessage($chatId, "❌ Невірний формат. Введіть номер телефону, наприклад:\n<code>+380671234567</code>",
            ['inline_keyboard' => [[['text' => '✕ Скасувати', 'callback_data' => 'main_menu']]]]
        );
        return;
    }
    
    setUserState($chatId, '');
    
    // Зберігаємо телефон для наступних разів
    $pdo = botGetDb();
    $pdo->prepare("UPDATE bot_users SET phone = ? WHERE telegram_user_id = ?")->execute([$phone, $chatId]);
    
    // Привʼязуємо до клієнта якщо є
    $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '(', ''), ')', ''), '-', '') LIKE ?");
    $clientStmt->execute(['%' . substr($phone, -10) . '%']);
    $clientId = $clientStmt->fetchColumn();
    if ($clientId) {
        $pdo->prepare("UPDATE bot_users SET client_id = ? WHERE telegram_user_id = ?")->execute([$clientId, $chatId]);
    }
    
    showUserBookings($chatId, $phone);
}

function showUserBookings($chatId, $phone) {
    $pdo = botGetDb();
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $last10 = substr($cleanPhone, -10);
    
    // Активні броні
    $stmt = $pdo->prepare("
        SELECT b.booking_code, b.booking_date, b.booking_time, b.total_price, b.status,
               s.name AS service_name, m.name AS master_name
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN masters m ON m.id = b.master_id
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(b.client_phone, ' ', ''), '(', ''), ')', ''), '-', '') LIKE ?
        AND b.status IN ('pending', 'confirmed')
        ORDER BY b.booking_date ASC, b.booking_time ASC
        LIMIT 10
    ");
    $stmt->execute(['%' . $last10 . '%']);
    $bookings = $stmt->fetchAll();
    
    if (empty($bookings)) {
        botSendMessage($chatId,
            "📋 <b>Мої записи</b>\n\nАктивних бронювань не знайдено для номера <code>{$phone}</code>.\n\nМожливо ви записувались з іншого номера, або запис вже виконано.",
            ['inline_keyboard' => [
                [['text' => '🔄 Ввести інший номер', 'callback_data' => 'change_phone']],
                [['text' => '← Головне меню', 'callback_data' => 'main_menu']],
            ]]
        );
        return;
    }
    
    $webAppUrl = botGetSetting('client_bot_webapp_url', 'https://curls.servicehelp.com.ua');
    $dayNames = ['Нд','Пн','Вт','Ср','Чт','Пт','Сб'];
    $monthNames = ['','січ','лют','бер','кві','тра','чер','лип','сер','вер','жов','лис','гру'];
    
    $text = "📋 <b>Ваші активні записи:</b>\n\n";
    $keyboard = [];
    
    foreach ($bookings as $b) {
        $date = new DateTime($b['booking_date']);
        $dayName = $dayNames[(int)$date->format('w')];
        $day = (int)$date->format('d');
        $month = $monthNames[(int)$date->format('n')];
        $time = substr($b['booking_time'], 0, 5);
        $statusIcon = $b['status'] === 'confirmed' ? '✅' : '⏳';
        
        $text .= "{$statusIcon} <b>{$dayName}, {$day} {$month}</b> о {$time}\n";
        $text .= "    {$b['service_name']} · {$b['master_name']}\n";
        $text .= "    💰 {$b['total_price']} ₴\n\n";
        
        $manageUrl = $webAppUrl . '/manage.html?code=' . $b['booking_code'];
        $keyboard[] = [['text' => "✎ {$day} {$month} · {$b['service_name']}", 'web_app' => ['url' => $manageUrl]]];
    }
    
    $keyboard[] = [['text' => '🔄 Інший номер', 'callback_data' => 'change_phone']];
    $keyboard[] = [['text' => '← Головне меню', 'callback_data' => 'main_menu']];
    
    botSendMessage($chatId, $text, ['inline_keyboard' => $keyboard]);
}