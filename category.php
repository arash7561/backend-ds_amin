<?php

require_once 'db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

// تابع تولید اسلاگ از نام (مشابه JavaScript)
function generateSlug($title) {
    if (empty($title) || trim($title) === '') {
        return '';
    }

    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabicNumbers = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $slug = $title;

    // تبدیل اعداد فارسی و عربی به انگلیسی
    foreach ($persianNumbers as $index => $persian) {
        $slug = str_replace($persian, $englishNumbers[$index], $slug);
    }

    foreach ($arabicNumbers as $index => $arabic) {
        $slug = str_replace($arabic, $englishNumbers[$index], $slug);
    }

    // تبدیل به lowercase و حذف کاراکترهای غیرمجاز
    $slug = mb_strtolower($slug, 'UTF-8');
    $slug = preg_replace('/[^\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFFa-zA-Z0-9\s-]/u', ' ', $slug);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    return $slug;
}

$json = file_get_contents('php://input');
$data = json_decode($json);

$name = $data->name ?? '';
$description = $data->description ?? '';
$slug = $data->slug ?? '';
$parent_id = $data->parent_id ?? null;
$status = $data->status ?? 1;

$error;

if(empty($name)) {
    $error = 'نام خالی است';
}
elseif(empty($description)) {
    $error = 'توضیحات خالی است';
}

// اگر اسلاگ خالی بود، از نام تولید کن و یک عدد تصادفی اضافه کن
if(empty($slug) && !empty($name)) {
    $baseSlug = generateSlug($name);
    // اضافه کردن یک عدد تصادفی 4 رقمی به اسلاگ
    $randomSuffix = rand(1000, 9999);
    $slug = $baseSlug . '-' . $randomSuffix;
}

// بررسی تکراری نبودن اسلاگ و در صورت تکراری بودن، یک عدد تصادفی جدید اضافه کن
$originalSlug = $slug;
$attempts = 0;
while ($attempts < 10) {
    $query = "SELECT * FROM categories WHERE slug = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$slug]);
    $category = $stmt->fetch();

    if($category === false){
        // اسلاگ منحصر به فرد است، می‌توانیم ذخیره کنیم
        $query = "INSERT INTO categories (name, description, slug, parent_id, status) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $result = $stmt->execute([$name, $description, $slug, $parent_id, $status]);

        if($result){
            $response = ['status' => true];
            $response['message'] = 'دسته بندی با موفقیت انجام شد';
        } else {
            $response = ['status' => false];
            $response['message'] = 'دسته بندی با موفقیت انجام نشد';
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        // اگر تکراری بود، یک عدد تصادفی جدید اضافه کن
        $randomSuffix = rand(1000, 9999);
        $slug = $originalSlug . '-' . $randomSuffix;
        $attempts++;
    }
}

// اگر بعد از 10 تلاش نتوانستیم اسلاگ منحصر به فردی پیدا کنیم
if ($attempts >= 10) {
    $response = ['status' => false];
    $response['message'] = 'نمی‌توان اسلاگ منحصر به فردی ایجاد کرد. لطفاً اسلاگ را دستی وارد کنید.';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

if(isset($error)) {
    echo json_encode(['status' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
}
