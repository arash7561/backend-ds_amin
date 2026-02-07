<?php
// CORS headers
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'https://aminindpharm.ir',
    'http://aminindpharm.ir'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (
    in_array($origin, $allowed_origins) ||
    strpos($origin, 'localhost') !== false ||
    strpos($origin, '127.0.0.1') !== false ||
    strpos($origin, 'aminindpharm.ir') !== false
) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
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

// Load autoload.php from api/vendor directory
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    $autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Fallback for getallheaders() in WAMP
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}

// Function to get Authorization header robustly
if (!function_exists('getAuthorizationHeader')) {
    function getAuthorizationHeader() {
        $headers = [];
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            if (isset($allHeaders['Authorization'])) {
                $headers['Authorization'] = $allHeaders['Authorization'];
            }
        }
        return $headers['Authorization'] ?? '';
    }
}

// بررسی احراز هویت ادمین
$authHeader = getAuthorizationHeader();

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن احراز هویت الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $matches[1];
$secretKey = $_ENV['JWT_SECRET'] ?? 'your-secret-key';

try {
    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
    $user = (array)$decoded;
    
    // بررسی اینکه کاربر ادمین است یا نه
    if (!isset($user['aid']) && (!isset($user['role']) || $user['role'] !== 'admin')) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند فاکتورها را مشاهده کند'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (\Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن منقضی شده است'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (\Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    error_log("JWT decode error in admin_sub_orders.php: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// دریافت affiliate_user_id و sub_user_id از query parameters
$affiliateUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$subUserId = isset($_GET['sub_user_id']) ? (int)$_GET['sub_user_id'] : null;

error_log("Admin sub orders - affiliateUserId: " . ($affiliateUserId ?? "NULL") . ", subUserId: " . ($subUserId ?? "NULL"));

if (!$affiliateUserId) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه بازاریاب الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    // بررسی اینکه آیا این کاربر بازاریاب است
    $stmt = $conn->prepare("SELECT id, user_id, code FROM affiliates WHERE user_id = ?");
    $stmt->execute([$affiliateUserId]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'بازاریاب یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی وجود ستون affiliate_id در جدول users
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'affiliate_id'");
    $hasAffiliateIdColumn = $stmt->fetch();
    
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'parent_affiliate_id'");
    $hasParentAffiliateIdColumn = $stmt->fetch();

    // دریافت فاکتورهای کاربران زیرمجموعه
    $orders = [];
    
    if ($hasAffiliateIdColumn) {
        if ($subUserId) {
            // فاکتورهای یک کاربر خاص
            $stmt = $conn->prepare("
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.affiliate_id = ? AND o.user_id = ?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$affiliateUserId, $subUserId]);
        } else {
            // تمام فاکتورهای کاربران زیرمجموعه
            error_log("Admin sub orders - Fetching all orders for affiliate: " . $affiliateUserId);
            $stmt = $conn->prepare("
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.affiliate_id = ? AND o.status != 'cancelled'
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$affiliateUserId]);
        }
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Admin sub orders - Found " . count($orders) . " orders");
    } elseif ($hasParentAffiliateIdColumn) {
        if ($subUserId) {
            $stmt = $conn->prepare("
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.parent_affiliate_id = ? AND o.user_id = ?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$affiliateUserId, $subUserId]);
        } else {
            error_log("Admin sub orders - Fetching all orders for affiliate (parent_affiliate_id): " . $affiliateUserId);
            $stmt = $conn->prepare("
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.parent_affiliate_id = ? AND o.status != 'cancelled'
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$affiliateUserId]);
        }
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Admin sub orders - Found " . count($orders) . " orders (parent_affiliate_id)");
    }

    // محاسبه مبلغ کل برای هر سفارش
    $totalAmount = 0;
    foreach ($orders as &$order) {
        $orderId = $order['order_id'];
        $totalPrice = 0;
        
        // دریافت مبلغ کل از payments (اولویت اول)
        $paymentStmt = $conn->prepare("SELECT amount, ref_id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $paymentStmt->execute([$orderId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment && !empty($payment['amount'])) {
            $totalPrice = (float)$payment['amount'];
            $order['payment_ref_id'] = $payment['ref_id'] ?? null;
        } else {
            // Fallback 1: جمع total_price از order_items (برای فاکتورهای اعتباری و سفارش‌های بدون پرداخت)
            $sumStmt = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS total FROM order_items WHERE order_id = ?");
            $sumStmt->execute([$orderId]);
            $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC);
            $totalPrice = (float)($sumRow['total'] ?? 0);
            if ($totalPrice <= 0) {
                // Fallback 2: محاسبه از products (در صورت وجود)
                $itemStmt = $conn->prepare("
                    SELECT oi.quantity, COALESCE(p.discount_price, p.price, oi.price) AS unit_price
                    FROM order_items oi 
                    LEFT JOIN products p ON p.id = oi.product_id 
                    WHERE oi.order_id = ?
                ");
                $itemStmt->execute([$orderId]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($items as $item) {
                    $totalPrice += (float)($item['unit_price'] ?? 0) * (int)($item['quantity'] ?? 1);
                }
            }
            if ($totalPrice <= 0) {
                // Fallback 3: برای فاکتور اعتباری، مبلغ از marketer_credit_invoices
                try {
                    $mciStmt = $conn->prepare("SELECT total_amount FROM marketer_credit_invoices WHERE order_id = ?");
                    $mciStmt->execute([$orderId]);
                    $mci = $mciStmt->fetch(PDO::FETCH_ASSOC);
                    if ($mci && !empty($mci['total_amount'])) {
                        $totalPrice = (float)$mci['total_amount'];
                    }
                } catch (PDOException $e) { /* ignore */ }
            }
            $order['payment_ref_id'] = null;
        }
        
        $order['total_price'] = $totalPrice;
        $totalAmount += $totalPrice;

        // بررسی فاکتور اعتباری
        $order['is_credit_invoice'] = false;
        $order['settlement_date'] = null;
        $order['check_images'] = [];
        try {
            $creditStmt = $conn->prepare("
                SELECT mci.id AS credit_invoice_id, mci.settlement_date
                FROM marketer_credit_invoices mci
                WHERE mci.order_id = ?
            ");
            $creditStmt->execute([$orderId]);
            $creditInfo = $creditStmt->fetch(PDO::FETCH_ASSOC);
            if ($creditInfo) {
                $order['is_credit_invoice'] = true;
                $order['settlement_date'] = $creditInfo['settlement_date'] ?? null;
                $checkStmt = $conn->prepare("SELECT id, image_path FROM marketer_credit_check_images WHERE invoice_id = ? ORDER BY id ASC");
                $checkStmt->execute([$creditInfo['credit_invoice_id']]);
                $order['check_images'] = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            // جدول ممکن است وجود نداشته باشد
        }
    }
    unset($order);

    echo json_encode([
        'status' => true,
        'orders' => $orders,
        'total_orders' => count($orders),
        'total_amount' => $totalAmount
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Admin sub orders error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Admin sub orders error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
