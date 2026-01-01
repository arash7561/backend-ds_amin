-- جدول payments برای ذخیره اطلاعات پرداخت‌ها
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `amount` bigint(20) NOT NULL COMMENT 'مبلغ به ریال',
  `authority` varchar(255) NOT NULL COMMENT 'کد authority از زرین‌پال',
  `ref_id` varchar(255) DEFAULT NULL COMMENT 'کد پیگیری پرداخت',
  `status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  KEY `authority` (`authority`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

