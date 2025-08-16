<?php

require_once '../../db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

// فقط درخواست POST رو قبول کن
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// دریافت و پاکسازی داده‌ها
$name = htmlspecialchars(trim($_POST['name'] ?? ''));
$description = htmlspecialchars(trim($_POST['description'] ?? ''));
$slug = htmlspecialchars(trim($_POST['slug'] ?? ''));
$parent_id = $_POST['parent_id'] ?? null;
$status = $_POST['status'] ?? 1;

$errors = [];

// اعتبارسنجی ساده
if ($name === '') $errors[] = 'نام خالی است';
if ($slug === '') $errors[] = 'اسلاگ خالی است';

// بررسی فایل تصویر
$imagePath = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['image']['tmp_name'];
    $fileName = basename($_FILES['image']['name']);
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($fileExt, $allowedExt)) {
        $errors[] = 'فرمت تصویر مجاز نیست.';
    } else {
        $newFileName = uniqid('cat_') . '.' . $fileExt;
        $uploadDir = '../../uploads/categories/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $destPath = $uploadDir . $newFileName;
        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $imagePath = 'uploads/categories/' . $newFileName;
        } else {
            $errors[] = 'در ذخیره‌سازی تصویر خطا رخ داد.';
        }
    }
} 

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی تکراری نبودن اسلاگ
$stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ?");
$stmt->execute([$slug]);
if ($stmt->fetch()) {
    echo json_encode(['status' => false, 'message' => 'اسلاگ قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ذخیره در دیتابیس
$stmt = $conn->prepare("INSERT INTO categories (name, description, slug, parent_id, status, image) VALUES (?, ?, ?, ?, ?, ?)");
$result = $stmt->execute([$name, $description, $slug, $parent_id, $status, $imagePath]);

if ($result) {
    echo json_encode(['status' => true, 'message' => 'دسته‌بندی با موفقیت ایجاد شد.'], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['status' => false, 'message' => 'ثبت دسته‌بندی با مشکل مواجه شد.'], JSON_UNESCAPED_UNICODE);
}
