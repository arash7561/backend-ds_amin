<?php
/**
 * پیام همگانی: ارسال پیامک به همه کاربران سایت از طریق ملی‌پیامک
 * POST: message
 */

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Melipayamak\MelipayamakApi;

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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$message = isset($input['message']) ? trim($input['message']) : '';

if (empty($message)) {
    echo json_encode(['status' => false, 'message' => 'متن پیام همگانی الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($message) > 500) {
    echo json_encode(['status' => false, 'message' => 'طول پیام حداکثر ۵۰۰ کاراکتر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    $stmt = $conn->query("SELECT id, mobile, name FROM users WHERE mobile IS NOT NULL AND mobile != ''");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = array_filter($users, function ($u) {
        $m = trim($u['mobile'] ?? '');
        return preg_match('/^09\d{9}$/', $m);
    });

    if (empty($users)) {
        echo json_encode(['status' => false, 'message' => 'هیچ شماره موبایل معتبری یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $username = '9128375080';
    $password = '8T05B';
    $from = '50002710065080';

    $api = new MelipayamakApi($username, $password);
    $sms = $api->sms();

    $sent = 0;
    $failed = 0;

    foreach ($users as $user) {
        $mobile = trim($user['mobile']);
        if (!preg_match('/^09\d{9}$/', $mobile)) {
            $failed++;
            continue;
        }
        try {
            $sms->send($mobile, $from, $message);
            $sent++;
        } catch (Exception $e) {
            error_log("Broadcast SMS failed for {$mobile}: " . $e->getMessage());
            $failed++;
        }
    }

    echo json_encode([
        'status' => true,
        'message' => "پیام همگانی ارسال شد. موفق: {$sent}، ناموفق: {$failed}",
        'sent' => $sent,
        'failed' => $failed,
        'total' => count($users),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("SMS broadcast error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در ارسال پیام همگانی: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
