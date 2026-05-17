<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $masterId = (int)($_GET['master_id'] ?? 0);
    if ($masterId <= 0) jsonError('Невірний master_id');
    
    $pdo = getDb();
    
    // Постійний графік
    $stmt = $pdo->prepare("SELECT weekday, is_working, start_time, end_time, break_start, break_end 
                           FROM master_schedule WHERE master_id = ? ORDER BY weekday");
    $stmt->execute([$masterId]);
    $schedule = $stmt->fetchAll();
    
    // Виключення (тільки майбутні і за останні 7 днів)
    $exStmt = $pdo->prepare("SELECT id, exception_date, exception_type, is_working, 
                                    start_time, end_time, note
                             FROM master_schedule_exceptions 
                             WHERE master_id = ? AND exception_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                             ORDER BY exception_date ASC");
    $exStmt->execute([$masterId]);
    $exceptions = $exStmt->fetchAll();
    
    jsonOk([
        'master_id' => $masterId,
        'schedule' => $schedule,
        'exceptions' => $exceptions
    ]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}