<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$postId = $_GET['id'] ?? 0;
$wc = new WooCommerceClient();
$response = $wc->getPost($postId);

// بررسی اینکه آیا مقاله پیدا شده است
if ($response['status'] !== 200) {
    die("مقاله مورد نظر یافت نشد یا مشکلی در دریافت اطلاعات وجود دارد.");
}

$post = $response['body'];
$featuredMediaId = $post['featured_media'] ?? 0;
$featuredImageUrl = '';

// دریافت URL تصویر شاخص اگر وجود دارد
if ($featuredMediaId > 0) {
    $mediaResponse = $wc->get('wp-json/wp/v2/media/' . $featuredMediaId);
    if ($mediaResponse['status'] === 200) {
        $featuredImageUrl = $mediaResponse['body']['source_url'] ?? '';
    }
}

// دریافت لیست دسته‌بندی‌ها
$categoriesResponse = $wc->getPostCategories();
$categories = [];
if ($categoriesResponse['status'] === 200 && !empty($categoriesResponse['body'])) {
    $categories = $categoriesResponse['body'];
}

// دسته‌بندی‌های انتخاب‌شده برای این پست
$postCategories = $post['categories'] ?? [];

$pageTitle = 'ویرایش مقاله';
require __DIR__ . '/partials/header.php';
?>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<div class="card shadow-sm mb-4">
    <h3 class="card-header bg-primary text-white fw-bold">ویرایش مقاله: <?= htmlspecialchars($post['title']['rendered']) ?></h3>
    <div class="card-body">
    <form id="editPostForm">
        <input type="hidden" id="post_id" value="<?= $post['id'] ?>">

        <div class="mb-3">
            <label>عنوان:</label>
            <input type="text" id="post_title" class="form-control" value="<?= htmlspecialchars($post['title']['rendered']) ?>">
        </div>

        <div class="mb-3">
            <label>محتوا:</label>
            <textarea id="post_content"><?= $post['content']['rendered'] ?></textarea>
        </div>

        <div class="mb-3">
            <label>تصویر شاخص (Featured Image):</label>
            <?php if ($featuredImageUrl): ?>
                <div class="mb-2">
                    <img src="<?= htmlspecialchars($featuredImageUrl) ?>" alt="Featured Image" style="max-width: 200px; height: auto; border: 1px solid #ddd; padding: 5px;">
                    <p class="text-muted small mt-1">تصویر فعلی</p>
                </div>
            <?php endif; ?>
            <input type="file" id="featured_image" class="form-control" accept="image/*">
            <small class="text-muted">برای تغییر تصویر، فایل جدید انتخاب کنید. اگر نمی‌خواهید تصویر را تغییر دهید، خالی بگذارید.</small>
        </div>

        <div class="mb-3">
            <label>دسته‌بندی:</label>
            <div class="input-group">
                <select id="post_category" class="form-select">
                    <option value="">بدون دسته‌بندی</option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $postCategories) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                      </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newCategoryModal">
                  ➕ جدید
                </button>
            </div>
            <small class="text-muted">دسته‌بندی مقاله را انتخاب کنید یا دسته جدید بسازید</small>
        </div>

        <div class="mb-3">
            <label>وضعیت:</label>
            <select id="post_status" class="form-select">
                <option value="publish" <?= $post['status'] == 'publish' ? 'selected' : '' ?>>منتشر شده</option>
                <option value="draft" <?= $post['status'] == 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
            </select>
        </div>

        <button type="button" id="updateBtn" class="btn btn-primary">ذخیره تغییرات</button>
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

<script>
let editor;
ClassicEditor.create(document.querySelector('#post_content'), { language: 'fa' })
    .then(newEditor => { editor = newEditor; })
    .catch(error => console.error(error));

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

document.getElementById('updateBtn').addEventListener('click', function() {
    const formData = new FormData();
    formData.append('id', document.getElementById('post_id').value);
    formData.append('title', document.getElementById('post_title').value);
    formData.append('content', editor.getData());
    formData.append('status', document.getElementById('post_status').value);

    const categoryId = document.getElementById('post_category').value;
    if (categoryId) {
        formData.append('category_id', categoryId);
    }

    const featuredImageInput = document.getElementById('featured_image');
    if (featuredImageInput.files.length > 0) {
        formData.append('featured_image', featuredImageInput.files[0]);
    }

    const originalText = this.innerHTML;
    this.disabled = true;
    this.innerHTML = '⏳ در حال ذخیره...';

    fetch('ajax/update_magazine_post.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            alert('✅ با موفقیت ویرایش شد!');
            location.reload();
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
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>