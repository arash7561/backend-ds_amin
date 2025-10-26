<?php

// CORS headers
header('Access-Control-Allow-Origin: http://localhost:3002');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();

require_once __DIR__ . '/../vendor/autoload.php'; // اضافه برای لود پکیج ملی‌پیامک
use Melipayamak\MelipayamakApi;

$json = file_get_contents('php://input');
$data = json_decode($json);

$name   = trim($data->username ?? '');
$mobile = trim($data->mobile ?? '');
$guest_token = trim($data->guest_token ?? '');
$response = [];

// بررسی اولیه
if (empty($name)) {
    echo json_encode(['status' => false, 'message' => 'نام کاربری الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($mobile) || !preg_match('/^09\d{9}$/', $mobile)) {
    echo json_encode(['status' => false, 'message' => 'شماره موبایل نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // آیا قبلاً کاربر ثبت‌نام شده؟
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'این شماره قبلاً ثبت‌نام شده. لطفاً وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف OTP قبلی برای این شماره (اگر وجود دارد)
    $conn->prepare("DELETE FROM otp_requests WHERE mobile = ?")->execute([$mobile]);

    // ساخت OTP و توکن ثبت‌نام موقت
    $otp_code = rand(100000, 999999);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $register_token = bin2hex(random_bytes(32));

    // درج اطلاعات در جدول موقت otp_requests
    $stmt = $conn->prepare("INSERT INTO otp_requests (name, mobile, otp_code, otp_expires_at, register_token, guest_token) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $mobile, $otp_code, $otp_expires_at, $register_token, $guest_token]);

    // ✅ ارسال پیامک با ملی پیامک
    $username = '9128375080'; // نام کاربری پنل ملی پیامک
    $password = '8T05B'; // رمز عبور پنل ملی پیامک
    $from = '50002710065080';       // شماره اختصاصی ارسال‌کننده (مثلاً 5000...)
    $text = " $name عزیز کد تایید شما برای ثبت نام در داروخانه صنعتی امین  :  $otp_code";

    try {
        $api = new MelipayamakApi($username, $password);
        $sms = $api->sms();
        $sms->send($mobile, $from, $text);
    } catch (Exception $e) {
        // خطای ارسال SMS اختیاری است و ثبت‌نام را متوقف نمی‌کند
        error_log("SMS Error: " . $e->getMessage());
    }

    echo json_encode([
        'status' => true,
        'message' => 'کد تایید ارسال شد.',
        'register_token' => $register_token,
        // 'otp' => $otp_code // فقط برای تست
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
