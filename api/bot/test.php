<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1\n";
require_once __DIR__ . '/bot-helper.php';
echo "Step 2: helper OK\n";

echo "Step 3: checking send-reminders...\n";
include __DIR__ . '/send-reminders.php';
echo "\nStep 4: DONE";