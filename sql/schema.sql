-- =====================================================
-- WooCommerce Manager - Database Schema
-- =====================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `target` VARCHAR(150) NULL,
  `details` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user -> username: admin / password: admin123
-- IMPORTANT: change this password immediately after first login!
INSERT INTO `users` (`full_name`, `username`, `password_hash`, `role`)
VALUES ('مدیر سیستم', 'admin', '$2y$10$ckZohqWd89038qFj2nVDp.niMe/JRi0FSfbdjSD8pCkLnnui2K3tS', 'admin')
ON DUPLICATE KEY UPDATE username = username;

-- Default (empty) settings rows
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('store_url', ''),
  ('consumer_key', ''),
  ('consumer_secret', ''),
  ('site_title', 'مدیریت محصولات ووکامرس')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
