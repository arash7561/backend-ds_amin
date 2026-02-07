<?php
/**
 * لیست فاکتورهای اعتباری بازاریابان - پنل ادمین
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست GET مجاز است'], JSON_UNESCAPED_UNICODE);
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

try {
    $conn = getPDO();

    $statusFilter = $_GET['status'] ?? null;
    $typeFilter = $_GET['type'] ?? 'marketer'; // 'marketer' = بازاریابان، 'regular' = کاربران معمولی
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $sql = "
        SELECT 
            i.id,
            i.marketer_id,
            i.customer_id,
            i.customer_name,
            i.customer_mobile,
            i.order_id,
            i.total_amount,
            i.settlement_date,
            i.description,
            i.status,
            i.admin_note,
            i.reviewed_by,
            i.reviewed_at,
            i.created_at,
            u.name AS marketer_name,
            u.mobile AS marketer_mobile
        FROM marketer_credit_invoices i
        LEFT JOIN users u ON u.id = i.marketer_id
        WHERE 1=1
    ";
    $params = [];

    // فیلتر نوع: بازاریاب (در affiliates) یا کاربر معمولی (خارج از affiliates)
    if ($typeFilter === 'regular') {
        $sql .= " AND i.marketer_id NOT IN (SELECT user_id FROM affiliates)";
    } else {
        $sql .= " AND i.marketer_id IN (SELECT user_id FROM affiliates)";
    }

    if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected', 'settled'])) {
        $sql .= " AND i.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as &$inv) {
        $inv['id'] = (int)$inv['id'];
        $inv['marketer_id'] = (int)$inv['marketer_id'];
        $inv['total_amount'] = (int)$inv['total_amount'];
        try {
            $stmtImg = $conn->prepare("SELECT id, image_path, settlement_date FROM marketer_credit_check_images WHERE invoice_id = ? ORDER BY settlement_date ASC, id ASC");
            $stmtImg->execute([$inv['id']]);
            $inv['check_images'] = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'settlement_date') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                $stmtImg = $conn->prepare("SELECT id, image_path FROM marketer_credit_check_images WHERE invoice_id = ? ORDER BY id ASC");
                $stmtImg->execute([$inv['id']]);
                $rows = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
                $inv['check_images'] = array_map(function ($r) { $r['settlement_date'] = null; return $r; }, $rows);
            } else {
                throw $e;
            }
        }
        $inv['items'] = [];
        try {
            $stmtItems = $conn->prepare("SELECT id, product_id, product_name, price, quantity, total_price FROM marketer_credit_invoice_items WHERE invoice_id = ?");
            $stmtItems->execute([$inv['id']]);
            $inv['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // جدول items ممکن است وجود نداشته باشد
        }
    }
    unset($inv);

    // تعداد کل
    $countParams = [];
    $countSql = "SELECT COUNT(*) AS total FROM marketer_credit_invoices WHERE 1=1";
    if ($typeFilter === 'regular') {
        $countSql .= " AND marketer_id NOT IN (SELECT user_id FROM affiliates)";
    } else {
        $countSql .= " AND marketer_id IN (SELECT user_id FROM affiliates)";
    }
    if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected', 'settled'])) {
        $countSql .= " AND status = ?";
        $countParams[] = $statusFilter;
    }
    $stmtCount = $conn->prepare($countSql);
    $stmtCount->execute($countParams);
    $total = (int)($stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    echo json_encode([
        'status' => true,
        'data' => $invoices,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Credit invoice admin list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده'], JSON_UNESCAPED_UNICODE);
}
