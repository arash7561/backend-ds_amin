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

    // پیدا کردن affiliate مربوط به این کاربر
    $stmt = $conn->prepare("SELECT id, user_id, code FROM affiliates WHERE user_id = ?");
    $stmt->execute([$userId]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'شما بازاریاب نیستید'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $affiliateId = $affiliate['id']; // id از جدول affiliates
    // در verify_otp.php، affiliate_id در جدول users برابر با user_id از جدول affiliates ذخیره می‌شود
    // از آنجایی که ما از WHERE user_id = ? استفاده کردیم، userId فعلی همان user_id از affiliates است
    $affiliateUserId = $affiliate['user_id'] ?? $userId; // user_id از جدول affiliates (اگر null بود، از userId استفاده می‌کنیم)

    // بررسی وجود ستون affiliate_id در جدول users
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'affiliate_id'");
    $hasAffiliateIdColumn = $stmt->fetch();
    
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'parent_affiliate_id'");
    $hasParentAffiliateIdColumn = $stmt->fetch();

    // Debug: بررسی ساختار جدول
    error_log("Subsets - Column check: hasAffiliateIdColumn=" . ($hasAffiliateIdColumn ? "YES" : "NO") . ", hasParentAffiliateIdColumn=" . ($hasParentAffiliateIdColumn ? "YES" : "NO"));
    error_log("Subsets - Affiliate info: affiliateId=" . $affiliateId . ", affiliateUserId=" . ($affiliateUserId ?? "NULL") . ", userId=" . $userId);

    // زیرمجموعه‌های مستقیم با اطلاعات فاکتورها
    $subUsers = [];
    
    if ($hasAffiliateIdColumn) {
        // در verify_otp.php، affiliate_id در جدول users برابر با user_id از جدول affiliates ذخیره می‌شود
        // از آنجایی که ما از WHERE user_id = ? استفاده کردیم، userId فعلی همان user_id از affiliates است
        // پس باید با userId (که همان user_id از affiliates است) جستجو کنیم
        error_log("Subsets - Trying affiliate_id column with userId (which is user_id from affiliates): " . $userId);
        
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.mobile,
                u.created_at,
                COUNT(DISTINCT o.id) AS order_count
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
            WHERE u.affiliate_id = ?
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$userId]);
        $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Subsets - Found " . count($subUsers) . " users with affiliate_id = " . $userId . " (current userId = user_id from affiliates)");
        
        // اگر با userId چیزی پیدا نشد، سعی می‌کنیم با id از affiliates (برای سازگاری با subsets.php قدیمی)
        if (count($subUsers) === 0 && $affiliateId) {
            error_log("Subsets - No results with userId, trying with affiliateId (id from affiliates): " . $affiliateId);
            $stmt = $conn->prepare("
                SELECT 
                    u.id,
                    u.name,
                    u.mobile,
                    u.created_at,
                    COUNT(DISTINCT o.id) AS order_count
                FROM users u
                LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
                WHERE u.affiliate_id = ?
                GROUP BY u.id
                ORDER BY u.created_at DESC
            ");
            $stmt->execute([$affiliateId]);
            $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Subsets - Found " . count($subUsers) . " users with affiliate_id = " . $affiliateId . " (id from affiliates)");
        }
        
        // محاسبه total_orders_amount برای هر کاربر
        foreach ($subUsers as &$user) {
            $totalAmount = 0;
            // دریافت تمام سفارش‌های این کاربر
            $orderStmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND status != 'cancelled'");
            $orderStmt->execute([$user['id']]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as $order) {
                // محاسبه قیمت از payments (اولویت اول)
                $paymentStmt = $conn->prepare("SELECT amount FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
                $paymentStmt->execute([$order['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($payment && !empty($payment['amount'])) {
                    $totalAmount += (float)$payment['amount'];
                } else {
                    // Fallback: محاسبه از order_items
                    $itemStmt = $conn->prepare("
                        SELECT oi.quantity, p.price, p.discount_price 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?
                    ");
                    $itemStmt->execute([$order['id']]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($items as $item) {
                        $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
                                 ? (float)$item['discount_price'] 
                                 : (float)$item['price'];
                        $totalAmount += $price * (int)$item['quantity'];
                    }
                }
            }
            $user['total_orders_amount'] = $totalAmount;
        }
        unset($user);
    } elseif ($hasParentAffiliateIdColumn) {
        // استفاده از parent_affiliate_id که به user_id از جدول affiliates اشاره می‌کند
        error_log("Subsets - Using parent_affiliate_id column with userId: " . $userId);
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.mobile,
                u.created_at,
                COUNT(DISTINCT o.id) AS order_count
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
            WHERE u.parent_affiliate_id = ?
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$userId]);
        $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Subsets - Found " . count($subUsers) . " users with parent_affiliate_id = " . $userId);
        
        // محاسبه total_orders_amount برای هر کاربر
        foreach ($subUsers as &$user) {
            $totalAmount = 0;
            // دریافت تمام سفارش‌های این کاربر
            $orderStmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND status != 'cancelled'");
            $orderStmt->execute([$user['id']]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as $order) {
                // محاسبه قیمت از payments (اولویت اول)
                $paymentStmt = $conn->prepare("SELECT amount FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
                $paymentStmt->execute([$order['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($payment && !empty($payment['amount'])) {
                    $totalAmount += (float)$payment['amount'];
                } else {
                    // Fallback: محاسبه از order_items
                    $itemStmt = $conn->prepare("
                        SELECT oi.quantity, p.price, p.discount_price 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?
                    ");
                    $itemStmt->execute([$order['id']]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($items as $item) {
                        $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
                                 ? (float)$item['discount_price'] 
                                 : (float)$item['price'];
                        $totalAmount += $price * (int)$item['quantity'];
                    }
                }
            }
            $user['total_orders_amount'] = $totalAmount;
        }
        unset($user);
    }

    // اطمینان از اینکه $subUsers یک آرایه است
    if (!is_array($subUsers)) {
        $subUsers = [];
    }

    // Debug log
    error_log("Subsets API - Final result: " . count($subUsers) . " users found");
    if (count($subUsers) > 0) {
        error_log("Subsets API - First user sample: " . json_encode($subUsers[0], JSON_UNESCAPED_UNICODE));
    }
    
    echo json_encode([
        'status' => true,
        'affiliate_code' => $affiliate['code'],
        'count' => count($subUsers),
        'data' => $subUsers,
        'sub_users' => $subUsers, // برای سازگاری با frontend
        'total_sub_users' => count($subUsers),
        'debug' => [
            'affiliate_id' => $affiliateId,
            'affiliate_user_id' => $affiliateUserId,
            'has_affiliate_id_column' => $hasAffiliateIdColumn ? true : false,
            'has_parent_affiliate_id_column' => $hasParentAffiliateIdColumn ? true : false,
            'users_found' => count($subUsers)
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Subsets error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Subsets error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
