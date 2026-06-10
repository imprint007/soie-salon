<?php
require_once __DIR__ . '/bot-helper.php';

header('Content-Type: application/json; charset=utf-8');

$phone = trim($_GET['phone'] ?? '');
if (empty($phone)) {
    echo json_encode(['success' => false, 'error' => 'Вкажіть телефон']);
    exit;
}

try {
    $pdo = botGetDb();
    
    // Шукаємо по телефону (з нормалізацією)
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
    
    $stmt = $pdo->prepare("
        SELECT b.id, b.booking_code, b.booking_date, b.booking_time, b.duration_min,
               b.total_price, b.status, b.client_name,
               s.name AS service_name,
               m.name AS master_name
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN masters m ON m.id = b.master_id
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(b.client_phone, ' ', ''), '(', ''), ')', ''), '-', '') LIKE ?
        AND b.status IN ('pending', 'confirmed')
        ORDER BY b.booking_date ASC, b.booking_time ASC
        LIMIT 10
    ");
    $stmt->execute(['%' . substr($cleanPhone, -10) . '%']);
    $bookings = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $bookings], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}