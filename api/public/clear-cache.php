<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared!\n";
} else {
    echo "OPcache not available\n";
}

// Тест нового коду
require_once __DIR__ . '/../config/database.php';
echo "filteredMasters function exists in calendar-slots: ";
$code = file_get_contents(__DIR__ . '/calendar-slots.php');
echo strpos($code, 'filteredMasters') !== false ? 'YES' : 'NO';
echo "\nexceptionMap: ";
echo strpos($code, 'exceptionMap') !== false ? 'YES' : 'NO';
echo "\nFile size: " . strlen($code) . " bytes\n";