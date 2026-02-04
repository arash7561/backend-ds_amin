<?php
require_once '../../db_connection.php';
$conn = getPDO();

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

// Set CORS headers for actual requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json');

// بررسی نوع درخواست و استخراج داده‌ها
$data = [];

// Handle both FormData and JSON requests

// اگر Content-Type شامل multipart/form-data باشد، از $_POST و $_FILES استفاده کن
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
    $data = $_POST;
    // مدیریت فایل‌های آپلود شده
    if (!empty($_FILES)) {
        $data['uploaded_files'] = $_FILES;
    }
} else {
    // در غیر این صورت، JSON را از php://input بخوان
    $json = file_get_contents('php://input');
    $data = json_decode($json, true) ?? [];
}

// استخراج داده‌ها با فیلتر XSS و اطمینان از وجود مقدار
$title = htmlspecialchars(trim($data['title'] ?? ''));
$description = htmlspecialchars(trim($data['description'] ?? ''));
$slug = htmlspecialchars(trim($data['slug'] ?? ''));
$cat_id = $data['cat_id'] ?? $data['category'] ?? null; // پشتیبانی از هر دو نام فیلد
$status = $data['status'] ?? null;
$image = htmlspecialchars(trim($data['image'] ?? ''));
// موجودی: اگر خالی یا null باشد، null در نظر گرفته می‌شود (نامحدود)
$stock = isset($data['stock']) && $data['stock'] !== '' && $data['stock'] !== null ? $data['stock'] : null;
$price = $data['price'] ?? null;
$discount_percent = $data['discount_percent'] ?? 0;
$size = htmlspecialchars(trim($data['size'] ?? ''));
$width = htmlspecialchars(trim($data['width'] ?? ''));
$type = htmlspecialchars(trim($data['type'] ?? ''));
$brand = htmlspecialchars(trim($data['brand'] ?? ''));
$line_count = $data['line_count'] ?? null;
$dimensions_text = htmlspecialchars(trim($data['dimensions'] ?? ''));
$grade = htmlspecialchars(trim($data['grade'] ?? ''));
$half_finished = $data['half_finished'] ?? 0;
$views = $data['views'] ?? 0;
// فیلدهای اضافی مطابق ستون‌های دیتابیس
$color = htmlspecialchars(trim($data['color'] ?? ''));
$material = htmlspecialchars(trim($data['material'] ?? ''));
$slot_count = $data['slot_count'] ?? null;
$general_description = htmlspecialchars(trim($data['general_description'] ?? ''));
$weight = htmlspecialchars(trim($data['weight'] ?? ''));

// دریافت آرایه‌های قطر و طول (اگر موجود نبودند آرایه خالی در نظر گرفته میشن)
$diameters = isset($data['diameters']) && is_array($data['diameters']) ? $data['diameters'] : [];
$lengths = isset($data['lengths']) && is_array($data['lengths']) ? $data['lengths'] : [];

// اگر قطر و طول به صورت تک مقدار آمده‌اند، آن‌ها را به آرایه تبدیل کن
if (isset($data['diameter']) && !empty($data['diameter'])) {
    $diameters = [$data['diameter']];
}
if (isset($data['length']) && !empty($data['length'])) {
    $lengths = [$data['length']];
}

// دریافت قوانین تخفیف تعدادی (اگر موجود نبود آرایه خالی)
$discount_rules = isset($data['discount_rules']) && is_array($data['discount_rules']) ? $data['discount_rules'] : [];

// حذف پشتیبانی از مشخصات سفارشی - از فیلدهای اختصاصی استفاده می‌شود

// پردازش تصاویر آپلود شده
$uploaded_images = [];
$upload_dir = '../../uploads/products/';

// Debug: Log received files
error_log("create.php: Received " . count($_FILES) . " files");
foreach ($_FILES as $key => $file) {
    error_log("create.php: File key: $key, error: " . ($file['error'] ?? 'N/A'));
}

// ایجاد پوشه اگر وجود ندارد
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// پردازش فایل‌های آپلود شده
if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
        // بررسی اینکه کلید شامل image_ باشد (فایل‌های تصویری)
        if (strpos($key, 'image_') === 0 && $file['error'] === UPLOAD_ERR_OK) {
            error_log("create.php: Processing file: $key");
            $fileTmpPath = $file['tmp_name'];
            $fileName = basename($file['name']);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // بررسی فرمت فایل
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($fileExt, $allowedExt)) {
                $newFileName = uniqid('prod_') . '.' . $fileExt;
                $destPath = $upload_dir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $uploaded_images[] = 'uploads/products/' . $newFileName;
                    error_log("create.php: Successfully uploaded: $newFileName");
                } else {
                    // Fallback: try copy for testing scenarios
                    if (copy($fileTmpPath, $destPath)) {
                        $uploaded_images[] = 'uploads/products/' . $newFileName;
                        error_log("create.php: Successfully copied: $newFileName");
                    } else {
                        error_log("create.php: Failed to upload: $newFileName");
                    }
                }
            }
        }
    }
}

// پردازش تصاویر انتخاب شده از دسته‌بندی
$category_image_urls = [];
if (isset($data['category_image_urls'])) {
    $category_image_urls_json = $data['category_image_urls'];
    if (is_string($category_image_urls_json)) {
        $category_image_urls = json_decode($category_image_urls_json, true) ?? [];
    } elseif (is_array($category_image_urls_json)) {
        $category_image_urls = $category_image_urls_json;
    }
    
    // اضافه کردن تصاویر دسته‌بندی به لیست تصاویر
    foreach ($category_image_urls as $imgUrl) {
        if (!empty($imgUrl) && is_string($imgUrl)) {
            // اگر URL کامل است، مسیر نسبی را استخراج کن
            $relativePath = $imgUrl;
            if (strpos($imgUrl, 'uploads/') !== false) {
                // استخراج مسیر نسبی از URL کامل
                $parts = explode('uploads/', $imgUrl);
                if (count($parts) > 1) {
                    $relativePath = 'uploads/' . $parts[1];
                }
            }
            $uploaded_images[] = $relativePath;
        }
    }
}

// اگر تصاویری آپلود شده یا از دسته‌بندی انتخاب شده، اولین تصویر را به عنوان تصویر اصلی انتخاب کن
if (!empty($uploaded_images)) {
    $image = $uploaded_images[0];
}

$errors = [];

// اعتبارسنجی
if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if (!isset($cat_id) || $cat_id === null || $cat_id === '') $errors[] = 'دسته‌بندی خالی است';
if (!in_array($status, [0, 1, '0', '1'], true)) $errors[] = 'وضعیت نامعتبر است';
// موجودی می‌تواند null باشد (نامحدود) یا یک عدد معتبر
if ($stock !== null && $stock !== '' && (!is_numeric($stock) || $stock < 0)) $errors[] = 'موجودی نامعتبر است';
if (!is_numeric($price) || $price === '') $errors[] = 'قیمت نامعتبر است';
if (!is_numeric($discount_percent) || $discount_percent < 0 || $discount_percent > 100)
    $errors[] = 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد';

// اعتبارسنجی قطرها
foreach ($diameters as $d) {
    if (!is_numeric($d)) {
        $errors[] = 'یکی از مقادیر قطر نامعتبر است';
        break;
    }
}

// اعتبارسنجی طول‌ها
foreach ($lengths as $l) {
    if (!is_numeric($l)) {
        $errors[] = 'یکی از مقادیر طول نامعتبر است';
        break;
    }
}

// اعتبارسنجی قوانین تخفیف تعدادی
foreach ($discount_rules as $rule) {
    if (!isset($rule['min_quantity']) || !is_numeric($rule['min_quantity']) || $rule['min_quantity'] < 1) {
        $errors[] = 'مقدار حداقل تعداد در قوانین تخفیف معتبر نیست';
        break;
    }
    if (!isset($rule['discount_percent']) || !is_numeric($rule['discount_percent']) || $rule['discount_percent'] < 0 || $rule['discount_percent'] > 100) {
        $errors[] = 'درصد تخفیف در قوانین تخفیف معتبر نیست';
        break;
    }
}

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// تولید slug اگر ارسال نشده باشد
if (empty($slug)) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
}

// محاسبه قیمت تخفیف‌خورده
$discount_price = $price - ($price * $discount_percent / 100);

// دریافت واحدهای اندازه‌گیری
$length_unit = htmlspecialchars(trim($data['length_unit'] ?? 'cm'));
$diameter_unit = htmlspecialchars(trim($data['diameter_unit'] ?? 'cm'));
$width_unit = htmlspecialchars(trim($data['width_unit'] ?? 'cm'));

// دریافت مقادیر تک طول و قطر (اگر به صورت تک مقدار ارسال شده باشند)
$length = htmlspecialchars(trim($data['length'] ?? ''));
$diameter = htmlspecialchars(trim($data['diameter'] ?? ''));

// تبدیل قطر و طول‌ها به JSON برای ذخیره در دیتابیس (شامل واحدها)
$dimensions = json_encode([
    'diameters' => $diameters,
    'lengths' => $lengths,
    'length' => $length,
    'length_unit' => $length_unit,
    'diameter' => $diameter,
    'diameter_unit' => $diameter_unit,
    'width' => $width,
    'width_unit' => $width_unit
], JSON_UNESCAPED_UNICODE);

try {
    // بررسی یکتا بودن slug
    $stmt = $conn->prepare("SELECT id FROM products WHERE slug = ?");
    $stmt->execute([$slug]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo json_encode(['status' => false, 'message' => 'اسلاگ قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // درج محصول با تمام فیلدها
    $stmt = $conn->prepare("INSERT INTO products 
        (title, description, slug, cat_id, width, status, image, stock, price, discount_price, discount_percent, dimensions, size, type, brand, line_count, grade, half_finished, views, color, material, slot_count, general_description, weight) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $result = $stmt->execute([
        $title,
        $description,
        $slug,
        $cat_id,
        $width,
        $status,
        $image,
        $stock,
        $price,
        $discount_price,
        $discount_percent,
        $dimensions,
        $size,
        $type,
        $brand,
        $line_count,
        $grade,
        $half_finished,
        $views,
        $color,
        $material,
        $slot_count,
        $general_description,
        $weight
    ]);

    if ($result) {
        $productId = $conn->lastInsertId();

        // درج قوانین تخفیف تعدادی مرتبط با محصول
        if (!empty($discount_rules)) {
            $stmtRule = $conn->prepare("INSERT INTO product_discount_rules (product_id, min_quantity, discount_percent) VALUES (?, ?, ?)");
            foreach ($discount_rules as $rule) {
                $stmtRule->execute([
                    $productId,
                    $rule['min_quantity'],
                    $rule['discount_percent']
                ]);
            }
        }

        // درج تصاویر اضافی محصول (اگر جدول product_images وجود داشته باشد)
        if (!empty($uploaded_images)) {
            try {
                $stmtImage = $conn->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, ?)");
                foreach ($uploaded_images as $index => $imagePath) {
                    $isPrimary = ($index === 0) ? 1 : 0; // اولین تصویر به عنوان تصویر اصلی
                    $stmtImage->execute([
                        $productId,
                        $imagePath, // path کامل با uploads/products/
                        $isPrimary
                    ]);
                }
            } catch (PDOException $e) {
                // اگر جدول product_images وجود نداشت، خطا را نادیده بگیر
                // تصویر اصلی قبلاً در جدول products ذخیره شده است
            }
        }

        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت ثبت شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'در ثبت محصول مشکلی پیش آمد.'], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
