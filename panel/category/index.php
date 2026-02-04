<?php

require_once '../../db_connection.php';
$conn = getPDO();

// CORS headers - باید قبل از هر خروجی باشند
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// تابع تولید اسلاگ از نام (مشابه JavaScript)
function generateSlug($title) {
    if (empty($title) || trim($title) === '') {
        return '';
    }

    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabicNumbers = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $slug = $title;

    // تبدیل اعداد فارسی و عربی به انگلیسی
    foreach ($persianNumbers as $index => $persian) {
        $slug = str_replace($persian, $englishNumbers[$index], $slug);
    }

    foreach ($arabicNumbers as $index => $arabic) {
        $slug = str_replace($arabic, $englishNumbers[$index], $slug);
    }

    // تبدیل به lowercase و حذف کاراکترهای غیرمجاز
    $slug = mb_strtolower($slug, 'UTF-8');
    $slug = preg_replace('/[^\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFFa-zA-Z0-9\s-]/u', ' ', $slug);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    return $slug;
}

// فقط درخواست POST رو قبول کن
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// دریافت و پاکسازی داده‌ها
$name = htmlspecialchars(trim($_POST['name'] ?? ''));
$description = htmlspecialchars(trim($_POST['description'] ?? ''));
$slug = htmlspecialchars(trim($_POST['slug'] ?? ''));
$parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
$status = isset($_POST['status']) ? $_POST['status'] : 1;

$errors = [];

// اعتبارسنجی ساده
if ($name === '') $errors[] = 'نام خالی است';

// اگر اسلاگ خالی بود، از نام تولید کن و یک عدد تصادفی اضافه کن
if (empty($slug) && !empty($name)) {
    $baseSlug = generateSlug($name);
    // اضافه کردن یک عدد تصادفی 4 رقمی به اسلاگ
    $randomSuffix = rand(1000, 9999);
    $slug = $baseSlug . '-' . $randomSuffix;
}

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

// بررسی تکراری نبودن اسلاگ و در صورت تکراری بودن، یک عدد تصادفی جدید اضافه کن
$originalSlug = $slug;
$attempts = 0;
while ($attempts < 10) {
    $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ?");
    $stmt->execute([$slug]);
    if (!$stmt->fetch()) {
        break; // اسلاگ منحصر به فرد است
    }
    // اگر تکراری بود، یک عدد تصادفی جدید اضافه کن
    $randomSuffix = rand(1000, 9999);
    $slug = $originalSlug . '-' . $randomSuffix;
    $attempts++;
}

if ($attempts >= 10) {
    echo json_encode(['status' => false, 'message' => 'نمی‌توان اسلاگ منحصر به فردی ایجاد کرد. لطفاً اسلاگ را دستی وارد کنید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ذخیره در دیتابیس
try {
    $stmt = $conn->prepare("INSERT INTO categories (name, description, slug, parent_id, status, image) VALUES (?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([$name, $description, $slug, $parent_id, $status, $imagePath]);

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'دسته‌بندی با موفقیت ایجاد شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        $errorInfo = $stmt->errorInfo();
        echo json_encode(['status' => false, 'message' => 'ثبت دسته‌بندی با مشکل مواجه شد: ' . ($errorInfo[2] ?? 'خطای نامشخص')], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log("Category creation error: " . $e->getMessage());
    echo json_encode(['status' => false, 'message' => 'خطا در ثبت دسته‌بندی: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
