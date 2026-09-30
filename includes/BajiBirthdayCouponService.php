<?php
/**
 * Provision one non-shareable, one-use 15% WooCommerce coupon per mobile/year.
 * The coupon is created/verified BEFORE the birthday SMS event is claimed.
 * A deterministic secret-derived code lets retries recover an API timeout
 * without creating a second coupon or contacting the customer twice.
 */
declare(strict_types=1);

final class BajiBirthdayCouponService
{
    private PDO $db;
    private WooCommerceClient $woo;

    public function __construct(PDO $db, WooCommerceClient $woo)
    {
        $this->db = $db;
        $this->woo = $woo;
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS baji_birthday_coupons (
                phone VARCHAR(20) NOT NULL,
                campaign_year SMALLINT UNSIGNED NOT NULL,
                coupon_code VARCHAR(48) NOT NULL,
                coupon_id BIGINT UNSIGNED NULL,
                expires_gmt DATETIME NOT NULL,
                state VARCHAR(20) NOT NULL DEFAULT 'reserved',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (phone,campaign_year),
                UNIQUE KEY unique_birthday_code (coupon_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function secret(): string
    {
        $secret = (string)getSetting('baji_birthday_coupon_secret', '');
        if ($secret !== '') return $secret;
        $secret = bin2hex(random_bytes(32));
        setSetting('baji_birthday_coupon_secret', $secret);
        return $secret;
    }

    private static function couponMeta(array $coupon, string $key): string
    {
        foreach ((array)($coupon['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && (string)($meta['key'] ?? '') === $key) {
                return is_scalar($meta['value'] ?? null) ? (string)$meta['value'] : '';
            }
        }
        return '';
    }

    private function validateCoupon(array $coupon, string $code, string $phone, DateTimeImmutable $expiry): bool
    {
        if ((int)($coupon['id'] ?? 0) < 1 ||
            strtolower((string)($coupon['code'] ?? '')) !== strtolower($code) ||
            (string)($coupon['discount_type'] ?? '') !== 'percent' ||
            (float)($coupon['amount'] ?? 0) !== 15.0 ||
            (int)($coupon['usage_limit'] ?? 0) !== 1 ||
            empty($coupon['individual_use']) ||
            !empty($coupon['free_shipping']) ||
            self::couponMeta($coupon, '_baji_birthday_phone') !== $phone
        ) {
            return false;
        }
        $actual = (string)($coupon['date_expires_gmt'] ?? '');
        if ($actual === '') return false;
        try {
            $actualExpiry = new DateTimeImmutable($actual, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return false;
        }
        return abs($actualExpiry->getTimestamp() - $expiry->getTimestamp()) <= 60;
    }

    private function fetchByCode(string $code): ?array
    {
        $result = $this->woo->get('coupons', ['code'=>$code, 'per_page'=>10]);
        if (!empty($result['error']) || !is_array($result['body'] ?? null)) {
            throw new RuntimeException('Birthday coupon lookup failed');
        }
        foreach ($result['body'] as $coupon) {
            if (is_array($coupon) && strcasecmp((string)($coupon['code'] ?? ''), $code) === 0) {
                return $coupon;
            }
        }
        return null;
    }

    /**
     * @return array{code:string,id:int,expires:DateTimeImmutable}
     */
    public function ensure(string $phone, DateTimeImmutable $today): array
    {
        $phone = WooOrderConfirmationSms::normalizePhone($phone);
        if ($phone === '') throw new InvalidArgumentException('Invalid birthday coupon mobile');

        $tehran = $today->setTimezone(new DateTimeZone('Asia/Tehran'));
        $campaignYear = (int)$tehran->format('Y');
        $expires = $tehran->modify('+7 days')->setTime(23, 59, 59);
        $expiryUtc = $expires->setTimezone(new DateTimeZone('UTC'));
        $code = 'BAJIBD-' . substr((string)$campaignYear, -2) . '-'
            . strtoupper(substr(hash_hmac('sha256', $phone . ':' . $campaignYear, $this->secret()), 0, 12));
        $insert = $this->db->prepare(
            "INSERT IGNORE INTO baji_birthday_coupons
            (phone,campaign_year,coupon_code,expires_gmt,state)
            VALUES (:phone,:year,:code,:expires,'reserved')"
        );
        $insert->execute([
            'phone'=>$phone, 'year'=>$campaignYear, 'code'=>$code,
            'expires'=>$expiryUtc->format('Y-m-d H:i:s'),
        ]);
        $get = $this->db->prepare(
            "SELECT coupon_code,expires_gmt,coupon_id FROM baji_birthday_coupons
             WHERE phone=:phone AND campaign_year=:year"
        );
        $get->execute(['phone'=>$phone,'year'=>$campaignYear]);
        $record = $get->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new RuntimeException('Birthday coupon reservation missing');
        $code = (string)$record['coupon_code'];
        $expiryUtc = new DateTimeImmutable((string)$record['expires_gmt'], new DateTimeZone('UTC'));
        $expires = $expiryUtc->setTimezone(new DateTimeZone('Asia/Tehran'));
        if ($expires <= $tehran) throw new RuntimeException('Birthday coupon already expired');

        $coupon = null;
        if ((int)($record['coupon_id'] ?? 0) > 0) {
            $result = $this->woo->get('coupons/' . (int)$record['coupon_id']);
            if (empty($result['error']) && is_array($result['body'] ?? null)) $coupon=$result['body'];
        }
        if (!$coupon) $coupon=$this->fetchByCode($code);
        if (!$coupon) {
            $payload = [
                'code'=>$code,
                'discount_type'=>'percent',
                'amount'=>'15',
                'individual_use'=>true,
                'usage_limit'=>1,
                'usage_limit_per_user'=>1,
                'free_shipping'=>false,
                'date_expires_gmt'=>$expiryUtc->format('Y-m-d\TH:i:s'),
                'description'=>'BAJI private birthday gift, one use per customer',
                'meta_data'=>[
                    ['key'=>'_baji_birthday_phone','value'=>$phone],
                    ['key'=>'_baji_birthday_year','value'=>(string)$campaignYear],
                ],
            ];
            $created=$this->woo->post('coupons',$payload);
            if (empty($created['error']) && is_array($created['body'] ?? null)) {
                $coupon=$created['body'];
            } else {
                // A timeout might mean Woo accepted the coupon. Recover by
                // deterministic code rather than POSTing a new random coupon.
                $coupon=$this->fetchByCode($code);
                if (!$coupon) throw new RuntimeException('Woo birthday coupon creation failed');
            }
        }
        if (!$this->validateCoupon($coupon,$code,$phone,$expiryUtc)) {
            // Coupon date can differ when WordPress/Woo use a different
            // default timezone; correct via the explicit GMT field.
            $id=(int)($coupon['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Birthday coupon verification failed');
            $fix=$this->woo->put('coupons/'.$id, [
                'date_expires_gmt'=>$expiryUtc->format('Y-m-d\TH:i:s')
            ]);
            if (!empty($fix['error']) || !is_array($fix['body'] ?? null) ||
                !$this->validateCoupon($fix['body'],$code,$phone,$expiryUtc)) {
                throw new RuntimeException('Birthday coupon validity verification failed');
            }
            $coupon=$fix['body'];
        }
        if ((int)($coupon['usage_count'] ?? 0)>0) throw new RuntimeException('Existing birthday coupon already used');

        $save=$this->db->prepare(
            "UPDATE baji_birthday_coupons SET coupon_id=:id,state='ready'
             WHERE phone=:phone AND campaign_year=:year"
        );
        $save->execute(['id'=>(int)$coupon['id'],'phone'=>$phone,'year'=>$campaignYear]);
        return ['code'=>$code,'id'=>(int)$coupon['id'],'expires'=>$expires];
    }
}
