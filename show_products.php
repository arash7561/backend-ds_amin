<?php
require_once __DIR__ . '/db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// نمایش خطاها (فقط برای توسعه)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $product_id = $_GET['id'] ?? null;
    $product_slug = $_GET['slug'] ?? null;
    $onlySlugs = isset($_GET['slugs_only']) && $_GET['slugs_only'] == 1;
    
    // Decode URL-encoded slug if needed
    if ($product_slug) {
        $product_slug = urldecode($product_slug);
        // Also try to handle any double encoding
        if (strpos($product_slug, '%') !== false) {
            $product_slug = urldecode($product_slug);
        }
    }
    
    // Debug: Log received slug
    if ($product_slug) {
        error_log("show_products.php: Received slug (after decode): " . $product_slug);
        error_log("show_products.php: Slug length: " . strlen($product_slug));
    }

    if ($onlySlugs) {
        // فقط slugs محصولات
        $stmt = $conn->prepare("SELECT slug FROM products WHERE slug IS NOT NULL AND slug != ''");
        $stmt->execute();
        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($slugs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // گرفتن محصولات همراه با نام دسته‌بندی
    $sql = "
    SELECT 
        products.id,
        products.title,
        products.description,
        products.slug,
        products.image,
        products.price,
        products.discount_price,
        products.discount_percent,
        products.stock,
        products.cat_id,
        products.views,
        products.type,
        products.brand,
        products.line_count,
        products.grade,
        products.half_finished,
        products.dimensions,
        products.width,
        products.size,
        products.color,
        products.material,
        products.slot_count,
        products.general_description,
        products.weight,
        products.status,
        categories.name AS category_name
    FROM 
        products
    LEFT JOIN 
        categories ON products.cat_id = categories.id
    ";

    if ($product_id && is_numeric($product_id)) {
        $sql .= " WHERE products.id = :product_id";
    } elseif ($product_slug) {
        // Try exact match first
        $sql .= " WHERE products.slug = :product_slug";
    }

    $stmt = $conn->prepare($sql);
    if ($product_id && is_numeric($product_id)) {
        $stmt->execute([':product_id' => $product_id]);
    } elseif ($product_slug) {
        // Try exact match
        $stmt->execute([':product_slug' => $product_slug]);
        
        // If no results, try with trimmed slug
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($products) === 0) {
            $trimmedSlug = trim($product_slug);
            if ($trimmedSlug !== $product_slug) {
                $stmt->execute([':product_slug' => $trimmedSlug]);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        // If still no results, try case-insensitive search (for MySQL)
        if (count($products) === 0) {
            $sql2 = str_replace("WHERE products.slug = :product_slug", "WHERE LOWER(products.slug) = LOWER(:product_slug)", $sql);
            $stmt2 = $conn->prepare($sql2);
            $stmt2->execute([':product_slug' => $product_slug]);
            $products = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // If still no results, try to find by ID if slug contains numbers
        if (count($products) === 0 && preg_match('/\d+/', $product_slug, $matches)) {
            $possibleId = $matches[0];
            error_log("show_products.php: Trying to find product by ID from slug: " . $possibleId);
            $sql3 = str_replace("WHERE products.slug = :product_slug", "WHERE products.id = :product_id", $sql);
            $sql3 = str_replace(":product_slug", ":product_id", $sql3);
            $stmt3 = $conn->prepare($sql3);
            $stmt3->execute([':product_id' => $possibleId]);
            $products = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // If still no results, try LIKE search (for partial matches)
        if (count($products) === 0) {
            error_log("show_products.php: Trying LIKE search for slug: " . $product_slug);
            $sql4 = str_replace("WHERE products.slug = :product_slug", "WHERE products.slug LIKE :product_slug", $sql);
            $stmt4 = $conn->prepare($sql4);
            $stmt4->execute([':product_slug' => '%' . $product_slug . '%']);
            $products = $stmt4->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // If still no results, try reverse search (in case slug order is different)
        if (count($products) === 0) {
            // Extract parts from slug (e.g., "7160-مته-الماسه" -> ["7160", "مته", "الماسه"])
            $slugParts = explode('-', $product_slug);
            if (count($slugParts) >= 2) {
                // Try different combinations
                $reversedSlug = implode('-', array_reverse($slugParts));
                error_log("show_products.php: Trying reversed slug: " . $reversedSlug);
                $stmt5 = $conn->prepare($sql);
                $stmt5->execute([':product_slug' => $reversedSlug]);
                $products = $stmt5->fetchAll(PDO::FETCH_ASSOC);
                
                // If still no results, try all permutations
                if (count($products) === 0 && count($slugParts) === 3) {
                    // Try: part1-part2-part3, part1-part3-part2, part2-part1-part3, etc.
                    $permutations = [
                        $slugParts[0] . '-' . $slugParts[1] . '-' . $slugParts[2],
                        $slugParts[0] . '-' . $slugParts[2] . '-' . $slugParts[1],
                        $slugParts[1] . '-' . $slugParts[0] . '-' . $slugParts[2],
                        $slugParts[1] . '-' . $slugParts[2] . '-' . $slugParts[0],
                        $slugParts[2] . '-' . $slugParts[0] . '-' . $slugParts[1],
                        $slugParts[2] . '-' . $slugParts[1] . '-' . $slugParts[0],
                    ];
                    
                    foreach ($permutations as $perm) {
                        if ($perm !== $product_slug && $perm !== $reversedSlug) {
                            error_log("show_products.php: Trying permutation: " . $perm);
                            $stmt6 = $conn->prepare($sql);
                            $stmt6->execute([':product_slug' => $perm]);
                            $products = $stmt6->fetchAll(PDO::FETCH_ASSOC);
                            if (count($products) > 0) {
                                break;
                            }
                        }
                    }
                }
            }
        }
    } else {
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Debug: Log query results
    if ($product_slug) {
        error_log("show_products.php: Found " . count($products) . " products with slug: " . $product_slug);
        if (count($products) > 0) {
            error_log("show_products.php: Product ID: " . $products[0]['id']);
            error_log("show_products.php: Product title: " . $products[0]['title']);
            error_log("show_products.php: Product slug in DB: " . $products[0]['slug']);
        } else {
            // Try to find similar slugs
            $checkStmt = $conn->prepare("SELECT id, title, slug FROM products WHERE slug LIKE ? LIMIT 5");
            $checkStmt->execute(['%' . $product_slug . '%']);
            $similar = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("show_products.php: Similar slugs found: " . json_encode($similar, JSON_UNESCAPED_UNICODE));
        }
    }

    // افزایش بازدید
    if ($products) {
        foreach ($products as &$product) {
            $updateStmt = $conn->prepare("UPDATE products SET views = views + 1 WHERE id = ?");
            $updateStmt->execute([$product['id']]);
            $product['views'] += 1;
        }
    }

    // خروجی JSON
    if ($products && count($products) > 0) {
        if (($product_id && is_numeric($product_id)) || $product_slug) {
            echo json_encode($products[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    } else {
        // Return proper error response
        http_response_code(404);
        echo json_encode([
            "error" => true,
            "debug" => "هیچ محصولی یافت نشد.",
            "received_slug" => $product_slug ?? null,
            "message" => "محصول یافت نشد"
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'خطا در دریافت محصولات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
