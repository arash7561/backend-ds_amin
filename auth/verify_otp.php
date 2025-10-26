<?php
// CORS headers - باید در ابتدا باشد
header('Access-Control-Allow-Origin: http://localhost:3002');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
require_once '../vendor/autoload.php'; // برای JWT

use Firebase\JWT\JWT;

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$secret_key = 'your-secret-key'; // پیشنهاد میشه از env بخونی

$json = file_get_contents('php://input');
$data = json_decode($json);

// Debug logging
error_log('Received JSON: ' . $json);
error_log('Decoded data: ' . print_r($data, true));

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

error_log('Register token: ' . $register_token);
error_log('OTP code: ' . $otp_code);

// بررسی اینکه آیا JSON به درستی parse شده یا نه
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['status' => false, 'message' => 'خطا در پردازش درخواست: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'کد تایید الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جستجو رکورد درخواست OTP با توکن و کد
    $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ? AND otp_code = ?");
    $stmt->execute([$register_token, $otp_code]);
    $request = $stmt->fetch();

    error_log('Query result: ' . print_r($request, true));

    if (!$request) {
        // بررسی اینکه آیا توکن وجود دارد یا نه
        $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ?");
        $stmt->execute([$register_token]);
        $tokenExists = $stmt->fetch();
        
        if ($tokenExists) {
            error_log('Token exists but OTP code is wrong. Expected: ' . $tokenExists['otp_code'] . ', Received: ' . $otp_code);
            echo json_encode(['status' => false, 'message' => 'کد تایید اشتباه است.'], JSON_UNESCAPED_UNICODE);
        } else {
            error_log('Token does not exist: ' . $register_token);
            echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // چک کردن انقضای کد OTP
    if (strtotime($request['otp_expires_at']) < time()) {
        echo json_encode(['status' => false, 'message' => 'کد تایید منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mobile = $request['mobile'];
    $name = $request['name'];
    $guestToken = $request['guest_token'] ?? null;

    // چک کردن وجود کاربر با همین شماره
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        echo json_encode(['status' => false, 'message' => 'این شماره موبایل قبلا ثبت شده است. لطفا وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ثبت نهایی کاربر جدید
    $stmt = $conn->prepare("INSERT INTO users (name, mobile, is_verified, created_at) VALUES (?, ?, 1, NOW())");
    $stmt->execute([$name, $mobile]);
    $message = 'ثبت نام با موفقیت انجام شد.';

    // گرفتن user_id جدید
    $userId = $conn->lastInsertId();

    // ✅ انتقال سبد مهمان (اگر وجود دارد)
    if (!empty($guestToken)) {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
        $stmt->execute([$guestToken]);
        $guestCart = $stmt->fetch();

        if ($guestCart) {
            $stmt = $conn->prepare("UPDATE carts SET user_id = ?, guest_token = NULL WHERE id = ?");
            $stmt->execute([$userId, $guestCart['id']]);
        }
    }

    // تولید توکن JWT
    $payload = [
        'iss' => 'http://localhost',   // یا آدرس دامنه‌ی خودت
        'iat' => time(),
        'exp' => time() + (15 * 24 * 3600), // 15 روز اعتبار
        'uid' => $userId,
        'mobile' => $mobile
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    // حذف درخواست OTP
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    // پاسخ نهایی
    echo json_encode([
        'status' => true,
        'message' => 'ثبت نام با موفقیت انجام شد.',
        'token' => $jwt,
        'uid' => $userId
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
