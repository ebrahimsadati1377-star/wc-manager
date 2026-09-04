<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$postId = (int)($_GET['id'] ?? 0);
$wc = new WooCommerceClient();
$response = $wc->getPost($postId);
if ($postId <= 0 || $response['status'] !== 200) {
    setFlash('danger', 'مقاله موردنظر پیدا نشد یا دریافت اطلاعات آن ناموفق بود.');
    redirect('manage-posts.php');
}

$post = $response['body'];
$featuredMediaId = (int)($post['featured_media'] ?? 0);
$featuredImageUrl = '';
if ($featuredMediaId > 0) {
    $mediaResponse = $wc->get('wp-json/wp/v2/media/' . $featuredMediaId);
    if ($mediaResponse['status'] === 200) {
        $featuredImageUrl = (string)($mediaResponse['body']['source_url'] ?? '');
    }
}

$categoriesResponse = $wc->getPostCategories();
$categories = [];
if ($categoriesResponse['status'] === 200 && !empty($categoriesResponse['body'])) {
    $categories = $categoriesResponse['body'];
}
$postCategories = $post['categories'] ?? [];

$pageTitle = 'ویرایش مقاله';
require __DIR__ . '/partials/header.php';
?>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
<style>
  .ck-editor__editable_inline{min-height:380px;font-size:15px;line-height:1.9}
  .ck.ck-editor__main>.ck-editor__editable{border-radius:0 0 12px 12px!important}
  .ck.ck-toolbar{border-radius:12px 12px 0 0!important;border-color:#dfe4ea!important}
  .current-featured-image{max-width:220px;width:100%;height:auto;border:1px solid #e1e6ec;border-radius:14px;padding:5px;background:#fff}
  .article-savebar{display:flex;align-items:center;gap:.6rem;justify-content:flex-end}
  @media(max-width:767.98px){.article-savebar{display:grid;grid-template-columns:1fr}.article-savebar .btn{width:100%}}
</style>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-pen-to-square"></i> مجله فروشگاه</div>
    <h1 class="app-page-head__title">ویرایش مقاله</h1>
    <p class="app-page-head__subtitle"><?= htmlspecialchars($post['title']['rendered']) ?></p>
  </div>
  <div class="app-page-head__actions"><a href="manage-posts.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right ms-1"></i>بازگشت به مقالات</a></div>
</div>

<div class="card mb-4">
  <div class="card-header fw-bold"><i class="fas fa-file-lines ms-1 text-primary"></i>اطلاعات مقاله</div>
  <div class="card-body">
    <form id="editPostForm">
      <input type="hidden" id="post_id" value="<?= (int)$post['id'] ?>">
      <div class="mb-3"><label class="form-label fw-bold">عنوان مقاله</label><input type="text" id="post_title" class="form-control" value="<?= htmlspecialchars($post['title']['rendered']) ?>"></div>
      <div class="mb-3"><label class="form-label fw-bold">محتوای مقاله</label><textarea id="post_content"><?= $post['content']['rendered'] ?></textarea></div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-bold">تصویر شاخص</label>
          <?php if ($featuredImageUrl): ?><div class="mb-2"><img src="<?= htmlspecialchars($featuredImageUrl) ?>" alt="تصویر شاخص فعلی" class="current-featured-image"><div class="form-text mt-1">تصویر فعلی</div></div><?php endif; ?>
          <input type="file" id="featured_image" class="form-control" accept="image/*"><div class="form-text">اگر تصویر جدید انتخاب نکنید، تصویر فعلی حفظ می‌شود.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-bold">دسته‌بندی</label>
          <div class="input-group">
            <select id="post_category" class="form-select">
              <option value="">بدون دسته‌بندی</option>
              <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $postCategories) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newCategoryModal"><i class="fas fa-plus"></i> جدید</button>
          </div>
          <div class="form-text">دسته مناسب را انتخاب کنید یا دسته جدید بسازید.</div>
        </div>
        <div class="col-md-6"><label class="form-label fw-bold">وضعیت انتشار</label><select id="post_status" class="form-select"><option value="publish" <?= $post['status'] == 'publish' ? 'selected' : '' ?>>منتشر شده</option><option value="draft" <?= $post['status'] == 'draft' ? 'selected' : '' ?>>پیش‌نویس</option></select></div>
      </div>
      <div class="article-savebar mt-4"><a href="manage-posts.php" class="btn btn-outline-secondary">انصراف</a><button type="button" id="updateBtn" class="btn btn-primary"><i class="fas fa-check ms-1"></i>ذخیره تغییرات</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="newCategoryModal" tabindex="-1" aria-labelledby="newCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title" id="newCategoryModalLabel">ساخت دسته‌بندی جدید</h5><div class="text-muted small mt-1">دسته جدید بلافاصله به وردپرس اضافه می‌شود.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button></div>
    <div class="modal-body"><div class="mb-3"><label for="new_category_name" class="form-label">نام دسته‌بندی</label><input type="text" id="new_category_name" class="form-control" placeholder="مثلاً: مد و پوشاک"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" id="createCategoryBtn" class="btn btn-primary">ایجاد دسته‌بندی</button></div>
  </div></div>
</div>

<script>
let editor;
ClassicEditor.create(document.querySelector('#post_content'), { language: 'fa' }).then(newEditor => { editor = newEditor; }).catch(error => console.error(error));

const createCategoryBtn = document.getElementById('createCategoryBtn');
createCategoryBtn?.addEventListener('click', function() {
  const categoryName = document.getElementById('new_category_name').value.trim();
  if (!categoryName) { alert('لطفاً نام دسته‌بندی را وارد کنید.'); return; }
  const originalText = this.innerHTML;
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm ms-1"></span>در حال ایجاد';
  const formData = new FormData();
  formData.append('category_name', categoryName);
  fetch('ajax/create_post_category.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const categorySelect = document.getElementById('post_category');
        const newOption = document.createElement('option');
        newOption.value = data.category.id;
        newOption.textContent = data.category.name;
        newOption.selected = true;
        categorySelect.appendChild(newOption);
        document.getElementById('new_category_name').value = '';
        bootstrap.Modal.getInstance(document.getElementById('newCategoryModal')).hide();
      } else { alert('خطا: ' + data.message); }
    })
    .catch(err => alert('خطا در ارتباط با سرور: ' + err.message))
    .finally(() => { this.disabled = false; this.innerHTML = originalText; });
});

document.getElementById('updateBtn').addEventListener('click', function() {
  const title = document.getElementById('post_title').value.trim();
  const content = editor ? editor.getData().trim() : '';
  if (!title || !content) { alert('عنوان و محتوای مقاله نمی‌تواند خالی باشد.'); return; }

  const formData = new FormData();
  formData.append('id', document.getElementById('post_id').value);
  formData.append('title', title);
  formData.append('content', content);
  formData.append('status', document.getElementById('post_status').value);
  const categoryId = document.getElementById('post_category').value;
  if (categoryId) formData.append('category_id', categoryId);
  const featuredImageInput = document.getElementById('featured_image');
  if (featuredImageInput.files.length > 0) formData.append('featured_image', featuredImageInput.files[0]);

  const originalText = this.innerHTML;
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm ms-1"></span>در حال ذخیره';
  fetch('ajax/update_magazine_post.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
      if(data.success) { alert('تغییرات با موفقیت ذخیره شد.'); window.location.href = 'manage-posts.php'; }
      else { alert('خطا: ' + data.message); }
    })
    .catch(err => alert('خطا در ارتباط با سرور: ' + err.message))
    .finally(() => { this.disabled = false; this.innerHTML = originalText; });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
