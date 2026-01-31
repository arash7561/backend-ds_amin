<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// این فایل برای لاگ‌آوت استفاده می‌شود
// در سیستم JWT، logout بیشتر سمت کلاینت انجام می‌شود (حذف cookie)
// این API فقط برای اطمینان از پاک شدن session در سرور (در صورت نیاز) است

try {
    // اگر session استفاده می‌کنید، می‌توانید آن را destroy کنید
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    
    echo json_encode([
        'status' => true,
        'message' => 'خروج با موفقیت انجام شد'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در خروج: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

