# قابلیت علاقه‌مندی‌ها (Favorites)

این پوشه شامل API های مربوط به علاقه‌مندی‌های کاربران است.

## فایل‌های موجود

### 1. `get_favorites.php`

- **عملکرد**: دریافت لیست علاقه‌مندی‌های کاربر
- **متد**: POST
- **ورودی**: Authorization header با Bearer token
- **خروجی**: لیست محصولات مورد علاقه با جزئیات

### 2. `add_favorite.php`

- **عملکرد**: افزودن محصول به علاقه‌مندی‌ها
- **متد**: POST
- **ورودی**:
  - Authorization header با Bearer token
  - JSON body با `product_id`
- **خروجی**: پیام موفقیت یا خطا

### 3. `remove_favorite.php`

- **عملکرد**: حذف محصول از علاقه‌مندی‌ها
- **متد**: POST
- **ورودی**:
  - Authorization header با Bearer token
  - JSON body با `product_id`
- **خروجی**: پیام موفقیت یا خطا

### 4. `create_favorites_table.sql`

- **عملکرد**: اسکریپت SQL برای ایجاد جدول favorites
- **نحوه استفاده**: در phpMyAdmin یا MySQL client اجرا کنید

## نصب و راه‌اندازی

### 1. ایجاد جدول در دیتابیس

```sql
-- فایل create_favorites_table.sql را در دیتابیس ds_amin اجرا کنید
```

### 2. تنظیمات دیتابیس

مطمئن شوید که فایل `../../db_connection.php` موجود است و تنظیمات صحیح دارد.

### 3. تست API ها

می‌توانید از Postman یا curl برای تست استفاده کنید:

#### دریافت علاقه‌مندی‌ها:

```bash
curl -X POST http://localhost/ds_amin/cart/favorites/get_favorites.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer USER_ID" \
  -d '{"action": "get_favorites"}'
```

#### افزودن به علاقه‌مندی‌ها:

```bash
curl -X POST http://localhost/ds_amin/cart/favorites/add_favorite.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer USER_ID" \
  -d '{"product_id": 1}'
```

#### حذف از علاقه‌مندی‌ها:

```bash
curl -X POST http://localhost/ds_amin/cart/favorites/remove_favorite.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer USER_ID" \
  -d '{"product_id": 1}'
```

## نکات مهم

1. **احراز هویت**: فعلاً از token ساده استفاده می‌شود. در production از JWT استفاده کنید.
2. **امنیت**: همه ورودی‌ها validate می‌شوند.
3. **خطاها**: تمام خطاها log می‌شوند و پیام‌های کاربرپسند برگردانده می‌شوند.
4. **CORS**: برای تمام domainها فعال است (در production محدود کنید).

## ساختار جدول favorites

```sql
CREATE TABLE `favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product` (`user_id`, `product_id`)
);
```

## وابستگی‌ها

- جدول `products` باید موجود باشد
- جدول `categories` (اختیاری برای نمایش دسته‌بندی)
- جدول `users` (برای foreign key - اختیاری)
- فایل `../../db_connection.php` برای اتصال به دیتابیس
