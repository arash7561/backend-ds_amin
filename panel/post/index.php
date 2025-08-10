<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);  // توجه: اینجا true گذاشتیم برای آرایه

// استخراج داده‌ها با فیلتر XSS و اطمینان از وجود مقدار
$title = htmlspecialchars(trim($data['title'] ?? ''));
$description = htmlspecialchars(trim($data['description'] ?? ''));
$slug = htmlspecialchars(trim($data['slug'] ?? ''));
$cat_id = $data['cat_id'] ?? null;
$status = $data['status'] ?? null;
$image = htmlspecialchars(trim($data['image'] ?? ''));
$stock = $data['stock'] ?? null;
$price = $data['price'] ?? null;
$discount_percent = $data['discount_percent'] ?? 0;

// دریافت آرایه‌های قطر و طول (اگر موجود نبودند آرایه خالی در نظر گرفته میشن)
$diameters = isset($data['diameters']) && is_array($data['diameters']) ? $data['diameters'] : [];
$lengths = isset($data['lengths']) && is_array($data['lengths']) ? $data['lengths'] : [];

// دریافت قوانین تخفیف تعدادی (اگر موجود نبود آرایه خالی)
$discount_rules = isset($data['discount_rules']) && is_array($data['discount_rules']) ? $data['discount_rules'] : [];

$errors = [];

// اعتبارسنجی
if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if ($slug === '') $errors[] = 'اسلاگ خالی است';
if (!isset($cat_id)) $errors[] = 'دسته‌بندی خالی است';
if (!in_array($status, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است';
if ($image === '') $errors[] = 'تصویر خالی است';
if (!is_numeric($stock)) $errors[] = 'موجودی نامعتبر است';
if (!is_numeric($price)) $errors[] = 'قیمت نامعتبر است';
if (!is_numeric($discount_percent) || $discount_percent < 0 || $discount_percent > 100)
    $errors[] = 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد';

// اعتبارسنجی قطرها
foreach ($diameters as $d) {
    if (!is_numeric($d)) {
        $errors[] = 'یکی از مقادیر قطر نامعتبر است';
        break;
    }
}

// اعتبارسنجی طول‌ها
foreach ($lengths as $l) {
    if (!is_numeric($l)) {
        $errors[] = 'یکی از مقادیر طول نامعتبر است';
        break;
    }
}

// اعتبارسنجی قوانین تخفیف تعدادی
foreach ($discount_rules as $rule) {
    if (!isset($rule['min_quantity']) || !is_numeric($rule['min_quantity']) || $rule['min_quantity'] < 1) {
        $errors[] = 'مقدار حداقل تعداد در قوانین تخفیف معتبر نیست';
        break;
    }
    if (!isset($rule['discount_percent']) || !is_numeric($rule['discount_percent']) || $rule['discount_percent'] < 0 || $rule['discount_percent'] > 100) {
        $errors[] = 'درصد تخفیف در قوانین تخفیف معتبر نیست';
        break;
    }
}

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// محاسبه قیمت تخفیف‌خورده
$discount_price = $price - ($price * $discount_percent / 100);

// تبدیل قطر و طول‌ها به JSON برای ذخیره در دیتابیس
$dimensions = json_encode(['diameters' => $diameters, 'lengths' => $lengths], JSON_UNESCAPED_UNICODE);

try {
    // بررسی یکتا بودن slug
    $stmt = $conn->prepare("SELECT id FROM products WHERE slug = ?");
    $stmt->execute([$slug]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo json_encode(['status' => false, 'message' => 'اسلاگ قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // درج محصول با فیلد جدید dimensions
    $stmt = $conn->prepare("INSERT INTO products 
        (title, description, slug, cat_id, status, image, stock, price, discount_price, dimensions) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $result = $stmt->execute([
        $title, $description, $slug, $cat_id, $status, $image, $stock, $price, $discount_price, $dimensions
    ]);

    if ($result) {
        $productId = $conn->lastInsertId();

        // درج قوانین تخفیف تعدادی مرتبط با محصول
        $stmtRule = $conn->prepare("INSERT INTO product_discount_rules (product_id, min_quantity, discount_percent) VALUES (?, ?, ?)");
        foreach ($discount_rules as $rule) {
            $stmtRule->execute([
                $productId,
                $rule['min_quantity'],
                $rule['discount_percent']
            ]);
        }

        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت ثبت شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'در ثبت محصول مشکلی پیش آمد.'], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
