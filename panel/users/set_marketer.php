<?php
// پنل ادمین - تبدیل کاربر به بازاریاب

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../admin/middleware.php';

try {
    $conn = getPDO();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطا در اتصال به پایگاه داده'], JSON_UNESCAPED_UNICODE);
    exit;
}

// احراز هویت ادمین
$decoded = checkJWT();
$role = $decoded['role'] ?? ($decoded['role_id'] ?? null);
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
error_log("set_marketer.php - Raw input: " . $rawInput);

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("set_marketer.php - JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'خطا در پردازش درخواست: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

error_log("set_marketer.php - Decoded input: " . json_encode($input, JSON_UNESCAPED_UNICODE));

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
// پشتیبانی از هر دو نام: invite_code و marketer_code
$customInviteCode = isset($input['marketer_code']) ? trim($input['marketer_code']) : (isset($input['invite_code']) ? trim($input['invite_code']) : '');

error_log("set_marketer.php - User ID: " . $userId . ", Marketer Code: " . $customInviteCode);

if ($userId <= 0) {
    error_log("set_marketer.php - Invalid user_id: " . $userId);
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه کاربر نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

// اگر کد بازاریاب ارسال شده، باید حداقل 3 کاراکتر باشد
// اگر خالی باشد، بعداً به صورت خودکار تولید می‌شود
if ($customInviteCode !== '' && strlen($customInviteCode) < 3) {
    error_log("set_marketer.php - Marketer code too short: " . $customInviteCode);
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'کد بازاریاب باید حداقل 3 کاراکتر باشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود کاربر
    $stmt = $conn->prepare("SELECT id, name, mobile, invite_code, is_marketer FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اگر قبلاً بازاریاب است، فقط اطلاعات فعلی را برگردان
    if ((int)$user['is_marketer'] === 1 && !empty($user['invite_code'])) {
        echo json_encode([
            'status' => true,
            'message' => 'این کاربر قبلاً به عنوان بازاریاب ثبت شده است',
            'data' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'mobile' => $user['mobile'],
                'invite_code' => $user['invite_code'],
                'is_marketer' => 1,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تولید یا استفاده از کد بازاریاب
    if ($customInviteCode !== '') {
        $inviteCode = strtoupper($customInviteCode);
        
        // بررسی یکتایی کد
        $check = $conn->prepare("SELECT id FROM users WHERE invite_code = ? AND id != ?");
        $check->execute([$inviteCode, $userId]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($exists) {
            http_response_code(400);
            echo json_encode(['status' => false, 'message' => 'این کد بازاریاب قبلاً استفاده شده است'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif (!empty($user['invite_code'])) {
        $inviteCode = $user['invite_code'];
    } else {
        // تولید کد جدید شبیه create-invite-code.php
        $generateInviteCode = function () {
            return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        };

        $maxAttempts = 10;
        $attempts = 0;
        do {
            $inviteCode = $generateInviteCode();
            $check = $conn->prepare("SELECT id FROM users WHERE invite_code = ?");
            $check->execute([$inviteCode]);
            $exists = $check->fetch(PDO::FETCH_ASSOC);
            $attempts++;
            if ($attempts >= $maxAttempts) {
                throw new Exception('نمی‌توان کد بازاریاب یکتا تولید کرد');
            }
        } while ($exists);
    }

    // به‌روزرسانی کاربر
    $stmt = $conn->prepare("UPDATE users SET is_marketer = 1, invite_code = ? WHERE id = ?");
    $stmt->execute([$inviteCode, $userId]);

    // درج در جدول affiliates برای فعال‌سازی کامل سیستم بازاریابی
    try {
        $stmtAff = $conn->prepare("SELECT id FROM affiliates WHERE user_id = ?");
        $stmtAff->execute([$userId]);
        if (!$stmtAff->fetch()) {
            $stmtIns = $conn->prepare("INSERT INTO affiliates (user_id, code, created_at) VALUES (?, ?, NOW())");
            $stmtIns->execute([$userId, $inviteCode]);
        }
    } catch (PDOException $e) {
        error_log("set_marketer.php - affiliates insert (optional): " . $e->getMessage());
        // ادامه می‌دهیم - users به‌روز شده است
    }

    echo json_encode([
        'status' => true,
        'message' => 'کاربر با موفقیت به بازاریاب تبدیل شد',
        'data' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'mobile' => $user['mobile'],
            'invite_code' => $inviteCode,
            'is_marketer' => 1,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

