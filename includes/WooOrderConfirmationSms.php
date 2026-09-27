<?php
/**
 * One confirmation message per newly created WooCommerce order.
 * This class never assumes that an order with pending/on-hold status is paid.
 * An INSERT IGNORE claim plus persisted uncertain outcomes prevent duplicate SMS
 * when WooCommerce repeats created/updated webhooks.
 */
final class WooOrderConfirmationSms
{
    private PDO $db;
    private IPPanelClient $sms;
    private WooCommerceClient $woo;

    public function __construct(PDO $db, IPPanelClient $sms, WooCommerceClient $woo)
    {
        $this->db = $db;
        $this->sms = $sms;
        $this->woo = $woo;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS woo_order_confirmation_sms (
            order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            recipient VARCHAR(20) NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'sending',
            message_id VARCHAR(100) NULL,
            provider_route VARCHAR(60) NULL,
            error_code VARCHAR(90) NULL,
            claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_woo_order_confirm_state (state, claimed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function normalizePhone(string $input): string
    {
        $numbers = strtr(trim($input), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $numbers = preg_replace('/[\s().-]+/', '', $numbers);
        if (str_starts_with($numbers, '+98')) $numbers = '0' . substr($numbers, 3);
        elseif (str_starts_with($numbers, '0098')) $numbers = '0' . substr($numbers, 4);
        elseif (str_starts_with($numbers, '98') && strlen($numbers) === 12) $numbers = '0' . substr($numbers, 2);
        elseif (strlen($numbers) === 10 && str_starts_with($numbers, '9')) $numbers = '0' . $numbers;
        return (bool)preg_match('/^09[0-9]{9}$/D', $numbers) ? $numbers : '';
    }

    private static function firstName(array $order): string
    {
        $billing = (array)($order['billing'] ?? []);
        $shipping = (array)($order['shipping'] ?? []);
        $first = trim((string)($billing['first_name'] ?? '')) ?: trim((string)($shipping['first_name'] ?? ''));
        $first = (string)preg_replace('/[\x00-\x1f\x7f]+/u', '', $first);
        $first = trim((string)preg_replace('/\s+/u', ' ', $first));
        return mb_substr($first, 0, 38, 'UTF-8');
    }

    public static function buildMessage(array $order): string
    {
        $name = self::firstName($order);
        $greeting = $name !== '' ? $name . ' عزیز 🤍' : 'مشتری عزیز 🤍';
        $id = (int)$order['id'];
        $total = number_format((float)($order['total'] ?? 0), 0, '.', ',');
        $currency = (string)($order['currency'] ?? 'IRT');
        $unit = $currency === 'IRR' ? 'ریال' : ($currency === 'IRT' || $currency === 'TOMAN' ? 'تومان' : $currency);
        $status = (string)($order['status'] ?? '');
        $paid = !empty($order['date_paid']) || !empty($order['date_paid_gmt']);
        $paymentText = $paid ? 'پرداخت تأیید شده است و سفارش در مسیر آماده‌سازی قرار دارد.'
            : ($status === 'on-hold' ? 'پرداخت در انتظار بررسی و تأیید است.'
            : 'پرداخت هنوز تأیید نشده است. در صورت تکمیل پرداخت، سفارش پردازش می‌شود.');

        return "باجی 🤍\n" . $greeting . "\n\n"
            . "سفارش شما با شماره #" . $id . " ثبت شد.\n"
            . "مبلغ سفارش: " . $total . ' ' . $unit . "\n"
            . $paymentText . "\n\n"
            . "برای مشاهده وضعیت و جزئیات سفارش وارد حساب کاربری خود شوید:\n"
            . "https://bajistyle.ir/my-account/?tab=orders\n\n"
            . "باجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
    }

    private function record(int $id, string $state, string $error = '', string $messageId = '', string $route = ''): void
    {
        $stmt = $this->db->prepare(
            'UPDATE woo_order_confirmation_sms
             SET state=:state,error_code=:error,message_id=:message_id,provider_route=:route,updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id'
        );
        $stmt->execute([
            'id'=>$id, 'state'=>$state, 'error'=>mb_substr($error, 0, 90),
            'message_id'=>mb_substr($messageId, 0, 100), 'route'=>mb_substr($route, 0, 60),
        ]);
    }

    public function handle(int $id): array
    {
        if ($id < 1) return ['status'=>'invalid_order'];
        if ((string)getSetting('woo_order_confirmation_sms_enabled','0') !== '1') return ['status'=>'disabled'];
        $result = $this->woo->getOrder($id);
        if (!empty($result['error']) || (int)($result['body']['id'] ?? 0) !== $id) {
            throw new RuntimeException('Cannot load live WooCommerce order');
        }
        $order = (array)$result['body'];
        $created = trim((string)($order['date_created_gmt'] ?? ''));
        $enabledAt = trim((string)getSetting('woo_order_confirmation_sms_enabled_at',''));
        if ($created === '' || $enabledAt === '') return ['status'=>'missing_order_timestamp'];
        try {
            $createdAt = new DateTimeImmutable($created, new DateTimeZone('UTC'));
            $start = new DateTimeImmutable($enabledAt, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return ['status'=>'invalid_order_timestamp'];
        }
        if ($createdAt < $start) return ['status'=>'historical_order'];

        $status = (string)($order['status'] ?? '');
        if (!in_array($status, ['pending','on-hold','processing','completed'], true))
            return ['status'=>'not_a_placed_order'];

        $billing = (array)($order['billing'] ?? []);
        $phone = self::normalizePhone((string)($billing['phone'] ?? ''));
        if ($phone === '') return ['status'=>'awaiting_valid_mobile'];
        if ((float)($order['total'] ?? 0) < 0) return ['status'=>'invalid_total'];

        // A claim is inserted BEFORE contacting the SMS provider. Any ambiguous
        // network failure remains 'unknown' and is never automatically resent.
        $insert = $this->db->prepare(
            "INSERT IGNORE INTO woo_order_confirmation_sms
             (order_id,recipient,state,claimed_at,updated_at)
             VALUES (:id,:recipient,'sending',UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        $insert->execute(['id'=>$id,'recipient'=>$phone]);
        if ($insert->rowCount() !== 1) return ['status'=>'already_claimed'];

        $state='failed';$error='';$messageId='';$route='';
        try {
            $answer=$this->sms->send($phone,self::buildMessage($order));
            $accepted=array_key_exists('accepted',$answer)
                ? (bool)$answer['accepted'] : (bool)($answer['success'] ?? false);
            $state=$accepted
                ? (!empty($answer['delivery_confirmed']) ? 'delivered'
                    : (!empty($answer['confirmed_sent']) ? 'sent' : 'accepted'))
                : 'failed';
            $error=$accepted ? '' : 'provider_rejected';
            $messageId=(string)($answer['message_id'] ?? '');
            $route=(string)($answer['route'] ?? '');
        } catch (Throwable $e) {
            $text=$e->getMessage();
            $state=(bool)preg_match('/request failed|timeout|timed out|relay request failed/i',$text)
                ? 'unknown' : 'failed';
            $error=$state==='unknown' ? 'transport_uncertain' : 'provider_exception';
            error_log('[wc-manager] new-order SMS error order='.$id.' state='.$state.' detail='.$text);
        }
        try {
            $this->record($id,$state,$error,$messageId,$route);
        } catch (Throwable $e) {
            // The original 'sending' claim remains, preventing a duplicate.
            error_log('[wc-manager] new-order SMS audit update failed order='.$id.' '.$e->getMessage());
            return ['status'=>'audit_update_failed'];
        }
        logActivity('woo_new_order_sms_'.$state,'order:'.$id,'message_id='.$messageId.' route='.$route);
        return ['status'=>$state,'order_id'=>$id];
    }
}
