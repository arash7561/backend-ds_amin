<?php
/**
 * تسویه فاکتور اعتباری - انتقال به بایگانی
 * POST: { "invoice_id": number }
 */

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
    $user = checkJWT();
    if (!isset($user['aid']) && (!isset($user['role']) || $user['role'] !== 'admin')) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;

if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه فاکتور نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    $stmt = $conn->prepare("SELECT id, status FROM marketer_credit_invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'فاکتور یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($inv['status'] !== 'approved') {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'فقط فاکتورهای تایید شده قابل تسویه هستند'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $update = $conn->prepare("UPDATE marketer_credit_invoices SET status = 'settled' WHERE id = ?");
        $update->execute([$invoiceId]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "settled") !== false || strpos($e->getMessage(), "enum") !== false) {
            http_response_code(500);
            echo json_encode([
                'status' => false,
                'message' => 'وضعیت تسویه در دیتابیس تعریف نشده. لطفاً migration مربوط به add_settled_status.sql را اجرا کنید.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

    echo json_encode([
        'status' => true,
        'message' => 'فاکتور با موفقیت تسویه شد و به بایگانی منتقل گردید',
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Credit invoice settle error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده'], JSON_UNESCAPED_UNICODE);
}
