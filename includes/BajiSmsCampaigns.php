<?php
/** BAJI SMS campaign schema and shared one-message-per-purpose claims. */
final class BajiSmsCampaigns {
 public static function init(PDO $db): void {
  $db->exec("CREATE TABLE IF NOT EXISTS baji_sms_contacts (
   phone VARCHAR(20) PRIMARY KEY, first_name VARCHAR(80) NOT NULL DEFAULT '',
   birthday DATE NULL, birthday_jalali VARCHAR(10) NULL,
   birthday_consent TINYINT(1) NOT NULL DEFAULT 0,
   birthday_wp_user_id BIGINT UNSIGNED NULL,
   marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
   opted_out TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // The original SMS contacts table predates Jalali birthday/customer sync.
  $columns=$db->query("SHOW COLUMNS FROM baji_sms_contacts")->fetchAll(PDO::FETCH_COLUMN);
  foreach (array(
   'birthday_jalali'=>"ALTER TABLE baji_sms_contacts ADD COLUMN birthday_jalali VARCHAR(10) NULL",
   'birthday_consent'=>"ALTER TABLE baji_sms_contacts ADD COLUMN birthday_consent TINYINT(1) NOT NULL DEFAULT 0",
   'birthday_wp_user_id'=>"ALTER TABLE baji_sms_contacts ADD COLUMN birthday_wp_user_id BIGINT UNSIGNED NULL"
  ) as $column=>$statement) {
   if (!in_array($column,$columns,true)) {
    try { $db->exec($statement); } catch(PDOException $e) {
     if ((int)($e->errorInfo[1]??0)!==1060)throw $e;
    }
   }
  }
  $db->exec("CREATE TABLE IF NOT EXISTS baji_sms_events (
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(20) NOT NULL,
   campaign VARCHAR(40) NOT NULL, event_key VARCHAR(140) NOT NULL UNIQUE,
   state VARCHAR(24) NOT NULL DEFAULT 'claimed', message_id VARCHAR(100) NULL,
   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   INDEX idx_campaign (campaign,created_at), INDEX idx_phone (phone,created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS baji_sms_deliveries (order_id BIGINT UNSIGNED PRIMARY KEY, delivered_at DATETIME NOT NULL, confirmed_by VARCHAR(80) NOT NULL DEFAULT '', state VARCHAR(20) NOT NULL DEFAULT 'pending') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS baji_sms_purchase_history (phone VARCHAR(20) PRIMARY KEY, last_paid_at DATETIME NOT NULL, last_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS baji_stock_alerts (
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(20) NOT NULL,
   product_id BIGINT UNSIGNED NOT NULL, variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
   consent TINYINT(1) NOT NULL DEFAULT 0, state VARCHAR(20) NOT NULL DEFAULT 'waiting',
   created_at DATETIME DEFAULT CURRENT_TIMESTAMP, notified_at DATETIME NULL,
   UNIQUE KEY uniq_alert (phone,product_id,variation_id),
   INDEX idx_stock (state,product_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 public static function claim(PDO $db,string $phone,string $campaign,string $key,bool $marketing=false,bool $ignoreRecent=false): bool {
  if ($marketing) {
   $q=$db->prepare("SELECT 1 FROM baji_sms_contacts WHERE phone=? AND marketing_consent=1 AND opted_out=0");
   $q->execute([$phone]); if (!$q->fetchColumn()) return false;
  } else {
   $q=$db->prepare("SELECT 1 FROM baji_sms_contacts WHERE phone=? AND opted_out=1");
   $q->execute([$phone]); if ($q->fetchColumn()) return false;
  }
  if ($marketing) {
   // Cap BAJI promotional campaigns across all campaign names to one per seven days.
   $q=$db->prepare("SELECT 1 FROM baji_sms_events WHERE phone=? AND campaign IN ('winback','promotion','marketing','new_product') AND created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY AND state NOT IN ('failed','suppressed') LIMIT 1");
   $q->execute([$phone]);if ($q->fetchColumn()) return false;
  }
  if (!$ignoreRecent) {
   $q=$db->prepare("SELECT 1 FROM baji_sms_events WHERE phone=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR LIMIT 1");
   $q->execute([$phone]);if ($q->fetchColumn()) return false;
  }
  $q=$db->prepare("INSERT IGNORE INTO baji_sms_events (phone,campaign,event_key) VALUES (?,?,?)");
  $q->execute([$phone,$campaign,$key]);return $q->rowCount()===1;
 }
 public static function result(PDO $db,string $key,string $state,string $id=''):void {
  $q=$db->prepare("UPDATE baji_sms_events SET state=?,message_id=? WHERE event_key=?");
  $q->execute([$state,mb_substr($id,0,100),$key]);
 }
}
