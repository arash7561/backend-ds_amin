<?php
/**
 * تایید یا رد فاکتور اعتباری توسط ادمین
 * POST: { "invoice_id": number, "action": "approve"|"reject", "admin_note": string }
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
    $adminId = $user['aid'] ?? $user['id'] ?? $user['uid'] ?? null;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true) ?? [];

$invoiceId = $data['invoice_id'] ?? $data['id'] ?? null;
$action = $data['action'] ?? ''; // approve | reject
$adminNote = $data['admin_note'] ?? '';

if (!$invoiceId || !is_numeric($invoiceId)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه فاکتور معتبر نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'action باید approve یا reject باشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoiceId = (int)$invoiceId;
$newStatus = $action === 'approve' ? 'approved' : 'rejected';

try {
    $conn = getPDO();

    $stmt = $conn->prepare("
        SELECT id, status, customer_id, marketer_id, total_amount, order_id
        FROM marketer_credit_invoices
        WHERE id = ?
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'فاکتور یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($invoice['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'این فاکتور قبلاً بررسی شده است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orderId = null;
    $orderCreated = false;

    if ($action === 'approve') {
        $customerId = !empty($invoice['customer_id']) ? (int)$invoice['customer_id'] : null;

        if ($customerId) {
            $conn->beginTransaction();

            try {
                // دریافت اولین روش ارسال به عنوان پیش‌فرض
                $shippingId = null;
                $shippingStmt = $conn->query("SELECT id FROM shippings WHERE 1 LIMIT 1");
                if ($shippingStmt && $row = $shippingStmt->fetch(PDO::FETCH_ASSOC)) {
                    $shippingId = (int)$row['id'];
                }

                // ایجاد سفارش
                $stmtOrder = $conn->prepare("
                    INSERT INTO orders (user_id, status, shipping_id)
                    VALUES (?, 'pending', ?)
                ");
                $stmtOrder->execute([$customerId, $shippingId]);
                $orderId = (int)$conn->lastInsertId();

                // دریافت آیتم‌های فاکتور
                $items = [];
                try {
                    $stmtItems = $conn->prepare("
                        SELECT product_id, product_name, price, quantity, total_price
                        FROM marketer_credit_invoice_items
                        WHERE invoice_id = ?
                    ");
                    $stmtItems->execute([$invoiceId]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // جدول items ممکن است وجود نداشته باشد
                }

                if (!empty($items)) {
                    $stmtOrderItem = $conn->prepare("
                        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, total_price)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($items as $it) {
                        $stmtOrderItem->execute([
                            $orderId,
                            (int)($it['product_id'] ?? 0),
                            $it['product_name'] ?? '',
                            (int)($it['price'] ?? 0),
                            (int)($it['quantity'] ?? 1),
                            (int)($it['total_price'] ?? 0),
                        ]);
                    }
                } else {
                    // اگر آیتمی نبود، یک آیتم کلی با مبلغ کل
                    $totalAmount = (int)$invoice['total_amount'];
                    $stmtOrderItem = $conn->prepare("
                        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, total_price)
                        VALUES (?, 0, ?, ?, 1, ?)
                    ");
                    $stmtOrderItem->execute([$orderId, 'سفارش اعتباری (فاکتور #' . $invoiceId . ')', $totalAmount, $totalAmount]);
                }

                // بروزرسانی فاکتور با order_id
                $stmtUpdate = $conn->prepare("
                    UPDATE marketer_credit_invoices
                    SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW(), order_id = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$newStatus, $adminNote, $adminId, $orderId, $invoiceId]);

                $conn->commit();
                $orderCreated = true;
            } catch (Exception $e) {
                $conn->rollBack();
                error_log("Credit invoice create order error: " . $e->getMessage());
                throw $e;
            }
        } else {
            // مشتری ثبت‌نام نکرده - فقط تایید می‌کنیم، سفارش ایجاد نمی‌شود
            $stmt = $conn->prepare("
                UPDATE marketer_credit_invoices
                SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $adminNote, $adminId, $invoiceId]);
        }
    } else {
        // رد فاکتور
        $stmt = $conn->prepare("
            UPDATE marketer_credit_invoices
            SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $adminNote, $adminId, $invoiceId]);
    }

    $message = $action === 'approve' ? 'فاکتور تایید شد' : 'فاکتور رد شد';
    if ($action === 'approve' && $orderCreated) {
        $message .= ' و سفارش #' . $orderId . ' ایجاد شد';
    } elseif ($action === 'approve' && !$orderCreated && !$invoice['customer_id']) {
        $message .= ' (سفارش ایجاد نشد - مشتری در سیستم ثبت‌نام نکرده است)';
    }

    http_response_code(200);
    echo json_encode([
        'status' => true,
        'message' => $message,
        'invoice_id' => $invoiceId,
        'new_status' => $newStatus,
        'order_id' => $orderId,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Credit invoice review error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده'], JSON_UNESCAPED_UNICODE);
}
