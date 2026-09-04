<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$wc = new WooCommerceClient();
$categoriesResponse = $wc->getPostCategories();
$categories = [];
if ($categoriesResponse['status'] === 200 && !empty($categoriesResponse['body'])) {
    $categories = $categoriesResponse['body'];
}

$pageTitle = 'افزودن مقاله جدید';
require __DIR__ . '/partials/header.php';
?>

<style>
  .ck-editor__editable_inline { min-height: 380px; font-size: 15px; line-height: 1.9; }
  .ck.ck-editor__main>.ck-editor__editable { border-radius: 0 0 12px 12px !important; }
  .ck.ck-toolbar { border-radius: 12px 12px 0 0 !important; border-color: #dfe4ea !important; }
  .article-editor-card .card-body { padding: clamp(1rem, 2vw, 1.35rem); }
  .article-publish-bar { display:flex;align-items:center;justify-content:flex-end;gap:.6rem;padding-top:.25rem; }
  @media(max-width:767.98px){.article-publish-bar{display:grid;grid-template-columns:1fr}.article-publish-bar .btn{width:100%}}
</style>

<div class="app-page-head">
  <div class="app-page-head__copy">
    <div class="app-page-head__eyebrow"><i class="fas fa-pen-nib"></i> مجله فروشگاه</div>
    <h1 class="app-page-head__title">افزودن مقاله جدید</h1>
    <p class="app-page-head__subtitle">محتوا، تصویر شاخص، دسته‌بندی و وضعیت انتشار را در یک فرم مدیریت کنید.</p>
  </div>
  <div class="app-page-head__actions"><a href="manage-posts.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right ms-1"></i>بازگشت به مقالات</a></div>
</div>

<div class="card article-editor-card mb-4">
  <div class="card-header fw-bold"><i class="fas fa-file-lines ms-1 text-primary"></i>اطلاعات مقاله</div>
  <div class="card-body">
    <form id="addPostForm">
      <div class="row g-3">
        <div class="col-md-12">
          <label class="form-label fw-bold">عنوان مقاله</label>
          <input type="text" id="post_title" class="form-control" placeholder="مثلاً: راهنمای ست کردن لباس در فصل پاییز" required>
        </div>

        <div class="col-md-12">
          <label class="form-label fw-bold">محتوای مقاله</label>
          <textarea id="post_content" placeholder="متن کامل مقاله خود را اینجا بنویسید..."></textarea>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-bold">تصویر شاخص</label>
          <input type="file" id="featured_image" class="form-control" accept="image/*">
          <div class="form-text">تصویر شاخص در لیست و صفحه مقاله نمایش داده می‌شود.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-bold">دسته‌بندی</label>
          <div class="input-group">
            <select id="post_category" class="form-select">
              <option value="">بدون دسته‌بندی</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newCategoryModal"><i class="fas fa-plus"></i> جدید</button>
          </div>
          <div class="form-text">دسته مناسب را انتخاب کنید یا یک دسته جدید بسازید.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-bold">وضعیت انتشار</label>
          <select id="post_status" class="form-select">
            <option value="publish">منتشر شده (عمومی)</option>
            <option value="draft">پیش‌نویس</option>
          </select>
        </div>

        <div class="col-md-12 article-publish-bar mt-4">
          <a href="manage-posts.php" class="btn btn-outline-secondary">انصراف</a>
          <button type="button" id="submitPostBtn" class="btn btn-primary px-4"><i class="fas fa-paper-plane ms-1"></i>ذخیره مقاله</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="newCategoryModal" tabindex="-1" aria-labelledby="newCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div><h5 class="modal-title" id="newCategoryModalLabel">ساخت دسته‌بندی جدید</h5><div class="text-muted small mt-1">دسته جدید بلافاصله به وردپرس اضافه می‌شود.</div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label for="new_category_name" class="form-label">نام دسته‌بندی</label><input type="text" id="new_category_name" class="form-control" placeholder="مثلاً: مد و پوشاک"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" id="createCategoryBtn" class="btn btn-primary">ایجاد دسته‌بندی</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
<script>
class Base64UploadAdapter {
  constructor(loader) { this.loader = loader; }
  upload() {
    return this.loader.file.then(file => new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.readAsDataURL(file);
      reader.onload = () => resolve({ default: reader.result });
      reader.onerror = error => reject(error);
    }));
  }
  abort() {}
}

function Base64UploadAdapterPlugin(editor) {
  editor.plugins.get('FileRepository').createUploadAdapter = (loader) => new Base64UploadAdapter(loader);
}

document.addEventListener('DOMContentLoaded', function() {
  let magazineEditor;
  ClassicEditor
    .create(document.querySelector('#post_content'), {
      language: 'fa',
      extraPlugins: [ Base64UploadAdapterPlugin ],
      toolbar: ['heading', '|', 'bold', 'italic', 'link', 'uploadImage', '|', 'bulletedList', 'numberedList', 'blockQuote', '|', 'insertTable', 'undo', 'redo']
    })
    .then(editor => { magazineEditor = editor; })
    .catch(error => { console.error('خطا در بارگذاری ویرایشگر متنی:', error); });

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

  const submitBtn = document.getElementById('submitPostBtn');
  submitBtn?.addEventListener('click', function() {
    const title = document.getElementById('post_title').value.trim();
    const status = document.getElementById('post_status').value;
    const content = magazineEditor ? magazineEditor.getData().trim() : '';
    const featuredImageInput = document.getElementById('featured_image');
    const categoryId = document.getElementById('post_category').value;
    if (!title || !content) { alert('لطفاً عنوان و محتوای مقاله را وارد کنید.'); return; }

    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm ms-1"></span>در حال ارسال به وردپرس';
    const formData = new FormData();
    formData.append('title', title);
    formData.append('content', content);
    formData.append('status', status);
    if (categoryId) formData.append('category_id', categoryId);
    if (featuredImageInput.files.length > 0) formData.append('featured_image', featuredImageInput.files[0]);

    fetch('ajax/add_magazine_post.php', { method: 'POST', body: formData })
      .then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); } catch (err) { throw new Error('پاسخ سرور معتبر نبود: ' + text); }
      })
      .then(data => {
        if (data.success) {
          alert('مقاله با موفقیت ذخیره شد.');
          window.location.href = 'manage-posts.php';
        } else { alert('خطا در انتشار مقاله: ' + data.message); }
      })
      .catch(err => { console.error(err); alert(err.message); })
      .finally(() => { submitBtn.disabled = false; submitBtn.innerHTML = originalText; });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
