<?php
require_once '../../db_connection.php';
$pdo = getPDO();

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: PUT, POST");

// گرفتن داده‌ها از JSON
$data = json_decode(file_get_contents("php://input"), true);

// بررسی فیلدها
if (empty($data['id']) || empty($data['content'])) {
    http_response_code(400);
    echo json_encode(["error" => "شناسه (id) و متن نظر الزامی است."]);
    exit;
}

$id = intval($data['id']);
$content = trim($data['content']);

try {
    // بررسی وجود نظر با id داده‌شده
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE id = :id");
    $checkStmt->execute([":id" => $id]);
    $exists = $checkStmt->fetchColumn();

    if ($exists == 0) {
        http_response_code(404);
        echo json_encode(["error" => "نظری با این شناسه پیدا نشد."]);
        exit;
    }

    // بررسی تکراری نبودن متن
    $duplicateStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content = :content AND id != :id");
    $duplicateStmt->execute([":content" => $content, ":id" => $id]);
    $duplicate = $duplicateStmt->fetchColumn();

    if ($duplicate > 0) {
        http_response_code(409);
        echo json_encode(["error" => "این متن قبلاً در نظر دیگری ثبت شده است."]);
        exit;
    }

    // به‌روزرسانی نظر
    $stmt = $pdo->prepare("UPDATE comments SET content = :content WHERE id = :id");
    $stmt->execute([
        ":content" => $content,
        ":id" => $id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "نظر با موفقیت ویرایش شد."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "خطایی در ویرایش نظر رخ داده است.", "details" => $e->getMessage()]);
}
?>
