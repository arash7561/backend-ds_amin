<?php
// CORS headers
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

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (
    in_array($origin, $allowed_origins) ||
    strpos($origin, 'localhost') !== false ||
    strpos($origin, '127.0.0.1') !== false ||
    strpos($origin, 'aminindpharm.ir') !== false
) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../jwt_utils.php';

// Fallback برای getallheaders() در WAMP
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}

// بررسی JWT
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$userId = null;

if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    $authResult = verify_jwt_token($jwt);
    if ($authResult['valid']) {
        $userId = $authResult['uid'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    // جلوگیری از درخواست تکراری
    $stmt = $conn->prepare("SELECT id FROM affiliate_requests WHERE user_id=? AND status='pending'");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'درخواست شما در حال بررسی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی اینکه آیا قبلاً بازاریاب شده است
    $stmt = $conn->prepare("SELECT id FROM affiliates WHERE user_id=?");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'شما قبلاً بازاریاب شده‌اید'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO affiliate_requests (user_id, created_at) VALUES (?, NOW())");
    $stmt->execute([$userId]);

    echo json_encode(['status' => true, 'message' => 'درخواست شما ثبت شد'], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Affiliate request error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Affiliate request error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
