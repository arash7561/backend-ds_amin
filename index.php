<?php
require_once 'db_connection.php';
$conn = getPDO();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فروشگاه داروخانه صنعتی امین</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'layouts/top-nav.php'; ?>
    
    <main class="container">
        <h1>به فروشگاه آنلاین خوش آمدید</h1>
        
        <section class="categories">
            <h2>دسته‌بندی محصولات</h2>
            <?php
            try {
                $stmt = $conn->query("SELECT id, name, slug, image FROM categories WHERE status = 1");
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($categories as $category) {
                    echo '<div class="category-card">';
                    if ($category['image']) {
                        echo '<img src="uploads/categories/' . htmlspecialchars($category['image']) . '" alt="' . htmlspecialchars($category['name']) . '">';
                    }
                    echo '<h3>' . htmlspecialchars($category['name']) . '</h3>';
                    echo '<a href="category.php?slug=' . htmlspecialchars($category['slug']) . '" class="btn">مشاهده محصولات</a>';
                    echo '</div>';
                }
            } catch (PDOException $e) {
                echo '<p>خطا در بارگذاری دسته‌بندی‌ها</p>';
            }
            ?>
        </section>
    </main>
    
    <script src="assets/js/main.js"></script>
</body>
</html>
