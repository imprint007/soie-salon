<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

try {
    $pdo = getDb();
    $articles = $pdo->query("SELECT * FROM bot_articles ORDER BY sort_order ASC, id DESC")->fetchAll();
    jsonOk(['data' => $articles]);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}