<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $period = $_GET['period'] ?? 'month';
    $endDate = date('Y-m-d');
    switch ($period) {
        case 'today':    $startDate = $endDate; break;
        case 'week':     $startDate = date('Y-m-d', strtotime('-7 days')); break;
        case 'month':    $startDate = date('Y-m-d', strtotime('-30 days')); break;
        case '3months':  $startDate = date('Y-m-d', strtotime('-90 days')); break;
        case '6months':  $startDate = date('Y-m-d', strtotime('-180 days')); break;
        case 'year':     $startDate = date('Y-m-d', strtotime('-365 days')); break;
        case 'all':      $startDate = '2000-01-01'; break;
        default:         $startDate = date('Y-m-d', strtotime('-30 days'));
    }
    
    $stmt = $pdo->prepare("
        SELECT e.id, e.category_id, e.category_name, e.expense_date, e.description, e.amount,
               c.name AS current_category_name, c.icon AS category_icon
        FROM master_expenses e
        LEFT JOIN master_expense_categories c ON c.id = e.category_id
        WHERE e.master_id = ?
          AND e.expense_date BETWEEN ? AND ?
        ORDER BY e.expense_date DESC, e.id DESC
    ");
    $stmt->execute([$masterId, $startDate, $endDate]);
    $expenses = $stmt->fetchAll();
    
    $total = 0;
    $byCategory = [];
    foreach ($expenses as &$e) {
        $amount = (float)$e['amount'];
        $total += $amount;
        $catName = $e['current_category_name'] ?: $e['category_name'] ?: 'Без категорії';
        $byCategory[$catName] = ($byCategory[$catName] ?? 0) + $amount;
        $e['display_category'] = $catName;
    }
    unset($e);
    
    arsort($byCategory);
    
    jsonOk([
        'expenses' => $expenses,
        'total' => round($total, 2),
        'by_category' => $byCategory,
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}