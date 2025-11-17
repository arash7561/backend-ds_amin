<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Display errors for debugging
ini_set('log_errors', 1);

// Set error handler to catch all errors
set_error_handler(function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});

// CORS headers - MUST be set before any output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');
// Ensure PHP doesn't mangle input
ini_set('default_charset', 'UTF-8');

// Handle OPTIONS preflight FIRST, before any require/include
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Now require files after OPTIONS is handled
try {
    require_once __DIR__ . '/../../db_connection.php';
    require_once __DIR__ . '/../admin/middleware.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در بارگذاری فایل‌های مورد نیاز: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Protect with JWT admin middleware (bypass is handled inside middleware.php if ?dev=1)
$decoded = checkJWT(); // exits on failure with 401, or returns decoded token (or bypass for localhost dev)
// Optionally, check role inside $decoded if needed

try {
    $pdo = getPDO();
    // Ensure PDO throws exceptions
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure table exists (auto-create if missing) - wrapped in try-catch
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS site_announcements (
                id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                message TEXT NOT NULL,
                message_color VARCHAR(32) NOT NULL DEFAULT '#000000',
                background_color VARCHAR(32) NOT NULL DEFAULT '#f59e0b',
                show_timer TINYINT(1) NOT NULL DEFAULT 0,
                starts_at DATETIME NULL DEFAULT NULL,
                ends_at DATETIME NULL DEFAULT NULL,
                cta_label VARCHAR(100) NULL,
                cta_href VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $tableError) {
        // Table might already exist or there's a permission issue
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'site_announcements'")->fetch();
        if (!$tableCheck) {
            throw new Exception('نمی‌توان جدول را ایجاد کرد: ' . $tableError->getMessage());
        }
    }

    // Accept JSON and x-www-form-urlencoded
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = null;
    }
    if (!is_array($data) || $data === null || $data === []) {
        // Fallback to POST vars if JSON not parsed
        $data = $_POST ?? [];
    }
    if (!is_array($data)) {
        throw new Exception('داده نامعتبر است - نوع داده: ' . gettype($data));
    }
    // Allow empty array for reset operations, but log it
    if (empty($data)) {
        // This is OK - might be a reset operation
    }

    $is_active = isset($data['is_active']) ? (int) !!$data['is_active'] : 0;
    $message = trim($data['message'] ?? '');
    $message_color = trim($data['message_color'] ?? '#000000');
    $background_color = trim($data['background_color'] ?? '#f59e0b');
    $show_timer = isset($data['show_timer']) ? (int) !!$data['show_timer'] : 0;
    $starts_at_raw = $data['starts_at'] ?? null;
    $ends_at_raw = $data['ends_at'] ?? null;
    $cta_label = trim($data['cta_label'] ?? '');
    $cta_href = trim($data['cta_href'] ?? '');

    // Normalize empty strings to NULL for datetime fields
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

    // Basic validations/sanitization
    if ($message) {
        $msgLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($msgLen > 5000) {
            $message = function_exists('mb_substr') ? mb_substr($message, 0, 5000) : substr($message, 0, 5000);
        }
    }
    // Normalize color strings
    try {
        $normalizeColor = function($c) {
            $c = trim((string)$c);
            if ($c === '') return null;
            // Allow formats like #fff or #ffffff
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c)) {
                return strtolower($c);
            }
            // If not valid hex, return original (don't fail)
            return $c;
        };
        $mc = $normalizeColor($message_color);
        $bc = $normalizeColor($background_color);
        if ($mc !== null) $message_color = $mc;
        if ($bc !== null) $background_color = $bc;
    } catch (Exception $colorError) {
        // Color normalization failed, use defaults
        $message_color = '#000000';
        $background_color = '#f59e0b';
    }

    // Business validations
    if ($is_active && $message === '') {
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => 'متن اطلاعیه خالی است.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($show_timer && !$ends_at) {
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => 'برای تایمر، زمان پایان اجباری است.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Upsert: if there is at least one row, update the latest; else insert new
    $pdo->beginTransaction();
    try {
        $check = $pdo->query("SELECT id FROM site_announcements ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw new Exception('خطا در بررسی رکورد موجود: ' . $e->getMessage());
    }
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
        $ok = $stmt->execute([
            ':is_active' => $is_active,
            ':message' => $message,
            ':message_color' => $message_color,
            ':background_color' => $background_color,
            ':show_timer' => $show_timer,
            ':starts_at' => $starts_at,
            ':ends_at' => $ends_at,
            ':cta_label' => $cta_label ?: null,
            ':cta_href' => $cta_href ?: null,
            ':id' => $check['id'],
        ]);
        if (!$ok) {
            throw new Exception('عدم موفقیت در بروزرسانی رکورد');
        }
        $id = (int)$check['id'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO site_announcements
                (is_active, message, message_color, background_color, show_timer, starts_at, ends_at, cta_label, cta_href, created_at, updated_at)
            VALUES
                (:is_active, :message, :message_color, :background_color, :show_timer, :starts_at, :ends_at, :cta_label, :cta_href, NOW(), NOW())
        ");
        $ok = $stmt->execute([
            ':is_active' => $is_active,
            ':message' => $message,
            ':message_color' => $message_color,
            ':background_color' => $background_color,
            ':show_timer' => $show_timer,
            ':starts_at' => $starts_at,
            ':ends_at' => $ends_at,
            ':cta_label' => $cta_label ?: null,
            ':cta_href' => $cta_href ?: null,
        ]);
        if (!$ok) {
            throw new Exception('عدم موفقیت در ایجاد رکورد');
        }
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'تنظیمات با موفقیت ذخیره شد',
        'id' => $id,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
        'code' => $e->getCode(),
    ], JSON_UNESCAPED_UNICODE);
} catch (ErrorException $e) {
    // Catch errors converted to exceptions by error handler
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای PHP (ErrorException): ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'severity' => $e->getSeverity(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در ذخیره تنظیمات: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    // Catch PHP 7+ errors (TypeError, etc.)
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای PHP: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Catch any other throwable (PHP 7+)
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای غیرمنتظره: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'type' => get_class($e),
    ], JSON_UNESCAPED_UNICODE);
}


