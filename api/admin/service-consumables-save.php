<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $serviceId = (int)($input['service_id'] ?? 0);
    $consumables = $input['consumables'] ?? [];
    
    if ($serviceId <= 0) jsonError('Невірний service_id');
    if (!is_array($consumables)) jsonError('Невірний формат');
    
    $pdo->beginTransaction();
    
    // Видаляємо старі
    $pdo->prepare("DELETE FROM service_consumables WHERE service_id = ?")->execute([$serviceId]);
    
    // Додаємо нові
    $stmt = $pdo->prepare("INSERT INTO service_consumables (service_id, product_id, quantity) VALUES (?, ?, ?)");
    foreach ($consumables as $c) {
        $productId = (int)($c['product_id'] ?? 0);
        $quantity = (float)($c['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) continue;
        $stmt->execute([$serviceId, $productId, $quantity]);
    }
    
    $pdo->commit();
    jsonOk();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}