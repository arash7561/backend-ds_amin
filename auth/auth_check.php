<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: http://localhost:3002');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$secret_key = 'your-secret-key';  // کلید مخفی JWT

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

error_log("Auth check - Authorization header: " . var_export($authHeader, true));

$userData = [
    'user_id' => null,
    'mobile'  => null
];



// اگر هدر Authorization وجود دارد
if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    error_log("Auth check - JWT token: " . $jwt);
    try {
        $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
        $decoded_array = (array) $decoded;
        
        error_log("Auth check - Decoded payload: " . json_encode($decoded_array));

        $userData['user_id'] = $decoded_array['uid'] ?? null;
        $userData['mobile']  = $decoded_array['mobile'] ?? null;
        
        error_log("Auth check - Extracted user_id: " . var_export($userData['user_id'], true));
    } catch (Exception $e) {
        // اگر توکن نامعتبر بود، کاربر را مهمان در نظر می‌گیریم
        error_log("Auth check - JWT decode error: " . $e->getMessage());
        $userData['user_id'] = null;
    }
} else {
    error_log("Auth check - No Authorization header found");
}

// آرایه اطلاعات کاربر را برگردان
return $userData;
