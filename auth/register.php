<?php

// CORS headers - Allow from localhost and production domain
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request - MUST BE BEFORE ANY OUTPUT
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
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
$invite_code = trim($data->invite_code ?? ''); // دریافت کد دعوت
$affiliate_code = trim($data->affiliate_code ?? ''); // دریافت کد بازاریاب
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

    // بررسی کد دعوت (اگر ارسال شده باشد)
    $inviter_id = null;
    if (!empty($invite_code)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE invite_code = ?");
        $stmt->execute([strtoupper($invite_code)]);
        $inviter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inviter) {
            echo json_encode(['status' => false, 'message' => 'کد دعوت نامعتبر است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $inviter_id = $inviter['id'];
    }

    // حذف OTP قبلی برای این شماره (اگر وجود دارد)
    $conn->prepare("DELETE FROM otp_requests WHERE mobile = ?")->execute([$mobile]);

    // ساخت OTP و توکن ثبت‌نام موقت
    $otp_code = rand(100000, 999999);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $register_token = bin2hex(random_bytes(32));

    // درج اطلاعات در جدول موقت otp_requests (بدون invite_code چون ستون وجود ندارد)
    $stmt = $conn->prepare("INSERT INTO otp_requests (name, mobile, otp_code, otp_expires_at, register_token, guest_token) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $mobile, $otp_code, $otp_expires_at, $register_token, $guest_token]);
    
    // بررسی کد بازاریاب (اگر ارسال شده باشد)
    $affiliate_id = null;
    if (!empty($affiliate_code)) {
        $stmt = $conn->prepare("SELECT a.id, a.user_id FROM affiliates a WHERE a.code = ?");
        $stmt->execute([strtoupper($affiliate_code)]);
        $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($affiliate) {
            $affiliate_id = $affiliate['user_id'];
            error_log("Affiliate code found: " . $affiliate_code . " for user_id: " . $affiliate_id);
        } else {
            error_log("Invalid affiliate code: " . $affiliate_code);
            // کد بازاریاب نامعتبر است اما ثبت نام را متوقف نمی‌کنیم
        }
    }

    // ذخیره کد دعوت و کد بازاریاب در یک جدول جداگانه
    // ابتدا جدول را ایجاد می‌کنیم (اگر وجود ندارد)
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS invite_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            register_token VARCHAR(64) NOT NULL UNIQUE,
            invite_code VARCHAR(10) NULL,
            inviter_id INT NULL,
            affiliate_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_register_token (register_token)
        )");
        
        // بررسی وجود ستون affiliate_id (اگر جدول از قبل وجود داشت)
        $stmt = $conn->query("SHOW COLUMNS FROM invite_registrations LIKE 'affiliate_id'");
        $hasAffiliateColumn = $stmt->fetch();
        
        if (!$hasAffiliateColumn) {
            // اضافه کردن ستون affiliate_id به جدول invite_registrations
            $conn->exec("ALTER TABLE invite_registrations ADD COLUMN affiliate_id INT NULL AFTER inviter_id");
        }
    } catch (PDOException $e) {
        error_log("Error creating/updating invite_registrations table: " . $e->getMessage());
    }

    // ذخیره کد دعوت و کد بازاریاب (اگر وجود دارند)
    if ((!empty($invite_code) && $inviter_id) || (!empty($affiliate_code) && $affiliate_id)) {
        try {
            // بررسی اینکه آیا رکوردی با این register_token وجود دارد
            $stmt = $conn->prepare("SELECT id FROM invite_registrations WHERE register_token = ?");
            $stmt->execute([$register_token]);
            $existingRecord = $stmt->fetch();
            
            if ($existingRecord) {
                // به‌روزرسانی رکورد موجود
                if (!empty($invite_code) && $inviter_id) {
                    $stmt = $conn->prepare("UPDATE invite_registrations SET invite_code = ?, inviter_id = ? WHERE register_token = ?");
                    $stmt->execute([strtoupper($invite_code), $inviter_id, $register_token]);
                }
                if (!empty($affiliate_code) && $affiliate_id) {
                    $stmt = $conn->prepare("UPDATE invite_registrations SET affiliate_id = ? WHERE register_token = ?");
                    $stmt->execute([$affiliate_id, $register_token]);
                }
            } else {
                // ایجاد رکورد جدید با هر دو کد (اگر وجود دارند)
                if (!empty($invite_code) && $inviter_id && !empty($affiliate_code) && $affiliate_id) {
                    // هر دو کد وجود دارند
                    $stmt = $conn->prepare("INSERT INTO invite_registrations (register_token, invite_code, inviter_id, affiliate_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$register_token, strtoupper($invite_code), $inviter_id, $affiliate_id]);
                } elseif (!empty($invite_code) && $inviter_id) {
                    // فقط کد دعوت وجود دارد
                    $stmt = $conn->prepare("INSERT INTO invite_registrations (register_token, invite_code, inviter_id, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$register_token, strtoupper($invite_code), $inviter_id]);
                } elseif (!empty($affiliate_code) && $affiliate_id) {
                    // فقط کد بازاریاب وجود دارد
                    $stmt = $conn->prepare("INSERT INTO invite_registrations (register_token, affiliate_id, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$register_token, $affiliate_id]);
                }
            }
        } catch (PDOException $e) {
            // اگر خطا رخ داد، فقط لاگ می‌کنیم و ادامه می‌دهیم
            error_log("Error storing invite/affiliate code: " . $e->getMessage());
        }
    }

    // ✅ ارسال پیامک با ملی پیامک
    $username = '9128375080'; // نام کاربری پنل ملی پیامک
    $password = '8T05B'; // رمز عبور پنل ملی پیامک
    $from = '50002710065080';       // شماره اختصاصی ارسال‌کننده (مثلاً 5000...)
    $text = " $name عزیز کد تایید شما   :  $otp_code";
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
