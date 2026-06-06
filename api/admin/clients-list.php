<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;
    
    $where = "1=1";
    $params = [];
    
    if (!empty($search)) {
        $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $s = "%$search%";
        $params = [$s, $s, $s];
    }
    
    // Загальна кількість
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Список з останньою бронею
    $sql = "
        SELECT c.id, c.name, c.phone, c.email, c.photo_url, c.notes, c.created_at,
               (SELECT COUNT(*) FROM bookings b WHERE b.client_id = c.id AND b.status != 'cancelled') AS bookings_count,
               (SELECT COALESCE(SUM(b4.total_price), 0) FROM bookings b4 WHERE b4.client_id = c.id AND b4.status IN ('done', 'confirmed')) AS total_spent,
               (SELECT b2.booking_date FROM bookings b2 WHERE b2.client_id = c.id ORDER BY b2.booking_date DESC LIMIT 1) AS last_visit,
               (SELECT s.name FROM bookings b3 LEFT JOIN services s ON s.id = b3.service_id WHERE b3.client_id = c.id ORDER BY b3.booking_date DESC LIMIT 1) AS last_service,
               (SELECT cp.photo_url FROM client_photos cp WHERE cp.client_id = c.id ORDER BY cp.created_at DESC LIMIT 1) AS last_photo
        FROM clients c
        WHERE $where
        ORDER BY c.name ASC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
    
    jsonOk([
        'data' => $clients,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}