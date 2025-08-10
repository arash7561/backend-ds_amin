<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=UTF-8');

$secret_key = 'your-secret-key';  // کلید مخفی JWT

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

$userData = [
    'user_id' => null,
    'mobile'  => null
];

// اگر هدر Authorization وجود دارد
if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    try {
        $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
        $decoded_array = (array) $decoded;

        $userData['user_id'] = $decoded_array['uid'] ?? null;
        $userData['mobile']  = $decoded_array['mobile'] ?? null;
    } catch (Exception $e) {
        // اگر توکن نامعتبر بود، کاربر را مهمان در نظر می‌گیریم
        $userData['user_id'] = null;
    }
}

// آرایه اطلاعات کاربر را برگردان
return $userData;
