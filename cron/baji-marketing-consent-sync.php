<?php
/** Sync only explicit WooCommerce marketing opt-ins. Never reset opt-outs. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
$db=Database::get();BajiSmsCampaigns::init($db);$wc=new WooCommerceClient();
$save=$db->prepare("INSERT INTO baji_sms_contacts(phone,first_name,marketing_consent) VALUES (?,?,1) ON DUPLICATE KEY UPDATE first_name=IF(first_name='',VALUES(first_name),first_name),marketing_consent=IF(opted_out=1,0,1)");
$total=0;
foreach(['customers','orders'] as $kind){
 for($page=1;$page<=30;$page++){
  $params=['per_page'=>100,'page'=>$page];
  if($kind==='orders')$params['after']=gmdate('Y-m-d\\TH:i:s',time()-365*86400);
  $r=$wc->get($kind,$params);
  if(!empty($r['error'])||!is_array($r['body']??null))throw new RuntimeException('Woo fetch '.$kind.' page '.$page);
  foreach($r['body'] as $item){
   $key=$kind==='customers'?'baji_sms_marketing_optin':'_baji_sms_marketing_optin';
   $consent=false;
   foreach((array)($item['meta_data']??[]) as $m)if(($m['key']??'')===$key && (string)($m['value']??'')==='1')$consent=true;
   if(!$consent)continue;
   $billing=(array)($item['billing']??[]);
   $phone=WooOrderConfirmationSms::normalizePhone((string)($billing['phone']??''));if(!$phone)continue;
   $save->execute([$phone,mb_substr(trim((string)($billing['first_name']??$item['first_name']??'')),0,80)]);$total++;
  }
  if(count($r['body'])<100)break;
 }
}
echo 'explicit_optins_synced='.$total."\n";
