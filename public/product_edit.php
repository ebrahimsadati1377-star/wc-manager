<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
if (!$wc->isConfigured()) {
    setFlash('warning', 'ابتدا اتصال به ووکامرس را در تنظیمات کامل کنید.');
    redirect('index.php');
}

$productId = (int)($_GET['id'] ?? 0);
$isEdit = $productId > 0;
$product = null;
$variations = [];

// Extract video meta from product
$videoAttachmentId = 0;
if ($isEdit) {
    $res = $wc->getProduct($productId);
    if ($res['error']) {
        setFlash('danger', 'محصول یافت نشد: ' . $res['error']);
        redirect('products.php');
    }
    $product = $res['body'];

    if (!empty($product['meta_data']) && is_array($product['meta_data'])) {
        foreach ($product['meta_data'] as $meta) {
            if (($meta['key'] ?? '') === '_bajistyle_product_video_id') {
                $videoAttachmentId = (int)($meta['value'] ?? 0);
                break;
            }
            if (($meta['key'] ?? '') === '_product_video_url' && !$videoAttachmentId) {
                // Legacy URL meta is kept only for backward compatibility.
            }
        }
    }

    if ($product['type'] === 'variable') {
        $varRes = $wc->getVariations($productId);
        if (!$varRes['error']) {
            $variations = $varRes['body'];
        }
    }
}

$catRes = $wc->getCategories(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
$allCategories = $catRes['error'] ? [] : $catRes['body'];

function buildCatTree(array $cats, int $parent = 0, int $depth = 0): array
{
    $out = [];
    foreach ($cats as $c) {
        if ((int)$c['parent'] === $parent) {
            $c['_depth'] = $depth;
            $out[] = $c;
            $out = array_merge($out, buildCatTree($cats, (int)$c['id'], $depth + 1));
        }
    }
    return $out;
}
$catTree = buildCatTree($allCategories);

$attrRes = $wc->getAttributes();
$globalAttributes = $attrRes['error'] ? [] : $attrRes['body'];

$selectedCategoryIds = array_map(fn($c) => (int)$c['id'], $product['categories'] ?? []);

$pageTitle = $isEdit ? 'ویرایش محصول' : 'افزودن محصول جدید';
require __DIR__ . '/partials/header.php';
?>

<style>
.product-edit-header { gap: .75rem; }
.product-edit-header h3 { overflow-wrap: anywhere; line-height: 1.6; }
.product-edit-card { border: 0; box-shadow: 0 .125rem .35rem rgba(0,0,0,.04); }
.product-type-toggle .btn { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
.variation-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.variation-actions .btn { min-height: 40px; }
.video-section { border-top: 1px solid #eef0f2; padding: 1rem; }
.video-preview { width: 100%; height: auto; max-height: 320px; border-radius: .65rem; background: #000; }
.category-scroll { max-height: 300px; overflow-y: auto; }
.category-scroll .form-check { min-height: 36px; display: flex; align-items: center; gap: .35rem; }
.category-scroll .form-check-input { width: 1.15rem; height: 1.15rem; flex: 0 0 auto; }
.save-panel { position: relative; z-index: 10; }
.attr-row { padding: .85rem 0; border-bottom: 1px solid #eef0f2; }
.attr-row:first-child { padding-top: 0; }
.attr-row:last-child { border-bottom: 0; }
#variationsWrap .var-thumb { cursor: pointer; }

@media (max-width: 767.98px) {
    body { padding-bottom: 88px; }
    .product-edit-header { flex-direction: column; align-items: stretch !important; margin-bottom: 1rem !important; }
    .product-edit-header h3 { font-size: 1.15rem; }
    .product-edit-header .btn { width: 100%; min-height: 44px; }
    #productForm > .row { --bs-gutter-y: 1rem; }
    .product-edit-card { margin-bottom: 1rem !important; border-radius: .9rem; }
    .product-edit-card .card-header { padding: .85rem 1rem; }
    .product-edit-card .card-body { padding: 1rem; }
    .product-edit-card .form-control,
    .product-edit-card .form-select,
    #bulkEditSection .form-control,
    #bulkEditSection .btn { min-height: 44px; }

    .product-type-toggle { display: grid !important; grid-template-columns: 1fr 1fr; width: 100%; }
    .product-type-toggle .btn { width: 100%; border-radius: .5rem !important; }

    .attributes-header { flex-direction: column; align-items: stretch !important; gap: .65rem; }
    .attributes-header .btn { min-height: 44px; width: 100%; }
    .attr-row { padding: 1rem; margin-bottom: .75rem; border: 1px solid #e9ecef; border-radius: .8rem; background: #fff; }
    .attr-row .pt-4 { padding-top: .5rem !important; }
    .attr-row .remove-attr-btn { min-height: 44px; }
    .attr-row .form-check { min-height: 44px; display: flex; align-items: center; gap: .35rem; }

    .variations-header { flex-direction: column; align-items: stretch !important; }
    .variation-actions { display: grid; grid-template-columns: 1fr 1fr; width: 100%; }
    .variation-actions .btn { min-height: 44px; width: 100%; white-space: normal; }
    .variation-actions #saveAllVariationsBtn { grid-column: 1 / -1; }
    #bulkEditSection { padding: 1rem !important; }

    #variationsWrap .table-responsive { overflow: visible; }
    #variationsWrap .variation-table,
    #variationsWrap .variation-table tbody,
    #variationsWrap .variation-table tr,
    #variationsWrap .variation-table td { display: block; width: 100%; }
    #variationsWrap .variation-table thead { display: none; }
    #variationsWrap .variation-table { margin: 0; }
    #variationsWrap .variation-row { border: 1px solid #e5e7eb; border-radius: .85rem; padding: .85rem; margin-bottom: .85rem; background: #fff; box-shadow: 0 .125rem .25rem rgba(0,0,0,.03); }
    #variationsWrap .variation-row td { border: 0; padding: .42rem 0; display: grid; grid-template-columns: minmax(88px, .75fr) minmax(0, 1.25fr); align-items: center; gap: .6rem; text-align: right !important; }
    #variationsWrap .variation-row td::before { color: #6c757d; font-size: .78rem; font-weight: 600; }
    #variationsWrap .variation-row td:nth-child(1)::before { content: 'تصویر'; }
    #variationsWrap .variation-row td:nth-child(2)::before { content: 'ترکیب'; }
    #variationsWrap .variation-row td:nth-child(3)::before { content: 'SKU'; }
    #variationsWrap .variation-row td:nth-child(4)::before { content: 'قیمت اصلی'; }
    #variationsWrap .variation-row td:nth-child(5)::before { content: 'قیمت حراج'; }
    #variationsWrap .variation-row td:nth-child(6)::before { content: 'موجودی'; }
    #variationsWrap .variation-row td:nth-child(7)::before { content: 'فعال'; }
    #variationsWrap .variation-row td:nth-child(8)::before { content: 'عملیات'; }
    #variationsWrap .variation-row .form-control { min-height: 44px; width: 100% !important; }
    #variationsWrap .variation-row .form-check-input { width: 1.3rem; height: 1.3rem; }
    #variationsWrap .variation-row td:last-child { grid-template-columns: 88px 1fr 1fr; }
    #variationsWrap .variation-row td:last-child .btn { min-height: 44px; }
    #variationsWrap .var-thumb { width: 64px; height: 64px; object-fit: cover; border-radius: .6rem; }

    .gallery-wrap { gap: .65rem; }
    .gallery-item, .gallery-add { width: 88px; height: 110px; }
    .video-section .input-group { display: grid; grid-template-columns: 1fr; gap: .5rem; }
    .video-section .input-group > .form-control,
    .video-section .input-group > .btn { width: 100%; min-height: 44px; border-radius: .5rem !important; }

    .category-scroll { max-height: 240px; }
    .save-panel {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        padding: .65rem max(.75rem, env(safe-area-inset-right)) calc(.65rem + env(safe-area-inset-bottom)) max(.75rem, env(safe-area-inset-left));
        background: rgba(255,255,255,.96);
        border-top: 1px solid #e5e7eb;
        box-shadow: 0 -.25rem .75rem rgba(0,0,0,.08);
        backdrop-filter: blur(8px);
    }
    .save-panel #saveProductBtn { min-height: 48px; }
    .save-panel #saveResultMsg { text-align: center; margin-top: .25rem; }
}

@media (max-width: 390px) {
    .variation-actions { grid-template-columns: 1fr; }
    .variation-actions #saveAllVariationsBtn { grid-column: auto; }
    #variationsWrap .variation-row td,
    #variationsWrap .variation-row td:last-child { grid-template-columns: 1fr; }
    #variationsWrap .variation-row td::before { margin-bottom: .15rem; }
}
</style>

<div class="product-edit-header d-flex justify-content-between align-items-center mb-4 flex-wrap">
  <h3 class="mb-0"><?= $isEdit ? 'ویرایش محصول: ' . e($product['name']) : 'افزودن محصول جدید' ?></h3>
  <a href="products.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-right ms-1"></i> بازگشت به لیست</a>
</div>

<form id="productForm">
  <input type="hidden" id="product_id" value="<?= (int)$productId ?>">
  <div class="row g-4">
    <div class="col-lg-8">

      <div class="card product-edit-card mb-4">
        <div class="card-header fw-bold">اطلاعات پایه</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">نام محصول <span class="text-danger">*</span></label>
            <input type="text" id="f_name" class="form-control" value="<?= e($product['name'] ?? '') ?>" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">SKU</label>
              <input type="text" id="f_sku" class="form-control" dir="ltr" value="<?= e($product['sku'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">وضعیت انتشار</label>
              <select id="f_status" class="form-select">
                <option value="publish" <?= ($product['status'] ?? '') === 'publish' ? 'selected' : '' ?>>منتشرشده</option>
                <option value="draft" <?= ($product['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                <option value="pending" <?= ($product['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار بررسی</option>
                <option value="private" <?= ($product['status'] ?? '') === 'private' ? 'selected' : '' ?>>خصوصی</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">توضیحات کوتاه</label>
            <textarea id="f_short_description" class="form-control" rows="2"><?= e($product['short_description'] ?? '') ?></textarea>
          </div>
          <div class="mb-0">
            <label class="form-label">توضیحات کامل</label>
            <textarea id="f_description" class="form-control" rows="6"><?= e($product['description'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <div class="card product-edit-card mb-4">
        <div class="card-header fw-bold">نوع محصول و قیمت‌گذاری</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label d-block">نوع محصول</label>
            <div class="btn-group product-type-toggle" role="group">
              <input type="radio" class="btn-check" name="f_type" id="type_simple" value="simple" <?= ($product['type'] ?? 'simple') === 'simple' ? 'checked' : '' ?>>
              <label class="btn btn-outline-primary" for="type_simple">ساده (Simple)</label>
              <input type="radio" class="btn-check" name="f_type" id="type_variable" value="variable" <?= ($product['type'] ?? '') === 'variable' ? 'checked' : '' ?>>
              <label class="btn btn-outline-primary" for="type_variable">متغیر (Variable)</label>
            </div>
            <?php if ($isEdit): ?>
              <div class="form-text text-warning">توجه: تغییر نوع محصول موجود ممکن است باعث از دست رفتن تنوع‌های موجود شود.</div>
            <?php endif; ?>
          </div>

          <div id="simplePricing" class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">قیمت اصلی (تومان)</label>
              <input type="number" id="f_regular_price" class="form-control" value="<?= e($product['regular_price'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">قیمت حراج (تومان)</label>
              <input type="number" id="f_sale_price" class="form-control" value="<?= e($product['sale_price'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">وضعیت موجودی</label>
              <select id="f_stock_status" class="form-select">
                <option value="instock" <?= ($product['stock_status'] ?? 'instock') === 'instock' ? 'selected' : '' ?>>موجود</option>
                <option value="outofstock" <?= ($product['stock_status'] ?? '') === 'outofstock' ? 'selected' : '' ?>>ناموجود</option>
                <option value="onbackorder" <?= ($product['stock_status'] ?? '') === 'onbackorder' ? 'selected' : '' ?>>پیش‌سفارش</option>
              </select>
            </div>
            <div class="col-md-4 mb-3 form-check ps-4">
              <input type="checkbox" class="form-check-input" id="f_manage_stock" <?= !empty($product['manage_stock']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="f_manage_stock">مدیریت موجودی انبار</label>
            </div>
            <div class="col-md-4 mb-3" id="stockQtyWrap">
              <label class="form-label">تعداد موجودی</label>
              <input type="number" id="f_stock_quantity" class="form-control" value="<?= e($product['stock_quantity'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card product-edit-card mb-4">
        <div class="card-header attributes-header d-flex justify-content-between align-items-center">
          <span class="fw-bold">ویژگی‌ها (Attributes)</span>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addAttrBtn"><i class="fas fa-plus ms-1"></i> افزودن ویژگی</button>
        </div>
        <div class="card-body">
          <div id="attributesWrap"></div>
          <p class="text-muted small mb-0">برای محصول متغیر، حداقل یک ویژگی را با گزینه «استفاده برای تنوع» فعال کنید.</p>
        </div>
      </div>

      <div class="card product-edit-card mb-4" id="variationsCard" style="display:none;">
        <div class="card-header variations-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="fw-bold">تنوع‌های محصول (Variations)</span>
          <div class="variation-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleBulkBtn">🛠️ تغییر دسته‌جمعی</button>
            <button type="button" class="btn btn-sm btn-primary" id="genVariationsBtn">🔄 تولید ترکیب‌های جدید</button>
            <button type="button" class="btn btn-sm btn-success" id="saveAllVariationsBtn">💾 ذخیره تغییرات تمام تنوع‌ها</button>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$isEdit): ?>
            <div class="alert alert-info mb-0">ابتدا محصول را ذخیره کنید تا بتوانید تنوع‌ها را مدیریت کنید.</div>
          <?php else: ?>
            <div id="bulkEditSection" class="p-3 mb-3 bg-light rounded border" style="display: none;">
              <h6 class="mb-3 fw-bold text-secondary">⚡ اعمال تغییرات روی تمام ۲۰+ تنوع به صورت یکجا:</h6>
              <div class="row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="small form-label fw-bold text-muted">قیمت اصلی برای همه</label>
                  <input type="number" id="bulk_regular_price" class="form-control form-control-sm" placeholder="مثلا 550000">
                </div>
                <div class="col-md-3">
                  <label class="small form-label fw-bold text-muted">قیمت حراج برای همه</label>
                  <input type="number" id="bulk_sale_price" class="form-control form-control-sm" placeholder="مثلا 490000">
                </div>
                <div class="col-md-3">
                  <label class="small form-label fw-bold text-muted">تعداد موجودی همه</label>
                  <input type="number" id="bulk_stock_qty" class="form-control form-control-sm" placeholder="مثلا 15">
                </div>
                <div class="col-md-3">
                  <button type="button" class="btn btn-sm btn-success w-100" id="applyBulkBtn">🚀 اعمال روی همه تنوع‌ها</button>
                </div>
              </div>
            </div>

            <div id="variationsWrap"></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card product-edit-card mb-4">
        <div class="card-header fw-bold">تصاویر محصول</div>
        <div class="card-body">
          <div class="gallery-wrap" id="galleryWrap"></div>
          <input type="file" id="galleryFileInput" accept="image/*" multiple class="d-none">
          <p class="text-muted small mt-2 mb-0">اولین تصویر به‌عنوان تصویر شاخص نمایش داده می‌شود. برای تغییر ترتیب، تصاویر را جابه‌جا کنید.</p>
        </div>
        <div class="video-section">
          <label class="form-label small fw-semibold">ویدیوی محصول</label>
          <div class="input-group">
            <input type="text" id="f_video_url" class="form-control form-control-sm" placeholder="شناسه ویدیو در کتابخانه رسانه" readonly value="<?= (int)$videoAttachmentId ?: '' ?>">
            <input type="file" id="videoFileInput" class="d-none" accept="video/*">
            <button class="btn btn-sm btn-outline-secondary" type="button" id="uploadVideoBtn">انتخاب و آپلود ویدیو</button>
          </div>
          <small class="text-muted d-block mt-2" id="videoUploadMsg"></small>

          <?php if ($videoAttachmentId > 0): ?>
            <div class="mt-2">
              <video controls class="video-preview" src="<?= e($wc->getBaseUrl() . '/wp-json/wp/v2/media/' . $videoAttachmentId) ?>?fields=source_url" preload="metadata"></video>
              <p class="text-muted small mt-1 mb-0">ویدیو در کتابخانه رسانه: شناسه <?= (int)$videoAttachmentId ?></p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card product-edit-card mb-4">
        <div class="card-header fw-bold">دسته‌بندی‌ها</div>
        <div class="card-body category-scroll">
          <?php foreach ($catTree as $cat): ?>
            <div class="form-check" style="margin-right: <?= $cat['_depth'] * 16 ?>px;">
              <input class="form-check-input cat-checkbox" type="checkbox" value="<?= (int)$cat['id'] ?>" id="cat_<?= (int)$cat['id'] ?>"
                <?= in_array((int)$cat['id'], $selectedCategoryIds, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="cat_<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></label>
            </div>
          <?php endforeach; ?>
          <?php if (empty($catTree)): ?>
            <p class="text-muted small mb-0">دسته‌بندی‌ای وجود ندارد. از صفحه «دسته‌بندی‌ها» یکی بسازید.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="save-panel d-grid gap-2">
        <button type="submit" class="btn btn-primary btn-lg" id="saveProductBtn">💾 ذخیره محصول</button>
        <div id="saveResultMsg" class="small"></div>
      </div>
    </div>
  </div>
</form>

<template id="attrRowTemplate">
  <div class="attr-row" data-index="__INDEX__">
    <div class="row g-2 align-items-start">
      <div class="col-md-4">
        <label class="form-label small">ویژگی</label>
        <select class="form-select attr-select">
          <option value="custom">➕ ویژگی سفارشی (جدید)</option>
          <?php foreach ($globalAttributes as $ga): ?>
            <option value="<?= (int)$ga['id'] ?>"><?= e($ga['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="form-control mt-2 attr-custom-name d-none" placeholder="نام ویژگی سفارشی (مثلاً: رنگ)">
      </div>
      <div class="col-md-6">
        <label class="form-label small">مقادیر (با کاما جدا کنید)</label>
        <input type="text" class="form-control attr-values" placeholder="مثلاً: قرمز, آبی, سبز">
      </div>
      <div class="col-md-2 d-flex flex-column justify-content-center h-100 pt-4">
        <div class="form-check">
          <input class="form-check-input attr-used-for-variation" type="checkbox">
          <label class="form-check-label small">استفاده برای تنوع</label>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger mt-2 remove-attr-btn">حذف</button>
      </div>
    </div>
  </div>
</template>

<script>
  window.CSRF_TOKEN = "<?= csrfToken() ?>";
  window.PRODUCT_ID = <?= (int)$productId ?>;
  window.IS_EDIT = <?= $isEdit ? 'true' : 'false' ?>;
  window.PRODUCT_DATA = <?= json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null' ?>;
  window.VARIATIONS_DATA = <?= json_encode($variations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.GLOBAL_ATTRIBUTES = <?= json_encode($globalAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="assets/js/product_edit.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>