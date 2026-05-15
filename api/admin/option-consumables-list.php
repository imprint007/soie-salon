<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $optionId = (int)($_GET['option_id'] ?? 0);
    if ($optionId <= 0) jsonError('Невірний option_id');
    
    $stmt = $pdo->prepare("
        SELECT oc.id, oc.option_id, oc.product_id, oc.quantity_delta,
               p.name AS product_name, p.unit AS product_unit
        FROM option_consumables oc
        JOIN products p ON p.id = oc.product_id
        WHERE oc.option_id = ?
        ORDER BY oc.id
    ");
    $stmt->execute([$optionId]);
    
    jsonOk(['data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}