<?php
/**
 * لیست کاربران سایت برای پنل ادمین
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

try {
    $conn = getPDO();

    $limit = isset($_GET['limit']) ? min(500, max(1, (int)$_GET['limit'])) : 100;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // تعداد کل کاربران
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM users");
    $total = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // لیست کاربران با تعداد دعوت‌شدگان (اختیاری)
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.name,
            u.mobile,
            u.created_at,
            u.role,
            u.invite_code,
            u.is_marketer,
            (SELECT COUNT(*) FROM users u2 WHERE u2.invited_by = u.id) AS invited_count
        FROM users u
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_marketer'] = isset($row['is_marketer']) ? (int)$row['is_marketer'] : 0;
        $row['invited_count'] = (int) ($row['invited_count'] ?? 0);
    }
    unset($row);

    echo json_encode([
        'status' => true,
        'data' => $users,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
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
