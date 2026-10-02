<?php
/** Import only recent paid orders into BAJI SMS purchase history; no outbound SMS. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
$db=Database::get();BajiSmsCampaigns::init($db);$woo=new WooCommerceClient();
if(!$woo->isConfigured())throw new RuntimeException('Woo unavailable');
$page=1;$seen=0;
do {
 $r=$woo->getOrders(['after'=>gmdate('Y-m-d\\TH:i:s',time()-120*86400),'per_page'=>100,'page'=>$page]);
 if(!empty($r['error']) || (int)($r['status']??0)!==200 || !is_array($r['body']??null))throw new RuntimeException('Order API error');
 foreach($r['body'] as $o){
  $phone=WooOrderConfirmationSms::normalizePhone((string)($o['billing']['phone']??''));
  $paid=(string)($o['date_paid_gmt']??'');
  if(!$phone || !$paid || in_array($o['status']??'',['cancelled','refunded','failed','trash'],true))continue;
  $q=$db->prepare("INSERT INTO baji_sms_purchase_history (phone,last_paid_at,last_order_id) VALUES (?,?,?)
   ON DUPLICATE KEY UPDATE last_paid_at=GREATEST(last_paid_at,VALUES(last_paid_at)),
   last_order_id=IF(VALUES(last_paid_at)>=last_paid_at,VALUES(last_order_id),last_order_id)");
  $q->execute([$phone,$paid,(int)$o['id']]);++$seen;
 }
 $pages=(int)($r['headers']['total_pages']??1);++$page;
}while($page<=$pages && $page<=30);
if($page<=$pages)throw new RuntimeException('History exceeds scan limit');
echo "paid_order_records=".$seen."\n";
