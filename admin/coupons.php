<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

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
?>