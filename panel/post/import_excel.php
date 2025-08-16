<?php
require_once __DIR__ . '/../../db_connection.php'; 
$conn = getPDO();
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function makeSlug($string) {
    $slug = mb_strtolower($string, 'UTF-8');
    $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['excelFile']) && $_FILES['excelFile']['error'] === UPLOAD_ERR_OK) {
        $tmpFilePath = $_FILES['excelFile']['tmp_name'];

        try {
            $spreadsheet = IOFactory::load($tmpFilePath);
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            die("خطا در بارگذاری فایل اکسل: " . $e->getMessage());
        }

        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        unset($data[0]); // حذف هدر اکسل
        global $conn;
        if (!$conn) {
            die("❌ اتصال به پایگاه داده برقرار نشده است.");
        }

        $sql = "INSERT INTO products (title, description, slug, cat_id, status, image, stock, price, discount_price) 
                VALUES (:title, :description, :slug, :cat_id, :status, :image, :stock, :price, :discount_price)";
        $stmt = $conn->prepare($sql);

        foreach ($data as $row) {
            $title = $row[0] ?? '';
            $stmt->execute([
                ':title' => $title,
                ':description' => $row[1] ?? '',
                ':slug' => makeSlug($title),
                ':cat_id' => isset($row[3]) ? (int)$row[3] : 0,
                ':status' => isset($row[4]) ? (int)$row[4] : 0,
                ':image' => $row[5] ?? '',
                ':stock' => isset($row[6]) ? (int)$row[6] : 0,
                ':price' => isset($row[7]) ? (float)$row[7] : 0,
                ':discount_price' => isset($row[8]) ? (float)$row[8] : 0,
            ]);
        }

        echo "✅ وارد کردن کالاها با موفقیت انجام شد.";
    } else {
        echo "❌ خطا در آپلود فایل.";
    }
} else {
    echo "❌ درخواست نامعتبر.";
}
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>آپلود فایل اکسل</title>
</head>
<body>
    <h2>آپلود فایل Excel برای ورود کالاها</h2>

    <form action="" method="post" enctype="multipart/form-data">
        <label for="excelFile">فایل اکسل را انتخاب کنید:</label><br><br>
        <input type="file" name="excelFile" id="excelFile" accept=".xls,.xlsx" required><br><br>
        <button type="submit">ارسال</button>
    </form>
</body>
</html>
