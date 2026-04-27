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
    
    // ТІЛЬКИ ці ключі віддаємо публічно
    $publicKeys = [
        'site_name','site_tagline','site_description','logo_url','favicon_url','og_image_url','hero_image_url',
        'phone','phone_display','email','address','city','google_maps_url',
        'instagram_url','telegram_url','tiktok_url','facebook_url',
        'working_hours_mon','working_hours_tue','working_hours_wed','working_hours_thu',
        'working_hours_fri','working_hours_sat','working_hours_sun',
        'deposit_amount','deposit_currency','deposit_required',
        'cancel_hours_before','reschedule_hours_before',
        'color_primary','color_primary_2','color_background','color_surface','color_text','color_text_mute',
        'font_heading','font_body','theme_mode',
        'seo_title','seo_description','seo_keywords',
        'currency_symbol','language','timezone',
        'privacy_policy_url','terms_of_service_url',
    ];
    
    $placeholders = str_repeat('?,', count($publicKeys) - 1) . '?';
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($publicKeys);
    
    $settings = [];
    foreach ($stmt->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    
    echo json_encode(['success' => true, 'data' => $settings], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка']);
}