<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Тільки активні послуги
    $services = $pdo->query("SELECT id, name, description, base_price, duration_min, image_url FROM services WHERE is_active = 1 ORDER BY id")->fetchAll();
    
    // Опції для активних послуг
    $options = $pdo->query("SELECT * FROM service_options ORDER BY service_id, group_name, sort_order, id")->fetchAll();
    
    // Групуємо опції по послугах і групах
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
                'id' => (int)$opt['id'],
                'name' => $opt['option_name'],
                'description' => $opt['description'] ?? '',
                'icon_url' => $opt['icon_url'] ?? '',
                'price_modifier' => (int)$opt['price_modifier'],
                'duration_modifier' => (int)$opt['duration_modifier']
            ];
        }
        
        $s['option_groups'] = array_values($groupsMap);
    }
    
    echo json_encode(['success' => true, 'data' => $services], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка']);
}