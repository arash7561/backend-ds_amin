<?php
// Enable error reporting for debugging but don't display errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering at the very beginning to catch any unexpected output
ob_start();

// CORS headers - Allow from any localhost origin for development AND production
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
header('Content-Type: application/json; charset=UTF-8');

// Clear any output before this point
ob_end_clean();

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Load dependencies with error handling
try {
    require_once __DIR__ . '/../db_connection.php';
} catch (Exception $e) {
    error_log("db_connection error: " . $e->getMessage());
    echo json_encode(['status' => false, 'message' => 'خطا در اتصال به دیتابیس'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Check if vendor/autoload.php exists
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    error_log("vendor/autoload.php not found");
    echo json_encode(['status' => false, 'message' => 'فایل autoload پیدا نشد'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    require_once __DIR__ . '/../vendor/autoload.php';
} catch (Exception $e) {
    error_log("autoload error: " . $e->getMessage());
    echo json_encode(['status' => false, 'message' => 'خطا در بارگذاری کتابخانه‌ها: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$conn = getPDO();

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن احراز هویت الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit();
}

$token = $matches[1];
$secret_key = 'your-secret-key';

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    
    // تبدیل به آرایه برای دسترسی بهتر
    $decoded_array = (array)$decoded;
    
    error_log("JWT decoded payload: " . json_encode($decoded_array));
    
    // دریافت شناسه کاربر - برای کاربران معمولی uid و برای ادمین‌ها aid
    $userId = $decoded_array['uid'] ?? null;
    $adminId = $decoded_array['aid'] ?? null;
    
    error_log("Extracted userId: " . var_export($userId, true));
    error_log("Extracted adminId: " . var_export($adminId, true));
    
    $user = null;
    $userRole = null;
    
    // اگر ادمین است
    if ($adminId) {
        $stmt = $conn->prepare("SELECT username, mobile FROM admin_users WHERE id = ?");
        $stmt->execute([$adminId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $userRole = 'admin';
        // تبدیل username به name برای سازگاری
        if ($user) {
            $user['name'] = $user['username'];
        }
    }
    // اگر کاربر معمولی است
    elseif ($userId) {
        $stmt = $conn->prepare("SELECT name, mobile, created_at, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // استفاده از role از جدول users
            $userRole = $user['role'] ?? 'user';
            error_log("User role from database: " . $userRole . " for user: " . $userId);
            
            // اگر role وجود نداشت، از جدول admin_users چک کنیم (backward compatibility)
            if (empty($user['role']) && !empty($user['mobile'])) {
                $stmt = $conn->prepare("SELECT id FROM admin_users WHERE mobile = ?");
                $stmt->execute([$user['mobile']]);
                $isAdminCheck = $stmt->fetch();
                
                if ($isAdminCheck) {
                    $userRole = 'admin';
                    error_log("User is admin based on admin_users table");
                    
                    // آپدیت role در جدول users
                    $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
                    $stmt->execute([$userId]);
                }
            }
        } else {
            $userRole = 'user';
            error_log("User not found, setting role as 'user'");
        }
    }
    
    if (!$userId && !$adminId) {
        http_response_code(401);
        echo json_encode([
            'status' => false, 
            'message' => 'شناسه کاربر یافت نشد.',
            'debug' => 'Available keys: ' . json_encode(array_keys($decoded_array))
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    echo json_encode([
        'status' => true,
        'data' => [
            'id' => (int)($userId ?? $adminId),
            'name' => $user['name'],
            'mobile' => $user['mobile'],
            'role' => $userRole,
            'created_at' => $user['created_at'] ?? null
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>