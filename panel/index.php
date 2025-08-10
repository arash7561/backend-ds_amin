<?php
session_start();
require_once '../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login_admin/');
    exit;
}

// Get admin info
$admin_id = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT name, email FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>پنل مدیریت</h1>
            <div class="admin-info">
                <span>خوش آمدید، <?php echo htmlspecialchars($admin['name']); ?></span>
                <a href="logout.php" class="logout-btn">خروج</a>
            </div>
        </header>
        
        <nav class="admin-nav">
            <ul>
                <li><a href="category/">مدیریت دسته‌بندی‌ها</a></li>
                <li><a href="post/">مدیریت محصولات</a></li>
                <li><a href="poster/">مدیریت پوسترها</a></li>
                <li><a href="orders/">مدیریت سفارشات</a></li>
            </ul>
        </nav>
        
        <main class="admin-main">
            <div class="dashboard-stats">
                <div class="stat-card">
                    <h3>تعداد دسته‌بندی‌ها</h3>
                    <?php
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM categories");
                    $categoryCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    echo '<p>' . $categoryCount . '</p>';
                    ?>
                </div>
                
                <div class="stat-card">
                    <h3>تعداد محصولات</h3>
                    <?php
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM products");
                    $productCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    echo '<p>' . $productCount . '</p>';
                    ?>
                </div>
                
                <div class="stat-card">
                    <h3>سفارشات جدید</h3>
                    <?php
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'");
                    $newOrderCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    echo '<p>' . $newOrderCount . '</p>';
                    ?>
                </div>
            </div>
        </main>
    </div>
    
    <script src="../assets/js/admin.js"></script>
</body>
</html>
