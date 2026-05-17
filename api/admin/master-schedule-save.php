<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $input = jsonInput();
    $masterId = (int)($input['master_id'] ?? 0);
    $schedule = $input['schedule'] ?? [];
    
    if ($masterId <= 0) jsonError('Невірний master_id');
    if (!is_array($schedule)) jsonError('Невірний schedule');
    
    $pdo = getDb();
    $pdo->beginTransaction();
    
    // Видаляємо старий графік повністю
    $pdo->prepare("DELETE FROM master_schedule WHERE master_id = ?")->execute([$masterId]);
    
    // Вставляємо новий
    $stmt = $pdo->prepare("INSERT INTO master_schedule 
        (master_id, weekday, is_working, start_time, end_time, break_start, break_end) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($schedule as $day) {
        $weekday = (int)($day['weekday'] ?? -1);
        if ($weekday < 0 || $weekday > 6) continue;
        
        $isWorking = !empty($day['is_working']) ? 1 : 0;
        $startTime = $isWorking ? ($day['start_time'] ?? null) : null;
        $endTime = $isWorking ? ($day['end_time'] ?? null) : null;
        $breakStart = !empty($day['break_start']) ? $day['break_start'] : null;
        $breakEnd = !empty($day['break_end']) ? $day['break_end'] : null;
        
        // Якщо робочий день — час обовʼязковий
        if ($isWorking && (empty($startTime) || empty($endTime))) {
            continue;
        }
        
        $stmt->execute([$masterId, $weekday, $isWorking, $startTime, $endTime, $breakStart, $breakEnd]);
    }
    
    $pdo->commit();
    jsonOk(['message' => 'Графік збережено']);
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonError('Помилка: ' . $e->getMessage(), 500);
}