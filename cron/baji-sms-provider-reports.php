<?php
/** Read-only IPPanel delivery reconciliation. No SMS is sent. */
require_once __DIR__.'/../includes/bootstrap.php';
$db=Database::get();$sms=new IPPanelClient();
if(!$sms->isConfigured())throw new RuntimeException('IPPanel not configured');
$db->exec("CREATE TABLE IF NOT EXISTS baji_sms_provider_reports (message_id VARCHAR(100) PRIMARY KEY, delivery_status VARCHAR(80) NOT NULL DEFAULT 'unknown', provider_cost DECIMAL(14,2) NULL, cost_unit VARCHAR(12) NULL, checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, raw_report JSON NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$q=$db->query("SELECT e.message_id FROM baji_sms_events e LEFT JOIN baji_sms_provider_reports p ON p.message_id=e.message_id WHERE e.message_id REGEXP '^[0-9]+$' AND e.message_id<>'' AND (p.checked_at IS NULL OR p.checked_at<UTC_TIMESTAMP()-INTERVAL 6 HOUR) GROUP BY e.message_id ORDER BY MAX(e.id) DESC LIMIT 100");
$save=$db->prepare("INSERT INTO baji_sms_provider_reports(message_id,delivery_status,raw_report) VALUES (?,?,?) ON DUPLICATE KEY UPDATE delivery_status=VALUES(delivery_status),raw_report=VALUES(raw_report),checked_at=UTC_TIMESTAMP()");
foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
 try {
  $report=$sms->getMessageStatus((int)$id);
  $status=(string)($report['status']??$report['final_status']??'unknown');
  $save->execute([$id,mb_substr($status,0,80),json_encode($report,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
  echo "report_id=".$id." fetched\n";
 }catch(Throwable $e){error_log('[BAJI sms report] id='.$id.' '.$e->getMessage());}
}
// Cost intentionally remains NULL until a verified provider monetary field and its unit are established.
