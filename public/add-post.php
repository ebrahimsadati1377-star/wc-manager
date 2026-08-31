<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin(); // اطمینان از اینکه کاربر لاگین کرده است

// دریافت لیست دسته‌بندی‌های پست
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
  .ck-editor__editable_inline {
    min-height: 350px; /* ارتفاع استاندارد برای راحتی در نوشتن مقاله */
    font-size: 15px;
    line-height: 1.6;
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="m-0">مدیریت مجله</h3>
  <a href="dashboard.php" class="btn btn-outline-secondary px-3">بازگشت به داشبورد</a>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-primary text-white fw-bold">
    📝 افزودن مقاله جدید به مجله
  </div>
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
          <label class="form-label fw-bold">تصویر شاخص (Featured Image)</label>
          <input type="file" id="featured_image" class="form-control" accept="image/*">
          <small class="text-muted">تصویر شاخص مقاله که در لیست مقالات نمایش داده می‌شود</small>
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
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newCategoryModal">
              ➕ جدید
            </button>
          </div>
          <small class="text-muted">دسته‌بندی مقاله را انتخاب کنید یا دسته جدید بسازید</small>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-bold">وضعیت انتشار</label>
          <select id="post_status" class="form-select">
            <option value="publish">منتشر شده (عمومی)</option>
            <option value="draft">پیش‌نویس</option>
          </select>
        </div>

        <div class="col-md-12 text-end mt-4">
          <button type="button" id="submitPostBtn" class="btn btn-success px-4">
            🚀 انتشار مقاله
          </button>
        </div>

      </div>
    </form>
  </div>
</div>

<!-- Modal برای ساخت دسته‌بندی جدید -->
<div class="modal fade" id="newCategoryModal" tabindex="-1" aria-labelledby="newCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="newCategoryModalLabel">ساخت دسته‌بندی جدید</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="new_category_name" class="form-label">نام دسته‌بندی:</label>
          <input type="text" id="new_category_name" class="form-control" placeholder="مثلاً: مد و پوشاک">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
        <button type="button" id="createCategoryBtn" class="btn btn-primary">ایجاد دسته‌بندی</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<script>
// ۱. پلاگین اختصاصی برای تبدیل عکس‌ها به کدهای متنی (Base64) بدون نیاز به سرور
class Base64UploadAdapter {
  constructor(loader) {
    this.loader = loader;
  }
  upload() {
    return this.loader.file
      .then(file => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve({ default: reader.result }); // تزریق عکس در متن
        reader.onerror = error => reject(error);
      }));
  }
  abort() {} // متد لغو آپلود (خالی می‌گذاریم)
}

// معرفی پلاگین آپلود ما به سیستم CKEditor
function Base64UploadAdapterPlugin(editor) {
  editor.plugins.get('FileRepository').createUploadAdapter = (loader) => {
    return new Base64UploadAdapter(loader);
  };
}

document.addEventListener('DOMContentLoaded', function() {

  let magazineEditor;

  // ۲. راه‌اندازی ویرایشگر به همراه فعال‌سازی دکمه عکس
  ClassicEditor
    .create(document.querySelector('#post_content'), {
      language: 'fa', // راست‌چین و فارسی‌سازی خودکار
      extraPlugins: [ Base64UploadAdapterPlugin ], // 👈 اتصال پلاگین آپلودگر عکس
      toolbar: [
        'heading', '|',
        'bold', 'italic', 'link', 'uploadImage', '|', // 👈 اضافه شدن دکمه 'uploadImage' به نوار ابزار
        'bulletedList', 'numberedList', 'blockQuote', '|',
        'insertTable', 'undo', 'redo'
      ]
    })
    .then(editor => {
      magazineEditor = editor;
    })
    .catch(error => {
      console.error('خطا در بارگذاری ویرایشگر متنی:', error);
    });

  // ساخت دسته‌بندی جدید
  const createCategoryBtn = document.getElementById('createCategoryBtn');
  createCategoryBtn?.addEventListener('click', function() {
    const categoryName = document.getElementById('new_category_name').value.trim();

    if (!categoryName) {
      alert('لطفاً نام دسته‌بندی را وارد کنید.');
      return;
    }

    const originalText = this.innerHTML;
    this.disabled = true;
    this.innerHTML = '⏳ در حال ایجاد...';

    const formData = new FormData();
    formData.append('category_name', categoryName);

    fetch('ajax/create_post_category.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        alert('✅ دسته‌بندی با موفقیت ایجاد شد!');

        // اضافه کردن دسته جدید به dropdown
        const categorySelect = document.getElementById('post_category');
        const newOption = document.createElement('option');
        newOption.value = data.category.id;
        newOption.textContent = data.category.name;
        newOption.selected = true;
        categorySelect.appendChild(newOption);

        // بستن modal و پاک کردن input
        document.getElementById('new_category_name').value = '';
        bootstrap.Modal.getInstance(document.getElementById('newCategoryModal')).hide();
      } else {
        alert('❌ خطا: ' + data.message);
      }
    })
    .catch(err => {
      alert('خطا در ارتباط با سرور: ' + err.message);
    })
    .finally(() => {
      this.disabled = false;
      this.innerHTML = originalText;
    });
  });

  // ۳. کدهای مربوط به ارسال فرم (مشابه قبل)
  const submitBtn = document.getElementById('submitPostBtn');
  
  submitBtn?.addEventListener('click', function() {
    const title = document.getElementById('post_title').value.trim();
    const status = document.getElementById('post_status').value;
    const content = magazineEditor ? magazineEditor.getData().trim() : '';
    const featuredImageInput = document.getElementById('featured_image');
    const categoryId = document.getElementById('post_category').value;

    if (!title || !content) {
      alert('لطفاً عنوان و محتوای مقاله را وارد کنید.');
      return;
    }

    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ در حال ارسال به وردپرس...';

    // ساخت FormData برای ارسال فایل + داده‌های متنی
    const formData = new FormData();
    formData.append('title', title);
    formData.append('content', content);
    formData.append('status', status);

    if (categoryId) {
      formData.append('category_id', categoryId);
    }

    if (featuredImageInput.files.length > 0) {
      formData.append('featured_image', featuredImageInput.files[0]);
    }

    fetch('ajax/add_magazine_post.php', {
      method: 'POST',
      body: formData // توجه: هدر Content-Type را خودکار تنظیم می‌کند
    })
    .then(async response => {
      const text = await response.text(); 
      try {
        return JSON.parse(text); 
      } catch (err) {
        throw new Error("پاسخ سرور JSON نبود! متن خطا:\n\n" + text);
      }
    })
    .then(data => {
      if (data.success) {
        alert('✅ مقاله (به همراه تصویر شاخص و دسته‌بندی) با موفقیت در سایت منتشر شد!');
        document.getElementById('post_title').value = '';
        document.getElementById('featured_image').value = '';
        document.getElementById('post_category').value = '';
        if (magazineEditor) magazineEditor.setData('');
      } else {
        alert('❌ خطا در انتشار مقاله: ' + data.message);
      }
    })
    .catch(err => {
      console.error(err);
      alert(err.message); 
    })
    .finally(() => {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>