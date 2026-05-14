<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $masterId = (int)($_GET['master_id'] ?? 0);
    $period = $_GET['period'] ?? 'month';
    $startDateParam = $_GET['start_date'] ?? null;
    $endDateParam = $_GET['end_date'] ?? null;
    $statusFilter = $_GET['status'] ?? 'paid'; // paid | all
    // paid = тільки done + confirmed
    // all  = усі крім cancelled
    
    // Розраховуємо період
    if ($startDateParam && $endDateParam) {
        $startDate = $startDateParam;
        $endDate = $endDateParam;
    } else {
        $endDate = date('Y-m-d');
        switch ($period) {
            case 'today':    $startDate = $endDate; break;
            case 'week':     $startDate = date('Y-m-d', strtotime('-7 days')); break;
            case 'month':    $startDate = date('Y-m-d', strtotime('-30 days')); break;
            case '3months':
            case 'quarter':  $startDate = date('Y-m-d', strtotime('-90 days')); break;
            case '6months':
            case 'halfyear': $startDate = date('Y-m-d', strtotime('-180 days')); break;
            case 'year':     $startDate = date('Y-m-d', strtotime('-365 days')); break;
            default:         $startDate = date('Y-m-d', strtotime('-30 days'));
        }
    }
    
    // Завантажуємо майстрів
    if ($masterId > 0) {
        $stmt = $pdo->prepare("SELECT id, name, role, photo_url FROM masters WHERE id = ?");
        $stmt->execute([$masterId]);
        $masters = $stmt->fetchAll();
    } else {
        $masters = $pdo->query("SELECT id, name, role, photo_url FROM masters WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
    }
    
    if (empty($masters)) {
        jsonOk(['masters' => [], 'start_date' => $startDate, 'end_date' => $endDate, 'total' => 0]);
    }
    
    // Завантажуємо правила зарплат для всіх потрібних майстрів
    $masterIds = array_column($masters, 'id');
    $placeholders = implode(',', array_fill(0, count($masterIds), '?'));
    $rulesStmt = $pdo->prepare("
        SELECT master_id, rule_type, service_id, option_id, percent 
        FROM master_salary_rules 
        WHERE master_id IN ($placeholders)
    ");
    $rulesStmt->execute($masterIds);
    $allRules = $rulesStmt->fetchAll();
    
    // Групуємо правила по майстру
    $rulesByMaster = [];
    foreach ($allRules as $r) {
        $mid = $r['master_id'];
        if (!isset($rulesByMaster[$mid])) {
            $rulesByMaster[$mid] = ['all' => null, 'services' => [], 'options' => []];
        }
        if ($r['rule_type'] === 'all') {
            $rulesByMaster[$mid]['all'] = (float)$r['percent'];
        } elseif ($r['rule_type'] === 'service' && $r['service_id']) {
            $rulesByMaster[$mid]['services'][$r['service_id']] = (float)$r['percent'];
        } elseif ($r['rule_type'] === 'option' && $r['option_id']) {
            $rulesByMaster[$mid]['options'][$r['option_id']] = (float)$r['percent'];
        }
    }
    
    // Завантажуємо брони
    $statusCondition = $statusFilter === 'all' 
        ? "status != 'cancelled'" 
        : "status IN ('done', 'confirmed')";
    
    $bookingsStmt = $pdo->prepare("
        SELECT 
            b.id, b.booking_code, b.booking_date, b.booking_time, b.master_id,
            b.client_name, b.service_id, b.total_price, b.deposit_amount, b.status,
            b.selected_options,
            s.name AS service_name, s.base_price AS service_base_price
        FROM bookings b
        LEFT JOIN services s ON s.id = b.service_id
        WHERE b.master_id IN ($placeholders)
          AND b.booking_date BETWEEN ? AND ?
          AND $statusCondition
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
    $params = array_merge($masterIds, [$startDate, $endDate]);
    $bookingsStmt->execute($params);
    $bookings = $bookingsStmt->fetchAll();
    
    // Розраховуємо зарплату для кожного майстра
    $result = [];
    $grandTotal = 0;
    
    foreach ($masters as $m) {
        $mid = (int)$m['id'];
        $rules = $rulesByMaster[$mid] ?? ['all' => null, 'services' => [], 'options' => []];
        
        $masterBookings = array_filter($bookings, fn($b) => (int)$b['master_id'] === $mid);
        
        $totalRevenue = 0;
        $totalSalary = 0;
        $bookingsDetails = [];
        
        foreach ($masterBookings as $b) {
            $bookingPrice = (float)$b['total_price'];
            $basePrice = (float)$b['service_base_price'];
            $totalRevenue += $bookingPrice;
            
            // Розраховуємо salary для цієї брони
            $salaryFromService = 0;
            $salaryFromOptions = 0;
            
            // % за послугу
            $servicePercent = $rules['services'][$b['service_id']] ?? $rules['all'] ?? 0;
            $salaryFromService = $basePrice * $servicePercent / 100;
            
            // % за опції
            $options = !empty($b['selected_options']) ? json_decode($b['selected_options'], true) : [];
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $optId = (int)($opt['id'] ?? 0);
                    $optPrice = (float)($opt['price'] ?? 0);
                    if ($optPrice <= 0) continue;
                    
                    // Шукаємо % для опції: спочатку option-rule, потім service-rule, потім all
                    $optPercent = $rules['options'][$optId] 
                               ?? $rules['services'][$b['service_id']] 
                               ?? $rules['all'] 
                               ?? 0;
                    $salaryFromOptions += $optPrice * $optPercent / 100;
                }
            }
            
            $bookingSalary = $salaryFromService + $salaryFromOptions;
            $totalSalary += $bookingSalary;
            
            $bookingsDetails[] = [
                'id' => (int)$b['id'],
                'booking_code' => $b['booking_code'],
                'date' => $b['booking_date'],
                'time' => substr($b['booking_time'], 0, 5),
                'client_name' => $b['client_name'],
                'service_name' => $b['service_name'],
                'status' => $b['status'],
                'total_price' => $bookingPrice,
                'base_price' => $basePrice,
                'service_percent' => round($servicePercent, 1),
                'salary_from_service' => round($salaryFromService, 2),
                'salary_from_options' => round($salaryFromOptions, 2),
                'salary_total' => round($bookingSalary, 2),
            ];
        }
        
        $totalSalary = round($totalSalary, 2);
        $grandTotal += $totalSalary;
        
        $result[] = [
            'master_id' => $mid,
            'master_name' => $m['name'],
            'master_role' => $m['role'],
            'master_photo' => $m['photo_url'],
            'bookings_count' => count($masterBookings),
            'total_revenue' => round($totalRevenue, 2),
            'total_salary' => $totalSalary,
            'avg_salary_per_booking' => count($masterBookings) > 0 
                ? round($totalSalary / count($masterBookings), 2) 
                : 0,
            'has_rules' => isset($rulesByMaster[$mid]),
            'bookings' => $bookingsDetails,
        ];
    }
    
    jsonOk([
        'masters' => $result,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'period' => $period,
        'status_filter' => $statusFilter,
        'total' => round($grandTotal, 2),
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}