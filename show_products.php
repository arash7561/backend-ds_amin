<?php
require_once 'db_connection.php';

// نمایش خطاها (فقط برای توسعه)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// گرفتن محصولات همراه با نام دسته‌بندی
$sql = "
SELECT 
    products.id,
    products.title,
    products.slug,
    products.image,
    products.price,
    products.discount_price,
    products.stock,
    products.cat_id,
    categories.name AS category_name
FROM 
    products
JOIN 
    categories ON products.cat_id = categories.id
WHERE 
    products.status = 'active'
";

$stmt = $conn->query($sql);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ارسال خروجی به صورت JSON
header("Content-Type: application/json; charset=utf-8");
echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
