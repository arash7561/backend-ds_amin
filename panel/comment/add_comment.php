<?php
require_once '../../db_connection.php';
$pdo = getPDO();

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

$data = json_decode(file_get_contents("php://input"), true);

// بررسی اینکه متن خالی نباشه
if (empty($data['content'])) {
    http_response_code(400);
    echo json_encode(["error" => "لطفاً متن نظر را وارد کنید."]);
    exit;
}

$content = trim($data['content']); // حذف فاصله‌های اضافی

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
        ]);
        exit;
    }

    // اگر تکراری نبود، درج کن
    $stmt = $pdo->prepare("INSERT INTO comments (content) VALUES (:content)");
    $stmt->execute([":content" => $content]);

    echo json_encode([
        "success" => true,
        "id" => $pdo->lastInsertId(),
        "message" => "نظر با موفقیت ثبت شد."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "خطایی در هنگام ثبت نظر رخ داده است.", "details" => $e->getMessage()]);
}
?>
