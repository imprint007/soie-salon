<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$updates = $input['settings'] ?? null;

if (!is_array($updates) || empty($updates)) {
    jsonError('Немає даних для збереження');
}

// Whitelist допустимих ключів — захист від підкидання сторонніх
$allowedKeys = [
    'site_name','site_tagline','site_description','logo_url','favicon_url','og_image_url','hero_image_url',
    'phone','phone_display','email','address','city','google_maps_url',
    'instagram_url','telegram_url','tiktok_url','facebook_url',
    'working_hours_mon','working_hours_tue','working_hours_wed','working_hours_thu',
    'working_hours_fri','working_hours_sat','working_hours_sun',
    'deposit_amount','deposit_currency','deposit_required',
    'cancel_hours_before','reschedule_hours_before','max_reschedules',
    'booking_advance_days','booking_min_hours','time_slot_minutes',
    'color_primary','color_primary_2','color_background','color_surface','color_text','color_text_mute',
    'font_heading','font_body','theme_mode',
    'smtp_host','smtp_port','smtp_user','smtp_password','smtp_encryption',
    'smtp_from_email','smtp_from_name','email_for_notifications',
    'email_template_client_subject','email_template_admin_subject','email_signature',
    'telegram_bot_token','telegram_bot_username','telegram_admin_chat_id','telegram_notifications_enabled',
    'google_calendar_id','google_oauth_client_id','google_oauth_client_secret','google_oauth_refresh_token',
    'payment_provider','payment_test_mode',
    'liqpay_public_key','liqpay_private_key','fondy_merchant_id','fondy_secret_key',
    'privacy_policy_url','terms_of_service_url','legal_entity','tax_id',
    'seo_title','seo_description','seo_keywords',
    'language','timezone','currency_symbol','booking_code_prefix','hero_video_url','hero_video_speed','google_client_id','google_client_secret','google_redirect_uri','allow_client_cancel','allow_client_reschedule',
    'mail_provider','brevo_api_key','brevo_from_email','brevo_from_name','mail_provider','brevo_api_key','brevo_from_email','brevo_from_name',
];

$pdo = getDb();

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    
    $saved = 0;
    foreach ($updates as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) continue;
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $stmt->execute([$key, (string)$value]);
        $saved++;
    }
    
    $pdo->commit();
    jsonOk(['saved' => $saved, 'message' => "Збережено $saved параметрів"]);
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}