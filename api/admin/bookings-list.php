<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $status = $_GET['status'] ?? 'all';
    $period = $_GET['period'] ?? 'all'; // today, week, month, all
    
    $sql = "SELECT b.*, 
                   s.name AS service_name, s.image_url AS service_image,
                   m.name AS master_name, m.photo_url AS master_photo
            FROM bookings b
            LEFT JOIN services s ON b.service_id = s.id
            LEFT JOIN masters m ON b.master_id = m.id
            WHERE 1=1";
    $params = [];
    
    if ($status !== 'all') {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    
    if ($period === 'today') {
        $sql .= " AND DATE(b.booking_date) = CURDATE()";
    } elseif ($period === 'week') {
        $sql .= " AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $sql .= " AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    }
    
    $sql .= " ORDER BY b.booking_date DESC, b.booking_time DESC LIMIT 500";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
    
    // Подсчёт по статусам для фильтров
    $counts = $pdo->query("
        SELECT status, COUNT(*) AS cnt FROM bookings GROUP BY status
    ")->fetchAll();
    $countsMap = ['all' => 0, 'pending' => 0, 'confirmed' => 0, 'done' => 0, 'cancelled' => 0];
    foreach ($counts as $c) {
        $countsMap[$c['status']] = (int)$c['cnt'];
        $countsMap['all'] += (int)$c['cnt'];
    }
    
    jsonOk(['data' => $bookings, 'counts' => $countsMap]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}