<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== Тест відправки email ===\n\n";

// 1. Перевіряємо чи є PHPMailer
$phpmailerPath = __DIR__ . '/lib/PHPMailer/PHPMailer.php';
if (file_exists($phpmailerPath)) {
    echo "✓ PHPMailer знайдено\n";
} else {
    echo "✗ PHPMailer НЕ знайдено за шляхом: $phpmailerPath\n";
    echo "  Завантажте з https://github.com/PHPMailer/PHPMailer/releases\n";
    exit;
}

// 2. Перевіряємо mailer.php
$mailerPath = __DIR__ . '/lib/mailer.php';
if (file_exists($mailerPath)) {
    echo "✓ mailer.php знайдено\n";
} else {
    echo "✗ mailer.php НЕ знайдено за шляхом: $mailerPath\n";
    exit;
}

// 3. Підключаємо
require_once $mailerPath;
echo "✓ mailer.php підключений\n\n";

// 4. Завантажуємо налаштування
$config = require __DIR__ . '/config/database.php';
$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
$pdo = new PDO($dsn, $config['username'], $config['password']);
$rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp%' OR setting_key = 'email_for_notifications'")->fetchAll(PDO::FETCH_ASSOC);

echo "=== SMTP-налаштування з БД ===\n";
foreach ($rows as $r) {
    $val = $r['setting_value'];
    // Маскуємо пароль
    if (str_contains($r['setting_key'], 'password')) {
        $val = $val ? '*** (' . strlen($val) . ' символів)' : 'ПОРОЖНІЙ';
    }
    if (empty($val)) $val = 'ПОРОЖНІЙ';
    echo "  {$r['setting_key']}: $val\n";
}
echo "\n";

// 5. Куди слати тест
$testEmail = $_GET['to'] ?? '';
if (empty($testEmail)) {
    echo "Щоб надіслати тест: додайте ?to=ваш@email.com у URL\n";
    exit;
}

echo "Відправляємо тест на: $testEmail\n";

$result = sendEmail(
    $testEmail,
    'Тест',
    'Тест відправки з Unique Curls',
    '<h1>Тест працює! ✓</h1><p>Якщо ви бачите цей лист — SMTP налаштовано правильно.</p>'
);

if ($result['success']) {
    echo "\n✓✓✓ ЛИСТ ВІДПРАВЛЕНО! Перевірте пошту (і папку Спам).\n";
} else {
    echo "\n✗✗✗ ПОМИЛКА: " . $result['error'] . "\n";
}