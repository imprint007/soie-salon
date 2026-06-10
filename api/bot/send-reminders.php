<?php
/**
 * Cron-скрипт: відправка нагадувань про бронювання
 * Запускати кожні 5 хвилин:
 */

require_once __DIR__ . '/bot-helper.php';

$pdo = botGetDb();

// Знаходимо нагадування які потрібно відправити
$stmt = $pdo->prepare("
    SELECT r.*, 
           b.booking_code, b.booking_date, b.booking_time, b.client_name, b.total_price, b.status,
           s.name AS service_name,
           m.name AS master_name
    FROM bot_reminders r
    JOIN bookings b ON b.id = r.booking_id
    LEFT JOIN services s ON s.id = b.service_id
    LEFT JOIN masters m ON m.id = b.master_id
    WHERE r.is_sent = 0 
      AND r.remind_at <= NOW()
      AND b.status IN ('pending', 'confirmed')
    ORDER BY r.remind_at ASC
    LIMIT 20
");
$stmt->execute();
$reminders = $stmt->fetchAll();

if (empty($reminders)) {
    echo "No reminders to send\n";
    exit;
}

$webAppUrl = botGetSetting('client_bot_webapp_url', 'https://curls.servicehelp.com.ua');
$salonName = botGetSetting('site_name', 'Unique Curls');
$salonPhone = botGetSetting('phone', '');

$dayNames = ['Неділя','Понеділок','Вівторок','Середа','Четвер','Пʼятниця','Субота'];
$monthNames = ['','січня','лютого','березня','квітня','травня','червня','липня','серпня','вересня','жовтня','листопада','грудня'];

$sent = 0;
$errors = 0;

foreach ($reminders as $r) {
    try {
        $date = new DateTime($r['booking_date']);
        $dayName = $dayNames[(int)$date->format('w')];
        $day = (int)$date->format('d');
        $month = $monthNames[(int)$date->format('n')];
        $time = substr($r['booking_time'], 0, 5);
        
        $manageUrl = $webAppUrl . '/manage.html?code=' . $r['booking_code'];
        
        if ($r['reminder_type'] === '24h') {
            $icon = '🔔';
            $title = 'Нагадування про запис завтра';
            $timeText = "завтра, <b>{$dayName}</b>";
        } else {
            $icon = '⏰';
            $title = 'Запис через 2 години!';
            $timeText = "<b>сьогодні</b>";
        }
        
        $text = "{$icon} <b>{$title}</b>\n\n";
        $text .= "📅 {$timeText}, <b>{$day} {$month}</b> о <b>{$time}</b>\n";
        $text .= "💇 {$r['service_name']}\n";
        $text .= "👩 Майстер: {$r['master_name']}\n";
        $text .= "💰 {$r['total_price']} ₴\n\n";
        
        if ($r['reminder_type'] === '24h') {
            $text .= "Якщо потрібно перенести або скасувати — натисніть кнопку нижче 👇";
        } else {
            $text .= "Чекаємо на вас у <b>{$salonName}</b>! 💫";
            if ($salonPhone) $text .= "\n📞 {$salonPhone}";
        }
        
        $keyboard = ['inline_keyboard' => [
            [['text' => '✎ Перенести / Скасувати', 'web_app' => ['url' => $manageUrl]]],
            [['text' => '✅ Буду вчасно!', 'callback_data' => 'confirm_visit_' . $r['booking_id']]],
        ]];
        
        $result = botSendMessage($r['telegram_user_id'], $text, $keyboard);
        
        if ($result && !empty($result['ok'])) {
            $pdo->prepare("UPDATE bot_reminders SET is_sent = 1, sent_at = NOW() WHERE id = ?")->execute([$r['id']]);
            $sent++;
        } else {
            $errors++;
            error_log("Reminder send failed for booking {$r['booking_code']}: " . json_encode($result));
        }
        
        usleep(100000); // 0.1 сек між повідомленнями
        
    } catch (Throwable $e) {
        $errors++;
        error_log("Reminder error: " . $e->getMessage());
    }
}

echo "Sent: {$sent}, Errors: {$errors}\n";