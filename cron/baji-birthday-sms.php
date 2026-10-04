<?php
/**
 * BAJI birthday-only SMS. Both birthday and explicit, separate consent are
 * required. One claim per contact/year; provider timeouts are never resent.
 * Existing manually enrolled Gregorian contacts remain supported.
 */
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
require_once __DIR__.'/../includes/BajiBirthdayCouponService.php';

$db=Database::get();
BajiSmsCampaigns::init($db);
if (getSetting('baji_campaign_birthday','0')!=='1') {
    echo "birthday disabled\n";
    exit;
}
if (!class_exists('IntlDateFormatter')) throw new RuntimeException('Intl is required for Persian birthday dates');

$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
$formatter=new IntlDateFormatter('fa_IR@calendar=persian',
    IntlDateFormatter::NONE,IntlDateFormatter::NONE,'Asia/Tehran',
    IntlDateFormatter::TRADITIONAL,'yyyy/MM/dd');
$today=strtr((string)$formatter->format($now),[
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
]);
if (!preg_match('~^\d{4}/\d{2}/\d{2}$~D',$today)) throw new RuntimeException('Could not derive Persian local date');
$jalaliMonthDay=substr($today,5,5);
$tomorrowJ=strtr((string)$formatter->format($now->modify('+1 day')),[
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
]);
// For a person born on leap-day Esfand 30, celebrate on Esfand 29 in a
// non-leap year rather than silently skipping their birthday.
$jalaliLeapDayFallback=($jalaliMonthDay==='12/29' && substr($tomorrowJ,5,5)==='01/01')
    ? '12/30' : '__/__';
$gregorianMonthDay=$now->format('m-d');

$stmt=$db->prepare(
    "SELECT phone,first_name FROM baji_sms_contacts
     WHERE opted_out=0 AND (
       (birthday_jalali IS NOT NULL AND birthday_jalali <> ''
           AND RIGHT(birthday_jalali,5) IN (?,?) AND birthday_consent=1)
       OR
       (birthday_wp_user_id IS NULL AND (birthday_jalali IS NULL OR birthday_jalali='')
           AND birthday IS NOT NULL AND DATE_FORMAT(birthday,'%m-%d')=?
           AND marketing_consent=1)
     )"
);
$stmt->execute([$jalaliMonthDay,$jalaliLeapDayFallback,$gregorianMonthDay]);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
$couponService=new BajiBirthdayCouponService($db,new WooCommerceClient());
$sms=new IPPanelClient();
if ($rows && !$sms->isConfigured()) throw new RuntimeException('IPPanel birthday SMS connection unavailable');
$claimed=0;$accepted=0;$failed=0;
foreach ($rows as $row) {
    $phone=WooOrderConfirmationSms::normalizePhone((string)$row['phone']);
    if ($phone==='') continue;
    // Preserve the event key used by the previous birthday campaign.
    $key='birthday:'.$now->format('Y').':'.$phone;
    // If a previous run already claimed an SMS (accepted, rejected or
    // uncertain), never issue a new coupon or send a duplicate notification.
    $check=$db->prepare('SELECT 1 FROM baji_sms_events WHERE event_key=? LIMIT 1');
    $check->execute([$key]);
    if ($check->fetchColumn()) continue;
    try {
        // Provision and verify the real WooCommerce coupon first. Failures are
        // retryable without claiming the SMS event or sending a false promise.
        $gift=$couponService->ensure($phone,$now);
    } catch(Throwable $e) {
        ++$failed;
        error_log('[BAJI birthday coupon] provisioning failed: '.$e->getMessage());
        continue;
    }
    // The SQL query provides birthday-specific opt-in, while claim() enforces
    // global opt-out and annual dedup. Birthdays take precedence over the
    // general 24-hour campaign spacing.
    if (!BajiSmsCampaigns::claim($db,$phone,'birthday',$key,false,true)) continue;
    $claimed++;
    $name=trim((string)($row['first_name']??'')) ?: 'دوست عزیز';
    $name=mb_substr((string)preg_replace('/[\x00-\x1f\x7f]+/u',' ',$name),0,50,'UTF-8');
    $expiresJ=strtr((string)$formatter->format($gift['expires']),[
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
    $msg="باجی 🤍\n".$name." جان، تولدت مبارک! 🎂\n\n"
        ."هدیه تولدت: ۱۵٪ تخفیف برای یک خرید از باجی.\n"
        ."کد اختصاصی: ".$gift['code']."\n"
        ."اعتبار تا پایان ".$expiresJ."\n"
        ."این کد یک‌بار و فقط با شماره موبایل خودت قابل استفاده است.\n\n"
        ."خرید: https://bajistyle.ir/?utm_source=sms&utm_medium=sms&utm_campaign=birthday\n"
        ."باجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
    try {
        $result=$sms->send($phone,$msg);
        $ok=array_key_exists('accepted',$result)
            ? (bool)$result['accepted'] : (bool)($result['success']??false);
        BajiSmsCampaigns::result($db,$key,$ok?'accepted':'failed',(string)($result['message_id']??''));
        if ($ok)++$accepted; else ++$failed;
    } catch(Throwable $e) {
        BajiSmsCampaigns::result($db,$key,'unknown');
        ++$failed;
        error_log('[BAJI birthday SMS] uncertain/rejected outcome: '.$e->getMessage());
    }
}
echo 'birthday_cron_checked='.count($rows).' claimed='.$claimed.' accepted='.$accepted.' failed='.$failed.PHP_EOL;
