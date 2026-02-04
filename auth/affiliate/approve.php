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
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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

// Function to get Authorization header robustly
function getAuthorizationHeader() {
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } else {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            }
        }
    }
    return $authHeader;
}

try {
    // بررسی احراز هویت ادمین
    $authHeader = getAuthorizationHeader();
    
    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن احراز هویت الزامی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = $matches[1];
    $secretKey = $_ENV['JWT_SECRET'] ?? 'your-secret-key';

    try {
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
        $user = (array)$decoded;
        
        // بررسی اینکه کاربر ادمین است یا نه
        if (!isset($user['aid']) && (!isset($user['role']) || $user['role'] !== 'admin')) {
            http_response_code(403);
            echo json_encode(['status' => false, 'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند درخواست را تایید کند'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (\Firebase\JWT\ExpiredException $e) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن منقضی شده است'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Firebase\JWT\SignatureInvalidException $e) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        error_log("JWT decode error in approve.php: " . $e->getMessage());
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    error_log("Auth error in approve.php: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
$affiliateCode = isset($input['affiliate_code']) ? trim(strtoupper($input['affiliate_code'])) : '';

// بررسی ورودی‌های الزامی
if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه درخواست نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($affiliateCode)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'کد بازاریاب الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی فرمت کد بازاریاب (حداقل 3 کاراکتر)
if (strlen($affiliateCode) < 3) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'کد بازاریاب باید حداقل 3 کاراکتر باشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();
    $conn->beginTransaction();

// دریافت درخواست
$stmt = $conn->prepare("SELECT user_id FROM affiliate_requests WHERE id=? AND status='pending'");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'درخواست یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $request['user_id'];

    // بررسی اینکه آیا این کد قبلاً استفاده شده است
    $stmt = $conn->prepare("SELECT id FROM affiliates WHERE code = ?");
    $stmt->execute([$affiliateCode]);
    if ($stmt->fetch()) {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(['status' => false, 'message' => 'این کد بازاریاب قبلاً استفاده شده است. لطفاً کد دیگری انتخاب کنید'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی اینکه آیا این کاربر قبلاً بازاریاب شده است
    $stmt = $conn->prepare("SELECT id FROM affiliates WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(['status' => false, 'message' => 'این کاربر قبلاً بازاریاب شده است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ثبت بازاریاب با کد اختصاصی از ادمین
    $stmt = $conn->prepare("
        INSERT INTO affiliates (user_id, code, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$userId, $affiliateCode]);

// بروزرسانی درخواست
$stmt = $conn->prepare("
  UPDATE affiliate_requests
  SET status='approved', reviewed_at=NOW()
  WHERE id=?
");
$stmt->execute([$requestId]);

    $conn->commit();

    echo json_encode([
        'status' => true,
        'affiliate_code' => $affiliateCode,
        'message' => 'درخواست با موفقیت تایید شد'
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Affiliate approve error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Affiliate approve error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
