<?php
require_once __DIR__ . '/../../db_connection.php';
header('Content-Type: application/json');

// فقط درخواست POST رو قبول کن
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// دریافت و پاکسازی داده‌ها
$title = htmlspecialchars(trim($_POST['title'] ?? ''));
$description = htmlspecialchars(trim($_POST['description'] ?? ''));
$button_text = htmlspecialchars(trim($_POST['button_text'] ?? ''));
$button_link = htmlspecialchars(trim($_POST['button_link'] ?? ''));
$is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

$errors = [];

// اعتبارسنجی ساده
if ($title === '') $errors[] = 'عنوان خالی است.';
if ($description === '') $errors[] = 'توضیحات خالی است.';

// بررسی فایل تصویر (الزامی)
$imagePath = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['image']['tmp_name'];
    $fileName = basename($_FILES['image']['name']);
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($fileExt, $allowedExt)) {
        $errors[] = 'فرمت تصویر مجاز نیست.';
    } else {
        $newFileName = uniqid('poster_') . '.' . $fileExt;
        $uploadDir = '../../uploads/posters/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $destPath = $uploadDir . $newFileName;
        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $imagePath = 'uploads/posters/' . $newFileName;
        } else {
            $errors[] = 'در ذخیره‌سازی تصویر خطا رخ داد.';
        }
    }
} else {
    $errors[] = 'آپلود تصویر الزامی است.';
}

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی تکراری نبودن عنوان
$stmt = $conn->prepare("SELECT id FROM posters WHERE LOWER(title) = LOWER(?)");
$stmt->execute([$title]);
if ($stmt->fetch()) {
    echo json_encode(['status' => false, 'message' => 'عنوان قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ذخیره در دیتابیس
$stmt = $conn->prepare("INSERT INTO posters (title, description, button_text, button_link, is_active, image) VALUES (?, ?, ?, ?, ?, ?)");
$result = $stmt->execute([$title, $description, $button_text, $button_link, $is_active, $imagePath]);

if ($result) {
    echo json_encode(['status' => true, 'message' => 'پوستر با موفقیت ایجاد شد.'], JSON_UNESCAPED_UNICODE);
} else {
    $errorInfo = $stmt->errorInfo();
    echo json_encode(['status' => false, 'message' => 'خطا در ذخیره‌سازی: ' . $errorInfo[2]], JSON_UNESCAPED_UNICODE);
}
