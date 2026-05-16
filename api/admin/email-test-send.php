<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/mailer.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $input = jsonInput();
    $to = trim($input['to'] ?? '');
    
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        jsonError('Невірний email');
    }
    
    // Тестовий лист
    $subject = 'Тестовий лист — Unique Curls';
    $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:20px;">';
    $html .= '<h2 style="color:#e0c9a8;">Тестовий лист</h2>';
    $html .= '<p>Цей лист надіслано з вашої системи бронювання.</p>';
    $html .= '<p>Якщо ви бачите цей лист — інтеграція з email-провайдером працює.</p>';
    $html .= '<p style="color:#666;font-size:12px;margin-top:30px;">Час відправлення: ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '</body></html>';
    
    $result = sendEmail($to, 'Тест', $subject, $html);
    
    if ($result['success']) {
        jsonOk(['message' => 'Лист відправлено через ' . ($result['provider'] ?? 'провайдер')]);
    } else {
        jsonError($result['error'] ?? 'Невідома помилка');
    }
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}