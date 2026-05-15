<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $cats = $pdo->query("SELECT id, name, sort_order FROM product_categories ORDER BY sort_order, name")->fetchAll();
    jsonOk(['data' => $cats]);
} catch (Throwable $e) {
    jsonError('Помилка', 500);
}