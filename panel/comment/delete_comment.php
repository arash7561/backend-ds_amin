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

// گرفتن id از JSON body یا GET parameter
$id = null;

// اول سعی کن از JSON body بخون
$rawInput = file_get_contents("php://input");
if (!empty($rawInput)) {
    $data = json_decode($rawInput, true);
    if ($data !== null && isset($data['id'])) {
        $id = $data['id'];
    }
}

// اگر از body پیدا نشد، از GET parameter بخون
if ($id === null && isset($_GET['id'])) {
    $id = $_GET['id'];
}

// بررسی اینکه id وجود داشته باشد
if ($id === null || empty($id)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "شناسه کامنت الزامی است."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = intval($id);

try {
    $stmt = $pdo->prepare("DELETE FROM comments WHERE id = :id");
    $stmt->execute([":id" => $id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            "success" => true, 
            "message" => "کامنت با موفقیت حذف شد."
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "error" => "کامنتی با این شناسه پیدا نشد."
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "خطا در حذف کامنت.",
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
