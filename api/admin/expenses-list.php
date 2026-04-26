<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $period = $_GET['period'] ?? 'month';
    
    $sql = "SELECT * FROM expenses WHERE 1=1";
    if ($period === 'month') {
        $sql .= " AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($period === '3months') {
        $sql .= " AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
    } elseif ($period === 'year') {
        $sql .= " AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
    }
    $sql .= " ORDER BY expense_date DESC LIMIT 500";
    
    $expenses = $pdo->query($sql)->fetchAll();
    
    // Сумма по категориям
    $byCategory = $pdo->query("
        SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
        FROM expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY category
        ORDER BY total DESC
    ")->fetchAll();
    
    $totalAll = array_sum(array_map(fn($e) => (int)$e['amount'], $expenses));
    
    jsonOk([
        'data' => $expenses,
        'total' => $totalAll,
        'by_category' => $byCategory
    ]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}