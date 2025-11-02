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

// All requests to edit.php are expected to be FormData
// Get data from POST
$id = $_POST['id'] ?? null;
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$button_text = trim($_POST['button_text'] ?? '');
$button_link = trim($_POST['button_link'] ?? '');
$is_active = (int)($_POST['is_active'] ?? 1);
$image = ''; // This will hold the path of the image to be saved in DB

$errors = [];

// Debug log
error_log("=== POSTER EDIT DEBUG ===");
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));
error_log("ID: " . $id);
error_log("Title: " . $title);
error_log("Description: " . $description);
error_log("Current Image from POST: " . ($_POST['current_image'] ?? 'NOT SET'));
error_log("Final Image value: " . $image);
error_log("=== END DEBUG ===");

// Handle image upload if a new file is provided
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK && $_FILES['image']['size'] > 0) {
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
        } else {
            $errors[] = 'در ذخیره‌سازی تصویر جدید خطا رخ داد.';
        }
    } else {
        $errors[] = 'فرمت یا حجم تصویر جدید مجاز نیست.';
    }
} else {
    // No new image file uploaded. Use the existing image path from POST data.
    $image = $_POST['current_image'] ?? '';
    
    // اگر current_image خالی است، از دیتابیس بخوان
    if (empty($image)) {
        // از دیتابیس پوستر فعلی را بخوان تا عکس آن را بگیریم
        try {
            $stmt = $conn->prepare("SELECT image FROM posters WHERE id = ?");
            $stmt->execute([$id]);
            $currentPoster = $stmt->fetch();
            
            if ($currentPoster && !empty($currentPoster['image'])) {
                $image = $currentPoster['image'];
                error_log("Using existing image from database: " . $image);
            } else {
                error_log("No existing image found in database for poster ID: " . $id);
                // اگر هیچ عکسی در دیتابیس نیست، خطا نده - فقط warning
                error_log("Warning: No image found for poster, but continuing without image");
            }
        } catch (Exception $e) {
            error_log("Error reading current poster image: " . $e->getMessage());
        }
    }
}

// Validation
if (!$id || !is_numeric($id)) $errors[] = 'آیدی پوستر معتبر نیست';
if ($title === '') $errors[] = 'عنوان خالی است';
if (!in_array($is_active, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است: ' . $is_active;

// عکس اجباری نیست - اگر خالی باشد، پوستر بدون عکس ذخیره می‌شود
if (empty($image)) {
    error_log("Warning: Image is empty, poster will be saved without image");
    $image = ''; // عکس خالی
}

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود پوستر با ID داده شده
    $stmt = $conn->prepare("SELECT id, image FROM posters WHERE id = ?"); // Also fetch current image to potentially delete old one
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
    
    // If a new image was uploaded, delete the old one
    $oldImage = $poster['image'];
    if (!empty($image) && $image !== $oldImage && strpos($oldImage, 'uploads/posters/') !== false) {
        $oldImagePath = '../../' . $oldImage; // Adjust path for deletion
        if (file_exists($oldImagePath)) {
            unlink($oldImagePath);
        }
    }
    
    // Update poster - فقط فیلدهای تغییر کرده را آپدیت کن
    $updateFields = [];
    $updateValues = [];
    
    // همیشه این فیلدها را آپدیت کن
    $updateFields[] = "title = ?";
    $updateValues[] = $title;
    
    $updateFields[] = "description = ?";
    $updateValues[] = $description;
    
    $updateFields[] = "button_text = ?";
    $updateValues[] = $button_text;
    
    $updateFields[] = "button_link = ?";
    $updateValues[] = $button_link;
    
    $updateFields[] = "is_active = ?";
    $updateValues[] = $is_active;
    
    // عکس را فقط اگر تغییر کرده آپدیت کن
    if (!empty($image)) {
        $updateFields[] = "image = ?";
        $updateValues[] = $image;
    }
    
    // ID را اضافه کن
    $updateValues[] = $id;
    
    $sql = "UPDATE posters SET " . implode(", ", $updateFields) . " WHERE id = ?";
    error_log("Update SQL: " . $sql);
    error_log("Update values: " . print_r($updateValues, true));
    
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute($updateValues);
    
    if ($result) {
        echo json_encode(['status' => true, 'message' => 'پوستر با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش پوستر.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

?>
