<?php
/**
 * Webhook клієнтського Telegram-бота
 */

require_once __DIR__ . '/bot-helper.php';

// Отримуємо update від Telegram
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200);
    exit('ok');
}

try {
    // Обробка повідомлень
    if (isset($update['message'])) {
        handleMessage($update['message']);
    }
    
    // Обробка натискань кнопок
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
    }
    
} catch (Throwable $e) {
    error_log('Bot webhook error: ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';

// ============================================
// ОБРОБКА ПОВІДОМЛЕНЬ
// ============================================
function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $user = $message['from'] ?? [];
    
    // Реєструємо користувача
    $botUser = botRegisterUser($user);
    
    // Команда /start
    if ($text === '/start' || $text === '/menu') {
        sendWelcome($chatId, $user);
        return;
    }
    
    // Команда /help
    if ($text === '/help') {
        sendHelp($chatId);
        return;
    }
    
    // Перевіряємо чи користувач в режимі "Питання майстру"
    $pdo = botGetDb();
    $state = getUserState($chatId);
    
    if ($state === 'awaiting_question') {
        handleQuestionText($chatId, $text, $botUser);
        return;
    }
    
    if (strpos($state, 'awaiting_question_to_') === 0) {
        $masterId = (int)str_replace('awaiting_question_to_', '', $state);
        handleQuestionText($chatId, $text, $botUser, $masterId);
        return;
    }
    
    // Невідома команда — показуємо меню
    botSendMessage($chatId, "Оберіть дію з меню 👇", botGetMainMenu());
}

// ============================================
// ОБРОБКА CALLBACK (КНОПКИ)
// ============================================
function handleCallback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data = $callback['data'] ?? '';
    $user = $callback['from'] ?? [];
    $callbackId = $callback['id'];
    
    botAnswerCallback($callbackId);
    
    // Головне меню
    if ($data === 'main_menu') {
        botEditMessage($chatId, $messageId, getWelcomeText($user), botGetMainMenu());
        return;
    }
    
    // Питання майстру
    if ($data === 'ask_master') {
        showMastersList($chatId, $messageId);
        return;
    }
    
    // Вибір конкретного майстра для питання
    if (strpos($data, 'ask_to_') === 0) {
        $masterId = (int)str_replace('ask_to_', '', $data);
        startQuestion($chatId, $messageId, $masterId);
        return;
    }
    
    // Питання всім
    if ($data === 'ask_all') {
        startQuestion($chatId, $messageId, 0);
        return;
    }
    
    // Статті
    if ($data === 'articles') {
        showArticlesList($chatId, $messageId);
        return;
    }
    
    // Конкретна стаття
    if (strpos($data, 'article_') === 0) {
        $articleId = (int)str_replace('article_', '', $data);
        showArticle($chatId, $messageId, $articleId);
        return;
    }
    
    // Контакти
    if ($data === 'contacts') {
        showContacts($chatId, $messageId);
        return;
    }
    
    // Про салон
    if ($data === 'about') {
        showAbout($chatId, $messageId);
        return;
    }
}

// ============================================
// /START — ПРИВІТАННЯ
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
            "/help — Ця довідка\n\n" .
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
    
    // Зберігаємо питання
    $stmt = $pdo->prepare("INSERT INTO bot_questions (bot_user_id, telegram_user_id, master_id, question_text) VALUES (?, ?, ?, ?)");
    $stmt->execute([$botUser['id'], $botUser['telegram_user_id'], $masterId, $text]);
    
    // Скидаємо стан
    setUserState($chatId, '');
    
    // Пересилаємо питання майстрам/адміну через існуючий бот сповіщень
    $notifyToken = botGetSetting('telegram_bot_token', '');
    $notifyChatId = botGetSetting('telegram_chat_id', '');
    
    if ($notifyToken && $notifyChatId) {
        $userName = trim(($botUser['first_name'] ?? '') . ' ' . ($botUser['last_name'] ?? ''));
        $userLink = $botUser['telegram_username'] ? "@{$botUser['telegram_username']}" : $userName;
        
        $masterName = 'всім';
        if ($masterId) {
            $stmt = $pdo->prepare("SELECT name FROM masters WHERE id = ?");
            $stmt->execute([$masterId]);
            $masterName = $stmt->fetchColumn() ?: 'майстру';
        }
        
        $msg = "💬 <b>Питання від клієнта</b>\n\n" .
               "👤 {$userName} ({$userLink})\n" .
               "👉 Кому: {$masterName}\n\n" .
               "❓ {$text}";
        
        // Відправляємо через бот сповіщень
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
// СТАТТІ ПРО ДОГЛЯД
// ============================================
function showArticlesList($chatId, $messageId) {
    $pdo = botGetDb();
    $articles = $pdo->query("SELECT id, title, excerpt FROM bot_articles WHERE is_published = 1 ORDER BY sort_order, id DESC")->fetchAll();
    
    if (empty($articles)) {
        botEditMessage($chatId, $messageId, "📖 Статті скоро зʼявляться! Слідкуйте за оновленнями.", [
            'inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'main_menu']]]
        ]);
        return;
    }
    
    $keyboard = [];
    foreach ($articles as $a) {
        $keyboard[] = [['text' => "📄 {$a['title']}", 'callback_data' => 'article_' . $a['id']]];
    }
    $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main_menu']];
    
    botEditMessage($chatId, $messageId,
        "📖 <b>Догляд за волоссям</b>\n\nОберіть статтю:",
        ['inline_keyboard' => $keyboard]
    );
}

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
    
    $text = "📖 <b>{$article['title']}</b>\n\n{$article['content']}";
    
    // Telegram обмеження — 4096 символів
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . '...';
    }
    
    botEditMessage($chatId, $messageId, $text, [
        'inline_keyboard' => [
            [['text' => '← До статей', 'callback_data' => 'articles']],
            [['text' => '🏠 Головне меню', 'callback_data' => 'main_menu']],
        ]
    ]);
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
    
    $keyboard = [['text' => '← Назад', 'callback_data' => 'main_menu']];
    if ($googleMaps) {
        $keyboard = [
            [['text' => '🗺 Відкрити на карті', 'url' => $googleMaps]],
            [['text' => '← Назад', 'callback_data' => 'main_menu']],
        ];
    } else {
        $keyboard = [[['text' => '← Назад', 'callback_data' => 'main_menu']]];
    }
    
    botEditMessage($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

function showAbout($chatId, $messageId) {
    $salonName = botGetSetting('site_name', 'Unique Curls');
    $tagline = botGetSetting('hero_tagline', '');
    $description = botGetSetting('hero_description', '');
    
    $text = "⭐️ <b>{$salonName}</b>\n\n";
    if ($tagline) $text .= "<i>{$tagline}</i>\n\n";
    if ($description) $text .= $description;
    
    botEditMessage($chatId, $messageId, $text, [
        'inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'main_menu']]]
    ]);
}

// ============================================
// СТАН КОРИСТУВАЧА (для питань)
// ============================================
function getUserState($chatId) {
    $pdo = botGetDb();
    // Зберігаємо в тимчасовій таблиці або в bot_users
    $stmt = $pdo->prepare("SELECT phone FROM bot_users WHERE telegram_user_id = ?");
    $stmt->execute([$chatId]);
    $user = $stmt->fetch();
    // Використовуємо поле phone тимчасово як state (або додамо окреме поле)
    // Краще додати поле:
    try {
        $stmt = $pdo->prepare("SELECT state FROM bot_users WHERE telegram_user_id = ?");
        $stmt->execute([$chatId]);
        return $stmt->fetchColumn() ?: '';
    } catch (Throwable $e) {
        return '';
    }
}

function setUserState($chatId, $state) {
    $pdo = botGetDb();
    try {
        $pdo->prepare("UPDATE bot_users SET state = ? WHERE telegram_user_id = ?")->execute([$state, $chatId]);
    } catch (Throwable $e) {
        error_log('setUserState error: ' . $e->getMessage());
    }
}