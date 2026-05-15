<?php
require_once __DIR__ . '/_helper.php';
requireMaster();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $masterId = getMasterId();
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $bio = trim($input['bio'] ?? '');
    $photoUrl = trim($input['photo_url'] ?? '');
    $username = isset($input['username']) ? trim($input['username']) : null;
    $newPassword = isset($input['new_password']) ? $input['new_password'] : null;
    $currentPassword = isset($input['current_password']) ? $input['current_password'] : null;
    
    if (empty($name)) jsonError('Імʼя обовʼязкове');
    
    // Якщо хоче змінити пароль — перевіряємо поточний
    $passwordHash = null;
    if (!empty($newPassword)) {
        if (empty($currentPassword)) {
            jsonError('Введіть поточний пароль');
        }
        if (strlen($newPassword) < 4) {
            jsonError('Новий пароль мінімум 4 символи');
        }
        
        // Перевіряємо поточний
        $check = $pdo->prepare("SELECT password_hash FROM masters WHERE id = ?");
        $check->execute([$masterId]);
        $row = $check->fetch();
        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            jsonError('Невірний поточний пароль');
        }
        
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    }
    
    // Валідація логіна
    if ($username !== null && $username !== '') {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) {
            jsonError('Логін: 3-50 символів, тільки латинські літери, цифри, _ та -');
        }
        // Унікальність
        $check = $pdo->prepare("SELECT id FROM masters WHERE username = ? AND id != ?");
        $check->execute([$username, $masterId]);
        if ($check->fetch()) jsonError('Цей логін вже використовується');
    }
    if ($username === '') $username = null;
    
    if ($passwordHash !== null) {
        $pdo->prepare("UPDATE masters SET name=?, phone=?, email=?, bio=?, photo_url=?, username=?, password_hash=? WHERE id=?")
            ->execute([$name, $phone, $email, $bio, $photoUrl, $username, $passwordHash, $masterId]);
    } else {
        $pdo->prepare("UPDATE masters SET name=?, phone=?, email=?, bio=?, photo_url=?, username=? WHERE id=?")
            ->execute([$name, $phone, $email, $bio, $photoUrl, $username, $masterId]);
    }
    
    jsonOk(['message' => 'Збережено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}