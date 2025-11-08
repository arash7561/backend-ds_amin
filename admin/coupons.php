<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// اتصال به دیتابیس
require_once '../db_connection.php';

try {
    $pdo = getPDO();
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'خطا در اتصال به دیتابیس: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch($action) {
    case 'get':
        getCoupons($pdo);
        break;
    case 'create':
        createCoupon($pdo);
        break;
    case 'update':
        updateCoupon($pdo);
        break;
    case 'delete':
        deleteCoupon($pdo);
        break;
    case 'toggle_status':
        toggleCouponStatus($pdo);
        break;
    case 'validate':
        validateCoupon($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر']);
}

function getCoupons($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM coupons ORDER BY created_at DESC");
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'coupons' => $coupons]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در دریافت کدهای تخفیف: ' . $e->getMessage()]);
    }
}

function createCoupon($pdo) {
    try {
        // بررسی وجود کد تکراری
        $checkStmt = $pdo->prepare("SELECT id FROM coupons WHERE code = ?");
        $checkStmt->execute([$_POST['code']]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف تکراری است']);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO coupons (code, description, discount_type, discount_value, 
                                min_order_amount, max_discount_amount, usage_limit, 
                                valid_from, valid_until, is_active, applicable_products, 
                                product_ids, category_ids) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_POST['code'],
            $_POST['description'],
            $_POST['discount_type'],
            $_POST['discount_value'],
            $_POST['min_order_amount'] ?: null,
            $_POST['max_discount_amount'] ?: null,
            $_POST['usage_limit'] ?: null,
            $_POST['valid_from'] ?: null,
            $_POST['valid_until'],
            $_POST['is_active'],
            $_POST['applicable_products'],
            $_POST['product_ids'] ?: null,
            $_POST['category_ids'] ?: null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'کد تخفیف با موفقیت ایجاد شد']);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در ایجاد کد تخفیف: ' . $e->getMessage()]);
    }
}

function updateCoupon($pdo) {
    try {
        // بررسی وجود کد تکراری (به جز خود رکورد فعلی)
        $checkStmt = $pdo->prepare("SELECT id FROM coupons WHERE code = ? AND id != ?");
        $checkStmt->execute([$_POST['code'], $_POST['coupon_id']]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف تکراری است']);
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE coupons SET 
                code = ?, description = ?, discount_type = ?, discount_value = ?, 
                min_order_amount = ?, max_discount_amount = ?, usage_limit = ?, 
                valid_from = ?, valid_until = ?, is_active = ?, applicable_products = ?, 
                product_ids = ?, category_ids = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['code'],
            $_POST['description'],
            $_POST['discount_type'],
            $_POST['discount_value'],
            $_POST['min_order_amount'] ?: null,
            $_POST['max_discount_amount'] ?: null,
            $_POST['usage_limit'] ?: null,
            $_POST['valid_from'] ?: null,
            $_POST['valid_until'],
            $_POST['is_active'],
            $_POST['applicable_products'],
            $_POST['product_ids'] ?: null,
            $_POST['category_ids'] ?: null,
            $_POST['coupon_id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'کد تخفیف با موفقیت بروزرسانی شد']);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در بروزرسانی کد تخفیف: ' . $e->getMessage()]);
    }
}

function deleteCoupon($pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ?");
        $stmt->execute([$_POST['coupon_id']]);
        echo json_encode(['success' => true, 'message' => 'کد تخفیف با موفقیت حذف شد']);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در حذف کد تخفیف: ' . $e->getMessage()]);
    }
}

function toggleCouponStatus($pdo) {
    try {
        $stmt = $pdo->prepare("UPDATE coupons SET is_active = ? WHERE id = ?");
        $stmt->execute([$_POST['is_active'], $_POST['coupon_id']]);
        echo json_encode(['success' => true, 'message' => 'وضعیت کد تخفیف تغییر کرد']);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در تغییر وضعیت: ' . $e->getMessage()]);
    }
}

function validateCoupon($pdo) {
    try {
        $code = $_POST['code'] ?? '';
        $totalAmount = floatval($_POST['total'] ?? 0);
        
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف را وارد کنید']);
            return;
        }
        
        // پیدا کردن کد تخفیف
        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ?");
        $stmt->execute([$code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$coupon) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف نامعتبر است']);
            return;
        }
        
        // بررسی فعال بودن
        if (!$coupon['is_active']) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف غیرفعال است']);
            return;
        }
        
        // بررسی تاریخ اعتبار
        $now = date('Y-m-d H:i:s');
        if ($coupon['valid_from'] && $coupon['valid_from'] > $now) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف هنوز فعال نشده است']);
            return;
        }
        
        if ($coupon['valid_until'] && $coupon['valid_until'] < $now) {
            echo json_encode(['success' => false, 'message' => 'کد تخفیف منقضی شده است']);
            return;
        }
        
        // بررسی حداقل مبلغ سفارش
        if ($coupon['min_order_amount'] && $totalAmount < floatval($coupon['min_order_amount'])) {
            echo json_encode([
                'success' => false, 
                'message' => 'حداقل مبلغ سفارش برای این کد تخفیف ' . number_format($coupon['min_order_amount']) . ' تومان است'
            ]);
            return;
        }
        
        // بررسی محدودیت استفاده
        if ($coupon['usage_limit'] && intval($coupon['usage_count']) >= intval($coupon['usage_limit'])) {
            echo json_encode(['success' => false, 'message' => 'محدودیت استفاده از این کد تخفیف به پایان رسیده است']);
            return;
        }
        
        // محاسبه تخفیف
        $discount = 0;
        if ($coupon['discount_type'] === 'percentage') {
            $discount = ($totalAmount * floatval($coupon['discount_value'])) / 100;
        } else {
            $discount = floatval($coupon['discount_value']);
        }
        
        // اعمال حداکثر تخفیف
        if ($coupon['max_discount_amount'] && $discount > floatval($coupon['max_discount_amount'])) {
            $discount = floatval($coupon['max_discount_amount']);
        }
        
        // اطمینان از اینکه تخفیف بیشتر از مبلغ کل نباشد
        if ($discount > $totalAmount) {
            $discount = $totalAmount;
        }
        
        // تبدیل تخفیف به عدد صحیح (بدون اعشار)
        $discount = round($discount);
        
        echo json_encode([
            'success' => true,
            'message' => 'کد تخفیف با موفقیت اعمال شد',
            'coupon' => [
                'code' => $coupon['code'],
                'discount_type' => $coupon['discount_type'],
                'discount_value' => $coupon['discount_value'],
                'discount' => $discount,
                'description' => $coupon['description']
            ]
        ]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در اعتبارسنجی کد تخفیف: ' . $e->getMessage()]);
    }
}
?>