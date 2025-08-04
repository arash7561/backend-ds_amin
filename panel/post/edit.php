<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json);

$title = trim($data->title ?? '');
$description = trim($data->description ?? '');
$slug = trim($data->slug ?? '');
$cat_id = $data->cat_id ?? null;
$status = $data->status ?? null;
$image = trim($data->image ?? '');
$stock = $data->stock ?? null;
$price = $data->price ?? null;
$discount_price = $data->discount_price ?? null;

$errors = [];

if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if ($slug === '') $errors[] = 'اسلاگ خالی است';
if (!isset($cat_id)) $errors[] = 'دسته بندی خالی است';
if (!in_array($status, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است';
if ($image === '') $errors[] = 'تصویر خالی است';
if (!is_numeric($stock)) $errors[] = 'موجودی نامعتبر است';
if (!is_numeric($price)) $errors[] = 'قیمت نامعتبر است';
if (!is_numeric($discount_price)) $errors[] = 'قیمت تخفیف نامعتبر است';

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول با slug داده شده
    $stmt = $conn->prepare("SELECT id FROM products WHERE slug = ?");
    $stmt->execute([$slug]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode(['status' => false, 'message' => 'محصولی با این اسلاگ پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // آپدیت محصول
    $stmt = $conn->prepare("UPDATE products SET title = ?, description = ?, cat_id = ?, status = ?, image = ?, stock = ?, price = ?, discount_price = ? WHERE slug = ?");
    $result = $stmt->execute([$title, $description, $cat_id, $status, $image, $stock, $price, $discount_price, $slug]);

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش محصول.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
