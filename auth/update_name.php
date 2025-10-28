<?php
require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$csrfToken = $headers['X-CSRF-Token'] ?? '';
$requestedWith = $headers['X-Requested-With'] ?? '';

// بررسی CSRF protection
if (!$csrfToken || !$requestedWith || $requestedWith !== 'XMLHttpRequest') {
    echo json_encode(['status' => false, 'message' => 'درخواست نامعتبر.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
    echo json_encode(['status' => false, 'message' => 'توکن احراز هویت الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = substr($authHeader, 7);
$secret_key = 'your-secret-key';
$newName = trim($data->name ?? '');

if (empty($newName)) {
    echo json_encode(['status' => false, 'message' => 'نام جدید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userId = $decoded->uid;
    
    // بروزرسانی نام کاربر
    $stmt = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
    $result = $stmt->execute([$newName, $userId]);
    
    if ($result) {
        echo json_encode([
            'status' => true,
            'message' => 'نام با موفقیت تغییر کرد.',
            'data' => ['name' => $newName]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در بروزرسانی نام.'], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
}
?>