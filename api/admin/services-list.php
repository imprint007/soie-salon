<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $services = $pdo->query("SELECT * FROM services ORDER BY id DESC")->fetchAll();
    $options = $pdo->query("SELECT * FROM service_options ORDER BY service_id, group_name, sort_order, id")->fetchAll();
    
    // Группируем опции по услугам и группам
    foreach ($services as &$s) {
        $s['option_groups'] = [];
        $groupsMap = [];
        
        foreach ($options as $opt) {
            if ($opt['service_id'] != $s['id']) continue;
            
            $gName = $opt['group_name'];
            if (!isset($groupsMap[$gName])) {
                $groupsMap[$gName] = [
                    'name' => $gName,
                    'is_required' => (int)$opt['is_required'],
                    'is_multiple' => (int)$opt['is_multiple'],
                    'options' => []
                ];
            }
            $groupsMap[$gName]['options'][] = [
                'id' => $opt['id'],
                'option_name' => $opt['option_name'],
                'description' => $opt['description'] ?? '',
                'icon_url' => $opt['icon_url'] ?? '',
                'price_modifier' => (int)$opt['price_modifier'],
                'duration_modifier' => (int)$opt['duration_modifier']
            ];
        }
        
        $s['option_groups'] = array_values($groupsMap);
    }
    
    jsonOk(['data' => $services]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}