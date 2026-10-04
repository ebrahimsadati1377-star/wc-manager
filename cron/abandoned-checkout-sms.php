<?php
/** BAJI unpaid checkout reminder. Cron every minute; one SMS per eligible order. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WooOrderConfirmationSms.php';
require_once __DIR__ . '/../includes/BajiSmsCampaigns.php';
$db = Database::get();
$db->exec("CREATE TABLE IF NOT EXISTS woo_abandoned_checkout_sms (
 order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, recipient VARCHAR(20) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'sending', message_id VARCHAR(100) NULL,
 claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$enabledAt = (string)getSetting('woo_abandoned_sms_enabled_at','');
if ((string)getSetting('woo_abandoned_sms_enabled','0')!=='1' || $enabledAt==='') { echo "disabled\n"; exit; }
$start=new DateTimeImmutable($enabledAt,new DateTimeZone('UTC'));
$woo=new WooCommerceClient(); $sms=new IPPanelClient();
if (!$woo->isConfigured() || !$sms->isConfigured()) throw new RuntimeException('WooCommerce or IPPanel not configured');
// Newly created checkout orders only. Do not message past orders.
$after=max($start->getTimestamp(),time()-86400);
foreach (['pending','failed'] as $status) {
 $result=$woo->getOrders(['status'=>$status,'after'=>gmdate('Y-m-d\\TH:i:s',$after),'per_page'=>100,'orderby'=>'date','order'=>'desc']);
 if (!empty($result['error']) || (int)($result['status']??0)!==200 || !is_array($result['body']??null)) throw new RuntimeException('Cannot list live WooCommerce orders');
 if ((int)($result['headers']['total_pages']??0)>1) throw new RuntimeException('Order pagination exceeds safe limit; no partial scan');
 foreach ($result['body'] as $candidate) {
  $id=(int)($candidate['id']??0); if ($id<1) continue;
  $created=(string)($candidate['date_created_gmt']??''); if (!$created) continue;
  $createdAt=new DateTimeImmutable($created,new DateTimeZone('UTC'));
  if ($createdAt<$start || time()-$createdAt->getTimestamp()<900) continue;
  $fresh=$woo->getOrder($id);
  if (!empty($fresh['error']) || (int)($fresh['status']??0)!==200) continue;
  $o=$fresh['body'];
  if (!in_array(($o['status']??''),['pending','failed'],true) || ($o['created_via']??'')!=='checkout') continue;
  if (!empty($o['date_paid']) || !empty($o['date_paid_gmt']) || (float)($o['total']??0)<=0) continue;
  $phone=WooOrderConfirmationSms::normalizePhone((string)($o['billing']['phone']??'')); if (!$phone) continue;
  $paymentUrl=trim((string)($o['payment_url']??''));
  $parts=parse_url($paymentUrl);
  if (!is_array($parts) || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='bajistyle.ir' || !str_contains((string)($parts['path']??''),'/checkout/order-pay/'.$id.'/')) continue;
  $claim=$db->prepare("INSERT IGNORE INTO woo_abandoned_checkout_sms (order_id,recipient,state) VALUES (:id,:phone,'sending')");
  $claim->execute(['id'=>$id,'phone'=>$phone]); if ($claim->rowCount()!==1) continue;
  BajiSmsCampaigns::init($db);
  $eventKey='unpaid-order:'.$id;
  if (!BajiSmsCampaigns::claim($db,$phone,'unpaid_order',$eventKey,false)) {
   $db->prepare("UPDATE woo_abandoned_checkout_sms SET state='suppressed' WHERE order_id=?")->execute([$id]);
   continue;
  }
  $first=trim((string)($o['billing']['first_name']??''));
  $first=mb_substr((string)preg_replace('/[\\x00-\\x1f\\x7f]+/u','',$first),0,35);
  $greeting=$first!==''?$first.' جان 🤍':'باجی جان 🤍';
  $paymentUrl=trim((string)($o['payment_url']??''));
  $parts=parse_url($paymentUrl);
  if (!is_array($parts) || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='bajistyle.ir' || !str_contains((string)($parts['path']??''),'/checkout/order-pay/'.$id.'/')) continue;
  $paymentUrl .= (str_contains($paymentUrl,'?')?'&':'?').http_build_query(['utm_source'=>'sms','utm_medium'=>'sms','utm_campaign'=>'unpaid_order','utm_content'=>'order_'.$id]);
  $message=$greeting."\nسفارشت در باجی هنوز منتظر تکمیل پرداخته. 🛍️\n\nاگه هنوز انتخابت رو می‌خوای، برگرد و سفارشت رو تکمیل کن تا موجودیش تموم نشده 🌷\n\nشماره سفارش: #".$id."\nسایت باجی: https://bajistyle.ir/?utm_source=sms&utm_medium=sms&utm_campaign=unpaid_order\nادامه خرید و پرداخت:\n".$paymentUrl."\n\nباجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
  $state='unknown';$messageId='';
  try {
   $answer=$sms->send($phone,$message);
   $accepted=array_key_exists('accepted',$answer)?(bool)$answer['accepted']:(bool)($answer['success']??false);
   $state=$accepted?'accepted':'failed'; $messageId=(string)($answer['message_id']??'');
  } catch(Throwable $e) { error_log('[baji abandoned SMS] order='.$id.' '.$e->getMessage()); }
  $update=$db->prepare('UPDATE woo_abandoned_checkout_sms SET state=:state,message_id=:mid WHERE order_id=:id');
  $update->execute(['state'=>$state,'mid'=>mb_substr($messageId,0,100),'id'=>$id]);
  BajiSmsCampaigns::result($db,$eventKey,$state,$messageId);
  echo "order=$id state=$state\n";
 }
}
