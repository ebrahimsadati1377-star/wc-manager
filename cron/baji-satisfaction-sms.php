<?php
/** Satisfaction SMS only after BAJI admin explicitly confirms physical delivery. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
$db=Database::get();BajiSmsCampaigns::init($db);
if(getSetting('baji_campaign_delivery','0')!=='1'){echo "delivery campaign disabled\n";exit;}
$woo=new WooCommerceClient();$sms=new IPPanelClient();
if(!$woo->isConfigured() || !$sms->isConfigured())throw new RuntimeException('Provider unavailable');
$rows=$db->query("SELECT * FROM baji_sms_deliveries WHERE state='pending' AND delivered_at<=UTC_TIMESTAMP()-INTERVAL 3 DAY ORDER BY delivered_at LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){
 $id=(int)$row['order_id'];$r=$woo->getOrder($id);
 if(!empty($r['error']) || (int)($r['status']??0)!==200)continue;
 $o=$r['body'];if(in_array($o['status']??'',['cancelled','refunded','failed'],true))continue;
 $phone=WooOrderConfirmationSms::normalizePhone((string)($o['billing']['phone']??''));if(!$phone)continue;
 $key='satisfaction:'.$id;
 if(!BajiSmsCampaigns::claim($db,$phone,'satisfaction',$key,false))continue;
 $name=trim((string)($o['billing']['first_name']??''))?:'دوست عزیز';
 $name=mb_substr($name,0,35);
 $msg=$name." جان 🤍\nامیدواریم از خریدت از باجی راضی باشی. 🌷\nخوشحال می‌شیم نظرت رو درباره سفارش #".$id." بدونیم.\nhttps://bajistyle.ir/my-account/orders/\nباجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
 try{$a=$sms->send($phone,$msg);$state=!empty($a['accepted'])?'accepted':'failed';BajiSmsCampaigns::result($db,$key,$state,(string)($a['message_id']??''));}
 catch(Throwable $e){$state='unknown';BajiSmsCampaigns::result($db,$key,$state);error_log('[BAJI satisfaction] '.$e->getMessage());}
 $q=$db->prepare("UPDATE baji_sms_deliveries SET state=? WHERE order_id=?");$q->execute([$state,$id]);
}
