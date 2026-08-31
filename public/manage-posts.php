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

function magazinePostView(array $post, array $allowedStatuses): array
{
    $id = (int)($post['id'] ?? 0);
    $status = (string)($post['status'] ?? '');
    $dateRaw = (string)($post['date'] ?? '');
    $date = $dateRaw !== '' ? date('Y/m/d H:i', strtotime($dateRaw)) : '-';
    $statusClass = in_array($status, $allowedStatuses, true) ? 'status-' . $status : '';
    $statusLabel = [
        'publish' => 'منتشر شده',
        'draft' => 'پیش‌نویس',
        'pending' => 'در انتظار',
        'private' => 'خصوصی',
    ][$status] ?? $status;

    return [
        'id' => $id,
        'status' => $status,
        'status_class' => $statusClass,
        'status_label' => $statusLabel,
        'date' => $date,
        'title' => (string)($post['title']['rendered'] ?? '(بدون عنوان)'),
        'link' => (string)($post['link'] ?? '#'),
    ];
}

require __DIR__ . '/partials/header.php';
?>

<style>
.search-filter-bar {
    background: #f8f9fa;
    padding: 1.25rem;
    border-radius: .85rem;
    margin-bottom: 1.25rem;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: .35rem .75rem;
    border-radius: 999px;
    font-size: .82rem;
    font-weight: 600;
    white-space: nowrap;
}
.status-publish { background: #d1e7dd; color: #0f5132; }
.status-draft { background: #fff3cd; color: #664d03; }
.status-pending { background: #cff4fc; color: #055160; }
.status-private { background: #f8d7da; color: #842029; }
.post-title { font-weight: 650; color: #212529; text-decoration: none; }
.post-title:hover { text-decoration: underline; }
.post-meta { font-size: .82rem; color: #6c757d; }
.action-buttons { display: flex; justify-content: center; gap: .35rem; flex-wrap: wrap; }
.bulk-actions-bar {
    background: #fff;
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: .65rem;
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
.mobile-post-list { display: none; }
.mobile-post-card {
    border: 1px solid #e9ecef;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 2px 10px rgba(33, 37, 41, .045);
    overflow: hidden;
}
.mobile-post-card + .mobile-post-card { margin-top: .85rem; }
.mobile-post-card__body { padding: 1rem; }
.mobile-post-card__top {
    display: flex;
    gap: .75rem;
    align-items: flex-start;
}
.mobile-post-card__check { flex: 0 0 auto; padding-top: .15rem; }
.mobile-post-card__main { min-width: 0; flex: 1 1 auto; }
.mobile-post-card__title {
    display: block;
    font-weight: 700;
    color: #212529;
    text-decoration: none;
    line-height: 1.65;
    overflow-wrap: anywhere;
}
.mobile-post-card__meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .45rem .65rem;
    margin-top: .7rem;
    color: #6c757d;
    font-size: .78rem;
}
.mobile-post-card__actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .5rem;
    padding: .75rem 1rem 1rem;
    border-top: 1px solid #f1f3f5;
}
.mobile-post-card__actions .btn {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding-inline: .4rem;
}

@media (max-width: 767.98px) {
    body { overflow-x: hidden; }
    .container-fluid.py-4 { padding-top: 1rem !important; padding-inline: .75rem !important; }
    .magazine-heading { align-items: stretch !important; flex-direction: column; gap: .75rem; margin-bottom: 1rem !important; }
    .magazine-heading h3 { font-size: 1.2rem; line-height: 1.5; }
    .magazine-heading .btn { width: 100%; min-height: 46px; display: flex; align-items: center; justify-content: center; gap: .45rem; }
    .search-filter-bar { padding: .9rem; border-radius: .75rem; margin-bottom: 1rem; }
    .search-filter-bar .form-label { font-size: .82rem; margin-bottom: .35rem; }
    .search-filter-bar .form-control,
    .search-filter-bar .form-select,
    .search-filter-bar .btn { min-height: 46px; }
    .desktop-post-table { display: none !important; }
    .mobile-post-list { display: block; }
    .pagination-info { font-size: .8rem; }
    .status-badge { font-size: .74rem; padding: .28rem .55rem; }
    .bulk-actions-bar.active {
        position: sticky;
        bottom: .65rem;
        z-index: 1030;
        display: grid;
        grid-template-columns: 1fr auto;
        box-shadow: 0 8px 28px rgba(0, 0, 0, .14);
        border-radius: .9rem;
        padding: .8rem;
    }
    .bulk-actions-bar .form-check { grid-column: 1 / -1; display: flex; align-items: center; gap: .5rem; }
    .bulk-actions-bar .form-select { max-width: none !important; min-height: 44px; }
    .bulk-actions-bar .btn { min-height: 44px; min-width: 78px; }
    .bulk-actions-bar #selectedCount { grid-column: 1 / -1; font-size: .78rem; }
    .pagination { flex-wrap: wrap; gap: .25rem; margin-bottom: 0; }
    .pagination .page-link { min-width: 42px; text-align: center; border-radius: .5rem !important; }
}

@media (max-width: 420px) {
    .mobile-post-card__actions { grid-template-columns: 1fr 1fr; }
    .mobile-post-card__actions .btn-danger-mobile { grid-column: 1 / -1; }
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
                    <button class="btn btn-outline-secondary" type="submit" aria-label="جستجو"><i class="fas fa-search"></i></button>
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
            <div class="col-12 col-sm-3 col-lg-2 d-grid"><button type="submit" class="btn btn-primary">اعمال فیلتر</button></div>
            <div class="col-12 col-sm-3 col-lg-2 d-grid"><a href="manage-posts.php" class="btn btn-outline-secondary">پاک کردن</a></div>
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
            <div class="pagination-info">نمایش <?= (($page - 1) * $perPage) + 1 ?> تا <?= min($page * $perPage, $totalPosts) ?> از <?= $totalPosts ?> مقاله</div>
        </div>

        <div class="desktop-post-table table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px"><input class="form-check-input" type="checkbox" id="selectAllTable" aria-label="انتخاب همه مقالات این صفحه"></th>
                        <th>عنوان</th>
                        <th style="width:120px">وضعیت</th>
                        <th style="width:150px">تاریخ</th>
                        <th style="width:210px" class="text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $post): $view = magazinePostView($post, $allowedStatuses); ?>
                    <tr data-post-id="<?= $view['id'] ?>">
                        <td><input class="form-check-input post-checkbox" data-post-id="<?= $view['id'] ?>" type="checkbox" value="<?= $view['id'] ?>" aria-label="انتخاب مقاله <?= e($view['title']) ?>"></td>
                        <td><a href="edit-post.php?id=<?= $view['id'] ?>" class="post-title"><?= e($view['title']) ?></a><div class="post-meta">شناسه: #<?= $view['id'] ?></div></td>
                        <td><span class="status-badge <?= e($view['status_class']) ?>"><?= e($view['status_label']) ?></span></td>
                        <td><small class="text-muted"><?= e($view['date']) ?></small></td>
                        <td><div class="action-buttons">
                            <a href="edit-post.php?id=<?= $view['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i><span class="ms-1">ویرایش</span></a>
                            <a href="<?= e($view['link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i><span class="ms-1">مشاهده</span></a>
                            <button type="button" onclick="deletePost(<?= $view['id'] ?>)" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i><span class="ms-1">حذف</span></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-post-list">
            <?php foreach ($posts as $post): $view = magazinePostView($post, $allowedStatuses); ?>
                <article class="mobile-post-card" data-post-id="<?= $view['id'] ?>">
                    <div class="mobile-post-card__body">
                        <div class="mobile-post-card__top">
                            <div class="mobile-post-card__check"><input class="form-check-input post-checkbox" data-post-id="<?= $view['id'] ?>" type="checkbox" value="<?= $view['id'] ?>" aria-label="انتخاب مقاله <?= e($view['title']) ?>"></div>
                            <div class="mobile-post-card__main">
                                <a href="edit-post.php?id=<?= $view['id'] ?>" class="mobile-post-card__title"><?= e($view['title']) ?></a>
                                <div class="mobile-post-card__meta">
                                    <span class="status-badge <?= e($view['status_class']) ?>"><?= e($view['status_label']) ?></span>
                                    <span><i class="fa-regular fa-calendar ms-1"></i><?= e($view['date']) ?></span>
                                    <span>#<?= $view['id'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mobile-post-card__actions">
                        <a href="edit-post.php?id=<?= $view['id'] ?>" class="btn btn-outline-primary"><i class="fas fa-edit"></i><span>ویرایش</span></a>
                        <a href="<?= e($view['link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-info"><i class="fas fa-eye"></i><span>مشاهده</span></a>
                        <button type="button" onclick="deletePost(<?= $view['id'] ?>)" class="btn btn-outline-danger btn-danger-mobile"><i class="fas fa-trash"></i><span>حذف</span></button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-3" aria-label="صفحه‌بندی مقالات">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(magazineListUrl(max(1, $page - 1), $search, $statusFilter)) ?>">قبلی</a></li>
                    <?php $start = max(1, $page - 2); $end = min($totalPages, $page + 2); for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(magazineListUrl($i, $search, $statusFilter)) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(magazineListUrl(min($totalPages, $page + 1), $search, $statusFilter)) ?>">بعدی</a></li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="loading-overlay" id="loadingOverlay" aria-hidden="true"><div class="spinner-border text-light spinner-border-lg" role="status"><span class="visually-hidden">در حال پردازش...</span></div></div>

<script>
const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedPosts = new Set();

function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.remove('active'); }

function checkboxesForPost(id) {
    return document.querySelectorAll(`.post-checkbox[data-post-id="${id}"]`);
}

function syncPostCheckboxes(id, checked) {
    checkboxesForPost(id).forEach(cb => { cb.checked = checked; });
}

function uniquePostIdsOnPage() {
    return Array.from(new Set(Array.from(document.querySelectorAll('.post-checkbox')).map(cb => parseInt(cb.value, 10)).filter(Number.isFinite)));
}

function updateBulkActionsBar() {
    const bar = document.getElementById('bulkActionsBar');
    const count = document.getElementById('selectedCount');
    const allIds = uniquePostIdsOnPage();
    const allSelected = allIds.length > 0 && allIds.every(id => selectedPosts.has(id));
    bar.classList.toggle('active', selectedPosts.size > 0);
    count.textContent = selectedPosts.size + ' مورد انتخاب شده';
    ['selectAll', 'selectAllTable'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = allSelected;
    });
}

function setAllPosts(checked) {
    uniquePostIdsOnPage().forEach(id => {
        if (checked) selectedPosts.add(id); else selectedPosts.delete(id);
        syncPostCheckboxes(id, checked);
    });
    updateBulkActionsBar();
}

document.getElementById('selectAll')?.addEventListener('change', function () { setAllPosts(this.checked); });
document.getElementById('selectAllTable')?.addEventListener('change', function () { setAllPosts(this.checked); });

document.querySelectorAll('.post-checkbox').forEach(cb => {
    cb.addEventListener('change', function () {
        const id = parseInt(this.value, 10);
        if (this.checked) selectedPosts.add(id); else selectedPosts.delete(id);
        syncPostCheckboxes(id, this.checked);
        updateBulkActionsBar();
    });
});

function parseJsonResponse(response) {
    return response.json().catch(() => ({})).then(data => {
        if (!response.ok) throw new Error(data.message || `خطای HTTP ${response.status}`);
        return data;
    });
}

function deletePost(id) {
    if (!confirm('آیا از حذف این مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.')) return;
    showLoading();
    fetch('ajax/delete_magazine_post.php?id=' + encodeURIComponent(id), {
        method: 'DELETE',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
    })
    .then(parseJsonResponse)
    .then(data => {
        if (!data.success) throw new Error(data.message || 'خطای نامشخص در حذف مقاله');
        document.querySelectorAll(`[data-post-id="${id}"]`).forEach(el => el.remove());
        selectedPosts.delete(id);
        updateBulkActionsBar();
        if (uniquePostIdsOnPage().length === 0) location.reload();
    })
    .catch(err => alert('خطا در حذف مقاله: ' + err.message))
    .finally(hideLoading);
}

function applyBulkAction() {
    const action = document.getElementById('bulkAction').value;
    if (!action) return alert('لطفاً یک عملیات انتخاب کنید.');
    if (selectedPosts.size === 0) return alert('لطفاً حداقل یک مقاله انتخاب کنید.');
    if (action === 'delete' && !confirm(`آیا از حذف ${selectedPosts.size} مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.`)) return;

    showLoading();
    fetch('ajax/bulk_action_posts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
        body: JSON.stringify({ action, post_ids: Array.from(selectedPosts) })
    })
    .then(parseJsonResponse)
    .then(data => {
        const errors = Array.isArray(data.errors) && data.errors.length ? '\n\n' + data.errors.join('\n') : '';
        alert(`عملیات انجام شد.\nموفق: ${data.successful || 0}\nناموفق: ${data.failed || 0}${errors}`);
        location.reload();
    })
    .catch(err => alert('خطا در انجام عملیات: ' + err.message))
    .finally(hideLoading);
}

document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>