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
    
    botSendMessage($chatId, "Оберіть дію з меню 👇", botGetMainMenu());
}

// ============================================
// CALLBACK (КНОПКИ)
// ============================================
function handleCallback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data = $callback['data'] ?? '';
    $user = $callback['from'] ?? [];
    $callbackId = $callback['id'];
    
    botAnswerCallback($callbackId);
    
    if ($data === 'main_menu') {
        botEditMessage($chatId, $messageId, getWelcomeText($user), botGetMainMenu());
        return;
    }
    
    if ($data === 'ask_master') {
        showMastersList($chatId, $messageId);
        return;
    }
    
    if (strpos($data, 'ask_to_') === 0) {
        $masterId = (int)str_replace('ask_to_', '', $data);
        startQuestion($chatId, $messageId, $masterId);
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
        $articleId = (int)str_replace('article_', '', $data);
        showArticle($chatId, $messageId, $articleId);
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
    $text = "📌 <b>Команди бота:</b>\n\n" .
            "/start — Головне меню\n" .
            "/menu — Показати меню\n" .
            "/help — Ця довідка\n" .
            "/link — Ваш Telegram ID\n\n" .
            "Або просто натисніть кнопку нижче 👇";
    
    botSendMessage($chatId, $text, botGetMainMenu());
}

// ============================================
// ПИТАННЯ МАЙСТРУ
// ============================================
function showMastersList($chatId, $messageId) {
    $pdo = botGetDb();
    $masters = $pdo->query("SELECT id, name, role FROM masters WHERE is_active = 1")->fetchAll();
    
    if (empty($masters)) {
        botEditMessage($chatId, $messageId, "На жаль, майстрів поки немає 😔", [
            'inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'main_menu']]]
        ]);
        return;
    }
    
    $keyboard = [];
    foreach ($masters as $m) {
        $keyboard[] = [['text' => "💇 {$m['name']} · {$m['role']}", 'callback_data' => 'ask_to_' . $m['id']]];
    }
    $keyboard[] = [['text' => '📢 Запитати всіх', 'callback_data' => 'ask_all']];
    $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main_menu']];
    
    botEditMessage($chatId, $messageId, 
        "💬 <b>Оберіть майстра</b>\n\nКому хочете задати питання?",
        ['inline_keyboard' => $keyboard]
    );
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
    
    botEditMessage($chatId, $messageId,
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
    $userLink = $botUser['telegram_username'] ? "@{$botUser['telegram_username']}" : $userName;
    
    $masterName = 'всім';
    $msg = "💬 <b>Питання від клієнта</b>\n\n" .
           "👤 {$userName} ({$userLink})\n";
    
    if ($masterId) {
        $stmt = $pdo->prepare("SELECT name, telegram_chat_id FROM masters WHERE id = ?");
        $stmt->execute([$masterId]);
        $master = $stmt->fetch();
        $masterName = $master['name'] ?? 'майстру';
        $msg .= "👉 Кому: {$masterName}\n\n";
        $msg .= "❓ {$text}";
        
        if (!empty($master['telegram_chat_id'])) {
            botSendMessage($master['telegram_chat_id'], $msg . "\n\n<i>Відповісти можна в адмінці</i>");
        }
    } else {
        $msg .= "👉 Кому: всім майстрам\n\n";
        $msg .= "❓ {$text}";
        
        $allMasters = $pdo->query("SELECT telegram_chat_id FROM masters WHERE is_active = 1 AND telegram_chat_id IS NOT NULL AND telegram_chat_id != ''")->fetchAll();
        foreach ($allMasters as $am) {
            botSendMessage($am['telegram_chat_id'], $msg . "\n\n<i>Відповісти можна в адмінці</i>");
        }
    }
    
    // Також в загальний чат
    $notifyToken = botGetSetting('telegram_bot_token', '');
    $notifyChatId = botGetSetting('telegram_chat_id', '');
    if ($notifyToken && $notifyChatId) {
        $url = "https://api.telegram.org/bot{$notifyToken}/sendMessage";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $notifyChatId,
                'text' => $msg,
                'parse_mode' => 'HTML',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    botSendMessage($chatId,
        "✅ <b>Питання надіслано!</b>\n\nМайстер відповість вам якнайшвидше. Очікуйте 💬",
        botGetMainMenu()
    );
}

// ============================================
// СТАТТІ
// ============================================
function showArticle($chatId, $messageId, $articleId) {
    $pdo = botGetDb();
    $stmt = $pdo->prepare("SELECT * FROM bot_articles WHERE id = ? AND is_published = 1");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch();
    
    if (!$article) {
        botEditMessage($chatId, $messageId, "Стаття не знайдена 😔", [
            'inline_keyboard' => [[['text' => '← До статей', 'callback_data' => 'articles']]]
        ]);
        return;
    }
    
    $backKeyboard = [
        'inline_keyboard' => [
            [['text' => '← До статей', 'callback_data' => 'articles']],
            [['text' => '🏠 Головне меню', 'callback_data' => 'main_menu']],
        ]
    ];
    
    // Інструкція — по кроках
    if ($article['article_type'] === 'instruction' && !empty($article['steps'])) {
        $steps = json_decode($article['steps'], true) ?: [];
        
        // Видаляємо попереднє повідомлення
        botApiCall('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        
        // Обкладинка
        if (!empty($article['image_url'])) {
            botSendPhoto($chatId, $article['image_url'], "📋 <b>{$article['title']}</b>\n\n{$article['excerpt']}");
        } else {
            botSendMessage($chatId, "📋 <b>{$article['title']}</b>\n\n{$article['excerpt']}");
        }
        
        // Кожен крок
        foreach ($steps as $i => $step) {
            $stepNum = $i + 1;
            $stepText = "<b>Крок {$stepNum}. {$step['title']}</b>\n\n{$step['text']}";
            
            if (!empty($step['photo'])) {
                $photoUrl = $step['photo'];
                if (strpos($photoUrl, 'http') !== 0) {
                    $photoUrl = botGetSetting('client_bot_webapp_url', 'https://curls.servicehelp.com.ua') . $photoUrl;
                }
                botSendPhoto($chatId, $photoUrl, $stepText);
            } else {
                botSendMessage($chatId, $stepText);
            }
            
            usleep(300000); // 0.3 сек між повідомленнями
        }
        
        // Кнопки навігації
        botSendMessage($chatId, "✅ <b>Готово!</b> Всього {$stepNum} кроків.", $backKeyboard);
        return;
    }
    
    // Звичайна стаття
    // Видаляємо попереднє повідомлення
    botApiCall('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    
    // Фото обкладинки
    if (!empty($article['image_url'])) {
        $photoUrl = $article['image_url'];
        if (strpos($photoUrl, 'http') !== 0) {
            $photoUrl = botGetSetting('client_bot_webapp_url', 'https://curls.servicehelp.com.ua') . $photoUrl;
        }
        botSendPhoto($chatId, $photoUrl, "📖 <b>{$article['title']}</b>");
    }
    
    // Текст (розбиваємо на частини якщо довгий)
    $text = $article['content'];
    if (mb_strlen($text) > 4000) {
        $parts = str_split($text, 3900);
        foreach ($parts as $part) {
            botSendMessage($chatId, $part);
            usleep(200000);
        }
    } else {
        botSendMessage($chatId, $text);
    }
    
    botSendMessage($chatId, "—", $backKeyboard);
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
    
    $text = "📍 <b>Як нас знайти</b>\n\n";
    if ($address) $text .= "🏠 {$address}" . ($city ? ", {$city}" : '') . "\n";
    if ($phone) $text .= "📞 {$phone}\n";
    if ($instagram) $text .= "📸 {$instagram}\n";
    
    $keyboard = [];
    if ($googleMaps) {
        $keyboard[] = [['text' => '🗺 Відкрити на карті', 'url' => $googleMaps]];
    }
    $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main_menu']];
    
    botEditMessage($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

function showAbout($chatId, $messageId) {
    $salonName = botGetSetting('site_name', 'Unique Curls');
    $tagline = botGetSetting('hero_tagline', '');
    $description = botGetSetting('hero_description', '');
    
    $text = "⭐️ <b>{$salonName}</b>\n\n";
    if ($tagline) $text .= "<i>{$tagline}</i>\n\n";
    if ($description) $text .= $description;
    if (empty($tagline) && empty($description)) $text .= "Ваш салон краси 💇‍♀️";
    
    botEditMessage($chatId, $messageId, $text, [
        'inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'main_menu']]]
    ]);
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