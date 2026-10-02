<?php
/** BAJI pre-order reminders: distinct from WooCommerce order-payment reminders. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WooOrderConfirmationSms.php';
require_once __DIR__ . '/../includes/BajiSmsCampaigns.php';
$db=Database::get();
$db->exec("CREATE TABLE IF NOT EXISTS woo_checkout_leads (
 session_token CHAR(32) NOT NULL PRIMARY KEY,
 phone VARCHAR(20) NOT NULL DEFAULT '', first_name VARCHAR(80) NOT NULL DEFAULT '',
 status VARCHAR(20) NOT NULL DEFAULT 'open',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 claimed_at DATETIME NULL, message_id VARCHAR(100) NULL,
 INDEX idx_baji_checkout_due (status,last_seen_at),
 INDEX idx_baji_checkout_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if ((string)getSetting('baji_checkout_lead_enabled','0')!=='1') { echo "disabled\n"; exit; }
$sms=new IPPanelClient();$woo=new WooCommerceClient();
if (!$sms->isConfigured() || !$woo->isConfigured()) throw new RuntimeException('Providers unavailable');
$rows=$db->query("SELECT session_token,phone,first_name,created_at,last_seen_at FROM woo_checkout_leads
 WHERE status='open' AND last_seen_at<=UTC_TIMESTAMP()-INTERVAL 15 MINUTE
 AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY ORDER BY last_seen_at LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "no_due_leads\n"; exit; }
$orders=[];$page=1;
do {
 $r=$woo->getOrders(['after'=>gmdate('Y-m-d\\TH:i:s',time()-2*86400),'per_page'=>100,'page'=>$page,'orderby'=>'date','order'=>'desc']);
 if (!empty($r['error']) || (int)($r['status']??0)!==200 || !is_array($r['body']??null)) throw new RuntimeException('WooCommerce listing failed');
 foreach($r['body'] as $order) {
  $phone=WooOrderConfirmationSms::normalizePhone((string)($order['billing']['phone']??''));
  if ($phone!=='') $orders[$phone][]=$order;
 }
 $pages=(int)($r['headers']['total_pages']??1);++$page;
} while ($page<=$pages && $page<=15);
if ($page<=$pages) throw new RuntimeException('Order pagination truncated, no messages sent');
foreach ($rows as $lead) {
 $phone=$lead['phone'];$token=$lead['session_token'];$since=strtotime($lead['created_at'].' UTC');
 $hasOrder=false;
 foreach($orders[$phone]??[] as $o) {
  $when=strtotime((string)($o['date_created_gmt']??'').' UTC');
  if ($when >= $since-120) { $hasOrder=true; break; }
 }
 if ($hasOrder) {
  $q=$db->prepare("UPDATE woo_checkout_leads SET status='order_created' WHERE session_token=:token AND status='open'");
  $q->execute(['token'=>$token]);continue;
 }
 $claim=$db->prepare("UPDATE woo_checkout_leads SET status='sending',claimed_at=UTC_TIMESTAMP()
 WHERE session_token=:token AND status='open' AND last_seen_at<=UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
 $claim->execute(['token'=>$token]);if ($claim->rowCount()!==1) continue;
 BajiSmsCampaigns::init($db);
 $eventKey='checkout-lead:'.$token;
 if (!BajiSmsCampaigns::claim($db,$phone,'checkout_lead',$eventKey,false)) {
  $db->prepare("UPDATE woo_checkout_leads SET status='suppressed' WHERE session_token=? AND status='sending'")->execute([$token]);
  continue;
 }
 $name=trim((string)$lead['first_name']);$greeting=$name!==''?$name.' جان 🤍':'باجی جان 🤍';
 $link='https://bajistyle.ir/checkout/';
 $message=$greeting."\nیه انتخاب قشنگ تو سبد خریدت منتظرته! 🛍️\n\nاگه هنوز می‌خوایش، از لینک زیر برگرد و خریدت رو تکمیل کن 🌷\n\nسایت باجی: https://bajistyle.ir\nادامه خرید:\n".$link."\n\nباجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
 $state='unknown';$mid='';
 try {
  $answer=$sms->send($phone,$message);
  $state=(bool)($answer['accepted']??false)?'accepted':'failed';
  $mid=(string)($answer['message_id']??'');
 } catch(Throwable $e) { error_log('[BAJI checkout lead] '.$e->getMessage()); }
 $q=$db->prepare("UPDATE woo_checkout_leads SET status=:state,message_id=:mid WHERE session_token=:token");
 $q->execute(['state'=>$state,'mid'=>mb_substr($mid,0,100),'token'=>$token]);
 BajiSmsCampaigns::result($db,$eventKey,$state,$mid);
 echo "lead=$token status=$state\n";
}
