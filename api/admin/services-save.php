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
$category = trim($input['category'] ?? '');
$image_url = trim($input['image_url'] ?? '');
$is_active = !empty($input['is_active']) ? 1 : 0;

if (empty($name)) jsonError('Назва обовʼязкова');
if ($base_price <= 0) jsonError('Невірна ціна');
if ($duration_min <= 0) jsonError('Невірна тривалість');

try {
    $pdo = getDb();
    
    if ($id > 0) {
        // Оновлення
        $stmt = $pdo->prepare("UPDATE services SET name=?, description=?, base_price=?, duration_min=?, category=?, image_url=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $description, $base_price, $duration_min, $category, $image_url, $is_active, $id]);
        jsonOk(['id' => $id, 'message' => 'Оновлено']);
    } else {
        // Створення
        $stmt = $pdo->prepare("INSERT INTO services (name, description, base_price, duration_min, category, image_url, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $base_price, $duration_min, $category, $image_url, $is_active]);
        jsonOk(['id' => $pdo->lastInsertId(), 'message' => 'Створено']);
    }
} catch (PDOException $e) {
    jsonError('Помилка БД: ' . $e->getMessage(), 500);
}