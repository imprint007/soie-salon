<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    if ($bookingId <= 0) jsonError('Невірний booking_id');
    
    // Бронювання з деталями
    $stmt = $pdo->prepare("
        SELECT b.*, 
               s.name AS service_name, s.image_url AS service_image,
               m.name AS master_name, m.photo_url AS master_photo,
               c.id AS cli_id, c.name AS cli_name, c.phone AS cli_phone
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN masters m ON m.id = b.master_id
        LEFT JOIN clients c ON c.id = b.client_id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) jsonError('Бронь не знайдена');
    
    $booking['selected_options'] = !empty($booking['selected_options']) 
        ? json_decode($booking['selected_options'], true) : [];
    
    // Списані товари
    $smStmt = $pdo->prepare("
        SELECT sm.quantity, p.name AS product_name, p.unit
        FROM stock_movements sm
        JOIN products p ON p.id = sm.product_id
        WHERE sm.booking_id = ? AND sm.movement_type = 'consumption'
    ");
    $smStmt->execute([$bookingId]);
    $consumables = $smStmt->fetchAll();
    
    // Поля анкети
    $fields = $pdo->query("SELECT * FROM client_custom_fields WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
    
    // Значення для цього візиту
    $vStmt = $pdo->prepare("SELECT field_id, field_value FROM visit_field_values WHERE booking_id = ?");
    $vStmt->execute([$bookingId]);
    $values = [];
    foreach ($vStmt->fetchAll() as $v) {
        $values[$v['field_id']] = $v['field_value'];
    }
    
    // Фото цього візиту
    $pStmt = $pdo->prepare("SELECT * FROM client_photos WHERE booking_id = ? ORDER BY photo_type, created_at");
    $pStmt->execute([$bookingId]);
    $photos = $pStmt->fetchAll();
    
    jsonOk([
        'booking' => $booking,
        'consumables' => $consumables,
        'custom_fields' => $fields,
        'field_values' => $values,
        'photos' => $photos,
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}