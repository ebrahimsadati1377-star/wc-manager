<?php
/** BAJI winback: only opted-in buyers with no order in the last 45 days. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
$db=Database::get();BajiSmsCampaigns::init($db);
if (getSetting('baji_campaign_winback','0')!=='1') {echo "winback disabled\n";exit;}
$woo=new WooCommerceClient();$sms=new IPPanelClient();
if (!$woo->isConfigured() || !$sms->isConfigured())throw new RuntimeException('Provider unavailable');
$contacts=$db->query("SELECT phone,first_name FROM baji_sms_contacts WHERE marketing_consent=1 AND opted_out=0 LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
if (!$contacts){echo "no consenting contacts\n";exit;}
$orders=[];$page=1;
do {
 $r=$woo->getOrders(['after'=>gmdate('Y-m-d\\TH:i:s',time()-50*86400),'per_page'=>100,'page'=>$page]);
 if (!empty($r['error']) || (int)($r['status']??0)!==200 || !is_array($r['body']??null))throw new RuntimeException('Order lookup failed');
 foreach($r['body'] as $o) {
  $p=WooOrderConfirmationSms::normalizePhone((string)($o['billing']['phone']??''));
  if($p && !in_array($o['status']??'',['cancelled','failed','refunded','trash'],true))$orders[$p]=true;
 }
 $pages=(int)($r['headers']['total_pages']??1);++$page;
}while($page<=$pages && $page<=30);
if($page<=$pages)throw new RuntimeException('Incomplete order history');
foreach($contacts as $c) {
 $p=WooOrderConfirmationSms::normalizePhone($c['phone']);if (!$p || isset($orders[$p]))continue;
 // Require evidence of a previous successful purchase: no speculative first-time marketing.
 $q=$db->prepare("SELECT 1 FROM baji_sms_purchase_history WHERE phone=? AND last_paid_at<UTC_TIMESTAMP()-INTERVAL 45 DAY LIMIT 1");
 $q->execute([$p]);if(!$q->fetchColumn())continue;
 $key='winback:'.gmdate('Y-m').':'.$p;
 if(!BajiSmsCampaigns::claim($db,$p,'winback',$key,true))continue;
 $name=trim($c['first_name'])?:'دوست عزیز';
 $msg=$name." جان 🤍\nدلمون برای دیدنت تو باجی تنگ شده! 🌷\nمدل‌های تازه رو ببین:\nhttps://bajistyle.ir\nباجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
 try {$a=$sms->send($p,$msg);BajiSmsCampaigns::result($db,$key,!empty($a['accepted'])?'accepted':'failed',(string)($a['message_id']??''));}
 catch(Throwable $e){BajiSmsCampaigns::result($db,$key,'unknown');error_log('[BAJI winback] '.$e->getMessage());}
}
