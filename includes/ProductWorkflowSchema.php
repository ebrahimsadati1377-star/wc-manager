<?php
class ProductWorkflowSchema
{
    private const VERSION = 2;

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        if ((int)getSetting('product_workflow_schema_version', '0') >= self::VERSION) {
            return;
        }

        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS ai_product_jobs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id BIGINT UNSIGNED NULL,
          workflow_status VARCHAR(50) NOT NULL DEFAULT 'draft_input',
          product_name VARCHAR(255) NOT NULL DEFAULT '',
          category_id BIGINT UNSIGNED NULL,
          raw_product_image_url TEXT NULL,
          face_reference_url TEXT NULL,
          provider VARCHAR(50) NOT NULL DEFAULT 'arena',
          fallback_provider VARCHAR(50) NULL,
          regular_price DECIMAL(12,2) NULL,
          sale_price DECIMAL(12,2) NULL,
          stock_quantity INT NULL,
          manual_input_json LONGTEXT NULL,
          analysis_json LONGTEXT NULL,
          seo_json LONGTEXT NULL,
          qc_json LONGTEXT NULL,
          publish_result_json LONGTEXT NULL,
          error_message TEXT NULL,
          retry_count INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY(id),
          KEY idx_workflow_status(workflow_status),
          KEY idx_product_id(product_id),
          KEY idx_created_at(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $jobColumns = [
            'progress_percent' => "TINYINT UNSIGNED NOT NULL DEFAULT 0",
            'current_step' => "VARCHAR(50) NOT NULL DEFAULT 'input'",
            'validation_json' => "LONGTEXT NULL",
            'provider_snapshot_json' => "LONGTEXT NULL",
            'visual_approved_at' => "DATETIME NULL",
            'started_at' => "DATETIME NULL",
            'completed_at' => "DATETIME NULL",
            'lock_token' => "CHAR(36) NULL",
            'lock_expires_at' => "DATETIME NULL",
            'revision' => "INT UNSIGNED NOT NULL DEFAULT 1",
        ];
        self::ensureColumns($db, 'ai_product_jobs', $jobColumns);

        $db->exec("CREATE TABLE IF NOT EXISTS ai_product_job_images (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          job_id BIGINT UNSIGNED NOT NULL,
          image_index INT NOT NULL,
          provider VARCHAR(50) NOT NULL DEFAULT '',
          generation_status VARCHAR(50) NOT NULL DEFAULT 'pending',
          local_path TEXT NULL,
          public_url TEXT NULL,
          wordpress_media_id BIGINT UNSIGNED NULL,
          qc_status VARCHAR(50) NOT NULL DEFAULT 'pending',
          qc_score DECIMAL(5,2) NULL,
          qc_json LONGTEXT NULL,
          retry_count INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY(id),
          UNIQUE KEY uq_job_image(job_id,image_index),
          KEY idx_job_id(job_id),
          CONSTRAINT fk_ai_product_job_images_job
            FOREIGN KEY(job_id) REFERENCES ai_product_jobs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $imageColumns = [
            'prompt_text' => "LONGTEXT NULL",
            'technical_qc_json' => "LONGTEXT NULL",
            'visual_qc_json' => "LONGTEXT NULL",
            'checksum_sha256' => "CHAR(64) NULL",
            'generation_ms' => "INT UNSIGNED NULL",
            'approved_at' => "DATETIME NULL",
            'error_message' => "TEXT NULL",
        ];
        self::ensureColumns($db, 'ai_product_job_images', $imageColumns);

        $db->exec("CREATE TABLE IF NOT EXISTS ai_product_job_events (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          job_id BIGINT UNSIGNED NOT NULL,
          level VARCHAR(20) NOT NULL DEFAULT 'info',
          event_type VARCHAR(80) NOT NULL,
          message TEXT NULL,
          context_json LONGTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id),
          KEY idx_job_created(job_id,created_at),
          CONSTRAINT fk_ai_product_job_events_job
            FOREIGN KEY(job_id) REFERENCES ai_product_jobs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'product_image_provider' => 'arena',
            'product_image_fallback_enabled' => '0',
            'product_image_fallback_provider' => 'openai',
            'required_product_images_count' => '7',
            'product_qc_retry_limit' => '2',
            'product_preview_before_publish' => '1',
        ] as $key => $value) {
            if (getSetting($key, null) === null) setSetting($key, $value);
        }
        setSetting('product_workflow_schema_version', (string)self::VERSION);
    }

    private static function ensureColumns(PDO $db, string $table, array $columns): void
    {
        $check = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        foreach ($columns as $name => $ddl) {
            $check->execute([$table, $name]);
            if ((int)$check->fetchColumn() === 0) {
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $ddl);
            }
        }
    }
}
