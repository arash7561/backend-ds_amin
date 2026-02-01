<?php
/**
 * لیست کاربرانی که توسط یک کاربر خاص دعوت شده‌اند (برای پنل ادمین)
 * GET: user_id=
 */

require_once __DIR__ . '/../../db_connection.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست GET مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
if ($userId <= 0) {
    echo json_encode(['status' => false, 'message' => 'user_id معتبر نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    $stmt = $conn->prepare("
        SELECT id, name, mobile, created_at
        FROM users
        WHERE invited_by = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);

    echo json_encode([
        'status' => true,
        'data' => $users,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
