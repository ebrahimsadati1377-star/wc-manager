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

    // Get video attachment ID from meta_data
    if (!empty($product['meta_data']) && is_array($product['meta_data'])) {
        foreach ($product['meta_data'] as $meta) {
            if (($meta['key'] ?? '') === '_bajistyle_product_video_id') {
                $videoAttachmentId = (int)($meta['value'] ?? 0);
                break;
            }
            // Fallback to old meta key
            if (($meta['key'] ?? '') === '_product_video_url' && !$videoAttachmentId) {
                // If it's a URL, we can't use it directly, but we keep for display
                // For now, just skip - we use attachment ID
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

// دسته‌بندی‌ها
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

// ویژگی‌های سراسری (global attributes) موجود در ووکامرس
$attrRes = $wc->getAttributes();
$globalAttributes = $attrRes['error'] ? [] : $attrRes['body'];

$selectedCategoryIds = array_map(fn($c) => (int)$c['id'], $product['categories'] ?? []);

$pageTitle = $isEdit ? 'ویرایش محصول' : 'افزودن محصول جدید';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0"><?= $isEdit ? 'ویرایش محصول: ' . e($product['name']) : 'افزودن محصول جدید' ?></h3>
  <a href="products.php" class="btn btn-outline-secondary btn-sm">بازگشت به لیست</a>
</div>

<form id="productForm">
  <input type="hidden" id="product_id" value="<?= (int)$productId ?>">
  <div class="row g-4">
    <div class="col-lg-8">

      <!-- اطلاعات پایه -->
      <div class="card mb-4">
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

      <!-- نوع محصول و قیمت‌گذاری -->
      <div class="card mb-4">
        <div class="card-header fw-bold">نوع محصول و قیمت‌گذاری</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label d-block">نوع محصول</label>
            <div class="btn-group" role="group">
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

      <!-- ویژگی‌ها -->
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="fw-bold">ویژگی‌ها (Attributes)</span>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addAttrBtn">➕ افزودن ویژگی</button>
        </div>
        <div class="card-body">
          <div id="attributesWrap"></div>
          <p class="text-muted small mb-0">برای محصول متغیر، حداقل یک ویژگی را با گزینه «استفاده برای تنوع» فعال کنید.</p>
        </div>
      </div>

      <div class="card mb-4" id="variationsCard" style="display:none;">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="fw-bold">تنوع‌های محصول (Variations)</span>
          <div class="d-flex gap-2">
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
      <!-- تصاویر -->
      <div class="card mb-4">
        <div class="card-header fw-bold">تصاویر محصول</div>
        <div class="card-body">
          <div class="gallery-wrap" id="galleryWrap"></div>
          <input type="file" id="galleryFileInput" accept="image/*" multiple class="d-none">
          <p class="text-muted small mt-2 mb-0">اولین تصویر به‌عنوان تصویر شاخص نمایش داده می‌شود. برای تغییر ترتیب، تصاویر را جابه‌جا کنید.</p>
        </div>
        <div class="mb-3">
  <label class="form-label small">ویدیوی محصول</label>
  <div class="input-group">
    <input type="text" id="f_video_url" class="form-control form-control-sm" placeholder="شناسه ویدیو در کتابخانه رسانه" readonly value="<?= (int)$videoAttachmentId ?: '' ?>">

    <input type="file" id="videoFileInput" class="d-none" accept="video/*">
    <button class="btn btn-sm btn-outline-secondary" type="button" id="uploadVideoBtn">انتخاب و آپلود ویدیو</button>
  </div>
  <small class="text-muted" id="videoUploadMsg"></small>

  <?php if ($videoAttachmentId > 0): ?>
    <div class="mt-2">
      <video controls style="max-width:100%; height:auto; border-radius:8px;" src="<?= e($wc->getBaseUrl() . '/wp-json/wp/v2/media/' . $videoAttachmentId) ?>?fields=source_url" preload="metadata"></video>
      <p class="text-muted small mt-1">ویدیو در کتابخانه رسانه: شناسه <?= (int)$videoAttachmentId ?></p>
    </div>
  <?php endif; ?>
</div>
      </div>

      <!-- دسته‌بندی‌ها -->
      <div class="card mb-4">
        <div class="card-header fw-bold">دسته‌بندی‌ها</div>
        <div class="card-body" style="max-height:300px; overflow-y:auto;">
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

      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary btn-lg" id="saveProductBtn">💾 ذخیره محصول</button>
        <div id="saveResultMsg" class="small"></div>
      </div>
    </div>
  </div>
</form>

<!-- Template های JS (مخفی) -->
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
