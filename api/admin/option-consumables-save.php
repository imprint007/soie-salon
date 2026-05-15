<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $optionId = (int)($input['option_id'] ?? 0);
    $consumables = $input['consumables'] ?? [];
    
    if ($optionId <= 0) jsonError('Невірний option_id');
    if (!is_array($consumables)) jsonError('Невірний формат');
    
    $pdo->beginTransaction();
    
    $pdo->prepare("DELETE FROM option_consumables WHERE option_id = ?")->execute([$optionId]);
    
    $stmt = $pdo->prepare("INSERT INTO option_consumables (option_id, product_id, quantity_delta) VALUES (?, ?, ?)");
    foreach ($consumables as $c) {
        $productId = (int)($c['product_id'] ?? 0);
        $quantityDelta = (float)($c['quantity_delta'] ?? 0);
        if ($productId <= 0) continue;
        // quantity_delta может быть отрицательной — это нормально (опция уменьшает расход)
        $stmt->execute([$optionId, $productId, $quantityDelta]);
    }
    
    $pdo->commit();
    jsonOk();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}