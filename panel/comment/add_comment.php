<?php
// CORS headers - باید قبل از هر چیز دیگری تنظیم شوند
// خواندن origin از header درخواست
$origin = null;

// خواندن origin از header درخواست
if (isset($_SERVER['HTTP_ORIGIN']) && !empty($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['Origin']) && !empty($headers['Origin'])) {
        $origin = $headers['Origin'];
    } elseif (isset($headers['origin']) && !empty($headers['origin'])) {
        $origin = $headers['origin'];
    }
}

// اگر origin وجود نداشت، از localhost:3000 به عنوان پیش‌فرض استفاده کن
if (!$origin || empty($origin)) {
    $origin = 'http://localhost:3000';
}

// تنظیم CORS headers - همیشه origin مشخص و credentials true
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle OPTIONS request (preflight) - باید بعد از تنظیم همه headers باشد
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // برای درخواست OPTIONS، فقط headers را برگردان و خروج کن
    http_response_code(200);
    exit;
}

// حالا می‌توانیم به دیتابیس متصل شویم
require_once '../../db_connection.php';
$pdo = getPDO();

// دریافت داده‌ها - پشتیبانی از FormData و JSON
$data = [];

// بررسی نوع محتوا
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'multipart/form-data') !== false || strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
    // FormData
    $data = $_POST;
    
    // پردازش فایل تصویر اگر وجود دارد
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $data['image'] = $_FILES['image'];
    }
} else {
    // JSON
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);
    
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        // اگر JSON نبود، سعی کن از $_POST بخونی
        $data = $_POST;
    }
}

$content = trim($data['content'] ?? $data['comment'] ?? ''); // حذف فاصله‌های اضافی

// بررسی اینکه متن خالی نباشه
if (empty($content)) {
    http_response_code(400);
    echo json_encode(["error" => "لطفاً متن نظر را وارد کنید."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // چک کن ببین آیا همین متن قبلاً ثبت شده یا نه
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content = :content");
    $checkStmt->execute([":content" => $content]);
    $exists = $checkStmt->fetchColumn();

    if ($exists > 0) {
        http_response_code(409); // 409 Conflict
        echo json_encode([
            "error" => "این نظر قبلاً ثبت شده است.",
            "message" => "متن تکراری مجاز نیست."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی ستون‌های موجود
    $stmt = $pdo->query("SHOW COLUMNS FROM comments");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // ساخت query درج بر اساس ستون‌های موجود
    $insertFields = ['content'];
    $insertValues = [':content'];
    $params = [':content' => $content];
    
    // فیلدهای اضافی که ممکن است وجود داشته باشند
    $optionalFields = [
        'name' => $data['name'] ?? null,
        'role' => $data['role'] ?? null,
        'title' => $data['title'] ?? null,
        'rating' => isset($data['rating']) ? intval($data['rating']) : null,
        'is_active' => isset($data['is_active']) ? 
            ($data['is_active'] == '1' || $data['is_active'] === '1' || $data['is_active'] === true || $data['is_active'] == 1) : 
            null,
    ];
    
    foreach ($optionalFields as $field => $value) {
        if (in_array($field, $columns) && $value !== null) {
            $insertFields[] = $field;
            $insertValues[] = ":$field";
            $params[":$field"] = $value;
        }
    }
    
    // پردازش تصویر اگر وجود دارد
    $imagePath = null;
    if (isset($data['image']) && is_array($data['image']) && $data['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../uploads/comments/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExtension = pathinfo($data['image']['name'], PATHINFO_EXTENSION);
        $fileName = 'comment_' . time() . '_' . uniqid() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($data['image']['tmp_name'], $filePath)) {
            $imagePath = 'uploads/comments/' . $fileName;
            if (in_array('image', $columns)) {
                $insertFields[] = 'image';
                $insertValues[] = ':image';
                $params[':image'] = $imagePath;
            }
        }
    }
    
    // اگر تکراری نبود، درج کن
    $insertQuery = "INSERT INTO comments (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute($params);

    echo json_encode([
        "success" => true,
        "id" => $pdo->lastInsertId(),
        "message" => "نظر با موفقیت ثبت شد."
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "خطایی در هنگام ثبت نظر رخ داده است.", 
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
