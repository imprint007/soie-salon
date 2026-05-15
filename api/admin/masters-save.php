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

// Нові поля — логін і пароль для особистого кабінету
$username = isset($input['username']) ? trim($input['username']) : null;
$password = isset($input['password']) ? $input['password'] : null;

if (empty($name)) jsonError('Імʼя обовʼязкове');

// Валідація логіна якщо заданий
if ($username !== null && $username !== '') {
    if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) {
        jsonError('Логін: 3-50 символів, тільки латинські літери, цифри, _ та -');
    }
}
if ($username === '') $username = null;

// Хешуємо пароль якщо переданий
$passwordHash = null;
if (!empty($password)) {
    if (strlen($password) < 4) {
        jsonError('Пароль мінімум 4 символи');
    }
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
}

$pdo = getDb();

try {
    $pdo->beginTransaction();
    
    // Перевірка унікальності username (тільки якщо заданий)
    if ($username !== null) {
        $checkStmt = $pdo->prepare("SELECT id FROM masters WHERE username = ? AND id != ? LIMIT 1");
        $checkStmt->execute([$username, $id]);
        if ($checkStmt->fetch()) {
            $pdo->rollBack();
            jsonError('Цей логін вже використовується іншим майстром');
        }
    }
    
    if ($id > 0) {
        // UPDATE — пароль обновляем только если он передан
        if ($passwordHash !== null) {
            $pdo->prepare("UPDATE masters SET name=?, role=?, phone=?, email=?, bio=?, photo_url=?, experience_years=?, is_active=?, username=?, password_hash=? WHERE id=?")
                ->execute([$name, $role, $phone, $email, $bio, $photo_url, $experience_years, $is_active, $username, $passwordHash, $id]);
        } else {
            $pdo->prepare("UPDATE masters SET name=?, role=?, phone=?, email=?, bio=?, photo_url=?, experience_years=?, is_active=?, username=? WHERE id=?")
                ->execute([$name, $role, $phone, $email, $bio, $photo_url, $experience_years, $is_active, $username, $id]);
        }
    } else {
        // INSERT — пишемо все поля
        $pdo->prepare("INSERT INTO masters (name, role, phone, email, bio, photo_url, experience_years, is_active, username, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $role, $phone, $email, $bio, $photo_url, $experience_years, $is_active, $username, $passwordHash]);
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