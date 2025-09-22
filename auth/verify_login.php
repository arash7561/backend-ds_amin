<?php
// CORS headers - باید در ابتدا باشد
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once '../db_connection.php';
$conn = getPDO();
require_once '../vendor/autoload.php'; // اضافه کردن JWT

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$secret_key = 'your-secret-key'; // حتما این کلید رو امن نگه دار و بهتره از .env بخونی

$json = file_get_contents('php://input');
$data = json_decode($json);

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'توکن و کد تایید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جستجو رکورد درخواست OTP با توکن و کد
    $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ? AND otp_code = ?");
    $stmt->execute([$register_token, $otp_code]);
    $request = $stmt->fetch();

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
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'کاربر با این شماره ثبت‌نام نکرده است. لطفا ابتدا ثبت‌نام کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $user['id'];

    // ساخت payload JWT برای کاربر
    $payload = [
        'iss' => 'http://localhost',
        'iat' => time(),
        'exp' => time() + (24 * 60 * 60),
        'uid' => $userId,
        'mobile' => $mobile,
        'role' => 'user'
    ];

    $jwt_token = JWT::encode($payload, $secret_key, 'HS256');

    // حذف درخواست OTP پس از ورود موفق
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    echo json_encode([
        'status' => true,
        'is_admin' => false,
        'message' => 'ورود موفقیت آمیز بود.',
        'token' => $jwt_token,
        'uid' => $userId
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
