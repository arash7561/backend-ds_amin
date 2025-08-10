<?php
// CORS headers - باید در ابتدا باشد
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once '../db_connection.php';
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

    // چک کردن وجود کاربر با همین شماره
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    if (!$user) {
        // کاربر ثبت‌نام نشده؛ پس پیام مناسب بده
        echo json_encode(['status' => false, 'message' => 'کاربر با این شماره ثبت‌نام نکرده است. لطفا ابتدا ثبت‌نام کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $user['id'];

    // ساخت payload JWT با userId برای احراز هویت دقیق
    $payload = [
        'iss' => 'http://localhost',   // یا دامنه واقعی‌ات
        'iat' => time(),
        'exp' => time() + (24 * 60 * 60), // 24 ساعت اعتبار
        'uid' => $userId,
        'mobile' => $mobile
    ];

    // تولید JWT با کلید مخفی و الگوریتم HS256
    $jwt_token = JWT::encode($payload, $secret_key, 'HS256');

    // حذف درخواست OTP پس از ورود موفق
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    echo json_encode([
        'status' => true,
        'message' => 'ورود موفقیت آمیز بود.',
        'token' => $jwt_token,
        'uid' => $userId
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
