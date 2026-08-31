<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$client = new WooCommerceClient();
$pageTitle = 'مدیریت مقالات مجله';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$allowedStatuses = ['publish', 'draft', 'pending', 'private'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$params = [
    'per_page' => $perPage,
    'page' => $page,
    'orderby' => 'date',
    'order' => 'desc',
    // WordPress REST defaults to publish when status is omitted.
    // Explicitly request every status that WC Manager supports so
    // "همه وضعیت‌ها" really includes drafts, pending and private posts.
    'status' => $statusFilter !== '' ? $statusFilter : $allowedStatuses,
];
if ($search !== '') {
    $params['search'] = $search;
}

$posts = [];
$totalPosts = 0;
$totalPages = 1;
$error = null;

$response = $client->get('wp-json/wp/v2/posts', $params);
if (!empty($response['error'])) {
    $error = $response['error'];
} else {
    $posts = is_array($response['body'] ?? null) ? $response['body'] : [];
    $totalPosts = (int)($response['headers']['total'] ?? count($posts));
    $totalPages = max(1, (int)($response['headers']['total_pages'] ?? 1));
}

function magazineListUrl(int $targetPage, string $search, string $status): string
{
    $query = ['page' => max(1, $targetPage)];
    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($status !== '') {
        $query['status'] = $status;
    }
    return 'manage-posts.php?' . http_build_query($query);
}

require __DIR__ . '/partials/header.php';
?>

<style>
.search-filter-bar {
    background: #f8f9fa;
    padding: 1.25rem;
    border-radius: .75rem;
    margin-bottom: 1.5rem;
}
.status-badge {
    display: inline-block;
    padding: .35rem .75rem;
    border-radius: 999px;
    font-size: .85rem;
    font-weight: 500;
    white-space: nowrap;
}
.status-publish { background: #d1e7dd; color: #0f5132; }
.status-draft { background: #fff3cd; color: #664d03; }
.status-pending { background: #cff4fc; color: #055160; }
.status-private { background: #f8d7da; color: #842029; }
.post-title { font-weight: 600; color: #212529; text-decoration: none; }
.post-title:hover { text-decoration: underline; }
.post-meta { font-size: .82rem; color: #6c757d; }
.action-buttons { display: flex; justify-content: center; gap: .35rem; flex-wrap: wrap; }
.bulk-actions-bar {
    background: #fff;
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: .5rem;
    margin-bottom: 1rem;
    display: none;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}
.bulk-actions-bar.active { display: flex; }
.pagination-info { color: #6c757d; font-size: .9rem; }
.loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .45);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.loading-overlay.active { display: flex; }
.spinner-border-lg { width: 3rem; height: 3rem; }
.empty-state { text-align: center; padding: 3rem 1rem; color: #6c757d; }
.empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: .3; }

@media (max-width: 768px) {
    .magazine-heading { align-items: stretch !important; flex-direction: column; gap: .75rem; }
    .magazine-heading .btn { width: 100%; }
    .search-filter-bar { padding: 1rem; }
    .table-responsive { font-size: .85rem; }
    .action-buttons { min-width: 205px; }
    .action-buttons .btn { flex: 1 1 auto; }
    .post-meta { font-size: .75rem; }
    .status-badge { font-size: .75rem; padding: .25rem .5rem; }
    .bulk-actions-bar > * { flex: 1 1 100%; }
    .bulk-actions-bar .form-check { display: flex; align-items: center; gap: .5rem; }
    .pagination { flex-wrap: wrap; }
}
</style>

<div class="mt-2">
    <div class="magazine-heading d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">مدیریت مقالات مجله</h3>
        <a href="add-post.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> افزودن مقاله جدید
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>خطا!</strong> <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
        </div>
    <?php endif; ?>

    <div class="search-filter-bar">
        <form method="get" action="manage-posts.php" class="row g-3 align-items-end">
            <div class="col-12 col-lg-5">
                <label for="magazineSearch" class="form-label">جستجو</label>
                <div class="input-group">
                    <input id="magazineSearch" type="search" name="search" class="form-control" placeholder="جستجو در عنوان یا محتوا..." value="<?= e($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit" aria-label="جستجو">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label for="magazineStatus" class="form-label">وضعیت</label>
                <select id="magazineStatus" name="status" class="form-select">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="publish" <?= $statusFilter === 'publish' ? 'selected' : '' ?>>منتشر شده</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="private" <?= $statusFilter === 'private' ? 'selected' : '' ?>>خصوصی</option>
                </select>
            </div>
            <div class="col-12 col-sm-3 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
            </div>
            <div class="col-12 col-sm-3 col-lg-2 d-grid">
                <a href="manage-posts.php" class="btn btn-outline-secondary">پاک کردن</a>
            </div>
        </form>
    </div>

    <div class="bulk-actions-bar" id="bulkActionsBar">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="selectAll">
            <label class="form-check-label" for="selectAll">انتخاب همه این صفحه</label>
        </div>
        <select id="bulkAction" class="form-select" style="max-width: 220px;">
            <option value="">عملیات گروهی</option>
            <option value="delete">حذف</option>
            <option value="publish">انتشار</option>
            <option value="draft">تبدیل به پیش‌نویس</option>
        </select>
        <button type="button" onclick="applyBulkAction()" class="btn btn-primary">اعمال</button>
        <span class="text-muted" id="selectedCount">0 مورد انتخاب شده</span>
    </div>

    <?php if (empty($posts)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h4>مقاله‌ای یافت نشد</h4>
            <p>هیچ مقاله‌ای با فیلترهای انتخابی وجود ندارد.</p>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a href="manage-posts.php" class="btn btn-outline-primary">نمایش همه مقالات</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="pagination-info">
                نمایش <?= (($page - 1) * $perPage) + 1 ?> تا <?= min($page * $perPage, $totalPosts) ?> از <?= $totalPosts ?> مقاله
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 44px;"><input class="form-check-input" type="checkbox" id="selectAllTable" aria-label="انتخاب همه مقالات این صفحه"></th>
                        <th>عنوان</th>
                        <th style="width: 120px;">وضعیت</th>
                        <th style="width: 150px;">تاریخ</th>
                        <th style="width: 210px;" class="text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $post):
                    $postId = (int)($post['id'] ?? 0);
                    $postStatus = (string)($post['status'] ?? '');
                    $postDateRaw = (string)($post['date'] ?? '');
                    $postDate = $postDateRaw !== '' ? date('Y/m/d H:i', strtotime($postDateRaw)) : '-';
                    $statusClass = in_array($postStatus, $allowedStatuses, true) ? 'status-' . $postStatus : '';
                    $statusLabel = [
                        'publish' => 'منتشر شده',
                        'draft' => 'پیش‌نویس',
                        'pending' => 'در انتظار',
                        'private' => 'خصوصی',
                    ][$postStatus] ?? $postStatus;
                    $postTitle = (string)($post['title']['rendered'] ?? '(بدون عنوان)');
                    $postLink = (string)($post['link'] ?? '#');
                ?>
                    <tr data-post-id="<?= $postId ?>">
                        <td><input class="form-check-input post-checkbox" type="checkbox" value="<?= $postId ?>" aria-label="انتخاب مقاله <?= e($postTitle) ?>"></td>
                        <td>
                            <a href="edit-post.php?id=<?= $postId ?>" class="post-title"><?= e($postTitle) ?></a>
                            <div class="post-meta">شناسه: #<?= $postId ?></div>
                        </td>
                        <td><span class="status-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                        <td><small class="text-muted"><?= e($postDate) ?></small></td>
                        <td>
                            <div class="action-buttons">
                                <a href="edit-post.php?id=<?= $postId ?>" class="btn btn-sm btn-outline-primary" title="ویرایش"><i class="fas fa-edit"></i><span class="ms-1">ویرایش</span></a>
                                <a href="<?= e($postLink) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info" title="مشاهده"><i class="fas fa-eye"></i><span class="ms-1">مشاهده</span></a>
                                <button type="button" onclick="deletePost(<?= $postId ?>)" class="btn btn-sm btn-outline-danger" title="حذف"><i class="fas fa-trash"></i><span class="ms-1">حذف</span></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="صفحه‌بندی مقالات">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(magazineListUrl(max(1, $page - 1), $search, $statusFilter)) ?>">قبلی</a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(magazineListUrl($i, $search, $statusFilter)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(magazineListUrl(min($totalPages, $page + 1), $search, $statusFilter)) ?>">بعدی</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="spinner-border text-light spinner-border-lg" role="status"><span class="visually-hidden">در حال پردازش...</span></div>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedPosts = new Set();

function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}
function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('active');
}
function updateBulkActionsBar() {
    const bar = document.getElementById('bulkActionsBar');
    const count = document.getElementById('selectedCount');
    const topSelectAll = document.getElementById('selectAll');
    const tableSelectAll = document.getElementById('selectAllTable');
    const allCheckboxes = Array.from(document.querySelectorAll('.post-checkbox'));
    const allSelected = allCheckboxes.length > 0 && allCheckboxes.every(cb => cb.checked);

    bar.classList.toggle('active', selectedPosts.size > 0);
    count.textContent = selectedPosts.size + ' مورد انتخاب شده';
    if (topSelectAll) topSelectAll.checked = allSelected;
    if (tableSelectAll) tableSelectAll.checked = allSelected;
}
function toggleAll(checked) {
    document.querySelectorAll('.post-checkbox').forEach(cb => {
        cb.checked = checked;
        const id = Number(cb.value);
        if (checked) selectedPosts.add(id); else selectedPosts.delete(id);
    });
    updateBulkActionsBar();
}

document.getElementById('selectAll')?.addEventListener('change', function () { toggleAll(this.checked); });
document.getElementById('selectAllTable')?.addEventListener('change', function () { toggleAll(this.checked); });
document.querySelectorAll('.post-checkbox').forEach(cb => {
    cb.addEventListener('change', function () {
        const id = Number(this.value);
        if (this.checked) selectedPosts.add(id); else selectedPosts.delete(id);
        updateBulkActionsBar();
    });
});

async function requestJson(url, options) {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ success: false, message: 'پاسخ نامعتبر از سرور دریافت شد.' }));
    if (!response.ok && !data.message) data.message = 'خطای HTTP ' + response.status;
    return data;
}

async function deletePost(id) {
    if (!confirm('آیا از حذف این مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.')) return;
    showLoading();
    try {
        const data = await requestJson('ajax/delete_magazine_post.php?id=' + encodeURIComponent(id), {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': csrfToken }
        });
        if (!data.success) {
            alert('خطا در حذف مقاله: ' + (data.message || 'خطای نامشخص'));
            return;
        }
        location.reload();
    } catch (error) {
        alert('خطا در ارتباط با سرور: ' + error.message);
    } finally {
        hideLoading();
    }
}

async function applyBulkAction() {
    const action = document.getElementById('bulkAction').value;
    if (!action) {
        alert('لطفاً یک عملیات انتخاب کنید.');
        return;
    }
    if (selectedPosts.size === 0) {
        alert('لطفاً حداقل یک مقاله انتخاب کنید.');
        return;
    }
    if (action === 'delete' && !confirm(`آیا از حذف ${selectedPosts.size} مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.`)) return;

    showLoading();
    try {
        const data = await requestJson('ajax/bulk_action_posts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ action, post_ids: Array.from(selectedPosts) })
        });
        if (!data.success) {
            alert('خطا در انجام عملیات: ' + (data.message || 'خطای نامشخص'));
            return;
        }

        let message = `عملیات انجام شد.\nموفق: ${data.successful || 0}\nناموفق: ${data.failed || 0}`;
        if (Array.isArray(data.errors) && data.errors.length) {
            message += '\n\n' + data.errors.slice(0, 5).join('\n');
        }
        alert(message);
        location.reload();
    } catch (error) {
        alert('خطا در ارتباط با سرور: ' + error.message);
    } finally {
        hideLoading();
    }
}

document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') {
        event.preventDefault();
        document.getElementById('magazineSearch')?.focus();
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>