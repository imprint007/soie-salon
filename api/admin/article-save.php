<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $id = (int)($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $imageUrl = trim($input['image_url'] ?? '');
    $content = trim($input['content'] ?? '');
    $excerpt = trim($input['excerpt'] ?? '');
    $isPublished = !empty($input['is_published']) ? 1 : 0;
    $sortOrder = (int)($input['sort_order'] ?? 0);
    
    if (empty($title)) jsonError('Введіть назву статті');
    if (empty($content)) jsonError('Введіть текст статті');
    
    if ($id > 0) {
        $pdo->prepare("UPDATE bot_articles SET title=?, image_url=?, content=?, excerpt=?, is_published=?, sort_order=? WHERE id=?")
            ->execute([$title, $imageUrl, $content, $excerpt, $isPublished, $sortOrder, $id]);
    } else {
        $pdo->prepare("INSERT INTO bot_articles (title, image_url, content, excerpt, is_published, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$title, $imageUrl, $content, $excerpt, $isPublished, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
    }
    
    jsonOk(['id' => $id, 'message' => 'Збережено']);
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}