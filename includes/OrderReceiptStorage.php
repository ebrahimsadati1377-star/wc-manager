<?php
/**
 * Private proof-of-handover storage. Files are outside /public and are served
 * only after an administrator session and a fresh WooCommerce order lookup.
 */
final class OrderReceiptStorage
{
    private const LIMIT_BYTES = 2097152;
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function root(): string
    {
        return dirname(__DIR__) . '/storage/order-receipts';
    }

    public static function filePath(string $stored): ?string
    {
        if (!preg_match('/^[1-9]\d{0,11}-[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/D', $stored)) return null;
        return self::root() . '/' . $stored;
    }

    public static function mime(string $stored): string
    {
        return match (strtolower((string)pathinfo($stored, PATHINFO_EXTENSION))) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    public static function upload(int $orderId, array $file, WooCommerceClient $wc): array
    {
        if ($orderId < 1) return ['type'=>'danger','message'=>'شماره سفارش نامعتبر است.'];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['type'=>'danger','message'=> $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'حجم فایل بیشتر از حد مجاز است؛ تصویر را کوچک‌تر کنید (حداکثر ۲ مگابایت).'
                : 'فایل رسید بارگذاری نشد؛ یک تصویر یا PDF انتخاب کنید.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::LIMIT_BYTES || !is_uploaded_file($tmp)) {
            return ['type'=>'danger','message'=>'رسید باید فایل واقعی و کمتر از ۲ مگابایت باشد.'];
        }
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::MIME_EXT[$type])) {
            return ['type'=>'danger','message'=>'فقط تصویر JPG، PNG، WebP یا فایل PDF پذیرفته می‌شود.'];
        }
        if ($type === 'application/pdf') {
            if (file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
                return ['type'=>'danger','message'=>'ساختار فایل PDF معتبر نیست.'];
            }
        } elseif (@getimagesize($tmp) === false) {
            return ['type'=>'danger','message'=>'فایل تصویر قابل شناسایی نیست.'];
        }

        $lock = @fopen(sys_get_temp_dir() . '/baji-shipment-' . $orderId . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['type'=>'warning','message'=>'سفارش در حال ویرایش است؛ مجدداً امتحان کنید.'];
        }
        try {
            $read = $wc->getOrder($orderId);
            if (!empty($read['error']) || (int)($read['body']['id'] ?? 0) !== $orderId) {
                return ['type'=>'danger','message'=>'سفارش قابل دریافت نیست؛ رسید ذخیره نشد.'];
            }
            $order = (array)$read['body'];
            if (OrderShipmentService::meta($order, '_baji_ship_tracking') === ''
                || (string)($order['status'] ?? '') !== 'completed') {
                return ['type'=>'danger','message'=>'برای ذخیره رسید ابتدا ارسال و تکمیل سفارش را ثبت کنید.'];
            }

            $root = self::root();
            if (!is_dir($root) || is_link($root) || !is_writable($root)) {
                error_log('[wc-manager] shipment receipt private directory missing/not writable');
                return ['type'=>'danger','message'=>'فضای خصوصی نگهداری رسید آماده نیست؛ با پشتیبانی فنی تماس بگیرید.'];
            }

            $stored = $orderId . '-' . bin2hex(random_bytes(16)) . '.' . self::MIME_EXT[$type];
            $path = self::filePath($stored);
            if (!$path || !move_uploaded_file($tmp, $path)) {
                return ['type'=>'danger','message'=>'ذخیره فایل رسید ناموفق بود.'];
            }
            @chmod($path, 0600);
            $actor = (array)($_SESSION['user'] ?? []);
            $old = OrderShipmentService::meta($order, '_baji_ship_receipt_file');
            $original = trim((string)($file['name'] ?? 'رسید ارسال'));
            if (function_exists('mb_substr')) $original = mb_substr($original, 0, 110, 'UTF-8');
            else $original = substr($original, 0, 110);
            $original = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $original);
            $data = [
                '_baji_ship_receipt_file' => $stored,
                '_baji_ship_receipt_name' => $original,
                '_baji_ship_receipt_at' => gmdate('c'),
                '_baji_ship_receipt_by' => (string)($actor['full_name'] ?? $actor['username'] ?? 'مدیر'),
                '_baji_ship_receipt_by_id' => (string)($actor['id'] ?? ''),
            ];
            $meta = [];
            foreach ($data as $key => $value) $meta[] = ['key'=>$key, 'value'=>$value];
            $saved = $wc->put('orders/' . $orderId, ['meta_data'=>$meta]);
            if (!empty($saved['error'])
                || OrderShipmentService::meta((array)($saved['body'] ?? []), '_baji_ship_receipt_file') !== $stored) {
                @unlink($path);
                return ['type'=>'danger','message'=>'ذخیره پیوند رسید در ووکامرس تأیید نشد؛ فایل موقت پاک شد.'];
            }
            if ($old !== '') {
                $oldPath = self::filePath($old);
                if ($oldPath && is_file($oldPath) && !is_link($oldPath)) @unlink($oldPath);
            }
            logActivity('shipment_receipt_uploaded', 'order:'.$orderId, 'file='.$stored);
            return ['type'=>'success','message'=>'رسید به‌صورت خصوصی برای سفارش ذخیره شد؛ فقط مدیر می‌تواند آن را ببیند.'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
