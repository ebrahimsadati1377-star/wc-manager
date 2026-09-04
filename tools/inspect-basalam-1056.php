<?php
require_once 'includes/bootstrap.php';
$wc = new WooCommerceClient();
$client = new BasalamClient();
$sync = new BasalamSync($wc, $client);
$wooRes = $wc->getProduct(1056);
$woo = (array)($wooRes['body'] ?? []);
$oldRes = $client->getProduct(57614424, true);
$old = (array)($oldRes['body'] ?? []);
$catRef = new ReflectionMethod(BasalamSync::class, 'resolveBasalamCategory');
$catRef->setAccessible(true);
$cat = $catRef->invoke($sync, $woo);
$search = $client->getVendorProducts(['title'=>(string)($woo['name'] ?? ''),'page'=>1,'per_page'=>100]);
echo 'INSPECT=' . json_encode([
    'woo'=>['id'=>$woo['id']??null,'name'=>$woo['name']??null,'sku'=>$woo['sku']??null,'type'=>$woo['type']??null],
    'map'=>$sync->getProductMap(1056),
    'expected_category'=>$cat,
    'old'=>[
        'id'=>$old['id']??57614424,'name'=>$old['name']??$old['title']??null,'sku'=>$old['sku']??null,
        'status'=>$old['status']??null,'category'=>$old['category']??null,'is_showable'=>$old['is_showable']??null,'is_available'=>$old['is_available']??null,
        'variants'=>$old['variants']??$old['variant']??[],
    ],
    'title_search'=>$search['body']??null,
    'title_search_error'=>$search['error']??null,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
