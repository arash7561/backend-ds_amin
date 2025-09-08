<?php
require_once __DIR__ . '/../../db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Get all posters (active and inactive)
    $stmt = $conn->query("SELECT id, title, description, button_text, button_link, is_active, image, created_at, updated_at FROM posters ORDER BY id DESC");
    $posters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => true,
        'data' => $posters
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در دریافت پوسترها: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
