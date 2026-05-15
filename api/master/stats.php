<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $period = $_GET['period'] ?? 'today';
    $endDate = date('Y-m-d');
    
    switch ($period) {
        case 'today':    $startDate = $endDate; break;
        case 'week':     $startDate = date('Y-m-d', strtotime('-7 days')); break;
        case 'month':    $startDate = date('Y-m-d', strtotime('-30 days')); break;
        case '3months':  $startDate = date('Y-m-d', strtotime('-90 days')); break;
        case '6months':  $startDate = date('Y-m-d', strtotime('-180 days')); break;
        case 'year':     $startDate = date('Y-m-d', strtotime('-365 days')); break;
        default:         $startDate = $endDate;
    }
    
    // Бронювання майстра за період
    $stmt = $pdo->prepare("
        SELECT 
            b.id, b.booking_code, b.booking_date, b.booking_time, b.status,
            b.client_name, b.client_phone, b.client_email,
            b.service_id, b.total_price, b.deposit_amount, b.deposit_paid,
            b.selected_options, b.duration_min,
            s.name AS service_name, s.base_price AS service_base_price
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        WHERE b.master_id = ?
          AND b.booking_date BETWEEN ? AND ?
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
    $stmt->execute([$masterId, $startDate, $endDate]);
    $bookings = $stmt->fetchAll();
    
    // Завантажуємо правила зарплат майстра
    $rulesStmt = $pdo->prepare("
        SELECT rule_type, service_id, option_id, percent 
        FROM master_salary_rules 
        WHERE master_id = ?
    ");
    $rulesStmt->execute([$masterId]);
    $allRules = $rulesStmt->fetchAll();
    
    $rules = ['all' => null, 'services' => [], 'options' => []];
    foreach ($allRules as $r) {
        if ($r['rule_type'] === 'all') {
            $rules['all'] = (float)$r['percent'];
        } elseif ($r['rule_type'] === 'service' && $r['service_id']) {
            $rules['services'][$r['service_id']] = (float)$r['percent'];
        } elseif ($r['rule_type'] === 'option' && $r['option_id']) {
            $rules['options'][$r['option_id']] = (float)$r['percent'];
        }
    }
    
    // Рахуємо статистику
    $totalRevenue = 0;
    $totalSalary = 0;
    $totalDone = 0;
    $totalCancelled = 0;
    $totalPending = 0;
    
    foreach ($bookings as &$b) {
        $price = (float)$b['total_price'];
        
        if ($b['status'] === 'cancelled') {
            $totalCancelled++;
            $b['my_salary'] = 0;
            continue;
        }
        
        if (in_array($b['status'], ['done', 'confirmed'])) {
            $totalDone++;
            $totalRevenue += $price;
            
            // Рахуємо зарплату
            $basePrice = (float)$b['service_base_price'];
            $servicePercent = $rules['services'][$b['service_id']] ?? $rules['all'] ?? 0;
            
            $optionsTotal = 0;
            $tmpOpts = !empty($b['selected_options']) ? json_decode($b['selected_options'], true) : [];
            if (is_array($tmpOpts)) {
                foreach ($tmpOpts as $tmpOpt) {
                    $optionsTotal += (float)($tmpOpt['price'] ?? 0);
                }
            }
            $servicePart = max(0, $price - $optionsTotal);
            $salaryFromService = $servicePart * $servicePercent / 100;
            
            $salaryFromOptions = 0;
            if (is_array($tmpOpts)) {
                foreach ($tmpOpts as $opt) {
                    $optId = (int)($opt['id'] ?? 0);
                    $optPrice = (float)($opt['price'] ?? 0);
                    if ($optPrice <= 0) continue;
                    $optPercent = $rules['options'][$optId] ?? $rules['services'][$b['service_id']] ?? $rules['all'] ?? 0;
                    $salaryFromOptions += $optPrice * $optPercent / 100;
                }
            }
            
            $b['my_salary'] = round($salaryFromService + $salaryFromOptions, 2);
            $totalSalary += $b['my_salary'];
        } else {
            $totalPending++;
            $b['my_salary'] = 0;
        }
    }
    unset($b);
    
    // Найближчі бронювання (майбутні)
    $upcomingStmt = $pdo->prepare("
        SELECT 
            b.id, b.booking_code, b.booking_date, b.booking_time, b.status,
            b.client_name, b.client_phone,
            b.total_price, b.duration_min,
            s.name AS service_name
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        WHERE b.master_id = ?
          AND b.status NOT IN ('cancelled', 'done')
          AND (b.booking_date > ? OR (b.booking_date = ? AND b.booking_time >= ?))
        ORDER BY b.booking_date ASC, b.booking_time ASC
        LIMIT 5
    ");
    $upcomingStmt->execute([$masterId, $endDate, $endDate, date('H:i:s')]);
    $upcoming = $upcomingStmt->fetchAll();
    
    jsonOk([
        'period' => $period,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'stats' => [
            'total_revenue' => round($totalRevenue, 2),
            'total_salary' => round($totalSalary, 2),
            'total_done' => $totalDone,
            'total_cancelled' => $totalCancelled,
            'total_pending' => $totalPending,
            'bookings_total' => count($bookings),
        ],
        'bookings' => $bookings,
        'upcoming' => $upcoming,
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}