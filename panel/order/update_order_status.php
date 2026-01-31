<?php
/**
 * به‌روزرسانی وضعیت سفارش توسط ادمین
 * POST: { "order_id": number, "status": "pending"|"paid"|"shipped"|"completed"|"cancelled" }
 */

require_once __DIR__ . '/../../db_connection.php';

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

try {
    $conn = getPDO();
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $orderId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'سفارش یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['status' => true, 'message' => 'وضعیت سفارش به‌روزرسانی شد'], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("update_order_status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور'], JSON_UNESCAPED_UNICODE);
}
