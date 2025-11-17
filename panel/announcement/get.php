<?php
require_once __DIR__ . '/../../db_connection.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');
// Debug (development only)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify table exists
    $tblCheck = $pdo->query("SHOW TABLES LIKE 'site_announcements'")->fetch(PDO::FETCH_NUM);
    if (!$tblCheck) {
        echo json_encode([
            'status' => true,
            'data' => null,
            'note' => 'site_announcements table not found',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Try to get currently active by time window if active
    // Use PHP date to avoid DateTimeZone issues on some environments
    $now = date('Y-m-d H:i:s');
    $sql = "
        SELECT id, is_active, message, message_color, background_color, show_timer, starts_at, ends_at, cta_label, cta_href, updated_at
        FROM site_announcements
        WHERE is_active = 1
          AND (starts_at IS NULL OR starts_at <= :now1)
          AND (ends_at IS NULL OR ends_at >= :now2)
        ORDER BY updated_at DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':now1' => $now, ':now2' => $now]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Fallback: latest row even if outside time window
        $stmt = $pdo->query("
            SELECT id, is_active, message, message_color, background_color, show_timer, starts_at, ends_at, cta_label, cta_href, updated_at
            FROM site_announcements
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => true,
        'data' => $row ?: null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در دریافت اطلاعیه: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}


