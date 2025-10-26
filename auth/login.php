<?php
// CORS headers - باید در ابتدا باشد
header('Access-Control-Allow-Origin: http://localhost:3002');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Melipayamak\MelipayamakApi; // اضافه برای ملی‌پیامک

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);

$mobile = trim($data->mobile ?? '');
$guestToken = trim($data->guest_token ?? ''); // ✅ دریافت guest_token

if (empty($mobile) || !preg_match('/^09\d{9}$/', $mobile)) {
    echo json_encode(['status' => false, 'message' => 'شماره موبایل نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // حذف رکوردهای خیلی قدیمی‌تر از 5 دقیقه (تمیزکاری)
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE created_at < NOW() - INTERVAL 5 MINUTE");
    $stmt->execute();

    // چک اینکه شماره متعلق به ادمین است یا نه
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        $stmt = $conn->prepare("DELETE FROM otp_requests WHERE mobile = ?");
        $stmt->execute([$mobile]);

        $otp_code = random_int(100000, 999999);
        $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $register_token = bin2hex(random_bytes(16));

        $stmt = $conn->prepare("INSERT INTO otp_requests (mobile, otp_code, otp_expires_at, register_token, created_at) 
                                VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$mobile, $otp_code, $otp_expires_at, $register_token]);

        // ارسال پیامک با ملی پیامک
        $username = '9128375080';
        $password = '8T05B';
        $from = '50002710065080';
        $text = "کد تایید ادمین: $otp_code";

        try {
            $api = new MelipayamakApi($username, $password);
            $sms = $api->sms();
            $sms->send($mobile, $from, $text);
        } catch (Exception $e) {
            error_log("SMS Error: " . $e->getMessage());
        }

        echo json_encode([
            'status' => true,
            'is_admin' => true,
            'message' => 'کد تایید به شماره ادمین ارسال شد.',
            'register_token' => $register_token
            // 'otp' => $otp_code // فقط برای تست
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'شماره موبایل ثبت نشده است. ابتدا ثبت‌نام کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $user['id'];

    if (!empty($guestToken)) {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
        $stmt->execute([$guestToken]);
        $guestCart = $stmt->fetch();

        if ($guestCart) {
            $stmt = $conn->prepare("UPDATE carts SET user_id = ?, guest_token = NULL WHERE id = ?");
            $stmt->execute([$userId, $guestCart['id']]);
        }
    }

    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE mobile = ?");
    $stmt->execute([$mobile]);

    $otp_code = random_int(100000, 999999);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $register_token = bin2hex(random_bytes(16));

    $stmt = $conn->prepare("SELECT name FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userInfo['name'] ?? '';

    $stmt = $conn->prepare("INSERT INTO otp_requests (name, mobile, otp_code, otp_expires_at, register_token, created_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$userName, $mobile, $otp_code, $otp_expires_at, $register_token]);

    // ارسال پیامک با ملی پیامک
    $username = '9128375080';
    $password = '8T05B';
    $from = '50002710065080';
    $text = " $userName عزیز کد تایید شما برای ورود به داروخانه صنعتی امین  :  $otp_code";

    try {
        $api = new MelipayamakApi($username, $password);
        $sms = $api->sms();
        $sms->send($mobile, $from, $text);
    } catch (Exception $e) {
        error_log("SMS Error: " . $e->getMessage());
    }

    echo json_encode([
        'status' => true,
        'is_admin' => false,
        'message' => 'کد تایید ارسال شد.',
        'register_token' => $register_token
        // 'otp' => $otp_code // فقط برای تست
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'status' => false,
        'message' => 'خطای سرور: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
