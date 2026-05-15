<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $stmt = $pdo->query("
        SELECT p.id, p.category_id, p.name, p.description, p.image_url, p.unit,
               p.sell_price, p.cost_price, p.current_stock, p.min_stock, 
               p.is_active, p.sort_order,
               c.name AS category_name
        FROM products p
        LEFT JOIN product_categories c ON c.id = p.category_id
        ORDER BY p.sort_order, p.id DESC
    ");
    $products = $stmt->fetchAll();
    
    // Маркер низького залишку
    foreach ($products as &$p) {
        $p['low_stock'] = ((float)$p['current_stock'] <= (float)$p['min_stock']) ? 1 : 0;
        $p['out_of_stock'] = ((float)$p['current_stock'] <= 0) ? 1 : 0;
    }
    unset($p);
    
    jsonOk(['data' => $products]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}