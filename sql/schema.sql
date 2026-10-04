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


-- =====================================================
-- BAJI Professional Product Workflow V2
-- =====================================================
CREATE TABLE IF NOT EXISTS `ai_product_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NULL,
  `workflow_status` VARCHAR(50) NOT NULL DEFAULT 'draft_input',
  `product_name` VARCHAR(255) NOT NULL DEFAULT '',
  `category_id` BIGINT UNSIGNED NULL,
  `raw_product_image_url` TEXT NULL,
  `face_reference_url` TEXT NULL,
  `provider` VARCHAR(50) NOT NULL DEFAULT 'arena',
  `fallback_provider` VARCHAR(50) NULL,
  `regular_price` DECIMAL(12,2) NULL,
  `sale_price` DECIMAL(12,2) NULL,
  `stock_quantity` INT NULL,
  `manual_input_json` LONGTEXT NULL,
  `analysis_json` LONGTEXT NULL,
  `seo_json` LONGTEXT NULL,
  `qc_json` LONGTEXT NULL,
  `publish_result_json` LONGTEXT NULL,
  `error_message` TEXT NULL,
  `retry_count` INT NOT NULL DEFAULT 0,
  `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `current_step` VARCHAR(50) NOT NULL DEFAULT 'input',
  `validation_json` LONGTEXT NULL,
  `provider_snapshot_json` LONGTEXT NULL,
  `visual_approved_at` DATETIME NULL,
  `started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `lock_token` CHAR(36) NULL,
  `lock_expires_at` DATETIME NULL,
  `revision` INT UNSIGNED NOT NULL DEFAULT 1,
  `visual_qc_json` LONGTEXT NULL,
  `diversity_qc_json` LONGTEXT NULL,
  `publish_verification_json` LONGTEXT NULL,
  `verification_status` VARCHAR(40) NULL,
  `category_rules_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workflow_status` (`workflow_status`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ai_product_job_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `image_index` INT NOT NULL,
  `provider` VARCHAR(50) NOT NULL DEFAULT '',
  `generation_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `local_path` TEXT NULL,
  `public_url` TEXT NULL,
  `wordpress_media_id` BIGINT UNSIGNED NULL,
  `qc_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `qc_score` DECIMAL(5,2) NULL,
  `qc_json` LONGTEXT NULL,
  `retry_count` INT NOT NULL DEFAULT 0,
  `prompt_text` LONGTEXT NULL,
  `technical_qc_json` LONGTEXT NULL,
  `visual_qc_json` LONGTEXT NULL,
  `checksum_sha256` CHAR(64) NULL,
  `generation_ms` INT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `error_message` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_image` (`job_id`, `image_index`),
  KEY `idx_job_id` (`job_id`),
  CONSTRAINT `fk_ai_product_job_images_job`
    FOREIGN KEY (`job_id`) REFERENCES `ai_product_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_product_job_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `level` VARCHAR(20) NOT NULL DEFAULT 'info',
  `event_type` VARCHAR(80) NOT NULL,
  `message` TEXT NULL,
  `context_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_created` (`job_id`, `created_at`),
  CONSTRAINT `fk_ai_product_job_events_job`
    FOREIGN KEY (`job_id`) REFERENCES `ai_product_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('product_image_provider', 'arena'),
  ('product_image_fallback_enabled', '0'),
  ('product_image_fallback_provider', 'openai'),
  ('required_product_images_count', '7'),
  ('product_qc_retry_limit', '2'),
  ('product_visual_qc_min_score', '85'),
  ('product_diversity_min_score', '75'),
  ('product_preview_before_publish', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
