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
    $description = trim($input['description'] ?? '');
    $imageUrl = trim($input['image_url'] ?? '');
    $categoryId = !empty($input['category_id']) ? (int)$input['category_id'] : null;
    $unit = $input['unit'] ?? 'pcs';
    $sellPrice = (float)($input['sell_price'] ?? 0);
    $costPrice = (float)($input['cost_price'] ?? 0);
    $minStock = (float)($input['min_stock'] ?? 0);
    $isActive = !empty($input['is_active']) ? 1 : 0;
    $sortOrder = (int)($input['sort_order'] ?? 0);
    
    if (empty($name)) jsonError('Назва обовʼязкова');
    if (!in_array($unit, ['ml', 'gr', 'pcs', 'pack', 'm', 'l', 'kg'])) {
        jsonError('Невірна одиниця виміру');
    }
    
    if ($id > 0) {
        // Не змінюємо current_stock тут — він керується через закупки/витрату
        $pdo->prepare("UPDATE products SET 
                category_id=?, name=?, description=?, image_url=?, unit=?,
                sell_price=?, cost_price=?, min_stock=?, is_active=?, sort_order=?
            WHERE id=?")
            ->execute([$categoryId, $name, $description, $imageUrl, $unit,
                       $sellPrice, $costPrice, $minStock, $isActive, $sortOrder, $id]);
    } else {
        $initialStock = (float)($input['current_stock'] ?? 0);
        $pdo->prepare("INSERT INTO products 
                (category_id, name, description, image_url, unit, sell_price, cost_price, 
                 current_stock, min_stock, is_active, sort_order) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$categoryId, $name, $description, $imageUrl, $unit,
                       $sellPrice, $costPrice, $initialStock, $minStock, $isActive, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
        
        // Якщо ввели початковий залишок — додаємо рух
        if ($initialStock > 0) {
            $pdo->prepare("INSERT INTO stock_movements 
                    (product_id, movement_type, quantity, stock_before, stock_after, reason_text)
                VALUES (?, 'adjustment', ?, 0, ?, 'Початковий залишок')")
                ->execute([$id, $initialStock, $initialStock]);
        }
    }
    
    jsonOk(['id' => $id]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}