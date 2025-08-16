<?php
require_once '../db_connection.php';
$conn = getPDO();

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Access-Control-Allow-Origin: http://localhost:3000');
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

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userId = $decoded->uid;
    
    // دریافت اطلاعات کاربر از جدول users
    $stmt = $conn->prepare("SELECT name, mobile, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'status' => true,
        'data' => [
            'name' => $user['name'],
            'mobile' => $user['mobile'],
            'created_at' => $user['created_at']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
}
?>