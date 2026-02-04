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

/**
 * ارسال پیامک تایید پرداخت به کاربر بعد از پرداخت موفق
 * 
 * @param int $orderId شناسه سفارش
 * @param string|null $refId کد پیگیری پرداخت
 * @param PDO|null $conn اتصال دیتابیس (اگر null باشد، از getPDO() استفاده می‌شود)
 * @return bool موفقیت عملیات
 */
if (!function_exists('sendPaymentConfirmationToUser')) {
function sendPaymentConfirmationToUser($orderId, $refId = null, $conn = null) {
    try {
        // اتصال به دیتابیس
        if ($conn === null) {
            $conn = getPDO();
        }

        if (!$conn) {
            error_log("خطا: اتصال به دیتابیس برقرار نشد");
            return false;
        }

        // دریافت اطلاعات سفارش و کاربر
        // ابتدا بررسی می‌کنیم که آیا فیلد phone، mobile یا email در جدول orders وجود دارد
        $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'phone'");
        $stmt->execute();
        $hasPhoneField = $stmt->fetch();
        
        $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'mobile'");
        $stmt->execute();
        $hasMobileField = $stmt->fetch();
        
        $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'email'");
        $stmt->execute();
        $hasEmailField = $stmt->fetch();
        
        // ساخت query بر اساس فیلدهای موجود
        $selectFields = "
            o.id AS order_id,
            o.user_id,
            o.guest_token,
            u.mobile AS user_mobile,
            u.name AS user_name
        ";
        
        if ($hasPhoneField) {
            $selectFields .= ", o.phone AS order_phone";
        }
        if ($hasMobileField) {
            $selectFields .= ", o.mobile AS order_mobile";
        }
        if ($hasEmailField) {
            $selectFields .= ", o.email AS order_email";
        }
        
        $stmt = $conn->prepare("
            SELECT 
                $selectFields
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            error_log("خطا: سفارش با شناسه $orderId یافت نشد");
            return false;
        }

        // تعیین شماره تلفن کاربر
        $userMobile = null;
        
        // اولویت 1: اگر کاربر لاگین شده است (user_id > 0)، شماره تلفن را از جدول users بگیر
        if ($order['user_id'] && $order['user_id'] > 0) {
            if (!empty($order['user_mobile'])) {
                $userMobile = $order['user_mobile'];
                error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر لاگین شده (user_id: {$order['user_id']}) - شماره تلفن از جدول users: $userMobile");
            } else {
                // اگر شماره تلفن در جدول users خالی است، از جدول addresses کاربر استفاده کن
                $stmt = $conn->prepare("
                    SELECT phone, mobile 
                    FROM addresses 
                    WHERE user_id = ? 
                    ORDER BY id DESC 
                    LIMIT 1
                ");
                $stmt->execute([$order['user_id']]);
                $address = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($address && !empty($address['mobile'])) {
                    $userMobile = $address['mobile'];
                    error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر لاگین شده (user_id: {$order['user_id']}) - شماره تلفن از جدول addresses: $userMobile");
                } elseif ($address && !empty($address['phone'])) {
                    $userMobile = $address['phone'];
                    error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر لاگین شده (user_id: {$order['user_id']}) - شماره تلفن از جدول addresses (phone): $userMobile");
                }
            }
        } 
        // اولویت 2: اگر کاربر مهمان است (user_id == 0)، از فیلدهای orders یا addresses استفاده کن
        elseif ($order['user_id'] == 0) {
            // اولویت 2-1: اگر فیلد phone یا mobile در orders وجود دارد، از آن استفاده کن
            if ($hasPhoneField && !empty($order['order_phone'])) {
                $userMobile = $order['order_phone'];
                error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر مهمان - شماره تلفن از orders.phone: $userMobile");
            } elseif ($hasMobileField && !empty($order['order_mobile'])) {
                $userMobile = $order['order_mobile'];
                error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر مهمان - شماره تلفن از orders.mobile: $userMobile");
            }
            // اولویت 2-2: بررسی فیلد email در orders (ممکن است شماره تلفن باشد)
            elseif ($hasEmailField && !empty($order['order_email']) && preg_match('/^09\d{9}$/', $order['order_email'])) {
                $userMobile = $order['order_email'];
                error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر مهمان - شماره تلفن از orders.email: $userMobile");
            }
            // اولویت 2-3: بررسی جدول addresses برای کاربر مهمان
            elseif (!empty($order['guest_token'])) {
                $stmt = $conn->prepare("
                    SELECT phone, mobile 
                    FROM addresses 
                    WHERE user_id = 0 
                    ORDER BY id DESC 
                    LIMIT 1
                ");
                $stmt->execute();
                $address = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($address && !empty($address['mobile'])) {
                    $userMobile = $address['mobile'];
                    error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر مهمان - شماره تلفن از addresses.mobile: $userMobile");
                } elseif ($address && !empty($address['phone'])) {
                    $userMobile = $address['phone'];
                    error_log("پیامک تایید پرداخت - سفارش #$orderId - کاربر مهمان - شماره تلفن از addresses.phone: $userMobile");
                }
            }
        }

        // اگر شماره تلفن پیدا نشد، خطا می‌دهیم
        if (empty($userMobile)) {
            error_log("❌ خطا: شماره تلفن کاربر برای سفارش #$orderId یافت نشد. user_id: " . ($order['user_id'] ?? 'NULL') . ", user_mobile: " . ($order['user_mobile'] ?? 'NULL') . ", order_email: " . (isset($order['order_email']) ? $order['order_email'] : 'NULL'));
            return false;
        }

        // ساخت متن پیامک تایید پرداخت
        $message = "پرداخت شما با موفقیت انجام شد.\n";
        $message .= "شماره سفارش: " . $order['order_id'] . "\n";
        
        if ($refId) {
            $message .= "کد پیگیری: " . $refId . "\n";
        }
        
        $message .= "از خرید شما متشکریم.";

        // ارسال پیامک با ملی‌پیامک
        $username = '9128375080';
        $password = '8T05B';
        $from = '50002710065080';

        try {
            $api = new MelipayamakApi($username, $password);
            $sms = $api->sms();
            $sms->send($userMobile, $from, $message);
            error_log("پیامک تایید پرداخت سفارش #$orderId با موفقیت به کاربر ارسال شد (شماره: $userMobile)");
            return true;
        } catch (Exception $e) {
            error_log("خطا در ارسال پیامک تایید پرداخت سفارش #$orderId: " . $e->getMessage());
            return false;
        }

    } catch (PDOException $e) {
        $errorMsg = "خطای دیتابیس در ارسال پیامک تایید پرداخت سفارش #$orderId: " . $e->getMessage();
        error_log($errorMsg);
        if (ini_get('display_errors')) {
            error_log("SQL Error Details: " . $e->getTraceAsString());
        }
        return false;
    } catch (Exception $e) {
        $errorMsg = "خطای عمومی در ارسال پیامک تایید پرداخت سفارش #$orderId: " . $e->getMessage();
        error_log($errorMsg);
        if (ini_get('display_errors')) {
            error_log("Exception Details: " . $e->getTraceAsString());
        }
        return false;
    }
}
} // end if !function_exists

/**
 * ارسال پیامک کد رهگیری ارسال به کاربر
 * 
 * @param int $orderId شناسه سفارش
 * @param string $trackingCode کد رهگیری
 * @param PDO|null $conn اتصال دیتابیس (اگر null باشد، از getPDO() استفاده می‌شود)
 * @return bool موفقیت عملیات
 */
if (!function_exists('sendShippingTrackingSMS')) {
function sendShippingTrackingSMS($orderId, $trackingCode, $conn = null) {
    try {
        // اتصال به دیتابیس
        if ($conn === null) {
            $conn = getPDO();
        }

        if (!$conn) {
            error_log("خطا: اتصال به دیتابیس برقرار نشد");
            return false;
        }

        // دریافت اطلاعات سفارش و کاربر
        $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'phone'");
        $stmt->execute();
        $hasPhoneField = $stmt->fetch();
        
        $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'mobile'");
        $stmt->execute();
        $hasMobileField = $stmt->fetch();
        
        $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'email'");
        $stmt->execute();
        $hasEmailField = $stmt->fetch();
        
        // ساخت query بر اساس فیلدهای موجود
        $selectFields = "
            o.id AS order_id,
            o.user_id,
            o.guest_token,
            u.mobile AS user_mobile,
            u.name AS user_name
        ";
        
        if ($hasPhoneField) {
            $selectFields .= ", o.phone AS order_phone";
        }
        if ($hasMobileField) {
            $selectFields .= ", o.mobile AS order_mobile";
        }
        if ($hasEmailField) {
            $selectFields .= ", o.email AS order_email";
        }
        
        $query = "
            SELECT $selectFields
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = ?
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            error_log("SMS Tracking - Order #$orderId - سفارش یافت نشد");
            return false;
        }

        error_log("SMS Tracking - Order #$orderId - Order data: user_id=" . ($order['user_id'] ?? 'NULL') . ", user_mobile=" . ($order['user_mobile'] ?? 'NULL') . ", guest_token=" . ($order['guest_token'] ?? 'NULL'));

        // تعیین شماره موبایل کاربر
        $userMobile = null;
        
        // Priority 1: Logged-in user's mobile from users table
        if ($order['user_id'] && $order['user_id'] > 0 && !empty($order['user_mobile'])) {
            $userMobile = $order['user_mobile'];
            error_log("SMS Tracking - Order #$orderId - User logged in, mobile from users: $userMobile");
        } 
        // Priority 2: If user is guest (user_id == 0), check fields in orders table or addresses
        elseif ($order['user_id'] == 0) {
            if ($hasPhoneField && !empty($order['order_phone'])) {
                $userMobile = $order['order_phone'];
                error_log("SMS Tracking - Order #$orderId - Guest user, mobile from orders.phone: $userMobile");
            } elseif ($hasMobileField && !empty($order['order_mobile'])) {
                $userMobile = $order['order_mobile'];
                error_log("SMS Tracking - Order #$orderId - Guest user, mobile from orders.mobile: $userMobile");
            } elseif ($hasEmailField && !empty($order['order_email']) && preg_match('/^09\d{9}$/', $order['order_email'])) {
                $userMobile = $order['order_email'];
                error_log("SMS Tracking - Order #$orderId - Guest user, mobile from orders.email: $userMobile");
            }
            // Fallback to addresses table for guest user if guest_token is present
            if (empty($userMobile) && !empty($order['guest_token'])) {
                $stmt = $conn->prepare("SELECT phone, mobile FROM addresses WHERE user_id = 0 AND guest_token = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$order['guest_token']]);
                $address = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($address && !empty($address['mobile'])) {
                    $userMobile = $address['mobile'];
                    error_log("SMS Tracking - Order #$orderId - Guest user, mobile from addresses.mobile: $userMobile");
                } elseif ($address && !empty($address['phone'])) {
                    $userMobile = $address['phone'];
                    error_log("SMS Tracking - Order #$orderId - Guest user, mobile from addresses.phone: $userMobile");
                }
            }
        }

        if (empty($userMobile) || !preg_match('/^09\d{9}$/', $userMobile)) {
            error_log("SMS Tracking - Order #$orderId - شماره موبایل معتبر یافت نشد");
            return false;
        }

        // دریافت تنظیمات پیامک (استفاده از همان تنظیمات sendPaymentConfirmationToUser)
        // ابتدا سعی می‌کنیم از config بخوانیم
        $configPath = __DIR__ . '/../../config/zarinpal.php';
        $username = '';
        $password = '';
        $from = '50002710065080'; // پیش‌فرض
        
        if (file_exists($configPath)) {
            $config = require $configPath;
            $username = $config['sms_username'] ?? '';
            $password = $config['sms_password'] ?? '';
            $from = $config['sms_from'] ?? '50002710065080';
        }
        
        // اگر از config پیدا نشد، از تنظیمات hardcoded استفاده می‌کنیم (مثل sendPaymentConfirmationToUser)
        if (empty($username) || empty($password)) {
            $username = '9128375080';
            $password = '8T05B';
            $from = '50002710065080';
            error_log("SMS Tracking - Order #$orderId - Using hardcoded SMS credentials");
        } else {
            error_log("SMS Tracking - Order #$orderId - Using SMS credentials from config");
        }

        // ساخت متن پیامک
        $message = "سفارش شما #$orderId با موفقیت ارسال شد.\n";
        $message .= "کد رهگیری: $trackingCode\n";
        $message .= "داروخانه صنعتی امین";

        // ارسال پیامک با ملی‌پیامک
        error_log("SMS Tracking - Order #$orderId - Preparing to send SMS to: $userMobile");
        error_log("SMS Tracking - Order #$orderId - SMS username: " . substr($username, 0, 3) . "...");
        error_log("SMS Tracking - Order #$orderId - SMS from: $from");
        error_log("SMS Tracking - Order #$orderId - SMS message: " . substr($message, 0, 50) . "...");
        
        try {
            $api = new MelipayamakApi($username, $password);
            $sms = $api->sms();
            error_log("SMS Tracking - Order #$orderId - MelipayamakApi object created, calling send()...");
            $result = $sms->send($userMobile, $from, $message);
            error_log("SMS Tracking - Order #$orderId - SMS send() returned: " . json_encode($result, JSON_UNESCAPED_UNICODE));
            error_log("پیامک کد رهگیری سفارش #$orderId با موفقیت به کاربر ارسال شد (شماره: $userMobile, کد رهگیری: $trackingCode)");
            return true;
        } catch (Exception $e) {
            error_log("خطا در ارسال پیامک کد رهگیری سفارش #$orderId: " . $e->getMessage());
            error_log("SMS Tracking - Order #$orderId - Exception class: " . get_class($e));
            error_log("SMS Tracking - Order #$orderId - Exception trace: " . $e->getTraceAsString());
            return false;
        }
    } catch (PDOException $e) {
        $errorMsg = "خطای دیتابیس در ارسال پیامک کد رهگیری سفارش #$orderId: " . $e->getMessage();
        error_log($errorMsg);
        return false;
    } catch (Exception $e) {
        $errorMsg = "خطای عمومی در ارسال پیامک کد رهگیری سفارش #$orderId: " . $e->getMessage();
        error_log($errorMsg);
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
