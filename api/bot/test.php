<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: OK\n";

require_once __DIR__ . '/bot-helper.php';
echo "Step 2: bot-helper loaded\n";

$token = botGetToken();
echo "Step 3: token = " . substr($token, 0, 10) . "...\n";

$pdo = botGetDb();
echo "Step 4: DB connected\n";

echo "ALL OK";