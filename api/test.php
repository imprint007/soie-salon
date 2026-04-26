<?php
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-errors.log');
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "PHP працює. Версія: " . PHP_VERSION . "<br>";
echo "Файл: " . __FILE__ . "<br>";

if (file_exists(__DIR__ . '/admin/_helper.php')) {
    echo "_helper.php знайдено<br>";
} else {
    echo "❌ _helper.php НЕ знайдено<br>";
}

if (file_exists(__DIR__ . '/config/database.php')) {
    echo "database.php знайдено<br>";
} else {
    echo "❌ database.php НЕ знайдено<br>";
}

echo "<br>Підключаємо хелпер...<br>";
require_once __DIR__ . '/admin/_helper.php';
echo "Хелпер підключений<br>";

echo "<br>Викликаємо getDb()...<br>";
$pdo = getDb();
echo "БД підключена<br>";

echo "<br>Робимо запит...<br>";
$count = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
echo "Послуг у базі: $count<br>";

echo "<br>✅ Все працює!";