<?php
/**
 * ارسال پیامک یادآوری به ادمین در تاریخ تسویه چک
 * این اسکریپت باید توسط cron روزانه اجرا شود (مثلاً هر روز ساعت 8 صبح)
 * مثال cron: 0 8 * * * php /path/to/send_settlement_reminder.php
 *
 * برای فعال‌سازی ارسال واقعی SMS، سرویس پیامک (مثل کاوینگار) را در تابع sendSmsToAdmin ادغام کنید.
 */

require_once __DIR__ . '/../../db_connection.php';

$today = date('Y-m-d');

try {
    $conn = getPDO();

    // فاکتورهای تایید شده که امروز تاریخ تسویه دارند
    $stmt = $conn->prepare("
        SELECT i.id, i.total_amount, i.settlement_date, i.customer_name, i.customer_mobile,
               u.name AS marketer_name, u.mobile AS marketer_mobile
        FROM marketer_credit_invoices i
        LEFT JOIN users u ON u.id = i.marketer_id
        WHERE i.status = 'approved' AND i.settlement_date = ?
    ");
    $stmt->execute([$today]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($invoices)) {
        exit(0);
    }

    // دریافت شماره ادمین‌ها (از admin_users یا users با role=admin)
    $adminMobiles = [];
    try {
        $stmtAdmin = $conn->query("SELECT mobile FROM admin_users WHERE mobile IS NOT NULL AND mobile != '' LIMIT 5");
        while ($row = $stmtAdmin->fetch(PDO::FETCH_ASSOC)) {
            $adminMobiles[] = $row['mobile'];
        }
    } catch (PDOException $e) {
        // fallback: از users با role admin
        $stmtAdmin = $conn->prepare("SELECT mobile FROM users WHERE role = 'admin' AND mobile IS NOT NULL AND mobile != '' LIMIT 5");
        $stmtAdmin->execute();
        while ($row = $stmtAdmin->fetch(PDO::FETCH_ASSOC)) {
            $adminMobiles[] = $row['mobile'];
        }
    }

    if (empty($adminMobiles)) {
        error_log("Settlement reminder: No admin mobile found");
        exit(1);
    }

    $message = "یادآوری: امروز " . count($invoices) . " فاکتور اعتباری برای وصول چک دارید. ";
    foreach ($invoices as $inv) {
        $amount = number_format($inv['total_amount']);
        $message .= "فاکتور #" . $inv['id'] . " - " . $amount . " تومان - مشتری: " . ($inv['customer_name'] ?? $inv['customer_mobile'] ?? '-') . ". ";
    }
    $message .= "لطفاً چک‌ها را وصول و در پنل تسویه کنید.";

    foreach ($adminMobiles as $mobile) {
        sendSmsToAdmin($mobile, $message, $invoices);
    }

} catch (Exception $e) {
    error_log("Settlement reminder error: " . $e->getMessage());
    exit(1);
}

/**
 * ارسال SMS به ادمین
 * برای فعال‌سازی واقعی، این تابع را با API سرویس پیامک (کاوینگار، ملی‌پیامک و...) پر کنید.
 */
function sendSmsToAdmin($mobile, $message, $invoices) {
    // TODO: ادغام با سرویس SMS (مثال کاوینگار):
    // $apiKey = getenv('KAVENEGAR_API_KEY');
    // $url = "https://api.kavenegar.com/v1/{$apiKey}/sms/send.json";
    // $params = ['receptor' => $mobile, 'message' => $message];
    // file_get_contents($url . '?' . http_build_query($params));

    error_log("Settlement reminder SMS (to $mobile): " . $message);
    // برای تست: ذخیره در فایل
    $logFile = __DIR__ . '/settlement_reminders.log';
    $logEntry = date('Y-m-d H:i:s') . " | To: $mobile | " . $message . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}
