<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$basalam = new BasalamClient();
$service = new BasalamSafeImageService();
$error = null;
$rows = [];
$defaultCrop = max(15, min(40, (int)getSetting('basalam_safe_crop_top_percent', '28')));

$wooByBasalam = [];
try {
    $stmt = Database::get()->query(
        'SELECT wc_product_id, basalam_product_id FROM basalam_product_map WHERE basalam_product_id IS NOT NULL AND basalam_product_id > 0'
    );
    foreach ($stmt->fetchAll() as $map) {
        $bid = (int)($map['basalam_product_id'] ?? 0);
        if ($bid > 0) {
            $wooByBasalam[$bid] = (int)($map['wc_product_id'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $error = 'خواندن مپ محصولات ناموفق بود.';
}

if (!$error && !$basalam->isConfigured()) {
    $error = 'اتصال باسلام تنظیم نشده است.';
}

if (!$error) {
    for ($page = 1; $page <= 50; $page++) {
        $res = $basalam->getVendorProducts(['page' => $page, 'per_page' => 100]);
        if ($res['error']) {
            $error = 'خواندن محصولات باسلام ناموفق بود: ' . $res['error'];
            break;
        }

        $body = $res['body'] ?? [];
        $batch = $body['data'] ?? $body['products'] ?? $body;
        if (!is_array($batch) || !$batch) {
            break;
        }

        $count = 0;
        foreach ($batch as $product) {
            if (!is_array($product)) {
                continue;
            }
            $id = (int)($product['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $count++;

            $status = $product['status'] ?? null;
            $statusValue = is_array($status) ? (int)($status['value'] ?? 0) : (is_numeric($status) ? (int)$status : 0);
            $statusName = is_array($status) ? trim((string)($status['name'] ?? '')) : (is_string($status) ? trim($status) : '');
            $normalizedStatusName = str_replace(['ي', 'ك'], ['ی', 'ک'], $statusName);
            $looksRejected = $statusValue === 3567
                || str_contains($normalizedStatusName, 'تایید نشده')
                || str_contains($normalizedStatusName, 'رد شده')
                || str_contains($normalizedStatusName, 'ردشده');

            if (!$looksRejected) {
                continue;
            }

            $detail = $service->inspectBasalamProduct($id);
            if (!$detail['success'] || empty($detail['illegal_photos'])) {
                continue;
            }

            $wcId = (int)($wooByBasalam[$id] ?? 0);
            $job = $wcId > 0 ? $service->getJob($wcId) : null;
            $photo = is_array($product['photo'] ?? null) ? $product['photo'] : [];
            $thumb = (string)($photo['sm'] ?? $photo['xs'] ?? $photo['md'] ?? '');

            $rows[] = [
                'basalam_id' => $id,
                'wc_id' => $wcId,
                'title' => (string)($product['name'] ?? $product['title'] ?? ('#' . $id)),
                'status_name' => $statusName !== '' ? $statusName : 'تایید نشده',
                'status_value' => $statusValue,
                'thumbnail' => $thumb,
                'illegal_count' => count((array)$detail['illegal_photos']),
                'reasons' => (array)$detail['reasons'],
                'job' => $job,
            ];
        }

        if ($count < 100) {
            break;
        }
    }
}

$pageTitle = 'اصلاح تصاویر ردشده باسلام';
require __DIR__ . '/partials/header.php';
?>

<style>
.safe-row-card{border:1px solid #e6e8eb;border-radius:14px;padding:14px;background:#fff}
.safe-thumb{width:72px;height:72px;object-fit:cover;border-radius:12px;background:#f2f3f5;border:1px solid #e5e7eb}
.safe-reason{font-size:.82rem;line-height:1.8;color:#6c757d}
.safe-result{font-size:.82rem;line-height:1.8;margin-top:8px}
@media(max-width:767.98px){.safe-actions{width:100%}.safe-actions .btn{width:100%}}
</style>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <h3 class="mb-1">اصلاح تصاویر ردشده باسلام</h3>
    <div class="text-muted small">نسخه WooCommerce دست‌نخورده می‌ماند؛ فقط نسخه مخصوص باسلام از بالای تصویر crop و دوباره مربع می‌شود.</div>
  </div>
  <a class="btn btn-outline-secondary" href="basalam-catalog.php">بازگشت به وضعیت باسلام</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php else: ?>
  <div class="card mb-3">
    <div class="card-body d-flex align-items-end justify-content-between flex-wrap gap-3">
      <div>
        <div class="text-muted small">محصولات با رد تصویری</div>
        <div class="fs-3 fw-bold text-danger"><?= count($rows) ?></div>
      </div>
      <div style="min-width:180px">
        <label class="form-label small">برش از بالای عکس</label>
        <div class="input-group">
          <input id="cropPercent" type="number" class="form-control" min="15" max="40" value="<?= (int)$defaultCrop ?>">
          <span class="input-group-text">٪</span>
        </div>
      </div>
      <div class="safe-actions">
        <button id="fixAllBtn" class="btn btn-danger" <?= !$rows ? 'disabled' : '' ?>>اصلاح و ارسال مجدد همه</button>
      </div>
    </div>
  </div>

  <div class="alert alert-warning small">
    این ابزار فقط محصولاتی را پردازش می‌کند که خود API باسلام برایشان <strong>illegal_photos</strong> ثبت کرده باشد. اگر دلیل رد تصویری نباشد، عملیات خودکار متوقف می‌شود.
  </div>

  <div class="d-grid gap-3" id="safeList">
    <?php if (!$rows): ?>
      <div class="card"><div class="card-body text-center text-muted py-5">در حال حاضر محصول ردشده با دلیل تصویری پیدا نشد.</div></div>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
      <div class="safe-row-card" data-wc-id="<?= (int)$row['wc_id'] ?>" data-basalam-id="<?= (int)$row['basalam_id'] ?>">
        <div class="d-flex gap-3 align-items-start flex-wrap flex-md-nowrap">
          <?php if ($row['thumbnail'] !== ''): ?><img class="safe-thumb" src="<?= e($row['thumbnail']) ?>" alt="" loading="lazy"><?php endif; ?>
          <div class="flex-grow-1 min-width-0">
            <div class="fw-semibold mb-1"><?= e($row['title']) ?></div>
            <div class="d-flex gap-2 flex-wrap small mb-2">
              <span class="badge text-bg-danger"><?= e($row['status_name']) ?></span>
              <span class="badge text-bg-light border">Basalam #<?= (int)$row['basalam_id'] ?></span>
              <?php if ($row['wc_id'] > 0): ?><span class="badge text-bg-info">Woo #<?= (int)$row['wc_id'] ?></span><?php else: ?><span class="badge text-bg-secondary">مپ نشده</span><?php endif; ?>
              <span class="badge text-bg-warning"><?= (int)$row['illegal_count'] ?> عکس ردشده</span>
            </div>
            <?php if ($row['reasons']): ?>
              <div class="safe-reason"><?= e(implode(' | ', $row['reasons'])) ?></div>
            <?php endif; ?>
            <?php if (is_array($row['job'])): ?>
              <div class="small text-muted mt-2">آخرین اقدام: <?= e((string)($row['job']['last_status'] ?? '-')) ?><?= !empty($row['job']['submitted_at']) ? ' — ' . e((string)$row['job']['submitted_at']) : '' ?></div>
            <?php endif; ?>
            <div class="safe-result d-none"></div>
          </div>
          <div class="safe-actions d-flex gap-2 flex-wrap">
            <?php if ($row['wc_id'] > 0): ?>
              <button class="btn btn-sm btn-danger fix-one-btn">اصلاح و ارسال مجدد</button>
            <?php else: ?>
              <button class="btn btn-sm btn-secondary" disabled>ابتدا مپ شود</button>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="https://basalam.com/p/<?= (int)$row['basalam_id'] ?>">باسلام</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(function(){
  const cropInput = document.getElementById('cropPercent');
  const allButton = document.getElementById('fixAllBtn');

  async function remediate(card){
    const wcId = Number(card.dataset.wcId || 0);
    if (!wcId) return {success:false,message:'محصول Woo مپ نشده است.'};
    const crop = Math.max(15, Math.min(40, Number(cropInput?.value || 28)));
    const resultBox = card.querySelector('.safe-result');
    const button = card.querySelector('.fix-one-btn');
    if (button) { button.disabled = true; button.textContent = 'در حال اصلاح...'; }
    if (resultBox) { resultBox.className = 'safe-result text-muted'; resultBox.textContent = 'در حال ساخت نسخه مخصوص باسلام و آپلود تصاویر...'; }

    try {
      const response = await fetch('ajax/basalam_prepare_safe_images.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({wc_product_id: wcId, crop_top_percent: crop})
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'اصلاح تصاویر ناموفق بود.');
      if (resultBox) {
        resultBox.className = 'safe-result text-success';
        const after = data.moderation_after || {};
        const status = after.status?.name || after.status?.value || '';
        resultBox.textContent = data.message + (status ? ' وضعیت فعلی باسلام: ' + status : '');
      }
      if (button) button.textContent = 'ارسال شد';
      return data;
    } catch (error) {
      if (resultBox) { resultBox.className = 'safe-result text-danger'; resultBox.textContent = error.message || 'اصلاح تصاویر ناموفق بود.'; }
      if (button) { button.disabled = false; button.textContent = 'تلاش مجدد'; }
      return {success:false,message:error.message};
    }
  }

  document.querySelectorAll('.fix-one-btn').forEach(btn => {
    btn.addEventListener('click', () => remediate(btn.closest('.safe-row-card')));
  });

  allButton?.addEventListener('click', async function(){
    const cards = Array.from(document.querySelectorAll('.safe-row-card')).filter(card => Number(card.dataset.wcId || 0) > 0);
    if (!cards.length) return;
    this.disabled = true;
    const original = this.textContent;
    let ok = 0, failed = 0;
    for (const card of cards) {
      const result = await remediate(card);
      if (result.success) ok++; else failed++;
    }
    this.textContent = 'تمام شد: ' + ok + ' موفق' + (failed ? '، ' + failed + ' خطا' : '');
    if (failed) this.classList.replace('btn-danger','btn-warning'); else this.classList.replace('btn-danger','btn-success');
    setTimeout(() => { if (!failed) this.textContent = original; }, 5000);
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
