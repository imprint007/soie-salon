<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $packages = $pdo->query("SELECT * FROM packages ORDER BY sort_order, id DESC")->fetchAll();
    $links = $pdo->query("SELECT package_id, service_id FROM package_services")->fetchAll();
    
    foreach ($packages as &$p) {
        $p['service_ids'] = [];
        foreach ($links as $l) {
            if ($l['package_id'] == $p['id']) {
                $p['service_ids'][] = (int)$l['service_id'];
            }
        }
    }
    
    jsonOk(['data' => $packages]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}