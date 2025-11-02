<?php

require_once __DIR__ . '/../../db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// بررسی نوع درخواست
if (isset($_FILES['image'])) {
    // درخواست با فایل (FormData)
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $parent_id = $_POST['parent_id'] ?? null;
    $status = (int)($_POST['status'] ?? 1);
    $image = '';
    
    // پردازش فایل آپلود شده
    $file = $_FILES['image'];
    $allowedTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (in_array($file['type'], $allowedTypes) && $file['size'] <= 5 * 1024 * 1024) {
        $uploadDir = '../../uploads/categories/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '.' . $extension;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $image = 'uploads/categories/' . $fileName;
        }
    }
} else {
    // درخواست JSON معمولی
    $json = file_get_contents('php://input');
    $data = json_decode($json);
    
    $id = $data->id ?? null;
    $name = trim($data->name ?? '');
    $description = trim($data->description ?? '');
    $slug = trim($data->slug ?? '');
    $parent_id = $data->parent_id ?? null;
    $status = (int)($data->status ?? 1);
    $image = trim($data->image ?? '');
}

$errors = [];

if (!$id || !is_numeric($id)) $errors[] = 'آیدی دسته بندی معتبر نیست';
if ($name === '') $errors[] = 'عنوان خالی است';
if ($slug === '') $errors[] = 'اسلاگ خالی است';
if (!isset($parent_id)) $errors[] = 'دسته بندی خالی است';
if (!in_array($status, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است: ' . $status;



if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود دسته بندی با ID داده شده و دریافت تصویر فعلی
    $stmt = $conn->prepare("SELECT id, image FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {
        echo json_encode(['status' => false, 'message' => 'دسته بندی پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی تکراری بودن slug
    $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
    $stmt->execute([$slug, $id]);
    $existingCategory = $stmt->fetch();
    
    if ($existingCategory) {
        echo json_encode(['status' => false, 'message' => 'این اسلاگ قبلاً استفاده شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اگر image خالی یا null بود، تصویر قبلی رو نگه دار
    $finalImage = $image;
    if (empty($image) || $image === '' || $image === null) {
        $finalImage = $category['image'] ?? '';
    }

    // تبدیل parent_id به null اگر 0 یا خالی باشد
    $finalParentId = ($parent_id === 0 || $parent_id === '0' || $parent_id === null || $parent_id === '') ? null : (int)$parent_id;

    // آپدیت دسته بندی
    $stmt = $conn->prepare("UPDATE categories SET name = ?, slug = ?, description = ?, parent_id = ?, status = ?, image = ? WHERE id = ?");
    $result = $stmt->execute([$name, $slug, $description, $finalParentId, $status, $finalImage, $id]);

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'دسته بندی با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش دسته بندی.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
