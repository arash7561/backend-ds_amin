<?php
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

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'فقط درخواست POST مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../../db_connection.php';
    $conn = getPDO();
    
    if (!$conn) {
        throw new Exception('خطا در اتصال به پایگاه داده');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در اتصال به پایگاه داده'], JSON_UNESCAPED_UNICODE);
    error_log("Database connection error in create-invite-code.php: " . $e->getMessage());
    exit;
}

// Load JWT library
if (!file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'فایل autoload پیدا نشد'], JSON_UNESCAPED_UNICODE);
    error_log("vendor/autoload.php not found in create-invite-code.php");
    exit;
}

try {
    require_once __DIR__ . '/../../vendor/autoload.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در بارگذاری کتابخانه‌ها'], JSON_UNESCAPED_UNICODE);
    error_log("Autoload error in create-invite-code.php: " . $e->getMessage());
    exit;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 1. گرفتن توکن از هدر Authorization
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

// اگر در Authorization header نبود، از HTTP_AUTHORIZATION چک کن
if (!$authHeader) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
}

// استخراج JWT token از Bearer header
if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'توکن احراز هویت الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $matches[1];
$secret_key = 'your-secret-key';

// 2. بررسی و دکد کردن JWT token
try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_array = (array)$decoded;
    
    // دریافت شناسه کاربر - برای کاربران معمولی uid و برای ادمین‌ها aid
    $userId = $decoded_array['uid'] ?? null;
    $adminId = $decoded_array['aid'] ?? null;
    
    if (!$userId && !$adminId) {
        http_response_code(401);
        echo json_encode(['error' => 'شناسه کاربر در توکن یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // استفاده از userId برای کاربران معمولی (ادمین‌ها نباید کد دعوت بسازند)
    if ($adminId) {
        http_response_code(403);
        echo json_encode(['error' => 'ادمین‌ها نمی‌توانند کد دعوت بسازند'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // بررسی کاربر در دیتابیس
    $stmt = $conn->prepare("
        SELECT id, invite_code
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'کاربر یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (\Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'توکن منقضی شده است'], JSON_UNESCAPED_UNICODE);
    error_log("JWT expired in create-invite-code.php: " . $e->getMessage());
    exit;
} catch (\Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
    error_log("JWT signature invalid in create-invite-code.php: " . $e->getMessage());
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در بررسی کاربر'], JSON_UNESCAPED_UNICODE);
    error_log("Error checking user in create-invite-code.php: " . $e->getMessage());
    exit;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'توکن نامعتبر یا منقضی شده است: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    error_log("JWT decode error in create-invite-code.php: " . $e->getMessage());
    exit;
}

// 3. اگر قبلاً کد دارد
if ($user['invite_code']) {
    echo json_encode([
        'success' => true,
        'invite_code' => $user['invite_code'],
        'message' => 'کد دعوت قبلاً ساخته شده است'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. ساخت کد دعوت
function generateInviteCode() {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

// 5. تضمین یکتابودن کد
$maxAttempts = 10;
$attempts = 0;
$code = null;

try {
    do {
        $code = generateInviteCode();
        $check = $conn->prepare("SELECT id FROM users WHERE invite_code = ?");
        $check->execute([$code]);
        $attempts++;
        
        if ($attempts >= $maxAttempts) {
            throw new Exception('نمی‌توان کد دعوت یکتا تولید کرد');
        }
    } while ($check->fetch());
    
    // 6. ذخیره کد
    $stmt = $conn->prepare("
        UPDATE users
        SET invite_code = ?, is_marketer = 1
        WHERE id = ?
    ");
    $stmt->execute([$code, $user['id']]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('خطا در ذخیره کد دعوت');
    }
    
    // 7. پاسخ نهایی
    echo json_encode([
        'success' => true,
        'invite_code' => $code,
        'message' => 'کد دعوت با موفقیت ساخته شد'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در ساخت کد دعوت'], JSON_UNESCAPED_UNICODE);
    error_log("Database error in create-invite-code.php: " . $e->getMessage());
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    error_log("Error in create-invite-code.php: " . $e->getMessage());
    exit;
}
