<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$masterId = (int)($input['master_id'] ?? 0);
$rules = $input['rules'] ?? [];

if ($masterId <= 0) jsonError('Невірний master_id');
if (!is_array($rules)) jsonError('Невірний формат правил');

$pdo = getDb();

try {
    $pdo->beginTransaction();
    
    // Видаляємо старі
    $pdo->prepare("DELETE FROM master_salary_rules WHERE master_id = ?")->execute([$masterId]);
    
    // Додаємо нові
    $stmt = $pdo->prepare("
        INSERT INTO master_salary_rules 
        (master_id, rule_type, service_id, option_id, percent, sort_order) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($rules as $i => $r) {
        $type = $r['rule_type'] ?? 'all';
        if (!in_array($type, ['service', 'option', 'all'])) continue;
        
        $sid = !empty($r['service_id']) ? (int)$r['service_id'] : null;
        $oid = !empty($r['option_id']) ? (int)$r['option_id'] : null;
        $percent = (float)($r['percent'] ?? 0);
        
        if ($percent < 0 || $percent > 100) continue;
        if ($type === 'service' && !$sid) continue;
        if ($type === 'option' && !$oid) continue;
        
        $stmt->execute([$masterId, $type, $sid, $oid, $percent, $i]);
    }
    
    $pdo->commit();
    jsonOk(['message' => 'Збережено']);
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}