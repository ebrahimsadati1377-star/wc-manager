<?php
/**
 * Sync customers' explicit birthday-only permission from WooCommerce to the
 * private BAJI SMS contact database. Does not send SMS or opt customers in to
 * general marketing campaigns.
 */
declare(strict_types=1);
final class BajiBirthdayContactsSync
{
    private PDO $db;
    private WooCommerceClient $wc;

    public function __construct(PDO $db, WooCommerceClient $wc)
    {
        $this->db = $db;
        $this->wc = $wc;
        BajiSmsCampaigns::init($db);
    }

    private static function orderMeta(array $item, string $key): ?string
    {
        foreach ((array)($item['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && (string)($meta['key'] ?? '') === $key) {
                $value = $meta['value'] ?? '';
                return is_scalar($value) ? trim((string)$value) : '';
            }
        }
        return null;
    }

    private static function normalizeBirthday(?string $input): ?string
    {
        if ($input === null || trim($input) === '') return null;
        $date = strtr(trim($input), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        if (!preg_match('~^([0-9]{4})/([0-9]{2})/([0-9]{2})$~D', $date, $m)) return null;
        $y=(int)$m[1];$mo=(int)$m[2];$day=(int)$m[3];
        if ($y<1300 || $y>1500 || $mo<1 || $mo>12 || $day<1 || $day>31 || ($mo>6 && $day>30)) return null;
        $cal=IntlCalendar::createInstance('Asia/Tehran','fa_IR@calendar=persian');
        if (!$cal) return null;
        $cal->clear();
        $cal->setLenient(false);
        $cal->set($y,$mo-1,$day);
        $time=$cal->getTime();
        if ($time===false || $cal->get(IntlCalendar::FIELD_YEAR)!==$y
            || $cal->get(IntlCalendar::FIELD_MONTH)!==$mo-1
            || $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH)!==$day) return null;
        return sprintf('%04d/%02d/%02d',$y,$mo,$day);
    }

    private static function phone(array $data): string
    {
        $billing=(array)($data['billing'] ?? []);
        return WooOrderConfirmationSms::normalizePhone((string)($billing['phone'] ?? ''));
    }
    private static function name(array $data): string
    {
        $billing=(array)($data['billing'] ?? []);
        $name=trim((string)($data['first_name'] ?? $billing['first_name'] ?? ''));
        $name=trim((string)preg_replace('/[\x00-\x1f\x7f]+/u',' ',$name));
        return mb_substr($name,0,80,'UTF-8');
    }

    public function syncCustomers(): array
    {
        $updated=0;$seen=0;$pageSize=100;
        for ($page=1;$page<=100;$page++) {
            $res=$this->wc->get('customers',['per_page'=>$pageSize,'page'=>$page]);
            if (!empty($res['error']) || !is_array($res['body'] ?? null)) {
                if ($page>1 && (int)($res['status']??0)===400) break;
                throw new RuntimeException('Woo customer page fetch failed page='.$page);
            }
            $rows=$res['body'];
            foreach ($rows as $item) {
                if (!is_array($item)) continue;
                $seen++;
                $id=(int)($item['id']??0);
                if ($id<1) continue;
                $dateValue=self::orderMeta($item,'baji_birthdate_jalali');
                $consentValue=self::orderMeta($item,'baji_birthday_sms_consent');
                if ($dateValue===null && $consentValue===null) continue;
                $phone=self::phone($item);
                if ($phone==='') continue;
                $date=self::normalizeBirthday($dateValue);
                $consent=$date!==null && $consentValue==='1' ? 1 : 0;
                $this->db->beginTransaction();
                try {
                    // Disable the old destination when a user changes mobile.
                    $stmt=$this->db->prepare(
                        'UPDATE baji_sms_contacts SET birthday_consent=0
                         WHERE birthday_wp_user_id=:id AND phone<>:phone'
                    );
                    $stmt->execute(['id'=>$id,'phone'=>$phone]);
                    $stmt=$this->db->prepare(
                        'INSERT INTO baji_sms_contacts
                         (phone,first_name,birthday_jalali,birthday_consent,birthday_wp_user_id)
                         VALUES (:phone,:name,:birthday,:consent,:uid)
                         ON DUPLICATE KEY UPDATE
                         first_name=VALUES(first_name),birthday_jalali=VALUES(birthday_jalali),
                         birthday_consent=VALUES(birthday_consent),birthday_wp_user_id=VALUES(birthday_wp_user_id)'
                    );
                    $stmt->execute([
                        'phone'=>$phone,'name'=>self::name($item),'birthday'=>$date,
                        'consent'=>$consent,'uid'=>$id,
                    ]);
                    $this->db->commit();
                    $updated++;
                } catch(Throwable $e) {
                    $this->db->rollBack();
                    throw $e;
                }
            }
            if (count($rows)<$pageSize) break;
            if ($page===100) throw new RuntimeException('Customer pagination safety limit reached');
        }
        return ['examined'=>$seen,'synced'=>$updated];
    }

    public function syncGuestOrders(): array
    {
        $updated=0;$seen=0;$pageSize=100;
        $after=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->sub(new DateInterval('P14D'))->format('Y-m-d\TH:i:s');
        for ($page=1;$page<=50;$page++) {
            $res=$this->wc->get('orders',[
                'per_page'=>$pageSize,'page'=>$page,'status'=>'any',
                'after'=>$after,'orderby'=>'date','order'=>'asc',
            ]);
            if (!empty($res['error']) || !is_array($res['body'] ?? null)) {
                if ($page>1 && (int)($res['status']??0)===400) break;
                throw new RuntimeException('Woo guest order page fetch failed page='.$page);
            }
            $rows=$res['body'];
            foreach ($rows as $order) {
                if (!is_array($order)) continue;
                $seen++;
                if ((int)($order['customer_id']??0)>0) continue;
                $dateMeta=self::orderMeta($order,'baji_birthdate_jalali');
                if ($dateMeta===null) continue;
                $date=self::normalizeBirthday($dateMeta);
                if ($date===null) continue;
                $phone=self::phone($order);
                if ($phone==='') continue;
                $consent=self::orderMeta($order,'baji_birthday_sms_consent')==='1'?1:0;
                // Customer-owned records always win over older guest checkout data.
                $stmt=$this->db->prepare(
                    'INSERT INTO baji_sms_contacts (phone,first_name,birthday_jalali,birthday_consent)
                     VALUES (:phone,:name,:birthday,:consent)
                     ON DUPLICATE KEY UPDATE
                     first_name=IF(birthday_wp_user_id IS NULL,VALUES(first_name),first_name),
                     birthday_jalali=IF(birthday_wp_user_id IS NULL,VALUES(birthday_jalali),birthday_jalali),
                     birthday_consent=IF(birthday_wp_user_id IS NULL,VALUES(birthday_consent),birthday_consent)'
                );
                $stmt->execute([
                    'phone'=>$phone,'name'=>self::name($order),'birthday'=>$date,'consent'=>$consent,
                ]);
                $updated++;
            }
            if (count($rows)<$pageSize) break;
            if ($page===50) throw new RuntimeException('Guest-order pagination safety limit reached');
        }
        return ['examined'=>$seen,'synced'=>$updated];
    }

    public function run(): array
    {
        // Scan guest orders first, then customer profiles. An account opt-out or
        // edited birthday always takes precedence over checkout history.
        $guests=$this->syncGuestOrders();
        $customers=$this->syncCustomers();
        return ['guests'=>$guests,'customers'=>$customers];
    }
}
