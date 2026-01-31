<?php
// CORS headers - باید در ابتدا باشد - Allow from localhost and production domain
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
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request - MUST BE BEFORE ANY OUTPUT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
require_once __DIR__ . '/../vendor/autoload.php'; // اضافه کردن JWT

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = 'your-secret-key'; // حتما این کلید رو امن نگه دار و بهتره از .env بخونی

$json = file_get_contents('php://input');
$data = json_decode($json);

// Debug logging
error_log('verify_login.php - Received JSON: ' . $json);
error_log('verify_login.php - Decoded data: ' . print_r($data, true));

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

error_log('verify_login.php - Register token: ' . $register_token);
error_log('verify_login.php - OTP code: ' . $otp_code);

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'توکن و کد تایید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // تست اتصال دیتابیس
    error_log('verify_login.php - Database connection test');
    
    // جستجو رکورد درخواست OTP با توکن و کد
    $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ? AND otp_code = ?");
    $stmt->execute([$register_token, $otp_code]);
    $request = $stmt->fetch();
    
    error_log('verify_login.php - Query result: ' . print_r($request, true));

    if (!$request) {
        echo json_encode(['status' => false, 'message' => 'کد تایید اشتباه است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // چک کردن انقضای کد OTP
    if (strtotime($request['otp_expires_at']) < time()) {
        echo json_encode(['status' => false, 'message' => 'کد تایید منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mobile = $request['mobile'];
    $name = $request['name'] ?? '';

    // 🔹 اول چک کنیم ادمین است یا نه
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $admin = $stmt->fetch();

    if ($admin) {
        $adminId = $admin['id'];

        // ساخت payload JWT برای ادمین
        $payload = [
            'iss' => 'http://localhost',
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60),
            'aid' => $adminId,
            'mobile' => $mobile,
            'role' => 'admin'
        ];

        $jwt_token = JWT::encode($payload, $secret_key, 'HS256');

        // حذف درخواست OTP پس از ورود موفق
        $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
        $stmt->execute([$request['id']]);

        echo json_encode([
            'status' => true,
            'is_admin' => true,
            'message' => 'ورود ادمین موفقیت‌آمیز بود.',
            'token' => $jwt_token,
            'aid' => $adminId,
            'admin_panel_url' => '/ds_amin/panel/admin/login_admin.php'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 🔹 اگر ادمین نبود → بررسی کاربر معمولی
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'کاربر با این شماره ثبت‌نام نکرده است. لطفا ابتدا ثبت‌نام کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $user['id'];
    
    // 🔹 شرط اسون: اگر شماره موبایل کاربر با شماره ادمین یکی بود، role را admin کن
    // چک میکنیم ببینیم آیا این شماره در جدول admin_users وجود دارد یا نه
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $isAdminUser = $stmt->fetch();
    
    $userRole = 'user';
    if ($isAdminUser) {
        // اگر شماره با ادمین یکی بود، role را admin کن
        $userRole = 'admin';
        
        // همچنین role را در جدول users هم آپدیت کن برای استفاده‌های بعدی
        $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$userId]);
    }

    // ساخت payload JWT برای کاربر
    $payload = [
        'iss' => 'http://localhost',
        'iat' => time(),
        'exp' => time() + (15 * 24 * 3600), // 15 روز اعتبار
        'uid' => $userId,
        'mobile' => $mobile,
        'role' => $userRole
    ];

    $jwt_token = JWT::encode($payload, $secret_key, 'HS256');

    // حذف درخواست OTP پس از ورود موفق
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    echo json_encode([
        'status' => true,
        'is_admin' => ($userRole === 'admin'),
        'message' => 'ورود موفقیت آمیز بود.',
        'token' => $jwt_token,
        'uid' => $userId,
        'role' => $userRole
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
