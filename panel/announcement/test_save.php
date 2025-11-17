<?php
// Simple test to see what error occurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../db_connection.php';
    require_once __DIR__ . '/../admin/middleware.php';
    
    $decoded = checkJWT();
    
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Test data
    $testData = [
        'is_active' => 1,
        'message' => 'تست',
        'message_color' => '#000000',
        'background_color' => '#f59e0b',
        'show_timer' => 0,
        'starts_at' => '',
        'ends_at' => '',
        'cta_label' => '',
        'cta_href' => '',
    ];
    
    $is_active = (int) !!$testData['is_active'];
    $message = trim($testData['message']);
    $message_color = trim($testData['message_color']);
    $background_color = trim($testData['background_color']);
    $show_timer = (int) !!$testData['show_timer'];
    $starts_at_raw = $testData['starts_at'] ?? null;
    $ends_at_raw = $testData['ends_at'] ?? null;
    
    $starts_at = null;
    if (!empty($starts_at_raw)) {
        $ts = strtotime((string)$starts_at_raw);
        if ($ts !== false) {
            $starts_at = date('Y-m-d H:i:s', $ts);
        }
    }
    $ends_at = null;
    if (!empty($ends_at_raw)) {
        $ts = strtotime((string)$ends_at_raw);
        if ($ts !== false) {
            $ends_at = date('Y-m-d H:i:s', $ts);
        }
    }
    
    $pdo->beginTransaction();
    $check = $pdo->query("SELECT id FROM site_announcements ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if ($check) {
        $stmt = $pdo->prepare("
            UPDATE site_announcements
            SET is_active = :is_active,
                message = :message,
                message_color = :message_color,
                background_color = :background_color,
                show_timer = :show_timer,
                starts_at = :starts_at,
                ends_at = :ends_at,
                cta_label = :cta_label,
                cta_href = :cta_href,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':is_active' => $is_active,
            ':message' => $message,
            ':message_color' => $message_color,
            ':background_color' => $background_color,
            ':show_timer' => $show_timer,
            ':starts_at' => $starts_at,
            ':ends_at' => $ends_at,
            ':cta_label' => null,
            ':cta_href' => null,
            ':id' => $check['id'],
        ]);
        $id = (int)$check['id'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO site_announcements
                (is_active, message, message_color, background_color, show_timer, starts_at, ends_at, cta_label, cta_href, created_at, updated_at)
            VALUES
                (:is_active, :message, :message_color, :background_color, :show_timer, :starts_at, :ends_at, :cta_label, :cta_href, NOW(), NOW())
        ");
        $stmt->execute([
            ':is_active' => $is_active,
            ':message' => $message,
            ':message_color' => $message_color,
            ':background_color' => $background_color,
            ':show_timer' => $show_timer,
            ':starts_at' => $starts_at,
            ':ends_at' => $ends_at,
            ':cta_label' => null,
            ':cta_href' => null,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
    
    echo json_encode([
        'status' => true,
        'message' => 'تست موفق بود',
        'id' => $id,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ], JSON_UNESCAPED_UNICODE);
}


