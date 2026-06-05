<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/telegram_notify.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    // Отправляем тестовое сообщение
    $result = telegramSendMessage("🔔 Тестове повідомлення\n\nЯкщо ви бачите це — Telegram-бот підключений і працює!\n\nЧас: " . date('H:i:s d.m.Y'));
    
    if ($result['success']) {
        jsonOk(['message' => 'Повідомлення відправлено! Перевірте Telegram.']);
    } else {
        jsonError($result['error'] ?? 'Помилка відправки');
    }
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}