<?php
/**
 * Helper functions for bulk pricing calculations
 */

/**
 * Get applicable bulk pricing rule for a product and quantity
 * @param PDO $conn Database connection
 * @param int $productId Product ID
 * @param int $quantity Quantity
 * @return array|null Applicable rule or null
 */
function getApplicableBulkPricingRule($conn, $productId, $quantity) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM bulk_pricing_rules 
            WHERE product_id = ? 
            AND min_quantity <= ?
            AND (max_quantity IS NULL OR max_quantity >= ?)
            ORDER BY min_quantity DESC
            LIMIT 1
        ");
        $stmt->execute([$productId, $quantity, $quantity]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rule) {
            // Convert to proper types
            $rule['id'] = (int)$rule['id'];
            $rule['product_id'] = (int)$rule['product_id'];
            $rule['min_quantity'] = (int)$rule['min_quantity'];
            $rule['max_quantity'] = $rule['max_quantity'] !== null ? (int)$rule['max_quantity'] : null;
            $rule['discount_percent'] = $rule['discount_percent'] !== null ? (float)$rule['discount_percent'] : null;
            $rule['discount_amount'] = $rule['discount_amount'] !== null ? (float)$rule['discount_amount'] : null;
            $rule['price_per_unit'] = $rule['price_per_unit'] !== null ? (float)$rule['price_per_unit'] : null;
        }
        
        return $rule ? $rule : null;
    } catch (PDOException $e) {
        error_log("Error getting bulk pricing rule: " . $e->getMessage());
        return null;
    }
}

/**
 * Calculate final price based on bulk pricing rule
 * @param float $originalPrice Original product price
 * @param array|null $rule Bulk pricing rule
 * @return array Array with 'final_price', 'discount_applied', 'discount_type', 'original_price'
 */
function calculateBulkPrice($originalPrice, $rule) {
    if (!$rule) {
        return [
            'final_price' => $originalPrice,
            'discount_applied' => false,
            'discount_type' => null,
            'original_price' => $originalPrice,
            'discount_amount' => 0,
            'discount_percent' => 0
        ];
    }
    
    $finalPrice = $originalPrice;
    $discountType = null;
    $discountAmount = 0;
    $discountPercent = 0;
    
    // Priority: price_per_unit > discount_percent > discount_amount
    if (!empty($rule['price_per_unit']) && $rule['price_per_unit'] > 0) {
        $finalPrice = (float)$rule['price_per_unit'];
        $discountType = 'price_per_unit';
        $discountAmount = $originalPrice - $finalPrice;
        $discountPercent = $originalPrice > 0 ? (($discountAmount / $originalPrice) * 100) : 0;
    } elseif (!empty($rule['discount_percent']) && $rule['discount_percent'] > 0) {
        $discountPercent = (float)$rule['discount_percent'];
        $finalPrice = $originalPrice * (1 - $discountPercent / 100);
        $discountType = 'discount_percent';
        $discountAmount = $originalPrice - $finalPrice;
    } elseif (!empty($rule['discount_amount']) && $rule['discount_amount'] > 0) {
        $discountAmount = (float)$rule['discount_amount'];
        $finalPrice = max(0, $originalPrice - $discountAmount);
        $discountType = 'discount_amount';
        $discountPercent = $originalPrice > 0 ? (($discountAmount / $originalPrice) * 100) : 0;
    }
    
    return [
        'final_price' => round($finalPrice, 2),
        'discount_applied' => true,
        'discount_type' => $discountType,
        'original_price' => $originalPrice,
        'discount_amount' => round($discountAmount, 2),
        'discount_percent' => round($discountPercent, 2)
    ];
}

/**
 * Get bulk pricing info for a product and quantity
 * @param PDO $conn Database connection
 * @param int $productId Product ID
 * @param int $quantity Quantity
 * @param float $originalPrice Original product price
 * @return array Bulk pricing information
 */
function getBulkPricingInfo($conn, $productId, $quantity, $originalPrice) {
    $rule = getApplicableBulkPricingRule($conn, $productId, $quantity);
    return calculateBulkPrice($originalPrice, $rule);
}
?>

