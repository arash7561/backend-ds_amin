<?php

require_once '../../db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json);

$id = $data->id ?? null;
$title = trim($data->title ?? '');
$description = trim($data->description ?? '');
$slug = trim($data->slug ?? '');
$cat_id = $data->cat_id ?? $data->category ?? null; // پشتیبانی از هر دو نام فیلد
$status = $data->status ?? 'active'; // پیش‌فرض فعال

// تبدیل وضعیت به فرمت مناسب برای دیتابیس
error_log("=== STATUS CONVERSION DEBUG ===");
error_log("Raw status received: " . var_export($data->status, true) . " (type: " . gettype($data->status) . ")");
error_log("Initial status: " . var_export($status, true) . " (type: " . gettype($status) . ")");

// همیشه به رشته تبدیل کن
if (is_numeric($status)) {
    $status = ($status == 1) ? 'active' : 'inactive';
    error_log("Numeric conversion: " . $status);
} elseif (is_string($status)) {
    $status = strtolower(trim($status));
    if ($status === 'active' || $status === '1' || $status === 'true') {
        $status = 'active';
    } elseif ($status === 'inactive' || $status === '0' || $status === 'false') {
        $status = 'inactive';
    } else {
        $status = 'active'; // پیش‌فرض
    }
    error_log("String conversion: " . $status);
} else {
    $status = 'active'; // پیش‌فرض
    error_log("Default to: active");
}

error_log("Final status: " . var_export($status, true) . " (type: " . gettype($status) . ")");

// Debug: لاگ کردن وضعیت دریافتی
error_log("=== DEBUG STATUS ===");
error_log("Raw data received: " . var_export($data, true));
error_log("Received status: " . var_export($status, true) . " (type: " . gettype($status) . ")");
error_log("ID: " . var_export($id, true));
error_log("Title: " . var_export($title, true));
$image = trim($data->image ?? '');
$stock = $data->stock ?? null;
$price = $data->price ?? null;
$discount_price = $data->discount_price ?? null;
$size = trim($data->size ?? '');
$type = trim($data->type ?? '');
$brand = trim($data->brand ?? '');
$line_count = $data->line_count ?? null;
$dimensions = trim($data->dimensions ?? '');
$grade = trim($data->grade ?? '');
$half_finished = $data->half_finished ?? 0;
$views = $data->views ?? 0;

$errors = [];

if (!$id || !is_numeric($id)) $errors[] = 'آیدی محصول معتبر نیست';

// اعتبارسنجی فیلدهای اجباری
if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if (!isset($cat_id)) $errors[] = 'دسته بندی خالی است';
if (!is_numeric($stock)) $errors[] = 'موجودی نامعتبر است';
if (!is_numeric($price)) $errors[] = 'قیمت نامعتبر است';

if (!in_array($status, [0, 1], true)) {
    $status = 1; // اگر نامعتبر است، فعال کن
}

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول با id داده شده
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode(['status' => false, 'message' => 'محصولی با این آیدی پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تولید slug اگر ارسال نشده باشد
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
    }

    // آپدیت محصول
    error_log("=== UPDATING PRODUCT ===");
    error_log("Updating product with status: " . var_export($status, true) . " (type: " . gettype($status) . ")");
    error_log("Product ID: " . $id);
    error_log("All update values: " . var_export([$title, $description, $slug, $cat_id, $status, $image, $stock, $price, $discount_price, $size, $type, $brand, $line_count, $dimensions, $grade, $half_finished, $views, $id], true));
    
    $stmt = $conn->prepare("UPDATE products SET title = ?, description = ?, slug = ?, cat_id = ?, status = ?, image = ?, stock = ?, price = ?, discount_price = ?, size = ?, type = ?, brand = ?, line_count = ?, dimensions = ?, grade = ?, half_finished = ?, views = ? WHERE id = ?");
    $result = $stmt->execute([$title, $description, $slug, $cat_id, $status, $image, $stock, $price, $discount_price, $size, $type, $brand, $line_count, $dimensions, $grade, $half_finished, $views, $id]);
    
    error_log("Update result: " . var_export($result, true));
    error_log("Rows affected: " . $stmt->rowCount());

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش محصول.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
