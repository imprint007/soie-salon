<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

$input = jsonInput();
$id = (int)($input['id'] ?? 0);
$name = trim($input['name'] ?? '');
$role = trim($input['role'] ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$bio = trim($input['bio'] ?? '');
$photo_url = trim($input['photo_url'] ?? '');
$experience_years = (int)($input['experience_years'] ?? 0);
$is_active = !empty($input['is_active']) ? 1 : 0;
$service_ids = $input['service_ids'] ?? [];

if (empty($name)) jsonError('Імʼя обовʼязкове');

$pdo = getDb();

try {
    $pdo->beginTransaction();
    
    if ($id > 0) {
        $pdo->prepare("UPDATE masters SET name=?, role=?, phone=?, email=?, bio=?, photo_url=?, experience_years=?, is_active=? WHERE id=?")
            ->execute([$name, $role, $phone, $email, $bio, $photo_url, $experience_years, $is_active, $id]);
    } else {
        $pdo->prepare("INSERT INTO masters (name, role, phone, email, bio, photo_url, experience_years, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $role, $phone, $email, $bio, $photo_url, $experience_years, $is_active]);
        $id = (int)$pdo->lastInsertId();
    }
    
    // Полностью пересохраняем связи мастер↔услуги
    $pdo->prepare("DELETE FROM master_services WHERE master_id = ?")->execute([$id]);
    
    if (!empty($service_ids) && is_array($service_ids)) {
        $stmt = $pdo->prepare("INSERT INTO master_services (master_id, service_id) VALUES (?, ?)");
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