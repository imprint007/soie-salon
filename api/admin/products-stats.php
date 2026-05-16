<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $period = $_GET['period'] ?? 'month';
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
    
    // 1. Загальні підсумки
    $totalsStmt = $pdo->query("
        SELECT 
            COUNT(*) AS total_products,
            COUNT(CASE WHEN current_stock <= 0 THEN 1 END) AS out_of_stock,
            COUNT(CASE WHEN current_stock > 0 AND current_stock <= min_stock AND min_stock > 0 THEN 1 END) AS low_stock,
            SUM(current_stock * cost_price) AS total_inventory_value
        FROM products WHERE is_active = 1
    ");
    $totals = $totalsStmt->fetch();
    
    // 2. Закупки за період (сума витрат на закупки)
    $purchStmt = $pdo->prepare("
        SELECT SUM(amount) AS total, COUNT(*) AS cnt
        FROM expenses
        WHERE product_id IS NOT NULL AND expense_date BETWEEN ? AND ?
    ");
    $purchStmt->execute([substr($startDate, 0, 10), substr($endDate, 0, 10)]);
    $purchases = $purchStmt->fetch();
    
    // 3. Розхід за період (вартість списаного по cost_price)
    $consStmt = $pdo->prepare("
        SELECT SUM(ABS(m.quantity) * p.cost_price) AS total_cost, COUNT(*) AS cnt
        FROM stock_movements m
        JOIN products p ON p.id = m.product_id
        WHERE m.movement_type = 'consumption'
          AND m.created_at BETWEEN ? AND ?
    ");
    $consStmt->execute([$startDate, $endDate]);
    $consumption = $consStmt->fetch();
    
    // 4. ТОП-10 товарів за витратами на послуги
    $topConsStmt = $pdo->prepare("
        SELECT 
            m.product_id, p.name, p.unit,
            SUM(ABS(m.quantity)) AS total_qty,
            SUM(ABS(m.quantity) * p.cost_price) AS total_cost,
            COUNT(*) AS times_used
        FROM stock_movements m
        JOIN products p ON p.id = m.product_id
        WHERE m.movement_type = 'consumption'
          AND m.created_at BETWEEN ? AND ?
        GROUP BY m.product_id
        ORDER BY total_qty DESC
        LIMIT 10
    ");
    $topConsStmt->execute([$startDate, $endDate]);
    $topConsumed = $topConsStmt->fetchAll();
    
    // 5. Низкий остаток
    $lowStockStmt = $pdo->query("
        SELECT id, name, unit, current_stock, min_stock, sell_price
        FROM products
        WHERE is_active = 1 AND min_stock > 0 AND current_stock <= min_stock
        ORDER BY (current_stock - min_stock) ASC
        LIMIT 20
    ");
    $lowStock = $lowStockStmt->fetchAll();
    
    // 6. Динаміка закупок і розходу по днях
    $daysStmt = $pdo->prepare("
        SELECT DATE(created_at) AS day,
               SUM(CASE WHEN movement_type = 'purchase' THEN quantity ELSE 0 END) AS purchases,
               SUM(CASE WHEN movement_type = 'consumption' THEN ABS(quantity) ELSE 0 END) AS consumption
        FROM stock_movements
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $daysStmt->execute([$startDate, $endDate]);
    $timeline = $daysStmt->fetchAll();
    
    jsonOk([
        'totals' => [
            'total_products' => (int)$totals['total_products'],
            'out_of_stock' => (int)$totals['out_of_stock'],
            'low_stock' => (int)$totals['low_stock'],
            'inventory_value' => round((float)$totals['total_inventory_value'], 2),
        ],
        'period_stats' => [
            'purchases_total' => round((float)($purchases['total'] ?? 0), 2),
            'purchases_count' => (int)($purchases['cnt'] ?? 0),
            'consumption_cost' => round((float)($consumption['total_cost'] ?? 0), 2),
            'consumption_count' => (int)($consumption['cnt'] ?? 0),
        ],
        'top_consumed' => $topConsumed,
        'low_stock' => $lowStock,
        'timeline' => $timeline,
        'period' => $period,
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}