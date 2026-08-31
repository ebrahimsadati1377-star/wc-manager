<?php

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                die('خطا در اتصال به دیتابیس. لطفاً اطلاعات دیتابیس را در config/config.php بررسی کنید. (' .
                    (APP_DEBUG ? $e->getMessage() : 'جزئیات در حالت دیباگ نمایش داده می‌شود') . ')');
            }
        }
        return self::$instance;
    }
}
