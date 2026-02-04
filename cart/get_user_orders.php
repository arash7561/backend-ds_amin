<?php
/**
 * دریافت لیست سفارش‌های کاربر
 * این API فقط سفارش‌های کاربر لاگین شده را برمی‌گرداند
 */

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../auth/jwt_utils.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'status' => false,
        'message' => 'فقط درخواست GET مجاز است'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();
    
    // Fallback برای getallheaders() در WAMP
    if (!function_exists('getallheaders')) {
        function getallheaders() {
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
            // همچنین Authorization را مستقیماً چک کن
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
            return $headers;
        }
    }
    
    // دریافت توکن از header - فقط چک می‌کنیم که JWT token معتبر است یا نه
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    $userId = null;
    $hasValidToken = false;
    
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
        $authResult = verify_jwt_token($jwt);
        if ($authResult['valid']) {
            $hasValidToken = true;
            $userId = $authResult['uid'];
        }
    }
    
    // اگر token معتبر نیست، خطا می‌دهیم
    if (!$hasValidToken) {
        http_response_code(401);
        echo json_encode([
            'status' => false,
            'message' => 'توکن معتبر نیست. لطفاً دوباره وارد شوید.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // اگر userId وجود ندارد، خطا می‌دهیم (این API فقط برای کاربران لاگین شده است)
    if (!$userId) {
        http_response_code(401);
        echo json_encode([
            'status' => false,
            'message' => 'شناسه کاربر یافت نشد'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // بررسی اینکه آیا درخواست برای یک سفارش خاص است یا لیست همه
    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if ($orderId) {
        // دریافت جزئیات یک سفارش خاص
        $stmt = $conn->prepare("
            SELECT 
                o.id AS order_id,
                o.created_at,
                o.status,
                o.user_id,
                o.guest_token,
                a.address,
                a.province,
                a.city,
                a.postal_code,
                s.title AS shipping_method,
                s.id AS shipping_id
            FROM orders o
            LEFT JOIN addresses a ON a.user_id = o.user_id
            LEFT JOIN shippings s ON s.id = o.shipping_id
            WHERE o.id = ? AND o.user_id = ?
            ORDER BY a.id DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode([
                'status' => false,
                'message' => 'سفارش یافت نشد'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // دریافت مبلغ کل از payments (اولویت اول)
        $totalPrice = 0;
        $stmt = $conn->prepare("SELECT amount, status AS payment_status, ref_id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment && !empty($payment['amount'])) {
            $totalPrice = (float)$payment['amount'];
            $order['payment_status'] = $payment['payment_status'];
            $order['payment_ref_id'] = $payment['ref_id'] ?? null;
        } else {
            // Fallback: محاسبه از order_items
            try {
                $stmt = $conn->prepare("
                    SELECT oi.quantity, p.price, p.discount_price 
                    FROM order_items oi 
                    JOIN products p ON oi.product_id = p.id 
                    WHERE oi.order_id = ?
                ");
                $stmt->execute([$orderId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as $item) {
                    $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
                             ? (float)$item['discount_price'] 
                             : (float)$item['price'];
                    $totalPrice += $price * (int)$item['quantity'];
                }
            } catch (PDOException $e) {
                error_log("خطا در محاسبه مبلغ سفارش #$orderId: " . $e->getMessage());
            }
            $order['payment_status'] = null;
            $order['payment_ref_id'] = null;
        }
        
        $order['total_price'] = $totalPrice;
        
        // دریافت محصولات سفارش با مشخصات کامل
        $stmt = $conn->prepare("
            SELECT 
                oi.id AS item_id,
                oi.product_id,
                oi.quantity,
                p.title AS product_title,
                p.image AS product_image,
                p.price,
                p.discount_price,
                p.brand,
                p.description,
                p.general_description,
                p.dimensions,
                p.weight,
                p.material,
                p.color,
                p.size,
                p.grade,
                p.line_count,
                p.slot_count,
                p.width,
                p.half_finished,
                c.name AS category_name,
                COALESCE(p.discount_price, p.price) AS unit_price
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN categories c ON c.id = p.cat_id
            WHERE oi.order_id = ?
            ORDER BY oi.id ASC
        ");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // محاسبه قیمت نهایی هر آیتم
        foreach ($items as &$item) {
            $item['item_total'] = (float)$item['unit_price'] * (int)$item['quantity'];
            $item['product_id'] = (int)$item['product_id'];
            $item['quantity'] = (int)$item['quantity'];
            $item['price'] = (float)$item['price'];
            $item['discount_price'] = $item['discount_price'] ? (float)$item['discount_price'] : null;
            $item['unit_price'] = (float)$item['unit_price'];
        }
        unset($item);
        
        $order['items'] = $items;
        $order['items_count'] = count($items);
        $order['order_id'] = (int)$order['order_id'];
        
        echo json_encode([
            'status' => true,
            'data' => $order
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        // دریافت لیست همه سفارش‌های کاربر
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $stmt = $conn->prepare("
            SELECT DISTINCT
                o.id AS order_id,
                o.created_at,
                o.status,
                o.user_id,
                a.province,
                a.city,
                s.title AS shipping_method
            FROM orders o
            LEFT JOIN addresses a ON a.user_id = o.user_id
            LEFT JOIN shippings s ON s.id = o.shipping_id
            WHERE o.user_id = ?
            ORDER BY o.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // برای هر سفارش، مبلغ کل و تعداد آیتم‌ها را اضافه می‌کنیم
        foreach ($orders as &$order) {
            $orderId = $order['order_id'];
            
            // دریافت مبلغ و وضعیت پرداخت از payments
            $stmt = $conn->prepare("SELECT amount, status AS payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$orderId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payment && !empty($payment['amount'])) {
                $order['total_price'] = (float)$payment['amount'];
                $order['payment_status'] = $payment['payment_status'];
            } else {
                $order['total_price'] = 0;
                $order['payment_status'] = null;
            }
            
            // شمارش آیتم‌ها
            $stmt = $conn->prepare("SELECT COUNT(*) as items_count FROM order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            $order['items_count'] = (int)$count['items_count'];
            
            $order['order_id'] = (int)$order['order_id'];
        }
        unset($order);
        
        // تعداد کل سفارش‌های کاربر برای pagination
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'status' => true,
            'data' => $orders,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای سرور: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

