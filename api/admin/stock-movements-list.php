<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $period = $_GET['period'] ?? 'month';
    $productId = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : null;
    $type = $_GET['type'] ?? null;
    
    // Період
    $endDate = date('Y-m-d 23:59:59');
    switch ($period) {
        case 'today':    $startDate = date('Y-m-d 00:00:00'); break;
        case 'week':     $startDate = date('Y-m-d 00:00:00', strtotime('-7 days')); break;
        case 'month':    $startDate = date('Y-m-d 00:00:00', strtotime('-30 days')); break;
        case '3months':  $startDate = date('Y-m-d 00:00:00', strtotime('-90 days')); break;
        case 'year':     $startDate = date('Y-m-d 00:00:00', strtotime('-365 days')); break;
        case 'all':      $startDate = '2000-01-01 00:00:00'; break;
        default:         $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
    }
    
    $sql = "
        SELECT m.id, m.product_id, m.movement_type, m.quantity, 
               m.stock_before, m.stock_after, m.cost_per_unit, m.reason_text,
               m.booking_id, m.expense_id, m.sale_id, m.created_at,
               p.name AS product_name, p.unit AS product_unit,
               b.booking_code, b.client_name,
               e.description AS expense_description
        FROM stock_movements m
        LEFT JOIN products p ON p.id = m.product_id
        LEFT JOIN bookings b ON b.id = m.booking_id
        LEFT JOIN expenses e ON e.id = m.expense_id
        WHERE m.created_at BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    
    if ($productId) {
        $sql .= " AND m.product_id = ?";
        $params[] = $productId;
    }
    
    if ($type && in_array($type, ['purchase', 'sale', 'consumption', 'adjustment', 'return'])) {
        $sql .= " AND m.movement_type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY m.created_at DESC, m.id DESC LIMIT 500";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $movements = $stmt->fetchAll();
    
    // Підсумки по типах
    $sumSql = "
        SELECT movement_type, SUM(ABS(quantity)) AS total_qty, COUNT(*) AS cnt
        FROM stock_movements
        WHERE created_at BETWEEN ? AND ?
    ";
    $sumParams = [$startDate, $endDate];
    if ($productId) {
        $sumSql .= " AND product_id = ?";
        $sumParams[] = $productId;
    }
    $sumSql .= " GROUP BY movement_type";
    
    $sumStmt = $pdo->prepare($sumSql);
    $sumStmt->execute($sumParams);
    $summary = [];
    foreach ($sumStmt->fetchAll() as $s) {
        $summary[$s['movement_type']] = [
            'total_qty' => (float)$s['total_qty'],
            'count' => (int)$s['cnt']
        ];
    }
    
    jsonOk([
        'data' => $movements,
        'summary' => $summary,
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}