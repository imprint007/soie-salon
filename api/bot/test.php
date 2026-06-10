<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1\n";
require_once __DIR__ . '/bot-helper.php';
echo "Step 2\n";

// Імітуємо пустий запит як Telegram
$input = '{}';
$update = json_decode($input, true);
echo "Step 3: update parsed\n";

// Перевіряємо всі функції з webhook
echo "Step 4: checking functions\n";

echo "botGetMainMenu: " . (function_exists('botGetMainMenu') ? 'OK' : 'MISSING') . "\n";
echo "botRegisterUser: " . (function_exists('botRegisterUser') ? 'OK' : 'MISSING') . "\n";
echo "botSendMessage: " . (function_exists('botSendMessage') ? 'OK' : 'MISSING') . "\n";

// Пробуємо підключити webhook
echo "Step 5: loading webhook\n";
include __DIR__ . '/webhook.php';
echo "\nStep 6: ALL OK";