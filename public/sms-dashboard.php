<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/BajiSmsCampaigns.php';
Auth::requireAdmin();
$db=Database::get();BajiSmsCampaigns::init($db);
$stats=$db->query("SELECT campaign,state,COUNT(*) total FROM baji_sms_events GROUP BY campaign,state ORDER BY campaign,state")->fetchAll(PDO::FETCH_ASSOC);
$leads=$db->query("SELECT status,COUNT(*) total FROM woo_checkout_leads GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$unpaid=$db->query("SELECT state,COUNT(*) total FROM woo_abandoned_checkout_sms GROUP BY state")->fetchAll(PDO::FETCH_ASSOC);
$alerts=$db->query("SELECT state,COUNT(*) total FROM baji_stock_alerts GROUP BY state")->fetchAll(PDO::FETCH_ASSOC);
$contacts=$db->query("SELECT COUNT(*) total,SUM(marketing_consent=1 AND opted_out=0) consented,SUM(opted_out=1) unsubscribed,SUM(birthday IS NOT NULL OR birthday_jalali IS NOT NULL) birthdays,SUM(birthday_consent=1 AND birthday_jalali IS NOT NULL AND opted_out=0) birthday_optins FROM baji_sms_contacts")->fetch(PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE IF NOT EXISTS baji_sms_provider_reports (message_id VARCHAR(100) PRIMARY KEY, delivery_status VARCHAR(80) NOT NULL DEFAULT 'unknown', provider_cost DECIMAL(14,2) NULL, cost_unit VARCHAR(12) NULL, checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, raw_report JSON NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$provider=$db->query("SELECT COUNT(*) checked, SUM(provider_cost IS NOT NULL) priced, SUM(CASE WHEN cost_unit='toman' THEN provider_cost ELSE 0 END) verified_toman FROM baji_sms_provider_reports")->fetch(PDO::FETCH_ASSOC);
$pageTitle='گزارش پیامک‌ها';require __DIR__.'/partials/header.php';
?>
<div class="container-fluid py-4" style="max-width:1100px">
<h1 class="h4 mb-3">داشبورد پیامک باجی</h1>
<p><a class="btn btn-outline-primary me-2" href="sms-contacts.php">مدیریت مخاطبان و موجودی</a><a class="btn btn-outline-primary" href="sms-delivery.php">تأیید تحویل سفارش</a></p>
<div class="alert alert-info">گزارش‌های اپراتور برای پیامک‌های دارای شناسه به‌صورت جداگانه استعلام می‌شوند. مبلغ هزینه فقط در صورت وجود مبلغ و واحد پولی تأییدشده نمایش داده می‌شود؛ عدد نامعلوم به‌جای صفر گزارش نمی‌شود.</div>
<div class="card p-3 mb-3"><h2 class="h6">هزینه واقعی IPPanel</h2><p>گزارش‌های استعلام‌شده: <?= (int)$provider['checked'] ?> | پیامک‌های دارای مبلغ تأییدشده: <?= (int)$provider['priced'] ?></p><strong>هزینه تأییدشده: <?= (int)$provider['priced']>0 ? number_format((float)$provider['verified_toman']).' تومان (فقط موارد با واحد تومان)' : 'هنوز از اپراتور قابل تأیید نیست' ?></strong><small class="text-muted">جمع فوق هزینه کل کمپین‌ها نیست مگر اینکه هزینه تمام پیامک‌ها از ارائه‌دهنده دریافت شود.</small></div>
<div class="row g-3 mb-3">
<div class="col-md-3"><div class="card p-3"><small>مخاطبان</small><strong><?= (int)$contacts['total'] ?></strong></div></div>
<div class="col-md-3"><div class="card p-3"><small>رضایت تبلیغاتی</small><strong><?= (int)$contacts['consented'] ?></strong></div></div>
<div class="col-md-3"><div class="card p-3"><small>لغو دریافت</small><strong><?= (int)$contacts['unsubscribed'] ?></strong></div></div>
<div class="col-md-3"><div class="card p-3"><small>تولد ثبت‌شده</small><strong><?= (int)$contacts['birthdays'] ?></strong></div></div>
<div class="col-md-3"><div class="card p-3"><small>رضایت پیامک تولد (جدا از تبلیغات)</small><strong><?= (int)$contacts['birthday_optins'] ?></strong></div></div>
</div>
<?php
function bajiSmsTable(string $title,array $rows,string $key):void { ?>
<div class="card p-3 mb-3"><h2 class="h6"><?= e($title) ?></h2>
<table class="table table-sm table-striped"><thead><tr><th>نوع/وضعیت</th><th>نتیجه</th><th>تعداد</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= e((string)($row[$key]??'')) ?></td><td><?= e((string)($row['state']??($row['status']??'—'))) ?></td><td><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3">هنوز داده‌ای ثبت نشده است.</td></tr><?php endif; ?>
</tbody></table></div><?php }
bajiSmsTable('کمپین‌ها و وضعیت پذیرش', $stats,'campaign');
bajiSmsTable('سبد خرید قبل از سفارش', $leads,'status');
bajiSmsTable('سفارش پرداخت‌نشده', $unpaid,'state');
bajiSmsTable('درخواست اطلاع از موجودی', $alerts,'state');
?>
<p class="text-muted small">ارسال کمپین‌های تبلیغاتی فقط برای مخاطبان دارای رضایت ثبت‌شده مجاز است. هر رویداد یک کلید یکتا و محدودیت تماس ۲۴ ساعته دارد.</p>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>
