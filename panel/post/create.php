<?php
require_once '../../db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

// --- استخراج داده‌ها ---
$title       = htmlspecialchars(trim($data['title'] ?? ''));
$description = htmlspecialchars(trim($data['description'] ?? ''));
$cat_id      = $data['cat_id'] ?? null;
$status      = $data['status'] ?? null;
$image       = htmlspecialchars(trim($data['image'] ?? ''));
$stock       = $data['stock'] ?? null;
$price       = $data['price'] ?? null;
$discount_percent = $data['discount_percent'] ?? 0;

// فیلدهای جدید
$size          = htmlspecialchars(trim($data['size'] ?? ''));
$type          = htmlspecialchars(trim($data['type'] ?? ''));
$brand         = htmlspecialchars(trim($data['brand'] ?? ''));
$line_count    = isset($data['line_count']) ? (int)$data['line_count'] : null;
$grade         = isset($data['grade']) ? (float)$data['grade'] : null;
$half_finished = htmlspecialchars(trim($data['half_finished'] ?? ''));

// آرایه طول و قطر
$diameters = isset($data['diameters']) && is_array($data['diameters']) ? $data['diameters'] : [];
$lengths   = isset($data['lengths']) && is_array($data['lengths']) ? $data['lengths'] : [];

// قوانین تخفیف
$discount_rules = isset($data['discount_rules']) && is_array($data['discount_rules']) ? $data['discount_rules'] : [];

// --- توابع کمکی ---
function makeSlug($string) {
    $slug = mb_strtolower($string, 'UTF-8');
    $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function getUniqueSlug($conn, $slug) {
    $baseSlug = $slug;
    $i = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() == 0) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $i;
        $i++;
    }
}

// --- اعتبارسنجی ---
$errors = [];
if ($title === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if (!isset($cat_id)) $errors[] = 'دسته‌بندی خالی است';
if (!in_array($status, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است';
if ($image === '') $errors[] = 'تصویر خالی است';
if (!is_numeric($stock)) $errors[] = 'موجودی نامعتبر است';
if (!is_numeric($price)) $errors[] = 'قیمت نامعتبر است';
if (!is_numeric($discount_percent) || $discount_percent < 0 || $discount_percent > 100)
    $errors[] = 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد';

if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- ساخت اسلاگ از عنوان ---
$slug = makeSlug($title);
if (empty($slug)) {
    $slug = uniqid("item-");
}
$slug = getUniqueSlug($conn, $slug);

// --- محاسبه قیمت تخفیف ---
$discount_price = $price - ($price * $discount_percent / 100);

// تبدیل طول و قطرها به JSON
$dimensions = json_encode(['diameters' => $diameters, 'lengths' => $lengths], JSON_UNESCAPED_UNICODE);

try {
    // درج محصول
    $stmt = $conn->prepare("INSERT INTO products 
        (title, description, slug, cat_id, status, image, stock, price, discount_price, dimensions, 
         size, type, brand, line_count, grade, half_finished) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $result = $stmt->execute([
        $title, $description, $slug, $cat_id, $status, $image, $stock, $price, $discount_price,
        $dimensions, $size, $type, $brand, $line_count, $grade, $half_finished
    ]);

    if ($result) {
        $productId = $conn->lastInsertId();

        // درج قوانین تخفیف
        $stmtRule = $conn->prepare("INSERT INTO product_discount_rules (product_id, min_quantity, discount_percent) VALUES (?, ?, ?)");
        foreach ($discount_rules as $rule) {
            $stmtRule->execute([$productId, $rule['min_quantity'], $rule['discount_percent']]);
        }

        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت ثبت شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ثبت محصول.'], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
