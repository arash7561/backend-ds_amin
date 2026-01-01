-- اصلاح جدول payments برای اجازه دادن به NULL در user_id
-- این فایل را در phpMyAdmin یا MySQL اجرا کنید

ALTER TABLE `payments` 
MODIFY COLUMN `user_id` int(11) DEFAULT NULL;

-- بررسی ساختار جدید
DESCRIBE `payments`;

