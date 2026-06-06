<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $photoUrl = trim($input['photo_url'] ?? '');
    $fieldValues = $input['field_values'] ?? [];
    
    if (empty($name)) jsonError('Введіть імʼя');
    if (empty($phone)) jsonError('Введіть телефон');
    
    if ($id > 0) {
        $pdo->prepare("UPDATE clients SET name=?, phone=?, email=?, notes=?, photo_url=? WHERE id=?")
            ->execute([$name, $phone, $email, $notes, $photoUrl, $id]);
    } else {
        $pdo->prepare("INSERT INTO clients (name, phone, email, notes, photo_url) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $phone, $email, $notes, $photoUrl]);
        $id = (int)$pdo->lastInsertId();
    }
    
    // Зберігаємо довільні поля
    if (is_array($fieldValues)) {
        $stmt = $pdo->prepare("INSERT INTO client_field_values (client_id, field_id, field_value) 
                               VALUES (?, ?, ?) 
                               ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)");
        foreach ($fieldValues as $fieldId => $value) {
            $stmt->execute([$id, (int)$fieldId, $value]);
        }
    }
    
    jsonOk(['id' => $id, 'message' => 'Збережено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}