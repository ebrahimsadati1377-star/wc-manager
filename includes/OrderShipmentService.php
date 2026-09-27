<?php
/**
 * BAJI shipment workflow for WooCommerce orders.
 * Carrier/tracking is stored on the WooCommerce order; SMS is sent only after
 * that update succeeds. Provider acceptance is not described as delivery.
 */
class OrderShipmentService
{
    private WooCommerceClient $wc;
    private IPPanelClient $sms;
    private const OWNER_SMS_MOBILE = '09111599908';

    private const CARRIERS = [
        'post_pishtaz' => 'پست پیشتاز',
        'post_sefareshi' => 'پست سفارشی',
        'post_vizhe' => 'پست ویژه',
        'tipax' => 'تیپاکس',
        'decapost' => 'دکاپست',
        'mahax' => 'ماهکس',
        'chapar' => 'چاپار',
        'postex' => 'پستکس',
        'snappbox' => 'اسنپ‌باکس',
        'alopeyk' => 'الوپیک',
        'courier' => 'پیک فروشگاه',
        'freight' => 'باربری',
        'other' => 'سایر (نام شرکت را وارد کنید)',
    ];

    public function __construct(WooCommerceClient $wc, IPPanelClient $sms)
    {
        $this->wc = $wc;
        $this->sms = $sms;
    }

    public static function carriers(): array
    {
        return self::CARRIERS;
    }

    /**
     * Verified carrier tracking entry points. If a carrier has no verified
     * official tracking page, do not invent a destination.
     */
    public static function trackingUrl(string $carrier, string $tracking = ''): string
    {
        if (str_starts_with($carrier, 'post_')) return 'https://tracking.post.ir/';
        return match ($carrier) {
            'tipax' => 'https://tipaxco.com/',
            'chapar' => $tracking !== ''
                ? 'https://chaparnet.com/track/' . rawurlencode($tracking)
                : 'https://chaparnet.com/track/',
            default => '',
        };
    }

    public static function moneyInput(string $value): ?int
    {
        $digits = self::normalizeTracking($value);
        $digits = str_replace([',', '٬', '،', ' ', '‌'], '', $digits);
        if (!preg_match('/^\d{1,9}$/D', $digits)) return null;
        $n = (int)$digits;
        return $n <= 100000000 ? $n : null;
    }

    /**
     * Actual freight expense, never changes what the customer paid.
     * Empty is unknown, while zero is a valid explicitly entered expense.
     */
    public function saveShippingCost(int $orderId, string $cost): array
    {
        if ($orderId < 1 || ($amount = self::moneyInput($cost)) === null) {
            return ['type'=>'danger','message'=>'هزینه واقعی ارسال را به تومان، عددی بین صفر تا صد میلیون وارد کنید.'];
        }
        $lock = @fopen(sys_get_temp_dir() . '/baji-shipment-' . $orderId . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['type'=>'warning','message'=>'سفارش در حال ویرایش است؛ چند لحظه دیگر دوباره تلاش کنید.'];
        }
        try {
            $read = $this->wc->getOrder($orderId);
            if (!empty($read['error']) || (int)($read['body']['id'] ?? 0) !== $orderId) {
                return ['type'=>'danger','message'=>'سفارش از ووکامرس دریافت نشد؛ هزینه ذخیره نشد.'];
            }
            $order = $read['body'];
            if (self::meta($order, '_baji_ship_tracking') === '') {
                return ['type'=>'danger','message'=>'برای ثبت هزینه واقعی ابتدا ارسال سفارش را ثبت کنید.'];
            }
            if (!in_array((string)($order['status'] ?? ''), ['processing', 'completed'], true)) {
                return ['type'=>'danger','message'=>'وضعیت سفارش برای ثبت هزینه ارسال مجاز نیست.'];
            }
            $previous = self::meta($order, '_baji_ship_actual_cost_toman');
            if ($previous !== '' && (int)$previous === $amount) {
                return ['type'=>'warning','message'=>'این هزینه قبلاً برای سفارش ثبت شده است.'];
            }
            $user = (array)($_SESSION['user'] ?? []);
            $save = $this->update($orderId, ['meta_data'=>self::metaItems([
                '_baji_ship_actual_cost_toman'=>(string)$amount,
                '_baji_ship_cost_recorded_at'=>gmdate('c'),
                '_baji_ship_cost_recorded_by'=>(string)($user['full_name'] ?? $user['username'] ?? 'مدیر'),
                '_baji_ship_cost_recorded_by_id'=>(string)($user['id'] ?? ''),
            ])]);
            if (!empty($save['error']) || self::meta((array)($save['body'] ?? []), '_baji_ship_actual_cost_toman') !== (string)$amount) {
                return ['type'=>'danger','message'=>'ذخیره هزینه در ووکامرس تأیید نشد؛ دوباره بررسی کنید.'];
            }
            logActivity('shipping_expense_saved', 'order:'.$orderId, 'actual_toman='.$amount.' previous='.$previous);
            return ['type'=>'success','message'=>'هزینه واقعی حمل در سفارش ووکامرس ثبت شد؛ مبلغ پرداختی مشتری تغییری نکرد.'];
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    public static function meta(array $order, string $key, string $default = ''): string
    {
        foreach ((array)($order['meta_data'] ?? []) as $item) {
            if (($item['key'] ?? '') === $key && is_scalar($item['value'] ?? null)) {
                return trim((string)$item['value']);
            }
        }
        return $default;
    }

    public static function carrierLabel(string $carrier, string $other = ''): string
    {
        return $carrier === 'other'
            ? ($other !== '' ? $other : 'سایر')
            : (self::CARRIERS[$carrier] ?? 'نامشخص');
    }

    public static function smsLabel(string $state): string
    {
        return match ($state) {
            'delivered' => 'تحویل پیامک تأیید شده',
            'sent' => 'خروج پیامک از پنل تأیید شده',
            'accepted' => 'پیامک در پنل ثبت شده (تحویل هنوز تأیید نشده)',
            'sending', 'unknown' => 'نیازمند بررسی نتیجه پیامک؛ ارسال مجدد خودکار غیرفعال است',
            'failed' => 'پیامک ناموفق؛ امکان تلاش مجدد',
            'no_phone' => 'شماره معتبر برای پیامک ثبت نشده',
            'same_recipient' => 'شماره مدیر و مشتری یکسان است؛ پیامک دوباره ارسال نشد',
            default => 'هنوز پیامک ارسال نشده',
        };
    }

    public static function normalizeTracking(string $tracking): string
    {
        $tracking = strtr(trim($tracking), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        return $tracking;
    }

    private static function validMobile(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0098')) $digits = substr($digits, 2);
        if (str_starts_with($digits, '98') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) $digits = '0' . $digits;
        return (bool)preg_match('/^09\d{9}$/', $digits);
    }

    private static function metaItems(array $values): array
    {
        $items = [];
        foreach ($values as $key => $value) $items[] = ['key' => $key, 'value' => (string)$value];
        return $items;
    }

    private function update(int $orderId, array $fields): array
    {
        return $this->wc->put('orders/' . $orderId, $fields);
    }

    private static function message(int $orderId, string $customerFirstName, string $carrierLabel, string $tracking, string $trackUrl = ''): string
    {
        $firstName = trim((string)preg_replace('/[\\x00-\\x1f\\x7f]+/u', ' ', $customerFirstName));
        $firstName = trim((string)preg_replace('/\\s+/u', ' ', $firstName));
        $firstName = mb_substr($firstName, 0, 40, 'UTF-8');
        $greeting = $firstName !== '' ? $firstName . ' عزیز 🤍' : 'مشتری عزیز 🤍';

        return "باجی 🤍\n" . $greeting . "\n\nسفارش #" . $orderId . " شما با " . $carrierLabel . " ارسال شد.\n"
            . "کد رهگیری: " . $tracking . "\n"
            . "پیگیری مرسوله: " . ($trackUrl !== '' ? $trackUrl : 'از طریق شرکت حمل‌ونقل') . "\n"
            . "bajistyle.ir\nباجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
    }


    /**
     * Sends the owner's independent copy. Customer and owner results are
     * persisted separately; an uncertain network error never triggers a retry.
     */
    private function notifyOwner(int $orderId, string $customerFirstName, string $customerPhone,
        string $carrier, string $other, string $tracking, string $customerState): array
    {
        $ownerState = 'failed';
        $ownerId = '';
        $ownerPhone = self::OWNER_SMS_MOBILE;
        $digits = static function(string $phone): string {
            $number = preg_replace('/\D+/', '', $phone);
            if (str_starts_with($number, '0098')) $number = substr($number, 2);
            if (str_starts_with($number, '98') && strlen($number) === 12) $number = '0' . substr($number, 2);
            if (strlen($number) === 10 && str_starts_with($number, '9')) $number = '0' . $number;
            return $number;
        };
        if (self::validMobile($customerPhone) && $digits($customerPhone) === $digits($ownerPhone)) {
            $ownerState = 'same_recipient';
        } else {
            $name = trim($customerFirstName) ?: 'مشتری بدون نام';
            $text = "رونوشت اطلاع‌رسانی ارسال | مدیر باجی\n"
                . "مشتری: " . $name . "\n"
                . "موبایل مشتری: " . ($customerPhone !== '' ? $customerPhone : 'ثبت نشده') . "\n"
                . "وضعیت پیامک مشتری: " . self::smsLabel($customerState) . "\n\n"
                . self::message($orderId, $customerFirstName, self::carrierLabel($carrier, $other),
                    $tracking, self::trackingUrl($carrier, $tracking));
            try {
                $result = $this->sms->send($ownerPhone, $text);
                if (array_key_exists('accepted', $result) ? (bool)$result['accepted'] : (bool)($result['success'] ?? false)) {
                    $ownerState = !empty($result['delivery_confirmed']) ? 'delivered'
                        : (!empty($result['confirmed_sent']) ? 'sent' : 'accepted');
                    $ownerId = (string)($result['message_id'] ?? '');
                }
            } catch (Throwable $ex) {
                $error = (string)$ex->getMessage();
                $ownerState = preg_match('/request failed|timeout|timed out|relay request failed/i', $error)
                    ? 'unknown' : 'failed';
                error_log('[wc-manager] owner shipment SMS error order=' . $orderId .
                    ' state=' . $ownerState . ' error=' . $error);
            }
        }
        $logged = $this->update($orderId, [
            'meta_data' => self::metaItems([
                '_baji_ship_owner_sms_state' => $ownerState,
                '_baji_ship_owner_sms_id' => $ownerId,
                '_baji_ship_owner_sms_updated_at' => gmdate('c'),
            ]),
        ]);
        if (!empty($logged['error'])) {
            logActivity('shipment_owner_sms_log_error', 'order:' . $orderId, 'state=' . $ownerState);
            return ['type'=>'warning', 'message'=>'وضعیت رونوشت مدیر در سفارش ذخیره نشد؛ قبل از ارسال دوباره، پنل پیامک بررسی شود.'];
        }
        logActivity('shipment_owner_sms', 'order:' . $orderId, 'state=' . $ownerState . ' message_id=' . $ownerId);
        return [
            'type' => in_array($ownerState, ['accepted','sent','delivered','same_recipient'], true) ? 'success' : 'warning',
            'message' => 'رونوشت مدیر (09111599908): ' . self::smsLabel($ownerState),
        ];
    }

    /**
     * $action: ship, retry_sms or retry_owner_sms. Never retries accepted/uncertain sends.
     * Returns ['type'=>'success|warning|danger', 'message'=>...].
     */
    public function process(int $orderId, string $action, string $carrier = '', string $other = '', string $tracking = ''): array
    {
        if ($orderId < 1 || !in_array($action, ['ship', 'retry_sms', 'retry_owner_sms'], true)) {
            return ['type' => 'danger', 'message' => 'درخواست ارسال نامعتبر است.'];
        }
        $lock = @fopen(sys_get_temp_dir() . '/baji-shipment-' . $orderId . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['type' => 'warning', 'message' => 'این سفارش در حال ثبت توسط کاربر دیگری است؛ چند لحظه بعد دوباره بررسی کنید.'];
        }

        try {
            $read = $this->wc->getOrder($orderId);
            if (!empty($read['error']) || !is_array($read['body'] ?? null) || (int)($read['body']['id'] ?? 0) !== $orderId) {
                return ['type' => 'danger', 'message' => 'دریافت سفارش از ووکامرس ناموفق بود؛ تغییری اعمال نشد.'];
            }
            $order = $read['body'];
            $status = (string)($order['status'] ?? '');
            if (!in_array($status, ['processing', 'completed'], true)) {
                return ['type' => 'danger', 'message' => 'فقط سفارش‌های در حال پردازش یا تکمیل‌شده قابلیت ثبت رهگیری دارند. وضعیت پرداخت سفارش را ابتدا بررسی کنید.'];
            }

            $billing = (array)($order['billing'] ?? []);
            $phone = trim((string)($billing['phone'] ?? ''));
            $customerFirstName = trim((string)($billing['first_name'] ?? ''));
            if ($customerFirstName === '') {
                $customerFirstName = trim((string)($order['shipping']['first_name'] ?? ''));
            }
            $phoneValid = self::validMobile($phone);
            $savedCarrier = self::meta($order, '_baji_ship_carrier');
            $savedOther = self::meta($order, '_baji_ship_other');
            $savedTracking = self::meta($order, '_baji_ship_tracking');
            $savedSmsState = self::meta($order, '_baji_ship_sms_state');
            $savedOwnerSmsState = self::meta($order, '_baji_ship_owner_sms_state');

            if ($action === 'retry_owner_sms') {
                if ($savedCarrier === '' || $savedTracking === '') {
                    return ['type'=>'danger', 'message'=>'ابتدا اطلاعات ارسال سفارش را ثبت کنید.'];
                }
                if ($savedOwnerSmsState !== 'failed') {
                    return ['type'=>'warning', 'message'=>'رونوشت مدیر قبلاً پذیرفته شده یا وضعیت آن نامشخص است؛ برای جلوگیری از تکرار، مجدد ارسال نشد.'];
                }
                $reserved = $this->update($orderId, ['meta_data'=>self::metaItems([
                    '_baji_ship_owner_sms_state'=>'sending',
                    '_baji_ship_owner_sms_updated_at'=>gmdate('c'),
                ])]);
                if (!empty($reserved['error'])) {
                    return ['type'=>'danger', 'message'=>'ثبت تلاش مجدد پیامک مدیر در ووکامرس ناموفق بود.'];
                }
                return $this->notifyOwner($orderId, $customerFirstName, $phone,
                    $savedCarrier, $savedOther, $savedTracking, $savedSmsState);
            }

            if ($action === 'retry_sms') {
                if ($savedCarrier === '' || $savedTracking === '') {
                    return ['type' => 'danger', 'message' => 'ابتدا اطلاعات ارسال سفارش را ثبت کنید.'];
                }
                if (!in_array($savedSmsState, ['failed', 'no_phone'], true)) {
                    return ['type' => 'warning', 'message' => 'این پیامک قبلاً پذیرفته شده یا نتیجه آن نامشخص است؛ برای جلوگیری از پیامک تکراری ارسال مجدد انجام نشد.'];
                }
                if (!$phoneValid) {
                    return ['type' => 'warning', 'message' => 'برای تلاش مجدد، شماره موبایل مشتری را ابتدا در ووکامرس اصلاح کنید.'];
                }
                $carrier = $savedCarrier;
                $other = $savedOther;
                $tracking = $savedTracking;
            } else {
                $carrier = trim($carrier);
                $other = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $other));
                $tracking = self::normalizeTracking($tracking);
                if (!array_key_exists($carrier, self::CARRIERS)) {
                    return ['type' => 'danger', 'message' => 'شرکت حمل‌ونقل را از فهرست انتخاب کنید.'];
                }
                if ($carrier === 'other' && ($other === '' || mb_strlen($other, 'UTF-8') > 60)) {
                    return ['type' => 'danger', 'message' => 'برای گزینه سایر، نام شرکت حمل‌ونقل را وارد کنید (حداکثر ۶۰ نویسه).'];
                }
                if ($carrier !== 'other') $other = '';
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\/._-]{3,63}$/D', $tracking)) {
                    return ['type' => 'danger', 'message' => 'کد رهگیری باید ۴ تا ۶۴ نویسه شامل اعداد، حروف انگلیسی، خط تیره یا / باشد.'];
                }
                if ($carrier === $savedCarrier && $other === $savedOther && $tracking === $savedTracking) {
                    return ['type' => 'warning', 'message' => 'این مشخصات ارسال قبلاً ثبت شده است. برای جلوگیری از پیامک تکراری چیزی دوباره ارسال نشد؛ اگر پیامک ناموفق بوده از «تلاش مجدد پیامک» استفاده کنید.'];
                }
            }

            $fingerprint = hash('sha256', $carrier . "\0" . $other . "\0" . $tracking . "\0" . $phone);
            if ($action === 'ship') {
                $values = [
                    '_baji_ship_carrier' => $carrier,
                    '_baji_ship_other' => $other,
                    '_baji_ship_tracking' => $tracking,
                    '_baji_ship_track_url' => self::trackingUrl($carrier, $tracking),
                    '_baji_ship_sent_at' => gmdate('c'),
                    '_baji_ship_handover_by' => (string)($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? 'مدیر'),
                    '_baji_ship_handover_by_id' => (string)($_SESSION['user']['id'] ?? ''),
                    '_baji_ship_sms_state' => $phoneValid ? 'sending' : 'no_phone',
                    '_baji_ship_sms_key' => $fingerprint,
                    '_baji_ship_sms_id' => '',
                    '_baji_ship_sms_updated_at' => gmdate('c'),
                    '_baji_ship_owner_sms_state' => 'sending',
                    '_baji_ship_owner_sms_id' => '',
                    '_baji_ship_owner_sms_updated_at' => gmdate('c'),
                ];
                $saved = $this->update($orderId, [
                    'status' => 'completed',
                    'meta_data' => self::metaItems($values),
                ]);
                if (!empty($saved['error']) || (string)($saved['body']['status'] ?? '') !== 'completed') {
                    return ['type' => 'danger', 'message' => 'ذخیره رهگیری و تکمیل سفارش در ووکامرس ناموفق بود؛ پیامکی ارسال نشد.'];
                }
                logActivity('shipment_saved', 'order:' . $orderId, 'carrier=' . $carrier . ' tracking=' . $tracking . ' completed');
            } else {
                $reserved = $this->update($orderId, [
                    'meta_data' => self::metaItems([
                        '_baji_ship_sms_state' => 'sending',
                        '_baji_ship_sms_key' => $fingerprint,
                        '_baji_ship_sms_updated_at' => gmdate('c'),
                    ]),
                ]);
                if (!empty($reserved['error'])) {
                    return ['type' => 'danger', 'message' => 'ثبت تلاش مجدد در ووکامرس ناموفق بود؛ پیامکی ارسال نشد.'];
                }
            }

            $smsState = $phoneValid ? 'failed' : 'no_phone';
            $messageId = '';
            if ($phoneValid) {
                try {
                    $result = $this->sms->send($phone, self::message($orderId, $customerFirstName,
                        self::carrierLabel($carrier, $other), $tracking, self::trackingUrl($carrier, $tracking)));
                    if (array_key_exists('accepted', $result) ? (bool)$result['accepted'] : (bool)($result['success'] ?? false)) {
                        $smsState = !empty($result['delivery_confirmed']) ? 'delivered'
                            : (!empty($result['confirmed_sent']) ? 'sent' : 'accepted');
                        $messageId = (string)($result['message_id'] ?? '');
                    }
                } catch (Throwable $ex) {
                    $error = (string)$ex->getMessage();
                    $smsState = preg_match('/request failed|timeout|timed out|relay request failed/i', $error)
                        ? 'unknown' : 'failed';
                    error_log('[wc-manager] shipment SMS failure order=' . $orderId .
                        ' state=' . $smsState . ' error=' . $error);
                }
            }
            $logged = $this->update($orderId, [
                'meta_data' => self::metaItems([
                    '_baji_ship_sms_state' => $smsState,
                    '_baji_ship_sms_id' => $messageId,
                    '_baji_ship_sms_key' => $fingerprint,
                    '_baji_ship_sms_updated_at' => gmdate('c'),
                ]),
            ]);
            $customerLogError = !empty($logged['error']);
            if ($customerLogError) {
                logActivity('shipment_sms_log_error', 'order:' . $orderId, $smsState);
            } else {
                logActivity('shipment_sms', 'order:' . $orderId, 'state=' . $smsState . ' message_id=' . $messageId);
            }
            $customerFeedback = 'پیامک مشتری: ' . self::smsLabel($smsState);
            if ($customerLogError) {
                $customerFeedback .= '؛ ثبت نتیجه در ووکامرس ناموفق بود و ارسال مجدد نیاز به بررسی پنل دارد.';
            }
            // A customer-only retry must never resend the owner's accepted copy.
            if ($action === 'retry_sms') {
                return ['type' => $customerLogError || !in_array($smsState, ['accepted','sent','delivered'], true)
                    ? 'warning' : 'success', 'message' => $customerFeedback];
            }

            // The owner receives a separate copy even if the customer number is invalid.
            $ownerOutcome = $this->notifyOwner($orderId, $customerFirstName, $phone,
                $carrier, $other, $tracking, $smsState);
            return [
                'type' => $customerLogError
                    || !in_array($smsState, ['accepted','sent','delivered'], true)
                    || $ownerOutcome['type'] !== 'success' ? 'warning' : 'success',
                'message' => 'اطلاعات ارسال ذخیره شد و سفارش تکمیل‌شده است. '
                    . $customerFeedback . ' | ' . $ownerOutcome['message'],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
