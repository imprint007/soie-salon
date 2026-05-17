<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    $masterId = (int)($input['master_id'] ?? 0);
    $date = trim($input['exception_date'] ?? '');
    $type = trim($input['exception_type'] ?? 'day_off');
    $isWorking = !empty($input['is_working']) ? 1 : 0;
    $startTime = !empty($input['start_time']) ? $input['start_time'] : null;
    $endTime = !empty($input['end_time']) ? $input['end_time'] : null;
    $note = trim($input['note'] ?? '');
    
    $validTypes = ['vacation', 'sick', 'day_off', 'extra_work', 'custom_hours'];
    if (!in_array($type, $validTypes)) jsonError('Невірний тип');
    if ($masterId <= 0) jsonError('Невірний master_id');
    if (empty($date)) jsonError('Введіть дату');
    
    // Для extra_work і custom_hours потрібен час
    if (in_array($type, ['extra_work', 'custom_hours'])) {
        if (empty($startTime) || empty($endTime)) {
            jsonError('Введіть час початку і кінця');
        }
        $isWorking = 1;
    } else {
        $isWorking = 0;
        $startTime = null;
        $endTime = null;
    }
    
    $pdo = getDb();
    
    if ($id > 0) {
        // UPDATE
        $pdo->prepare("UPDATE master_schedule_exceptions SET 
                exception_date=?, exception_type=?, is_working=?, 
                start_time=?, end_time=?, note=? 
            WHERE id=? AND master_id=?")
            ->execute([$date, $type, $isWorking, $startTime, $endTime, $note, $id, $masterId]);
    } else {
        // INSERT з захистом від дублювання дати
        $pdo->prepare("INSERT INTO master_schedule_exceptions 
                (master_id, exception_date, exception_type, is_working, start_time, end_time, note) 
            VALUES (?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                exception_type=VALUES(exception_type),
                is_working=VALUES(is_working),
                start_time=VALUES(start_time),
                end_time=VALUES(end_time),
                note=VALUES(note)")
            ->execute([$masterId, $date, $type, $isWorking, $startTime, $endTime, $note]);
    }
    
    jsonOk(['message' => 'Збережено']);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}