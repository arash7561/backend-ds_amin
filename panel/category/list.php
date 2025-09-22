<?php
require_once '../../db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

try {
    $stmt = $conn->prepare("SELECT * FROM categories WHERE status = 1 ORDER BY name ASC");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($categories, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'error' => true,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => 'خطای عمومی: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
