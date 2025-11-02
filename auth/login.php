<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering at the very beginning to catch any unexpected output
ob_start();

// CORS headers
header('Access-Control-Allow-Origin: *');

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header("Content-Type: application/json; charset=UTF-8");

// Clear any output before this point
ob_end_clean();

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Test database connection
try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getPDO();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    error_log("Database connection error in login.php: " . $e->getMessage());
    echo json_encode([
        'status' => false,
        'message' => 'خطا در اتصال به دیتابیس: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Test vendor autoload - with output buffering to catch any errors
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    error_log("Autoload file not found: " . __DIR__ . '/../vendor/autoload.php');
    echo json_encode([
        'status' => false,
        'message' => 'فایل autoload پیدا نشد'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load vendor autoload with error handling
try {
    require_once __DIR__ . '/../vendor/autoload.php';
} catch (Exception $e) {
    error_log("Autoload error: " . $e->getMessage());
    echo json_encode([
        'status' => false,
        'message' => 'خطا در بارگذاری کتابخانه‌ها: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Melipayamak\MelipayamakApi; // اضافه برای ملی‌پیامک

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
    $text = " $userName عزیز کد تایید شما   :  $otp_code";

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
