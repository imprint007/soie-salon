<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
$name = trim($input['name'] ?? '');
$description = trim($input['description'] ?? '');
$base_price = (int)($input['base_price'] ?? 0);
$duration_min = (int)($input['duration_min'] ?? 0);
$image_url = trim($input['image_url'] ?? '');
$is_active = !empty($input['is_active']) ? 1 : 0;
$option_groups = $input['option_groups'] ?? [];

if (empty($name)) jsonError('Назва обовʼязкова');
if ($base_price <= 0) jsonError('Невірна ціна');
if ($duration_min <= 0) jsonError('Невірна тривалість');

$pdo = getDb();

try {
    $pdo->beginTransaction();
    
    if ($id > 0) {
        $pdo->prepare("UPDATE services SET name=?, description=?, base_price=?, duration_min=?, image_url=?, is_active=? WHERE id=?")
            ->execute([$name, $description, $base_price, $duration_min, $image_url, $is_active, $id]);
    } else {
        $pdo->prepare("INSERT INTO services (name, description, base_price, duration_min, image_url, is_active) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$name, $description, $base_price, $duration_min, $image_url, $is_active]);
        $id = (int)$pdo->lastInsertId();
    }
    
    // Удаляем все старые опции этой услуги
    $pdo->prepare("DELETE FROM service_options WHERE service_id = ?")->execute([$id]);
    
    // Добавляем заново
    $sortGroup = 0;
    foreach ($option_groups as $group) {
        $groupName = trim($group['name'] ?? '');
        if (empty($groupName)) continue;
        
        $isRequired = !empty($group['is_required']) ? 1 : 0;
        $isMultiple = !empty($group['is_multiple']) ? 1 : 0;
        
        $sortOpt = 0;
        foreach (($group['options'] ?? []) as $opt) {
            $optName = trim($opt['name'] ?? $opt['option_name'] ?? '');
            if (empty($optName)) continue;
            
            $pdo->prepare("INSERT INTO service_options 
                (service_id, group_name, option_name, description, icon_url, price_modifier, duration_modifier, is_required, is_multiple, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $id, $groupName, $optName,
                    trim($opt['description'] ?? ''),
                    trim($opt['icon_url'] ?? ''),
                    (int)($opt['price_modifier'] ?? 0),
                    (int)($opt['duration_modifier'] ?? 0),
                    $isRequired,
                    $isMultiple,
                    $sortGroup * 100 + $sortOpt
                ]);
            $sortOpt++;
        }
        $sortGroup++;
    }
    
    $pdo->commit();
    jsonOk(['id' => $id, 'message' => 'Збережено']);
    
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}