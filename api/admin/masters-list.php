<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    
    $masters = $pdo->query("SELECT * FROM masters ORDER BY sort_order, id DESC")->fetchAll();
    $links = $pdo->query("SELECT master_id, service_id FROM master_services")->fetchAll();
    
    // Прикрепляем к каждому мастеру список ID услуг
    foreach ($masters as &$m) {
        $m['service_ids'] = [];
        foreach ($links as $l) {
            if ($l['master_id'] == $m['id']) {
                $m['service_ids'][] = (int)$l['service_id'];
            }
        }
    }
    
    jsonOk(['data' => $masters]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}