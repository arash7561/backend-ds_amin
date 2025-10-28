<?php
require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
require_once '../vendor/autoload.php';

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
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (in_array($origin, $allowed_origins) || 
    (strpos($origin, 'http://localhost') !== false || 
     strpos($origin, 'http://127.0.0.1') !== false ||
     strpos($origin, 'https://aminindpharm.ir') !== false ||
     strpos($origin, 'http://aminindpharm.ir') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header("Content-Type: application/json; charset=UTF-8");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$secret_key = 'your-secret-key';

// دریافت توکن از header
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    echo json_encode(['status' => false, 'message' => 'توکن یافت نشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $matches[1];

try {
    // تجزیه توکن
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userId = $decoded->uid;
    
    // چک کردن وجود کاربر
    $stmt = $conn->prepare("SELECT id, mobile FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // تولید توکن جدید با 15 روز اعتبار
    $newPayload = [
        'iss' => 'http://localhost',
        'iat' => time(),
        'exp' => time() + (15 * 24 * 3600), // 15 روز جدید
        'uid' => $userId,
        'mobile' => $user['mobile']
    ];
    
    $newJwt = JWT::encode($newPayload, $secret_key, 'HS256');
    
    echo json_encode([
        'status' => true,
        'token' => $newJwt,
        'message' => 'توکن تمدید شد'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
}
?>