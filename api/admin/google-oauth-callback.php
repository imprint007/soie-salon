<?php
<?php
header('Content-Type: text/html; charset=utf-8');
session_start();

require_once __DIR__ . '/../lib/google_api.php';

session_start();

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

function showResult($success, $message) {
    $bg = $success ? '#a0d4a0' : '#e89999';
    $emoji = $success ? '✅' : '❌';
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Google</title></head><body style='font-family:sans-serif;background:#0d0c0b;color:#ede6d9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'>";
    echo "<div style='text-align:center;padding:40px;'>";
    echo "<div style='font-size:64px;margin-bottom:20px;'>$emoji</div>";
    echo "<h1 style='color:$bg;margin:0 0 12px;'>" . htmlspecialchars($message) . "</h1>";
    echo "<p style='color:#9e968a;margin:0 0 24px;'>Це вікно можна закрити</p>";
    echo "<script>setTimeout(function(){ if(window.opener){window.opener.postMessage({google_oauth:'" . ($success?'success':'error') . "'}, '*');window.close();}}, 1500);</script>";
    echo "</div></body></html>";
    exit;
}

if ($error) {
    showResult(false, "Помилка: " . $error);
}

if (!$code || !$state) {
    showResult(false, "Невірний запит");
}

if ($state !== ($_SESSION['google_oauth_state'] ?? '')) {
    showResult(false, "Невірний state (CSRF)");
}

try {
    // Обмінюємо code на токени
    $tokens = googleExchangeCode($code);
    
    if (empty($tokens['access_token'])) {
        showResult(false, "Не отримали токен");
    }
    
    // Беремо інфо про користувача
    $userInfo = googleGetUserInfo($tokens['access_token']);
    
    // Зберігаємо
    googleSaveTokens($tokens, $userInfo);
    
    showResult(true, "Підключено: " . ($userInfo['email'] ?? 'OK'));
    
} catch (Throwable $e) {
    showResult(false, "Помилка: " . $e->getMessage());
}