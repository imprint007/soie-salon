<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $stmt = $pdo->query("
        SELECT 
            o.id, o.option_name, o.group_name, o.service_id,
            s.name AS service_name
        FROM service_options o
        JOIN services s ON s.id = o.service_id
        WHERE s.is_active = 1
        ORDER BY s.name, o.group_name, o.sort_order
    ");
    jsonOk(['data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    jsonError('Помилка', 500);
}