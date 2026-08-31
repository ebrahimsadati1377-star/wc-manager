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

// مرتب‌سازی درختی (parent -> children)
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

$pageTitle = 'دسته‌بندی‌ها';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">مدیریت دسته‌بندی‌ها</h3>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openCatModal()">➕ دسته‌بندی جدید</button>
</div>

<?php if ($loadError): ?>
  <div class="alert alert-danger"><?= e($loadError) ?></div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>تصویر</th>
          <th>نام</th>
          <th>نامک (slug)</th>
          <th>تعداد محصولات</th>
          <th class="text-end">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tree as $cat): ?>
        <tr>
          <td>
            <?php if (!empty($cat['image']['src'])): ?>
              <img src="<?= e($cat['image']['src']) ?>" class="thumb-sm">
            <?php else: ?>
              <div class="thumb-sm bg-light d-flex align-items-center justify-content-center text-muted">—</div>
            <?php endif; ?>
          </td>
          <td><?= str_repeat('— ', $cat['_depth']) . e($cat['name']) ?></td>
          <td class="text-muted small" dir="ltr"><?= e($cat['slug']) ?></td>
          <td><?= e($cat['count']) ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" onclick='openCatModal(<?= json_encode($cat, JSON_UNESCAPED_UNICODE) ?>)'>ویرایش</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteCat(<?= (int)$cat['id'] ?>, '<?= e(addslashes($cat['name'])) ?>')">حذف</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tree)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">هنوز دسته‌بندی‌ای ثبت نشده است.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modal افزودن/ویرایش دسته -->
<div class="modal fade" id="catModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="catForm">
        <div class="modal-header">
          <h5 class="modal-title" id="catModalTitle">دسته‌بندی جدید</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
            <textarea class="form-control" name="description" id="cat_description" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">تصویر دسته‌بندی</label>
            <input type="file" class="form-control" id="cat_image_file" accept="image/*">
            <input type="hidden" name="image_url" id="cat_image_url">
            <div class="mt-2"><img id="cat_image_preview" class="thumb-md d-none"></div>
          </div>
          <div id="catFormError" class="text-danger small"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
          <button type="submit" class="btn btn-primary" id="catSubmitBtn">ذخیره</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/js/categories.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
