<?php
require_once '../../db_connection.php';
$conn = getPDO();
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
$stock = $data['stock'] ?? null;
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

// پردازش مشخصات سفارشی
$custom_specifications = [];
if (isset($data['custom_specifications'])) {
    if (is_string($data['custom_specifications'])) {
        $custom_specifications = json_decode($data['custom_specifications'], true) ?? [];
    } elseif (is_array($data['custom_specifications'])) {
        $custom_specifications = $data['custom_specifications'];
    }
}

// پردازش تصاویر آپلود شده
$uploaded_images = [];
$upload_dir = '../../uploads/products/';

// ایجاد پوشه اگر وجود ندارد
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// پردازش فایل‌های آپلود شده
if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
        // بررسی اینکه کلید شامل image_ باشد (فایل‌های تصویری)
        if (strpos($key, 'image_') === 0 && $file['error'] === UPLOAD_ERR_OK) {
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
                } else {
                    // Fallback: try copy for testing scenarios
                    if (copy($fileTmpPath, $destPath)) {
                        $uploaded_images[] = 'uploads/products/' . $newFileName;
                    }
                }
            }
        }
    }
}

// اگر تصاویری آپلود شده، اولین تصویر را به عنوان تصویر اصلی انتخاب کن
if (!empty($uploaded_images)) {
    $image = $uploaded_images[0];
}

$errors = [];

// اعتبارسنجی
if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if (!isset($cat_id) || $cat_id === null || $cat_id === '') $errors[] = 'دسته‌بندی خالی است';
if (!in_array($status, [0, 1, '0', '1'], true)) $errors[] = 'وضعیت نامعتبر است';
if (!is_numeric($stock) || $stock === '') $errors[] = 'موجودی نامعتبر است';
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

// تبدیل قطر و طول‌ها به JSON برای ذخیره در دیتابیس
$dimensions = json_encode(['diameters' => $diameters, 'lengths' => $lengths], JSON_UNESCAPED_UNICODE);

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
        (title, description, slug, cat_id, width, status, image, stock, price, discount_price, discount_percent, dimensions, size, type, brand, line_count, grade, half_finished, views, custom_specifications) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $result = $stmt->execute([
        $title, $description, $slug, $cat_id, $width, $status, $image, $stock, $price, $discount_price, $discount_percent, $dimensions, $size, $type, $brand, $line_count, $grade, $half_finished, $views, json_encode($custom_specifications, JSON_UNESCAPED_UNICODE)
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

        // درج تصاویر اضافی محصول
        if (!empty($uploaded_images)) {
            $stmtImage = $conn->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, ?)");
            foreach ($uploaded_images as $index => $imagePath) {
                $isPrimary = ($index === 0) ? 1 : 0; // اولین تصویر به عنوان تصویر اصلی
                $stmtImage->execute([
                    $productId,
                    $imagePath, // path کامل با uploads/products/
                    $isPrimary
                ]);
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
