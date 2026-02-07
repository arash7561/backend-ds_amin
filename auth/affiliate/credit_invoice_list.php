<?php
/**
 * لیست فاکتورهای اعتباری بازاریاب (فقط فاکتورهای خودش)
 */

$allowed_origins = [
    'http://localhost:3000', 'http://localhost:3001', 'http://localhost:3002',
    'http://127.0.0.1:3000', 'http://127.0.0.1:3001', 'http://127.0.0.1:3002',
    'https://aminindpharm.ir', 'http://aminindpharm.ir'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins) || strpos($origin, 'localhost') !== false || strpos($origin, 'aminindpharm.ir') !== false) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
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
require_once __DIR__ . '/../jwt_utils.php';

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        return $headers;
    }
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$userId = null;

if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $authResult = verify_jwt_token($matches[1]);
    if ($authResult['valid']) {
        $userId = $authResult['uid'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    $stmt = $conn->prepare("SELECT id, is_marketer FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isMarketer = (int)($user['is_marketer'] ?? 0);
    if ($isMarketer !== 1) {
        $stmt = $conn->prepare("SELECT id FROM affiliates WHERE user_id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['status' => false, 'message' => 'شما بازاریاب نیستید'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $statusFilter = $_GET['status'] ?? null; // pending, approved, rejected
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $sql = "
        SELECT 
            i.id,
            i.customer_id,
            i.customer_name,
            i.customer_mobile,
            i.order_id,
            i.total_amount,
            i.settlement_date,
            i.description,
            i.status,
            i.admin_note,
            i.reviewed_at,
            i.created_at
        FROM marketer_credit_invoices i
        WHERE i.marketer_id = ?
    ";
    $params = [$userId];

    if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
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

    echo json_encode([
        'status' => true,
        'data' => $invoices,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Credit invoice list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده'], JSON_UNESCAPED_UNICODE);
}
