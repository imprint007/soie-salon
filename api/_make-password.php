<?php
/**
 * УВАГА: тимчасовий файл для генерації хешу пароля.
 * ОБОВʼЯЗКОВО видалити після використання!
 */

header('Content-Type: text/plain; charset=utf-8');

// ⚠️ Поміняйте на свій пароль (мінімум 8 символів):
$password = 'Curls2026!Beauty';

// Хешуємо
$hash = password_hash($password, PASSWORD_BCRYPT);

// Виводимо
echo "Пароль: $password\n";
echo "Хеш:    $hash\n\n";
echo "Скопіюйте хеш і виконайте в phpMyAdmin:\n\n";
echo "INSERT INTO admins (username, password_hash, full_name, role) VALUES\n";
echo "('admin', '$hash', 'Власник салону', 'owner');\n";