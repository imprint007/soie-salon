<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    // Період
    $period = $_GET['period'] ?? 'month';
    $today = date('Y-m-d');
    
    switch ($period) {
    case 'today':
        $startDate = date('Y-m-d');
        break;
    case 'week':
        $startDate = date('Y-m-d', strtotime('-7 days'));
        break;
    case 'month':
        $startDate = date('Y-m-d', strtotime('-30 days'));
        break;
    case 'quarter':
    case '3months':
        $startDate = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'halfyear':
    case '6months':
        $startDate = date('Y-m-d', strtotime('-180 days'));
        break;
    case 'year':
        $startDate = date('Y-m-d', strtotime('-365 days'));
        break;
    case 'all':
        $startDate = '2020-01-01';
        break;
    default:
        $startDate = date('Y-m-d', strtotime('-30 days'));
}
    
    // ============================================
    // СТАТИСТИКА ЗА ПЕРІОД
    // ============================================
    
    // Загальна кількість і виручка
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_bookings,
            SUM(CASE WHEN status = 'done' OR status = 'confirmed' THEN total_price ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN deposit_paid = 1 THEN deposit_amount ELSE 0 END) AS deposits_collected
        FROM bookings
        WHERE booking_date >= ?
    ");
    $stmt->execute([$startDate]);
    $stats = $stmt->fetch();
    
    // Витрати за період
    $expensesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_expenses, COUNT(*) AS expenses_count
        FROM expenses
        WHERE expense_date >= ?
    ");
    $expensesStmt->execute([$startDate]);
    $exp = $expensesStmt->fetch();
    
    // Прибуток (виручка − витрати)
    $profit = (float)($stats['total_revenue'] ?? 0) - (float)($exp['total_expenses'] ?? 0);
    
    // ============================================
    // ТАЙМЛАЙН — динаміка по днях
    // ============================================
    $timelineStmt = $pdo->prepare("
        SELECT 
            booking_date AS date,
            COUNT(*) AS bookings,
            SUM(CASE WHEN status = 'done' OR status = 'confirmed' THEN total_price ELSE 0 END) AS revenue
        FROM bookings
        WHERE booking_date >= ?
        GROUP BY booking_date
        ORDER BY booking_date ASC
    ");
    $timelineStmt->execute([$startDate]);
    $timeline = $timelineStmt->fetchAll();
    
    // ============================================
    // ТОП МАЙСТРІВ
    // ============================================
    $topMastersStmt = $pdo->prepare("
        SELECT 
            m.id, m.name, m.role, m.photo_url,
            COUNT(b.id) AS bookings_count,
            SUM(CASE WHEN b.status = 'done' OR b.status = 'confirmed' THEN b.total_price ELSE 0 END) AS revenue
        FROM masters m
        LEFT JOIN bookings b ON b.master_id = m.id AND b.booking_date >= ?
        WHERE m.is_active = 1
        GROUP BY m.id
        ORDER BY revenue DESC, bookings_count DESC
        LIMIT 10
    ");
    $topMastersStmt->execute([$startDate]);
    $topMasters = $topMastersStmt->fetchAll();
    
    // ============================================
    // ТОП ПОСЛУГ
    // ============================================
    $topServicesStmt = $pdo->prepare("
        SELECT 
            s.id, s.name, s.image_url,
            COUNT(b.id) AS bookings_count,
            SUM(CASE WHEN b.status = 'done' OR b.status = 'confirmed' THEN b.total_price ELSE 0 END) AS revenue
        FROM services s
        LEFT JOIN bookings b ON b.service_id = s.id AND b.booking_date >= ?
        WHERE s.is_active = 1
        GROUP BY s.id
        ORDER BY bookings_count DESC, revenue DESC
        LIMIT 10
    ");
    $topServicesStmt->execute([$startDate]);
    $topServices = $topServicesStmt->fetchAll();
    
    // ============================================
    // ОСТАННІ ЗАЯВКИ
    // ============================================
    $latestStmt = $pdo->prepare("
        SELECT 
            b.id, b.booking_code, b.client_name, b.booking_date, b.booking_time, 
            b.total_price, b.status,
            s.name AS service_name,
            m.name AS master_name
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN masters m ON m.id = b.master_id
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $latestStmt->execute();
    $latest = $latestStmt->fetchAll();
    
    // ============================================
    // ВІДПОВІДЬ
    // ============================================
    jsonOk([
        'period' => $period,
        'start_date' => $startDate,
        'end_date' => $today,
        'stats' => [
            'total_bookings' => (int)$stats['total_bookings'],
            'total_revenue' => (float)$stats['total_revenue'],
            'pending_count' => (int)$stats['pending_count'],
            'confirmed_count' => (int)$stats['confirmed_count'],
            'done_count' => (int)$stats['done_count'],
            'cancelled_count' => (int)$stats['cancelled_count'],
            'deposits_collected' => (float)$stats['deposits_collected'],
            'total_expenses' => (float)$exp['total_expenses'],
            'expenses_count' => (int)$exp['expenses_count'],
            'profit' => $profit,
        ],
        'timeline' => $timeline,
        'top_masters' => $topMasters,
        'top_services' => $topServices,
        'latest_bookings' => $latest,
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}