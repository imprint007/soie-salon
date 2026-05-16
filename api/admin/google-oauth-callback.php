<?php
// Спочатку ставимо правильний Content-Type
header('Content-Type: text/html; charset=utf-8');

// Стартуємо сесію (потрібна для state перевірки)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib/google_api.php';

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

function showResultPage($success, $message) {
    $color = $success ? '#a0d4a0' : '#e89999';
    $emoji = $success ? '✅' : '❌';
    $oauthStatus = $success ? 'success' : 'error';
    
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Google</title></head>";
    echo "<body style='font-family:sans-serif;background:#0d0c0b;color:#ede6d9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'>";
    echo "<div style='text-align:center;padding:40px;'>";
    echo "<div style='font-size:64px;margin-bottom:20px;'>$emoji</div>";
    echo "<h1 style='color:$color;margin:0 0 12px;'>" . htmlspecialchars($message) . "</h1>";
    echo "<p style='color:#9e968a;margin:0 0 24px;'>Це вікно можна закрити</p>";
    echo "<script>setTimeout(function(){ if(window.opener){window.opener.postMessage({google_oauth:'$oauthStatus'}, '*');window.close();}}, 1500);</script>";
    echo "</div></body></html>";
    exit;
}

if ($error) {
    showResultPage(false, "Помилка: " . $error);
}

if (!$code || !$state) {
    showResultPage(false, "Невірний запит");
}

if ($state !== ($_SESSION['google_oauth_state'] ?? '')) {
    showResultPage(false, "Невірний state (CSRF)");
}

try {
    // Обмінюємо code на токени
    $tokens = googleExchangeCode($code);
    
    if (empty($tokens['access_token'])) {
        showResultPage(false, "Не отримали токен");
    }
    
    // Беремо інфо про користувача
    $userInfo = googleGetUserInfo($tokens['access_token']);
    
    // Зберігаємо
    googleSaveTokens($tokens, $userInfo);
    
    showResultPage(true, "Підключено: " . ($userInfo['email'] ?? 'OK'));
    
} catch (Throwable $e) {
    showResultPage(false, "Помилка: " . $e->getMessage());
}