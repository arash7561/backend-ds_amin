<?php

require_once '../../db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

// بررسی نوع درخواست و استخراج داده‌ها
$data = [];

// اگر Content-Type شامل multipart/form-data باشد، از $_POST و $_FILES استفاده کن
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
    $data = (object) $_POST;
    // مدیریت فایل‌های آپلود شده
    if (!empty($_FILES)) {
        $data->uploaded_files = $_FILES;
    }
} else {
    // در غیر این صورت، JSON را از php://input بخوان
    $json = file_get_contents('php://input');
    $data = json_decode($json);
    if (!$data) {
        $data = (object) [];
    }
}

$id = $data->id ?? null;
$title = trim($data->title ?? '');
$description = trim($data->description ?? '');
$slug = trim($data->slug ?? '');
$cat_id = $data->cat_id ?? $data->category ?? null;
$status = $data->status ?? 'active';

// تبدیل وضعیت به فرمت مناسب برای دیتابیس
// همیشه به رشته تبدیل کن
if (is_numeric($status)) {
    $status = ($status == 1) ? 'active' : 'inactive';
} elseif (is_string($status)) {
    $status = strtolower(trim($status));
    if ($status === 'active' || $status === '1' || $status === 'true') {
        $status = 'active';
    } elseif ($status === 'inactive' || $status === '0' || $status === 'false') {
        $status = 'inactive';
    } else {
        $status = 'active';
    }
} else {
    $status = 'active';
}

$image = trim($data->image ?? '');
$stock = $data->stock ?? null;
$price = $data->price ?? null;
$discount_price = $data->discount_price ?? null;
$size = trim($data->size ?? '');
$type = trim($data->type ?? '');
$brand = trim($data->brand ?? '');
$line_count = $data->line_count ?? null;
$dimensions_text = trim($data->dimensions ?? '');
$grade = trim($data->grade ?? '');
$half_finished = $data->half_finished ?? 0;
$views = $data->views ?? 0;
$width = trim($data->width ?? '');
$discount_percent = $data->discount_percent ?? null;
// فیلدهای اضافی
$color = trim($data->color ?? '');
$material = trim($data->material ?? '');
$slot_count = $data->slot_count ?? null;
$general_description = trim($data->general_description ?? '');
$weight = trim($data->weight ?? '');

// پردازش dimensions - اگر خالی است، JSON خالی بده
$dimensions = '';
if (!empty($dimensions_text)) {
    // اگر به نظر JSON است، همان‌طور نگه دار
    if (json_decode($dimensions_text) !== null) {
        $dimensions = $dimensions_text;
    } else {
        // در غیر این صورت، آن را به JSON تبدیل کن
        $dimensions = json_encode(['text' => $dimensions_text], JSON_UNESCAPED_UNICODE);
    }
} else {
    // اگر خالی است، JSON خالی
    $dimensions = '{}';
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
        // بررسی اینکه کلید شامل image باشد (فایل‌های تصویری)
        if ((strpos($key, 'image_') === 0 || $key === 'image') && $file['error'] === UPLOAD_ERR_OK) {
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
} else {
    // اگر تصویر جدیدی آپلود نشده، تصویر قدیمی را حفظ کن
    // فقط اگر در درخواست، image خالی نیست
    if (empty($image)) {
        // بررسی تصویر فعلی محصول در دیتابیس
        $stmt = $conn->prepare("SELECT image FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $currentProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($currentProduct && !empty($currentProduct['image'])) {
            $image = $currentProduct['image'];
        }
    }
}

$errors = [];

if (!$id || !is_numeric($id)) $errors[] = 'آیدی محصول معتبر نیست';
if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if (!isset($cat_id)) $errors[] = 'دسته بندی خالی است';
if (!is_numeric($stock)) $errors[] = 'موجودی نامعتبر است';
if (!is_numeric($price)) $errors[] = 'قیمت نامعتبر است';

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode(['status' => false, 'message' => 'محصولی با این آیدی پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تولید slug اگر ارسال نشده باشد
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
    }

    // آپدیت محصول
    $stmt = $conn->prepare("UPDATE products SET 
        title = ?,
        description = ?,
        slug = ?,
        cat_id = ?,
        status = ?,
        image = ?,
        stock = ?,
        price = ?,
        discount_price = ?,
        discount_percent = ?,
        size = ?,
        type = ?,
        brand = ?,
        line_count = ?,
        dimensions = ?,
        grade = ?,
        half_finished = ?,
        views = ?,
        width = ?,
        color = ?,
        material = ?,
        slot_count = ?,
        general_description = ?,
        weight = ?
      WHERE id = ?");
    $result = $stmt->execute([
        $title,
        $description,
        $slug,
        $cat_id,
        $status,
        $image,
        $stock,
        $price,
        $discount_price,
        $discount_percent,
        $size,
        $type,
        $brand,
        $line_count,
        $dimensions,
        $grade,
        $half_finished,
        $views,
        $width,
        $color,
        $material,
        $slot_count,
        $general_description,
        $weight,
        $id
    ]);

    if ($result) {
        // اگر تصاویر جدیدی آپلود شده، آن‌ها را به جدول product_images اضافه کن
        if (!empty($uploaded_images)) {
            // حذف تصاویر قدیمی محصول (اختیاری - ممکن است بخواهید آن‌ها را نگه دارید)
            // $stmt = $conn->prepare("DELETE FROM product_images WHERE product_id = ?");
            // $stmt->execute([$id]);
            
            // اضافه کردن تصاویر جدید
            $stmtImage = $conn->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, ?)");
            foreach ($uploaded_images as $index => $imagePath) {
                $isPrimary = ($index === 0) ? 1 : 0; // اولین تصویر به عنوان تصویر اصلی
                $stmtImage->execute([
                    $id,
                    $imagePath,
                    $isPrimary
                ]);
            }
        }
        
        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش محصول.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>