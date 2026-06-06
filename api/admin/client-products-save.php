<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $clientId = (int)($input['client_id'] ?? 0);
    $products = $input['products'] ?? [];
    
    if ($clientId <= 0) jsonError('Невірний client_id');
    
    // Видаляємо старі записи (перезаписуємо)
    $pdo->prepare("DELETE FROM client_product_usage WHERE client_id = ? AND booking_id IS NULL")->execute([$clientId]);
    
    // Вставляємо нові
    $stmt = $pdo->prepare("INSERT INTO client_product_usage (client_id, product_id, quantity, note) VALUES (?, ?, ?, ?)");
    
    foreach ($products as $p) {
        $productId = (int)($p['product_id'] ?? 0);
        $quantity = (float)($p['quantity'] ?? 0);
        $note = trim($p['note'] ?? '');
        
        if ($productId > 0 && $quantity > 0) {
            $stmt->execute([$clientId, $productId, $quantity, $note]);
        }
    }
    
    jsonOk(['message' => 'Збережено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}