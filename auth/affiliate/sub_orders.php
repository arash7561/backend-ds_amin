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
require_once __DIR__ . '/../jwt_utils.php';

// Fallback برای getallheaders() در WAMP
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

// بررسی JWT
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$userId = null;

if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    $authResult = verify_jwt_token($jwt);
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

    // بررسی اینکه آیا کاربر بازاریاب است
    $stmt = $conn->prepare("SELECT id, user_id, code FROM affiliates WHERE user_id = ?");
    $stmt->execute([$userId]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'شما بازاریاب نیستید'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $affiliateId = $affiliate['id']; // id از جدول affiliates
    $affiliateUserId = $affiliate['user_id']; // user_id از جدول affiliates

    // دریافت user_id برای فیلتر (اختیاری)
    $subUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

    // بررسی وجود ستون‌های affiliate_id و parent_affiliate_id در جدول users
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'affiliate_id'");
    $hasAffiliateIdColumn = $stmt->fetch();
    
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'parent_affiliate_id'");
    $hasParentAffiliateIdColumn = $stmt->fetch();

    // بررسی وجود ستون payment_ref_id در جدول orders
    $stmt = $conn->query("SHOW COLUMNS FROM orders LIKE 'payment_ref_id'");
    $hasPaymentRefIdColumn = $stmt->fetch();

    // دریافت فاکتورهای کاربران زیرمجموعه
    $orders = [];
    
    // اول سعی می‌کنیم با affiliate_id (که باید user_id از affiliates باشد - مثل subsets.php)
    if ($hasAffiliateIdColumn) {
        // در verify_otp.php، affiliate_id در users table، user_id از affiliates table را ذخیره می‌کند
        // پس باید از affiliateUserId استفاده کنیم
        error_log("Sub Orders - Trying affiliate_id column with affiliateUserId: " . $affiliateUserId);
        
        if ($subUserId) {
            // فاکتورهای یک کاربر خاص
            error_log("Sub Orders - Filtering by specific sub user: " . $subUserId);
            
            // ساخت کوئری بر اساس وجود ستون payment_ref_id
            $paymentRefIdField = $hasPaymentRefIdColumn ? "o.payment_ref_id," : "";
            
            $query = "
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    $paymentRefIdField
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.affiliate_id = ? AND o.user_id = ? AND o.status != 'cancelled'
                ORDER BY o.created_at DESC
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute([$affiliateUserId, $subUserId]);
        } else {
            // تمام فاکتورهای کاربران زیرمجموعه
            error_log("Sub Orders - Fetching all sub user orders");
            
            $paymentRefIdField = $hasPaymentRefIdColumn ? "o.payment_ref_id," : "";
            
            $query = "
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    $paymentRefIdField
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.affiliate_id = ? AND o.status != 'cancelled'
                ORDER BY o.created_at DESC
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute([$affiliateUserId]);
        }
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Sub Orders - Found " . count($orders) . " orders with affiliate_id = " . $affiliateUserId);
    }
    
    // اگر هنوز چیزی پیدا نشد، بررسی parent_affiliate_id
    if (count($orders) === 0 && $hasParentAffiliateIdColumn) {
        error_log("Sub Orders - No results with affiliate_id, trying parent_affiliate_id column with affiliateUserId: " . $affiliateUserId);
        if ($subUserId) {
            // فاکتورهای یک کاربر خاص
            error_log("Sub Orders - Filtering by specific sub user (parent_affiliate_id): " . $subUserId);
            
            $paymentRefIdField = $hasPaymentRefIdColumn ? "o.payment_ref_id," : "";
            
            $query = "
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    $paymentRefIdField
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.parent_affiliate_id = ? AND o.user_id = ? AND o.status != 'cancelled'
                ORDER BY o.created_at DESC
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute([$affiliateUserId, $subUserId]);
        } else {
            // تمام فاکتورهای کاربران زیرمجموعه
            error_log("Sub Orders - Fetching all sub user orders (parent_affiliate_id)");
            
            $paymentRefIdField = $hasPaymentRefIdColumn ? "o.payment_ref_id," : "";
            
            $query = "
                SELECT 
                    o.id AS order_id,
                    o.user_id,
                    u.name AS user_name,
                    u.mobile AS user_mobile,
                    o.status,
                    o.created_at,
                    $paymentRefIdField
                    s.title AS shipping_method
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN shippings s ON s.id = o.shipping_id
                WHERE u.parent_affiliate_id = ? AND o.status != 'cancelled'
                ORDER BY o.created_at DESC
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute([$affiliateUserId]);
        }
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Sub Orders - Found " . count($orders) . " orders with parent_affiliate_id = " . $affiliateUserId);
    }

    // محاسبه مبلغ کل برای هر سفارش
    error_log("Sub Orders - Calculating total price for " . count($orders) . " orders");
    $totalAmount = 0;
    foreach ($orders as &$order) {
        $orderId = $order['order_id'];
        $totalPrice = 0;
        $paymentRefId = null;
        
        // دریافت مبلغ کل از payments (اولویت اول)
        $paymentStmt = $conn->prepare("SELECT amount, ref_id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $paymentStmt->execute([$orderId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment && !empty($payment['amount'])) {
            $totalPrice = (float)$payment['amount'];
            $paymentRefId = $payment['ref_id'] ?? null;
        } else {
            // Fallback: محاسبه از order_items
            $itemStmt = $conn->prepare("
                SELECT oi.quantity, p.price, p.discount_price 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ?
            ");
            $itemStmt->execute([$orderId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
                         ? (float)$item['discount_price'] 
                         : (float)$item['price'];
                $totalPrice += $price * (int)$item['quantity'];
            }
        }
        
        $order['total_price'] = $totalPrice;
        $order['payment_ref_id'] = $paymentRefId ?? $order['payment_ref_id'] ?? null;
        $totalAmount += $totalPrice;
    }
    unset($order);

    error_log("Sub Orders - Returning " . count($orders) . " orders with total amount: " . $totalAmount);

    echo json_encode([
        'status' => true,
        'orders' => $orders,
        'total_orders' => count($orders),
        'total_amount' => $totalAmount
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Sub orders PDO error: " . $e->getMessage());
    error_log("Sub orders PDO error code: " . $e->getCode());
    error_log("Sub orders PDO error info: " . json_encode($e->errorInfo ?? []));
    http_response_code(500);
    echo json_encode([
        'status' => false, 
        'message' => 'خطای سرور: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'error_info' => $e->errorInfo ?? []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Sub orders general error: " . $e->getMessage());
    error_log("Sub orders general error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => false, 
        'message' => 'خطای غیرمنتظره: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
