<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $serviceId = (int)($_GET['service_id'] ?? 0);
    if ($serviceId <= 0) jsonError('Невірний service_id');
    
    $stmt = $pdo->prepare("
        SELECT sc.id, sc.service_id, sc.product_id, sc.quantity,
               p.name AS product_name, p.unit AS product_unit, p.current_stock
        FROM service_consumables sc
        JOIN products p ON p.id = sc.product_id
        WHERE sc.service_id = ?
        ORDER BY sc.id
    ");
    $stmt->execute([$serviceId]);
    
    jsonOk(['data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}