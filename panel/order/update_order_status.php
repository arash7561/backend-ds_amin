<?php
/**
 * به‌روزرسانی وضعیت سفارش توسط ادمین
 * POST: { "order_id": number, "status": "pending"|"paid"|"shipped"|"completed"|"cancelled", "tracking_code": string (optional) }
 */

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/orders.php'; // برای استفاده از تابع ارسال پیامک

// بررسی اینکه آیا تابع تعریف شده است
if (!function_exists('sendShippingTrackingSMS')) {
    error_log("Update Order Status - Warning: sendShippingTrackingSMS function not found after requiring orders.php");
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedStatuses = ['pending', 'paid', 'shipped', 'completed', 'cancelled'];

$input = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['order_id']) ? (int) $input['order_id'] : 0;
$status = isset($input['status']) ? trim($input['status']) : '';
$trackingCode = isset($input['tracking_code']) ? trim($input['tracking_code']) : null;

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه سفارش نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'وضعیت نامعتبر است. مقادیر مجاز: ' . implode(', ', $allowedStatuses)], JSON_UNESCAPED_UNICODE);
    exit;
}

// اگر وضعیت shipped است و tracking_code ارسال نشده، خطا بده
if ($status === 'shipped' && empty($trackingCode)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'برای وضعیت "ارسال شد" کد رهگیری الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();
    
    // بررسی وجود فیلد tracking_code در جدول orders
    $stmt = $conn->query("SHOW COLUMNS FROM orders LIKE 'tracking_code'");
    $hasTrackingCodeField = $stmt->fetch();
    
    if (!$hasTrackingCodeField) {
        // ایجاد فیلد tracking_code
        try {
            $conn->exec("ALTER TABLE orders ADD COLUMN tracking_code VARCHAR(100) NULL AFTER status");
            error_log("Field tracking_code added to orders table");
        } catch (PDOException $e) {
            error_log("Error adding tracking_code field: " . $e->getMessage());
        }
    }
    
    // به‌روزرسانی وضعیت و کد رهگیری
    if ($trackingCode && $hasTrackingCodeField) {
        $stmt = $conn->prepare("UPDATE orders SET status = ?, tracking_code = ? WHERE id = ?");
        $stmt->execute([$status, $trackingCode, $orderId]);
    } else {
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $orderId]);
    }

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'سفارش یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اگر وضعیت shipped است و tracking_code وجود دارد، پیامک ارسال کن
    if ($status === 'shipped' && $trackingCode) {
        error_log("Update Order Status - Attempting to send tracking SMS for order #$orderId with tracking code: $trackingCode");
        try {
            if (function_exists('sendShippingTrackingSMS')) {
                error_log("Update Order Status - Function sendShippingTrackingSMS exists, calling it...");
                $smsResult = sendShippingTrackingSMS($orderId, $trackingCode, $conn);
                error_log("Update Order Status - SMS send result: " . ($smsResult ? 'SUCCESS' : 'FAILED'));
            } else {
                error_log("Update Order Status - Function sendShippingTrackingSMS does NOT exist!");
            }
        } catch (Exception $e) {
            error_log("Update Order Status - Exception sending tracking SMS for order #$orderId: " . $e->getMessage());
            error_log("Update Order Status - Exception trace: " . $e->getTraceAsString());
            // خطا را لاگ می‌کنیم اما پاسخ موفقیت‌آمیز برمی‌گردانیم
        }
    } else {
        error_log("Update Order Status - Skipping SMS: status=$status, trackingCode=" . ($trackingCode ?: 'NULL'));
    }

    echo json_encode(['status' => true, 'message' => 'وضعیت سفارش به‌روزرسانی شد'], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("update_order_status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور'], JSON_UNESCAPED_UNICODE);
}
