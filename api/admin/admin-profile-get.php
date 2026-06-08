<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) jsonError('Не авторизовано');
    
    $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, last_login FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    
    if (!$admin) jsonError('Адміна не знайдено');
    
    jsonOk(['admin' => $admin]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}