<?php
/**
 * تنظیمات زرین‌پال
 * به صورت خودکار محیط (localhost/production) را تشخیص می‌دهد
 */

// تشخیص محیط (localhost یا production)
$isLocalhost = (
    isset($_SERVER['HTTP_HOST']) && (
        strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'], '::1') !== false
    )
) || (
    isset($_SERVER['SERVER_NAME']) && (
        $_SERVER['SERVER_NAME'] === 'localhost' ||
        $_SERVER['SERVER_NAME'] === '127.0.0.1'
    )
);

// تنظیم mode بر اساس محیط
$mode = $isLocalhost ? 'sandbox' : 'production';

// ساخت callback URL به صورت داینامیک
if ($isLocalhost) {
    // برای localhost، از آدرس کامل استفاده می‌کنیم
    // توجه: در localhost باید از localhost:80 استفاده کنیم نه localhost:3000
    $callbackUrl = 'http://localhost/ds_amin/api/cart/payment/verify-payment.php';
} else {
    $callbackUrl = 'https://aminindpharm.ir/api/cart/payment/verify-payment.php';
}

return [
    /*
    |--------------------------------------------------------------------------
    | Zarinpal Mode
    |--------------------------------------------------------------------------
    | sandbox     => حالت تست (برای localhost)
    | production  => حالت واقعی (برای سرور اصلی)
    */
    'mode' => $mode,

    /*
    |--------------------------------------------------------------------------
    | Merchant ID
    |--------------------------------------------------------------------------
    | این مقدار رو از پنل زرین‌پال می‌گیری
    | در حالت sandbox می‌تونی از merchant_id تستی استفاده کنی
    | برای sandbox: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' (36 کاراکتر)
    */
    'merchant_id' => '586a9cbf-3f4c-4e1f-847f-17ac2ddb8374', // Merchant ID واقعی

    /*
    |--------------------------------------------------------------------------
    | Callback URL
    |--------------------------------------------------------------------------
    | بعد از پرداخت، زرین‌پال کاربر رو به این آدرس برمی‌گردونه
    | به صورت خودکار بر اساس محیط تنظیم می‌شود
    */
    'callback_url' => $callbackUrl,

    /*
    |--------------------------------------------------------------------------
    | Payment Description (Optional)
    |--------------------------------------------------------------------------
    */
    'description' => 'پرداخت سفارش از فروشگاه AminindPharm',

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | IRR = ریال (مبلغ باید به ریال ارسال شود)
    | IRT = تومان (زرین‌پال خودش ×10 می‌کند - توصیه می‌شود)
    | 
    | توجه: اگر مبالغ در دیتابیس به تومان هستند، از IRT استفاده کنید
    | حداقل مبلغ: 1000 تومان (IRT) یا 10000 ریال (IRR)
    */
    'currency' => 'IRT', // تغییر به IRT چون مبالغ در دیتابیس به تومان هستند
];
