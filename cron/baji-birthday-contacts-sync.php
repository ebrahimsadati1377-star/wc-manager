<?php
/** Pull account/checkout birthday consent into BAJI private SMS contact records. */
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
require_once __DIR__.'/../includes/WooOrderConfirmationSms.php';
require_once __DIR__.'/../includes/BajiBirthdayContactsSync.php';
$db=Database::get();
$sync=new BajiBirthdayContactsSync($db,new WooCommerceClient());
try {
    $report=$sync->run();
    echo 'birthday_sync '.json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e) {
    error_log('[BAJI birthday sync] '.$e->getMessage());
    fwrite(STDERR,"birthday contact sync failed\n");
    exit(1);
}
