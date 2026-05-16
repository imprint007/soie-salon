<?php
require_once __DIR__ . '/_helper.php';
require_once __DIR__ . '/../lib/google_api.php';
setupAdminApi();
requireAdmin();

try {
    $calendars = googleListCalendars();
    if ($calendars === null) {
        jsonError('Google не підключений', 400);
    }
    
    // Очищаємо для фронту — залишаємо тільки потрібне
    $clean = [];
    foreach ($calendars as $c) {
        $clean[] = [
            'id' => $c['id'] ?? '',
            'summary' => $c['summary'] ?? '',
            'description' => $c['description'] ?? '',
            'primary' => !empty($c['primary']),
            'access_role' => $c['accessRole'] ?? '',
            'color' => $c['backgroundColor'] ?? '#9e968a',
        ];
    }
    
    // Сортуємо: основний першим, потім ті де можемо писати
    usort($clean, function($a, $b) {
        if ($a['primary']) return -1;
        if ($b['primary']) return 1;
        return strcmp($a['summary'], $b['summary']);
    });
    
    jsonOk(['data' => $clean]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}