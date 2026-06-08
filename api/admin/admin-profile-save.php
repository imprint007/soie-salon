<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) jsonError('Не авторизовано');
    
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    
    // Оновлюємо імʼя та email
    if (!empty($name) || !empty($email)) {
        $pdo->prepare("UPDATE admins SET full_name = ?, email = ? WHERE id = ?")
            ->execute([$name, $email, $adminId]);
    }
    
    // Зміна пароля
    if (!empty($newPassword)) {
        if (empty($currentPassword)) {
            jsonError('Введіть поточний пароль');
        }
        if (strlen($newPassword) < 6) {
            jsonError('Новий пароль має бути мінімум 6 символів');
        }
        
        $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
            jsonError('Невірний поточний пароль');
        }
        
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?")->execute([$hashed, $adminId]);
    }
    
    jsonOk(['message' => 'Збережено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}