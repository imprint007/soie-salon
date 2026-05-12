<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
$name = trim($input['name'] ?? '');
$description = trim($input['description'] ?? '');
$items_text = trim($input['items_text'] ?? '');
$badge = trim($input['badge'] ?? '');
$image_url = trim($input['image_url'] ?? '');
$original_price = (int)($input['original_price'] ?? 0);
$sale_price = (int)($input['sale_price'] ?? 0);
$duration_min = (int)($input['duration_min'] ?? 0);
$is_active = !empty($input['is_active']) ? 1 : 0;
$sort_order = (int)($input['sort_order'] ?? 0);
$service_ids = $input['service_ids'] ?? [];

if (empty($name)) jsonError('Назва обовʼязкова');
if ($sale_price <= 0) jsonError('Невірна ціна');
if ($duration_min <= 0) jsonError('Невірна тривалість');

$pdo = getDb();

try {
    $pdo->beginTransaction();
    
    if ($id > 0) {
        $pdo->prepare("UPDATE packages SET name=?, description=?, items_text=?, badge=?, image_url=?, original_price=?, sale_price=?, duration_min=?, is_active=?, sort_order=? WHERE id=?")
            ->execute([$name, $description, $items_text, $badge, $image_url, $original_price, $sale_price, $duration_min, $is_active, $sort_order, $id]);
    } else {
        $pdo->prepare("INSERT INTO packages (name, description, items_text, badge, image_url, original_price, sale_price, duration_min, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $description, $items_text, $badge, $image_url, $original_price, $sale_price, $duration_min, $is_active, $sort_order]);
        $id = (int)$pdo->lastInsertId();
    }
    
    $pdo->prepare("DELETE FROM package_services WHERE package_id = ?")->execute([$id]);
    
    if (!empty($service_ids) && is_array($service_ids)) {
        $stmt = $pdo->prepare("INSERT INTO package_services (package_id, service_id) VALUES (?, ?)");
        foreach ($service_ids as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $stmt->execute([$id, $sid]);
        }
    }
    
    $pdo->commit();
    jsonOk(['id' => $id, 'message' => 'Збережено']);
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}