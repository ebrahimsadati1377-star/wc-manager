<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
$categories = [];
$loadError = null;

if (!$wc->isConfigured()) {
    $loadError = 'اتصال به ووکامرس تنظیم نشده است.';
} else {
    $res = $wc->getCategories(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
    if ($res['error']) {
        $loadError = $res['error'];
    } else {
        $categories = $res['body'];
    }
}

function buildCategoryTree(array $cats, int $parent = 0, int $depth = 0): array
{
    $result = [];
    foreach ($cats as $cat) {
        if ((int)$cat['parent'] === $parent) {
            $cat['_depth'] = $depth;
            $result[] = $cat;
            $result = array_merge($result, buildCategoryTree($cats, (int)$cat['id'], $depth + 1));
        }
    }
    return $result;
}
$tree = buildCategoryTree($categories);
$totalProductsInCategories = array_sum(array_map(static fn(array $cat): int => (int)($cat['count'] ?? 0), $categories));

$pageTitle = 'دسته‌بندی‌ها';
require __DIR__ . '/partials/header.php';
?>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-layer-group"></i> ساختار فروشگاه</div>
    <h1 class="app-page-head__title">دسته‌بندی‌های محصولات</h1>
    <p class="app-page-head__subtitle">ساختار دسته‌ها را مرتب نگه دارید تا مدیریت محصول و اتصال به مارکت‌پلیس‌ها ساده‌تر شود.</p>
  </div>
  <div class="app-page-head__actions">
    <span class="app-meta-chip"><i class="fas fa-folder-tree"></i> <?= count($tree) ?> دسته</span>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openCatModal()"><i class="fas fa-plus ms-1"></i> دسته‌بندی جدید</button>
  </div>
</div>

<?php if ($loadError): ?>
  <div class="alert alert-danger"><i class="fas fa-circle-exclamation ms-1"></i><?= e($loadError) ?></div>
<?php else: ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="app-section-card h-100"><div class="app-section-card__body">
      <div class="text-muted small mb-1">کل دسته‌ها</div><div class="fs-4 fw-bold"><?= count($categories) ?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="app-section-card h-100"><div class="app-section-card__body">
      <div class="text-muted small mb-1">محصولات دسته‌بندی‌شده</div><div class="fs-4 fw-bold"><?= (int)$totalProductsInCategories ?></div>
    </div></div>
  </div>
</div>

<div class="app-section-card">
  <div class="app-section-card__head">
    <div><h2>ساختار دسته‌ها</h2><p>دسته‌های فرزند با تورفتگی زیر والد نمایش داده می‌شوند.</p></div>
  </div>
  <div class="table-responsive app-desktop-table">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>تصویر</th>
          <th>نام</th>
          <th>نامک</th>
          <th>تعداد محصولات</th>
          <th class="text-end">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tree as $cat): ?>
        <tr>
          <td>
            <?php if (!empty($cat['image']['src'])): ?>
              <img src="<?= e($cat['image']['src']) ?>" class="thumb-sm" alt="<?= e($cat['name']) ?>">
            <?php else: ?>
              <div class="thumb-sm bg-light d-flex align-items-center justify-content-center text-muted"><i class="far fa-image"></i></div>
            <?php endif; ?>
          </td>
          <td><span class="fw-semibold"><?= str_repeat('— ', $cat['_depth']) . e($cat['name']) ?></span></td>
          <td class="text-muted small" dir="ltr"><?= e($cat['slug']) ?></td>
          <td><span class="app-meta-chip"><?= e($cat['count']) ?> محصول</span></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" onclick='openCatModal(<?= json_encode($cat, JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-pen ms-1"></i>ویرایش</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteCat(<?= (int)$cat['id'] ?>, '<?= e(addslashes($cat['name'])) ?>')"><i class="fas fa-trash ms-1"></i>حذف</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tree)): ?>
          <tr><td colspan="5"><div class="app-empty-state"><div class="app-empty-state__icon"><i class="fas fa-folder-open"></i></div><h4>هنوز دسته‌ای ساخته نشده</h4><p>اولین دسته‌بندی فروشگاه را ایجاد کنید.</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="app-mobile-list p-2">
    <?php foreach ($tree as $cat): ?>
      <div class="app-mobile-card">
        <div class="app-mobile-card__top">
          <?php if (!empty($cat['image']['src'])): ?>
            <img src="<?= e($cat['image']['src']) ?>" class="thumb-sm" alt="<?= e($cat['name']) ?>">
          <?php else: ?>
            <div class="thumb-sm bg-light d-flex align-items-center justify-content-center text-muted"><i class="far fa-image"></i></div>
          <?php endif; ?>
          <div class="app-mobile-card__main">
            <div class="app-mobile-card__title"><?= str_repeat('— ', $cat['_depth']) . e($cat['name']) ?></div>
            <div class="app-mobile-card__meta"><span dir="ltr"><?= e($cat['slug']) ?></span><span><?= e($cat['count']) ?> محصول</span></div>
          </div>
        </div>
        <div class="app-mobile-card__actions">
          <button class="btn btn-outline-primary" onclick='openCatModal(<?= json_encode($cat, JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-pen ms-1"></i>ویرایش</button>
          <button class="btn btn-outline-danger" onclick="deleteCat(<?= (int)$cat['id'] ?>, '<?= e(addslashes($cat['name'])) ?>')"><i class="fas fa-trash ms-1"></i>حذف</button>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($tree)): ?>
      <div class="app-empty-state"><div class="app-empty-state__icon"><i class="fas fa-folder-open"></i></div><h4>هنوز دسته‌ای ساخته نشده</h4></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="catModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="catForm">
        <div class="modal-header">
          <div><h5 class="modal-title" id="catModalTitle">دسته‌بندی جدید</h5><div class="text-muted small mt-1">اطلاعات دسته را کامل کنید.</div></div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="cat_id">
          <div class="mb-3">
            <label class="form-label">نام دسته‌بندی</label>
            <input type="text" class="form-control" name="name" id="cat_name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">دسته والد</label>
            <select class="form-select" name="parent" id="cat_parent">
              <option value="0">— بدون والد —</option>
              <?php foreach ($tree as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= str_repeat('— ', $cat['_depth']) . e($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">توضیحات</label>
            <textarea class="form-control" name="description" id="cat_description" rows="3"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">تصویر دسته‌بندی</label>
            <input type="file" class="form-control" id="cat_image_file" accept="image/*">
            <input type="hidden" name="image_url" id="cat_image_url">
            <div class="mt-2"><img id="cat_image_preview" class="thumb-md d-none" alt="پیش‌نمایش تصویر"></div>
          </div>
          <div id="catFormError" class="text-danger small"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
          <button type="submit" class="btn btn-primary" id="catSubmitBtn"><i class="fas fa-check ms-1"></i>ذخیره</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/js/categories.js"></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
