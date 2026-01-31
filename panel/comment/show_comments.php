<?php
require_once '../../db_connection.php';
$pdo = getPDO();

// CORS headers - تنظیم origin به صورت داینامیک
// خواندن origin از header درخواست
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : null;

// اگر origin وجود نداشت، سعی کن از getallheaders بخونی
if (!$origin && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['Origin'])) {
        $origin = $headers['Origin'];
    } elseif (isset($headers['origin'])) {
        $origin = $headers['origin'];
    }
}

// تنظیم CORS headers - همیشه origin را تنظیم کن و credentials را true کن
// چون درخواست با credentials: include است، باید origin مشخص باشد
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // اگر origin وجود نداشت، از localhost:3000 به عنوان پیش‌فرض استفاده کن
    // این برای development است
    header('Access-Control-Allow-Origin: http://localhost:3000');
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle OPTIONS request (preflight) - باید قبل از exit هم headers را تنظیم کنیم
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Check if table has additional columns, otherwise use basic structure
    $stmt = $pdo->query("SHOW COLUMNS FROM comments");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build SELECT query based on available columns
    $selectFields = ['id', 'content', 'created_at'];
    $availableFields = ['name', 'role', 'title', 'image', 'rating', 'is_active'];
    
    foreach ($availableFields as $field) {
        if (in_array($field, $columns)) {
            $selectFields[] = $field;
        }
    }
    
    $query = "SELECT " . implode(', ', $selectFields) . " FROM comments ORDER BY id DESC";
    $stmt = $pdo->query($query);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalize comments to match frontend expectations
    $normalizedComments = array_map(function($comment) {
        return [
            'id' => $comment['id'] ?? null,
            'content' => $comment['content'] ?? $comment['comment'] ?? '',
            'comment' => $comment['content'] ?? $comment['comment'] ?? '',
            'name' => $comment['name'] ?? 'کاربر',
            'role' => $comment['role'] ?? $comment['position'] ?? '',
            'title' => $comment['title'] ?? $comment['subject'] ?? '',
            'image' => $comment['image'] ?? $comment['avatar'] ?? $comment['photo'] ?? '',
            'rating' => isset($comment['rating']) ? (int)$comment['rating'] : 5,
            'is_active' => isset($comment['is_active']) ? 
                ($comment['is_active'] == 1 || $comment['is_active'] === '1' || $comment['is_active'] === true) : 
                true,
            'created_at' => $comment['created_at'] ?? null
        ];
    }, $comments);

    echo json_encode([
        "success" => true,
        "comments" => $normalizedComments
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "خطا در دریافت نظرات.",
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
