<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

try {
    $pdo = getDb();
    $input = jsonInput();
    
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['field_name'] ?? '');
    $type = $input['field_type'] ?? 'text';
    $options = $input['field_options'] ?? null;
    $placeholder = trim($input['placeholder'] ?? '');
    $isRequired = !empty($input['is_required']) ? 1 : 0;
    $sortOrder = (int)($input['sort_order'] ?? 0);
    $fieldScope = $input['field_scope'] ?? 'profile';
    if (!in_array($fieldScope, ['profile', 'visit'])) $fieldScope = 'profile';
    
    $validTypes = ['text', 'textarea', 'select', 'checkbox', 'number', 'date'];
    if (!in_array($type, $validTypes)) $type = 'text';
    if (empty($name)) jsonError('Введіть назву поля');
    
    $optionsJson = null;
    if ($type === 'select' && is_array($options)) {
        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
    } elseif ($type === 'select' && is_string($options)) {
        $optionsJson = $options;
    }
    
    if ($id > 0) {
        $pdo->prepare("UPDATE client_custom_fields SET field_name=?, field_type=?, field_options=?, placeholder=?, is_required=?, sort_order=?, field_scope=? WHERE id=?")
            ->execute([$name, $type, $optionsJson, $placeholder, $isRequired, $sortOrder, $fieldScope, $id]);
    } else {
        $pdo->prepare("INSERT INTO client_custom_fields (field_name, field_type, field_options, placeholder, is_required, sort_order, field_scope) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $type, $optionsJson, $placeholder, $isRequired, $sortOrder, $fieldScope]);
        $id = (int)$pdo->lastInsertId();
    }
    
    jsonOk(['id' => $id]);
    
} catch (Throwable $e) {
    jsonError('Помилка: ' . $e->getMessage(), 500);
}