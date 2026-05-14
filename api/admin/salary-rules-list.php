<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

$masterId = (int)($_GET['master_id'] ?? 0);
if ($masterId <= 0) jsonError('Невірний master_id');

try {
    $pdo = getDb();
    
    // Правила
    $stmt = $pdo->prepare("
        SELECT 
            r.id, r.master_id, r.rule_type, r.service_id, r.option_id, r.percent, r.sort_order,
            s.name AS service_name,
            o.option_name AS option_name,
            o.group_name AS option_group,
            os.name AS option_service_name
        FROM master_salary_rules r
        LEFT JOIN services s ON s.id = r.service_id
        LEFT JOIN service_options o ON o.id = r.option_id
        LEFT JOIN services os ON os.id = o.service_id
        WHERE r.master_id = ?
        ORDER BY r.sort_order, r.id
    ");
    $stmt->execute([$masterId]);
    $rules = $stmt->fetchAll();
    
    jsonOk(['data' => $rules]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}