<?php
// Database configuration
// لطفاً اطلاعات دیتابیس خود را وارد کنید:
// Host: معمولاً باید 'localhost' باشد (نه IP)
// Database: نام دیتابیس شما
// Username: نام کاربری دیتابیس
// Password: رمز عبور دیتابیس

// return [
//     'host' => '185.164.72.157', // یا آدرس دیتابیس هاست
//     'dbname' => 'aminin_ds_amini', // نام دیتابیس هاست
//     'username' => 'aminin_alavi', // نام کاربری دیتابیس هاست
//     'password' => 'H09371822616h_', // رمز عبور دیتابیس هاست
//     'charset' => 'utf8mb4',
//     'options' => [
//         PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
//         PDO::ATTR_EMULATE_PREPARES => false,
//     ]
// ];

return [
    'host' => 'localhost', // یا آدرس دیتابیس هاست
    'dbname' => 'ds_amin', // نام دیتابیس هاست
    'username' => 'root', // نام کاربری دیتابیس هاست
    'password' => '', // رمز عبور دیتابیس هاست
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    ]
];
