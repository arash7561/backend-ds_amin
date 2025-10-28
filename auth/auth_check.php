<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// CORS headers - Allow from localhost and production domain
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'https://aminindpharm.ir',
    'http://aminindpharm.ir'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins) || 
    (strpos($origin, 'http://localhost') !== false || 
     strpos($origin, 'http://127.0.0.1') !== false ||
     strpos($origin, 'https://aminindpharm.ir') !== false ||
     strpos($origin, 'http://aminindpharm.ir') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

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

        // دریافت شناسه کاربر - برای کاربران معمولی uid و برای ادمین‌ها aid
        $userId = $decoded_array['uid'] ?? null;
        $adminId = $decoded_array['aid'] ?? null;
        
        // user_id می‌تواند از uid یا aid باشد
        $userData['user_id'] = $userId ?? $adminId;
        $userData['mobile']  = $decoded_array['mobile'] ?? null;
        $userData['is_admin'] = isset($adminId);
        
        error_log("Auth check - Extracted user_id: " . var_export($userData['user_id'], true));
        error_log("Auth check - is_admin: " . var_export($userData['is_admin'], true));
    } catch (Exception $e) {
        // اگر توکن نامعتبر بود، کاربر را مهمان در نظر می‌گیریم
        error_log("Auth check - JWT decode error: " . $e->getMessage());
        $userData['user_id'] = null;
    }
} else {
    error_log("Auth check - No Authorization header found");
}

// بررسی اینکه آیا این فایل به صورت مستقیماً فراخوانی شده یا با require
$isDirectCall = !isset($_ENV['AUTH_CHECK_REQUIRED']);

if ($isDirectCall) {
    // مستقیماً فراخوانی شده - JSON return کن
    echo json_encode([
        'status' => true,
        'data' => $userData,
        'message' => 'Token validated successfully'
    ]);
    exit();
}

// اگر با require فراخوانی شده، آرایه return کن
return $userData;
