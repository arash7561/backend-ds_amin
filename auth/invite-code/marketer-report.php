<?php
// api/marketer-report.php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../db_connection.php';
$conn = getPDO();

try {
    $sql = "
    SELECT 
        m.id AS marketer_id,
        m.name AS marketer_name,
        m.mobile AS marketer_mobile,
        
        u.id AS sub_user_id,
        u.name AS sub_user_name,
        u.mobile AS sub_user_mobile,
        
        COALESCE(SUM(oi.total_price), 0) AS sub_user_total,
        
        (SELECT COALESCE(SUM(oi2.total_price),0)
         FROM users AS u2
         INNER JOIN orders AS o2 ON o2.user_id = u2.id
         INNER JOIN order_items AS oi2 ON oi2.order_id = o2.id
         WHERE u2.invited_by = m.id
        ) AS marketer_total
    FROM users AS m
    LEFT JOIN users AS u ON u.invited_by = m.id
    LEFT JOIN orders AS o ON o.user_id = u.id
    LEFT JOIN order_items AS oi ON oi.order_id = o.id
    WHERE m.is_marketer = 1
    GROUP BY m.id, u.id
    ORDER BY m.id, sub_user_total DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => true,
        'data' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای سرور: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
