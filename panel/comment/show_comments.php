<?php
require_once '../../db_connection.php';
$pdo = getPDO();

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

try {
    $stmt = $pdo->query("SELECT id, content, created_at FROM comments ORDER BY id DESC");
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "comments" => $comments
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "خطا در دریافت نظرات.",
        "details" => $e->getMessage()
    ]);
}
?>
