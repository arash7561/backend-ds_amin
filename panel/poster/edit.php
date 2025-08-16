<?php

require_once '../../db_connection.php';
$conn = getPDO();
// CORS headers
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
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
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $button_text = trim($_POST['button_text'] ?? '');
    $button_link = trim($_POST['button_link'] ?? '');
    $is_active = (int)($_POST['is_active'] ?? 1);
    $image = '';
    
    // پردازش فایل آپلود شده
    $file = $_FILES['image'];
    $allowedTypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (in_array($file['type'], $allowedTypes) && $file['size'] <= 5 * 1024 * 1024) {
        $uploadDir = '../../uploads/posters/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '.' . $extension;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $image = 'uploads/posters/' . $fileName;
        }
    }
} else {
    // درخواست JSON معمولی
    $json = file_get_contents('php://input');
    $data = json_decode($json);
    
    $id = $data->id ?? null;
    $title = trim($data->title ?? '');
    $description = trim($data->description ?? '');
    $button_text = trim($data->button_text ?? '');
    $button_link = trim($data->button_link ?? '');
    $is_active = (int)($data->is_active ?? 1);
    $image = trim($data->image ?? '');
}

$errors = [];

if (!$id || !is_numeric($id)) $errors[] = 'آیدی پوستر معتبر نیست';
if ($title === '') $errors[] = 'عنوان خالی است';
if (!in_array($is_active, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است: ' . $is_active;



if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود پوستر با ID داده شده
    $stmt = $conn->prepare("SELECT id FROM posters WHERE id = ?");
    $stmt->execute([$id]);
    $poster = $stmt->fetch();

    if (!$poster) {
        echo json_encode(['status' => false, 'message' => 'پوستر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // بررسی تکراری نبودن عنوان
    $stmt = $conn->prepare("SELECT id FROM posters WHERE LOWER(title) = LOWER(?) AND id != ?");
    $stmt->execute([$title, $id]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'عنوان قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // آپدیت پوستر
    $stmt = $conn->prepare("UPDATE posters SET title = ?, description = ?, button_text = ?, button_link = ?, is_active = ?, image = ? WHERE id = ?");
    $result = $stmt->execute([$title, $description, $button_text, $button_link, $is_active, $image, $id]);

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'پوستر با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش پوستر.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
