<?php
require_once 'db_connection.php';

// Get product ID from URL
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    header('Location: index.php');
    exit;
}

// Get product details
try {
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.id = ? AND p.status = 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> - فروشگاه آنلاین</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'layouts/top-nav.php'; ?>
    
    <main class="container">
        <div class="product-detail">
            <div class="product-images">
                <?php if ($product['image']): ?>
                    <img src="uploads/products/<?php echo htmlspecialchars($product['image']); ?>" 
                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                         class="main-image">
                <?php endif; ?>
            </div>
            
            <div class="product-info">
                <h1><?php echo htmlspecialchars($product['name']); ?></h1>
                
                <?php if ($product['category_name']): ?>
                    <p class="category">دسته‌بندی: <?php echo htmlspecialchars($product['category_name']); ?></p>
                <?php endif; ?>
                
                <div class="price">
                    <span class="current-price"><?php echo number_format($product['price']); ?> تومان</span>
                    <?php if ($product['old_price'] && $product['old_price'] > $product['price']): ?>
                        <span class="old-price"><?php echo number_format($product['old_price']); ?> تومان</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($product['description']): ?>
                    <div class="description">
                        <h3>توضیحات محصول</h3>
                        <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="product-actions">
                    <form action="cart/add.php" method="POST" class="add-to-cart-form">
                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                        <div class="quantity-selector">
                            <label for="quantity">تعداد:</label>
                            <input type="number" name="quantity" id="quantity" value="1" min="1" max="99">
                        </div>
                        <button type="submit" class="btn btn-primary">افزودن به سبد خرید</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Related Products -->
        <section class="related-products">
            <h2>محصولات مشابه</h2>
            <?php
            try {
                $stmt = $conn->prepare("
                    SELECT id, name, price, image 
                    FROM products 
                    WHERE category_id = ? AND id != ? AND status = 1 
                    LIMIT 4
                ");
                $stmt->execute([$product['category_id'], $product['id']]);
                $related_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($related_products as $related) {
                    echo '<div class="product-card">';
                    if ($related['image']) {
                        echo '<img src="uploads/products/' . htmlspecialchars($related['image']) . '" alt="' . htmlspecialchars($related['name']) . '">';
                    }
                    echo '<h3>' . htmlspecialchars($related['name']) . '</h3>';
                    echo '<p class="price">' . number_format($related['price']) . ' تومان</p>';
                    echo '<a href="detail.php?id=' . $related['id'] . '" class="btn">مشاهده</a>';
                    echo '</div>';
                }
            } catch (PDOException $e) {
                echo '<p>خطا در بارگذاری محصولات مشابه</p>';
            }
            ?>
        </section>
    </main>
    
    <script src="assets/js/main.js"></script>
</body>
</html>
