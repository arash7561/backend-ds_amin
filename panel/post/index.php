<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json);

// استخراج داده‌ها با فیلتر XSS و اطمینان از وجود مقدار
$title = htmlspecialchars(trim($data->title ?? ''));
$description = htmlspecialchars(trim($data->description ?? ''));
$slug = htmlspecialchars(trim($data->slug ?? ''));
$cat_id = $data->cat_id ?? null;
$status = $data->status ?? null;
$image = htmlspecialchars(trim($data->image ?? ''));
$stock = $data->stock ?? null;
$price = $data->price ?? null;
$discount_percent = $data->discount_percent ?? 0;

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

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// محاسبه قیمت تخفیف‌خورده
$discount_price = $price - ($price * $discount_percent / 100);

try {
    // بررسی یکتا بودن slug
    $stmt = $conn->prepare("SELECT id FROM products WHERE slug = ?");
    $stmt->execute([$slug]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo json_encode(['status' => false, 'message' => 'اسلاگ قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // درج محصول
    $stmt = $conn->prepare("INSERT INTO products 
        (title, description, slug, cat_id, status, image, stock, price, discount_price) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $result = $stmt->execute([
        $title, $description, $slug, $cat_id, $status, $image, $stock, $price, $discount_price
    ]);

    if ($result) {
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
