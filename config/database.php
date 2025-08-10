<?php
// Database configuration
return [
    'host' => 'localhost',
    'dbname' => 'ds_amin',
    'username' => 'root',
    'password' => '', // در محیط تولید حتماً رمز عبور قوی تنظیم کنید
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
