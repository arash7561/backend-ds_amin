<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

// خواندن JSON ورودی
$json = file_get_contents('php://input');
$data = json_decode($json);

// پاکسازی داده‌ها
$name = htmlspecialchars(trim($data->name ?? ''));
$description = htmlspecialchars(trim($data->description ?? ''));
$slug = htmlspecialchars(trim($data->slug ?? ''));
$parent_id = $data->parent_id ?? null;
$status = $data->status ?? 1;
$imageBase64 = $data->image ?? null;

$errors = [];

// اعتبارسنجی فیلدها
if ($name === '') $errors[] = 'نام خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if ($slug === '') $errors[] = 'اسلاگ خالی است';

// بررسی و ذخیره تصویر در صورت وجود
$imagePath = null;
if ($imageBase64) {
    if (preg_match('/^data:image\/(\w+);base64,/', $imageBase64, $type)) {
        $imageData = substr($imageBase64, strpos($imageBase64, ',') + 1);
        $imageData = base64_decode($imageData);
        if ($imageData === false) {
            $errors[] = 'فرمت تصویر نامعتبر است';
        } else {
            $ext = strtolower($type[1]);
            $fileName = uniqid('cat_') . '.' . $ext;
            $uploadDir = '../../uploads/categories/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filePath = $uploadDir . $fileName;
            file_put_contents($filePath, $imageData);
            $imagePath = 'uploads/categories/' . $fileName;
        }
    } else {
        $errors[] = 'ساختار تصویر نامعتبر است';
    }
}

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی تکراری نبودن اسلاگ
    $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'اسلاگ قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // درج دسته‌بندی
    $stmt = $conn->prepare("INSERT INTO categories (name, description, slug, parent_id, status, image) VALUES (?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([$name, $description, $slug, $parent_id, $status, $imagePath]);

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'دسته بندی با موفقیت ایجاد شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'در ثبت دسته بندی مشکلی پیش آمد.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
