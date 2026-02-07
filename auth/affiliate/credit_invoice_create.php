<?php
/**
 * ثبت فاکتور اعتباری توسط بازاریاب
 * POST multipart/form-data: customer_id, customer_name, customer_mobile, total_amount, settlement_date, description, check_images[]
 */

$allowed_origins = [
    'http://localhost:3000', 'http://localhost:3001', 'http://localhost:3002',
    'http://127.0.0.1:3000', 'http://127.0.0.1:3001', 'http://127.0.0.1:3002',
    'https://aminindpharm.ir', 'http://aminindpharm.ir'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins) || strpos($origin, 'localhost') !== false || strpos($origin, 'aminindpharm.ir') !== false) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../jwt_utils.php';

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        return $headers;
    }
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$userId = null;

if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $authResult = verify_jwt_token($matches[1]);
    if ($authResult['valid']) {
        $userId = $authResult['uid'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    // بررسی وجود کاربر
    $stmt = $conn->prepare("SELECT id, is_marketer FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // همه کاربران لاگین‌شده می‌توانند فاکتور اعتباری ثبت کنند (خرید اعتباری برای خود یا مشتری)
    // marketer_id = user_id (کاربر درخواست‌دهنده)

    // دریافت داده‌ها از POST
    $customerId = isset($_POST['customer_id']) && $_POST['customer_id'] !== '' ? (int)$_POST['customer_id'] : null;
    $customerName = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : null;
    $customerMobile = isset($_POST['customer_mobile']) ? preg_replace('/[^0-9]/', '', trim($_POST['customer_mobile'])) : null;
    $orderId = isset($_POST['order_id']) && $_POST['order_id'] !== '' ? (int)$_POST['order_id'] : null;
    $totalAmount = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : 0;
    $settlementDate = isset($_POST['settlement_date']) ? trim($_POST['settlement_date']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;

    // آیتم‌های فاکتور (محصولات)
    $itemsRaw = isset($_POST['items']) ? $_POST['items'] : null;
    $items = [];
    if ($itemsRaw) {
        $decoded = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }

    // اگر آیتم داریم، مبلغ کل از جمع آیتم‌ها محاسبه می‌شود
    if (!empty($items)) {
        $calculatedTotal = 0;
        foreach ($items as $it) {
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $price = (int)($it['price'] ?? 0);
            $calculatedTotal += $price * $qty;
        }
        if ($totalAmount <= 0) {
            $totalAmount = $calculatedTotal;
        }
    }

    if ($totalAmount <= 0) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'مبلغ فاکتور باید بیشتر از صفر باشد. لطفاً محصولات را اضافه کنید یا مبلغ را وارد کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($settlementDate)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'تاریخ تسویه الزامی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تبدیل تاریخ شمسی به میلادی در صورت نیاز (اگر فرمت 1404-xx-xx باشد)
    $dateObj = DateTime::createFromFormat('Y-m-d', $settlementDate);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y/m/d', $settlementDate);
    }
    if (!$dateObj) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'فرمت تاریخ تسویه نامعتبر است (مثال: 1404-11-20)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $settlementDateFormatted = $dateObj->format('Y-m-d');

    // پارس گروه‌های تاریخ و تصویر (check_groups)
    $checkGroupsRaw = isset($_POST['check_groups']) ? $_POST['check_groups'] : null;
    $checkGroups = [];
    if ($checkGroupsRaw) {
        $decoded = is_string($checkGroupsRaw) ? json_decode($checkGroupsRaw, true) : $checkGroupsRaw;
        if (is_array($decoded)) {
            $checkGroups = $decoded;
        }
    }

    // آپلود تصاویر چک (با نگاشت به تاریخ تسویه هر گروه)
    $upload_dir = __DIR__ . '/../../uploads/credit_checks/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $checkImageData = []; // [ ['path' => ..., 'settlement_date' => ...], ... ]
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!empty($checkGroups)) {
        // فرمت جدید: فایل‌ها به ترتیب check_0, check_1, ... و هر گروه تاریخ و تعداد مشخص دارد
        $fileIndex = 0;
        foreach ($checkGroups as $group) {
            $groupDate = isset($group['date']) ? trim($group['date']) : '';
            $count = (int)($group['count'] ?? 0);
            if (empty($groupDate) || $count <= 0) continue;

            $dateObj = DateTime::createFromFormat('Y-m-d', $groupDate) ?: DateTime::createFromFormat('Y/m/d', $groupDate);
            $groupDateFormatted = $dateObj ? $dateObj->format('Y-m-d') : $groupDate;

            for ($i = 0; $i < $count; $i++) {
                $key = 'check_' . $fileIndex;
                if (isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES[$key];
                    $fileTmpPath = $file['tmp_name'];
                    $fileName = basename($file['name']);
                    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $fileSize = $file['size'] ?? 0;
                    if (in_array($fileExt, $allowedExt) && $fileSize <= $maxSize) {
                        $newFileName = 'check_' . uniqid() . '.' . $fileExt;
                        $destPath = $upload_dir . $newFileName;
                        if (move_uploaded_file($fileTmpPath, $destPath)) {
                            $checkImageData[] = ['path' => 'uploads/credit_checks/' . $newFileName, 'settlement_date' => $groupDateFormatted];
                        }
                    }
                }
                $fileIndex++;
            }
        }
    }

    // سازگاری با فرمت قدیم: اگر check_groups نبود، فایل‌های check_* را با settlement_date اصلی ذخیره کن
    if (empty($checkImageData) && !empty($_FILES)) {
        foreach ($_FILES as $key => $file) {
            if ((strpos($key, 'check_') === 0 || strpos($key, 'check_images') !== false) && isset($file['error']) && $file['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $file['tmp_name'];
                $fileName = basename($file['name']);
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileSize = $file['size'] ?? 0;
                if (in_array($fileExt, $allowedExt) && $fileSize <= $maxSize) {
                    $newFileName = 'check_' . uniqid() . '.' . $fileExt;
                    $destPath = $upload_dir . $newFileName;
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        $checkImageData[] = ['path' => 'uploads/credit_checks/' . $newFileName, 'settlement_date' => $settlementDateFormatted];
                    }
                }
            }
        }
    }

    // حداقل یک تصویر چک الزامی است
    if (empty($checkImageData)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'حداقل یک تصویر چک الزامی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO marketer_credit_invoices 
        (marketer_id, customer_id, customer_name, customer_mobile, order_id, total_amount, settlement_date, description, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $userId,
        $customerId,
        $customerName,
        $customerMobile,
        $orderId,
        (int)$totalAmount,
        $settlementDateFormatted,
        $description
    ]);

    $invoiceId = (int)$conn->lastInsertId();

    // ذخیره تصاویر چک (با settlement_date در صورت وجود ستون، وگرنه بدون آن)
    try {
        $stmtImg = $conn->prepare("INSERT INTO marketer_credit_check_images (invoice_id, image_path, settlement_date) VALUES (?, ?, ?)");
        foreach ($checkImageData as $item) {
            $stmtImg->execute([$invoiceId, $item['path'], $item['settlement_date']]);
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'settlement_date') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            // ستون settlement_date وجود ندارد - migration را اجرا نکرده‌اند، بدون آن ذخیره کن
            $stmtImg = $conn->prepare("INSERT INTO marketer_credit_check_images (invoice_id, image_path) VALUES (?, ?)");
            foreach ($checkImageData as $item) {
                $stmtImg->execute([$invoiceId, $item['path']]);
            }
        } else {
            throw $e;
        }
    }

    // ذخیره آیتم‌های فاکتور (محصولات) - در صورت وجود جدول
    if (!empty($items)) {
        try {
            $stmtItem = $conn->prepare("
                INSERT INTO marketer_credit_invoice_items (invoice_id, product_id, product_name, price, quantity, total_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $it) {
                $productId = (int)($it['product_id'] ?? 0);
                $productName = trim($it['product_name'] ?? '');
                $price = (int)($it['price'] ?? 0);
                $quantity = max(1, (int)($it['quantity'] ?? 1));
                $totalPrice = $price * $quantity;
                if ($productId > 0 && $productName !== '' && $price > 0) {
                    $stmtItem->execute([$invoiceId, $productId, $productName, $price, $quantity, $totalPrice]);
                }
            }
        } catch (PDOException $e) {
            // جدول marketer_credit_invoice_items ممکن است وجود نداشته باشد - migration را اجرا کنید
            error_log("Credit invoice items insert error (run migration?): " . $e->getMessage());
        }
    }

    $conn->commit();

    echo json_encode([
        'status' => true,
        'message' => 'فاکتور اعتباری با موفقیت ثبت شد',
        'invoice_id' => $invoiceId,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Credit invoice create error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Credit invoice create error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطا: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
