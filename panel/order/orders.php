<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * ارسال پیامک به ادمین بعد از ثبت سفارش و پرداخت موفق
 * 
 * استفاده:
 * require_once __DIR__ . '/panel/order/orders.php';
 * sendOrderNotificationToAdmin($orderId, $conn);
 */

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Melipayamak\MelipayamakApi;

/**
 * ارسال پیامک به ادمین بعد از ثبت سفارش
 * 
 * @param int $orderId شناسه سفارش
 * @param PDO|null $conn اتصال دیتابیس (اگر null باشد، از getPDO() استفاده می‌شود)
 * @return bool موفقیت عملیات
 */
if (!function_exists('sendOrderNotificationToAdmin')) {
function sendOrderNotificationToAdmin($orderId, $conn = null) {
    try {
        // اتصال به دیتابیس
        if ($conn === null) {
            $conn = getPDO();
        }

        if (!$conn) {
            error_log("خطا: اتصال به دیتابیس برقرار نشد");
            return false;
        }

        // دریافت اطلاعات سفارش
        $stmt = $conn->prepare("
            SELECT 
                o.id AS order_id,
                s.title AS shipping_method,
                u.name AS user_name,
                u.mobile AS user_mobile,
                a.address,
                a.province,
                a.city,
                a.postal_code,
                o.created_at
            FROM orders o
            LEFT JOIN shippings s ON s.id = o.shipping_id
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN addresses a ON a.user_id = o.user_id
            WHERE o.id = ?
            ORDER BY a.id DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            error_log("خطا: سفارش با شناسه $orderId یافت نشد");
            return false;
        }

        // دریافت مبلغ کل از جدول payments (اولویت اول - دقیق‌تر است)
        $totalPrice = 0;
        $stmt = $conn->prepare("SELECT amount FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment && !empty($payment['amount'])) {
            $totalPrice = (float)$payment['amount'];
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
                error_log("خطا در محاسبه مبلغ از order_items: " . $e->getMessage());
                // اگر محاسبه از order_items هم شکست خورد، مقدار صفر باقی می‌ماند
            }
        }
        
        $order['total_price'] = $totalPrice;

        // ساخت متن پیامک
        $message = "سفارش جدید دارید.\n";
        $message .= "شماره سفارش: " . $order['order_id'] . "\n";
   
      
        // دریافت شماره ادمین از دیتابیس
        $stmt = $conn->prepare("SELECT mobile FROM admin_users WHERE role_id = '1' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || empty($admin['mobile'])) {
            error_log("خطا: شماره ادمین یافت نشد");
            return false;
        }

        $adminMobile = $admin['mobile'];

        // ارسال پیامک با ملی‌پیامک
        $username = '9128375080';
        $password = '8T05B';
        $from = '50002710065080';

        try {
            $api = new MelipayamakApi($username, $password);
            $sms = $api->sms();
            $sms->send($adminMobile, $from, $message);
            error_log("پیامک سفارش #$orderId با موفقیت به ادمین ارسال شد");
            return true;
        } catch (Exception $e) {
            error_log("خطا در ارسال پیامک سفارش #$orderId: " . $e->getMessage());
            return false;
        }

    } catch (PDOException $e) {
        $errorMsg = "خطای دیتابیس در ارسال پیامک سفارش #$orderId: " . $e->getMessage();
        error_log($errorMsg);
        if (ini_get('display_errors')) {
            error_log("SQL Error Details: " . $e->getTraceAsString());
        }
        return false;
    } catch (Exception $e) {
        $errorMsg = "خطای عمومی در ارسال پیامک سفارش #$orderId: " . $e->getMessage();
        error_log($errorMsg);
        if (ini_get('display_errors')) {
            error_log("Exception Details: " . $e->getTraceAsString());
        }
        return false;
    }
}
} // end if !function_exists

// اگر فایل مستقیماً فراخوانی شده باشد (نه به صورت include)
// این بخش برای زمانی است که می‌خواهید از این فایل به عنوان endpoint استفاده کنید
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'] ?? '')) {
    // تنظیمات CORS
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => false,
            'message' => 'فقط درخواست POST مجاز است'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;

        if (!$orderId) {
            http_response_code(400);
            echo json_encode([
                'status' => false,
                'message' => 'شناسه سفارش معتبر نیست'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = sendOrderNotificationToAdmin($orderId);

        if ($result) {
            echo json_encode([
                'status' => true,
                'message' => 'پیامک با موفقیت ارسال شد'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            $errorDetails = '';
            if (ini_get('display_errors')) {
                // در حالت debug می‌توانیم جزئیات بیشتری بدهیم
                $lastError = error_get_last();
                $errorDetails = $lastError ? $lastError['message'] : 'خطا در ارسال پیامک - لطفا error_log را بررسی کنید';
            }
            echo json_encode([
                'status' => false,
                'message' => 'خطا در ارسال پیامک' . ($errorDetails ? ': ' . $errorDetails : '')
            ], JSON_UNESCAPED_UNICODE);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'خطای سرور: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
