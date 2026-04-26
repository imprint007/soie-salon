<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $period = $_GET['period'] ?? 'month';
    
    // Период
    $intervals = [
        'today'   => '0 DAY',
        'week'    => '7 DAY',
        'month'   => '30 DAY',
        '3months' => '90 DAY',
        '6months' => '180 DAY',
        'year'    => '365 DAY',
    ];
    $interval = $intervals[$period] ?? '30 DAY';
    $where = $period === 'today' 
        ? "DATE(b.booking_date) = CURDATE()"
        : "b.booking_date >= DATE_SUB(CURDATE(), INTERVAL $interval)";
    
    // ================= ОБЩАЯ СТАТИСТИКА =================
    $stats = $pdo->query("
        SELECT 
            COUNT(*) AS total_bookings,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN status = 'done' THEN total_price ELSE 0 END) AS revenue,
            AVG(CASE WHEN status = 'done' THEN total_price ELSE NULL END) AS avg_check
        FROM bookings b
        WHERE $where
    ")->fetch();
    
    // Расходы за тот же период
    $expWhere = $period === 'today' 
        ? "DATE(expense_date) = CURDATE()"
        : "expense_date >= DATE_SUB(CURDATE(), INTERVAL $interval)";
    $expensesTotal = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $expWhere")->fetchColumn();
    
    $revenue = (int)($stats['revenue'] ?? 0);
    $profit = $revenue - $expensesTotal;
    $margin = $revenue > 0 ? round($profit / $revenue * 100, 1) : 0;
    $cancellationRate = $stats['total_bookings'] > 0 
        ? round($stats['cancelled_count'] / $stats['total_bookings'] * 100, 1) 
        : 0;
    
    // ================= ДИНАМИКА ПО ДНЯМ/МЕСЯЦАМ =================
    if ($period === 'today' || $period === 'week') {
        $groupBy = "DATE(booking_date)";
        $dateFormat = "%Y-%m-%d";
    } elseif ($period === 'month') {
        $groupBy = "DATE(booking_date)";
        $dateFormat = "%Y-%m-%d";
    } else {
        $groupBy = "DATE_FORMAT(booking_date, '%Y-%m')";
        $dateFormat = "%Y-%m";
    }
    
    $timeline = $pdo->query("
        SELECT $groupBy AS period, 
               COUNT(*) AS bookings_count,
               SUM(CASE WHEN status = 'done' THEN total_price ELSE 0 END) AS revenue
        FROM bookings b
        WHERE $where
        GROUP BY period
        ORDER BY period ASC
    ")->fetchAll();
    
    // ================= ТОП УСЛУГ =================
    $topServices = $pdo->query("
        SELECT s.id, s.name, s.image_url,
               COUNT(b.id) AS bookings_count,
               SUM(CASE WHEN b.status = 'done' THEN b.total_price ELSE 0 END) AS revenue
        FROM bookings b
        LEFT JOIN services s ON b.service_id = s.id
        WHERE $where AND s.id IS NOT NULL
        GROUP BY s.id, s.name, s.image_url
        ORDER BY bookings_count DESC
        LIMIT 10
    ")->fetchAll();
    
    // ================= ТОП МАСТЕРОВ — главное по запросу =================
    $topMasters = $pdo->query("
        SELECT m.id, m.name, m.role, m.photo_url,
               COUNT(b.id) AS bookings_count,
               SUM(CASE WHEN b.status = 'done' THEN 1 ELSE 0 END) AS done_count,
               SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
               SUM(CASE WHEN b.status = 'done' THEN b.total_price ELSE 0 END) AS revenue,
               AVG(CASE WHEN b.status = 'done' THEN b.total_price ELSE NULL END) AS avg_check
        FROM bookings b
        LEFT JOIN masters m ON b.master_id = m.id
        WHERE $where AND m.id IS NOT NULL
        GROUP BY m.id, m.name, m.role, m.photo_url
        ORDER BY revenue DESC
    ")->fetchAll();
    
    // ================= УСЛУГИ ПО МАСТЕРАМ — детально =================
    $masterServices = $pdo->query("
        SELECT m.id AS master_id, m.name AS master_name,
               s.id AS service_id, s.name AS service_name,
               COUNT(b.id) AS times_done,
               SUM(CASE WHEN b.status = 'done' THEN b.total_price ELSE 0 END) AS revenue
        FROM bookings b
        LEFT JOIN masters m ON b.master_id = m.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE $where AND m.id IS NOT NULL AND s.id IS NOT NULL
        GROUP BY m.id, m.name, s.id, s.name
        ORDER BY m.id, times_done DESC
    ")->fetchAll();
    
    // Группируем услуги по мастерам
    $masterServicesGrouped = [];
    foreach ($masterServices as $row) {
        $mid = $row['master_id'];
        if (!isset($masterServicesGrouped[$mid])) {
            $masterServicesGrouped[$mid] = ['master_name' => $row['master_name'], 'services' => []];
        }
        $masterServicesGrouped[$mid]['services'][] = [
            'service_id' => $row['service_id'],
            'service_name' => $row['service_name'],
            'times_done' => (int)$row['times_done'],
            'revenue' => (int)$row['revenue']
        ];
    }
    $masterServicesGrouped = array_values($masterServicesGrouped);
    
    // ================= ПРИЧИНЫ ОТМЕН (если будут добавлены позже) =================
    $cancellations = $pdo->query("
        SELECT DATE_FORMAT(booking_date, '%Y-%m') AS month, COUNT(*) AS cnt
        FROM bookings
        WHERE status = 'cancelled' AND $where
        GROUP BY month
        ORDER BY month DESC
    ")->fetchAll();
    
    jsonOk([
        'period' => $period,
        'summary' => [
            'total_bookings' => (int)$stats['total_bookings'],
            'done_count' => (int)$stats['done_count'],
            'confirmed_count' => (int)$stats['confirmed_count'],
            'pending_count' => (int)$stats['pending_count'],
            'cancelled_count' => (int)$stats['cancelled_count'],
            'cancellation_rate' => $cancellationRate,
            'revenue' => $revenue,
            'avg_check' => (int)round($stats['avg_check'] ?? 0),
            'expenses' => $expensesTotal,
            'profit' => $profit,
            'margin' => $margin,
        ],
        'timeline' => $timeline,
        'top_services' => $topServices,
        'top_masters' => $topMasters,
        'master_services' => $masterServicesGrouped,
        'cancellations' => $cancellations,
    ]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}