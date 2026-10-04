<?php
/** BAJI stock alert: only explicit requests, check live stock before sending. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
$db=Database::get();BajiSmsCampaigns::init($db);
if(getSetting('baji_campaign_stock','0')!=='1'){echo "stock alerts disabled\n";exit;}
$woo=new WooCommerceClient();$sms=new IPPanelClient();
if(!$woo->isConfigured() || !$sms->isConfigured())throw new RuntimeException('Provider unavailable');
$rows=$db->query("SELECT * FROM baji_stock_alerts WHERE state='waiting' AND consent=1 ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){
 $id=(int)$row['product_id'];$variant=(int)$row['variation_id'];
 $r=$woo->get('products/'.$id.($variant?'/variations/'.$variant:''));
 if(!empty($r['error']) || (int)($r['status']??0)!==200)continue;
 $product=$r['body'];if(($product['stock_status']??'')!=='instock' || ($product['status']??'publish')!=='publish')continue;
 $p=WooOrderConfirmationSms::normalizePhone($row['phone']);if(!$p)continue;
 $key='stock:'.$row['id'];
 if(!BajiSmsCampaigns::claim($db,$p,'stock',$key,false))continue;
 $link='https://bajistyle.ir/?p='.$id.'&utm_source=sms&utm_medium=sms&utm_campaign=stock_alert';
 $msg="باجی 🤍\nمحصولی که منتظرش بودی دوباره موجود شد!\nبرای دیدن محصول و خرید:\n".$link."\nباجی؛ کیفیتی که با اولین پوشیدن حسش می‌کنی🤍";
 try{$a=$sms->send($p,$msg);$state=!empty($a['accepted'])?'accepted':'failed';BajiSmsCampaigns::result($db,$key,$state,(string)($a['message_id']??''));}
 catch(Throwable $e){$state='unknown';BajiSmsCampaigns::result($db,$key,$state);error_log('[BAJI stock] '.$e->getMessage());}
 $q=$db->prepare("UPDATE baji_stock_alerts SET state=?,notified_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$state,$row['id']]);
}
