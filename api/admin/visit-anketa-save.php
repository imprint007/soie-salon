<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $bookingId = (int)($input['booking_id'] ?? 0);
    $fieldValues = $input['field_values'] ?? [];
    
    if ($bookingId <= 0) jsonError('Невірний booking_id');
    
    // Беремо client_id з бронювання
    $bStmt = $pdo->prepare("SELECT client_id FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch();
    if (!$booking) jsonError('Бронь не знайдена');
    
    $clientId = (int)($booking['client_id'] ?? 0);
    
    // Зберігаємо значення полів
    if (is_array($fieldValues) && $clientId > 0) {
        $stmt = $pdo->prepare("INSERT INTO visit_field_values (booking_id, client_id, field_id, field_value) 
                               VALUES (?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)");
        foreach ($fieldValues as $fieldId => $value) {
            $stmt->execute([$bookingId, $clientId, (int)$fieldId, $value]);
        }
    }
    
    // Позначаємо що анкета заповнена
    $pdo->prepare("UPDATE bookings SET anketa_filled = 1 WHERE id = ?")->execute([$bookingId]);
    
    jsonOk(['message' => 'Анкету збережено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}