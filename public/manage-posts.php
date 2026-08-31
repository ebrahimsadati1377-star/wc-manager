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
];
if ($search !== '') {
    $params['search'] = $search;
}
if ($statusFilter !== '') {
    $params['status'] = $statusFilter;
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
.action-buttons { white-space: nowrap; }
.action-buttons .btn { margin-inline-start: .25rem; }
.action-buttons .btn-text { display: none; }
.bulk-actions-bar {
    display: none;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
    background: #fff;
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: .75rem;
    margin-bottom: 1rem;
}
.bulk-actions-bar.active { display: flex; }
.pagination-info { color: #6c757d; font-size: .9rem; }
.loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.loading-overlay.active { display: flex; }
.spinner-border { width: 3rem; height: 3rem; }
.empty-state { text-align: center; padding: 3rem 1rem; color: #6c757d; }
.empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: .35; }
@media (max-width: 768px) {
    .page-heading { align-items: stretch !important; gap: .75rem; }
    .page-heading .btn { width: 100%; }
    .search-filter-bar { padding: 1rem; }
    .table-responsive { font-size: .85rem; }
    .action-buttons .btn { margin-bottom: .25rem; }
    .action-buttons .btn i { display: none; }
    .action-buttons .btn-text { display: inline; }
    .status-badge { font-size: .75rem; padding: .25rem .5rem; }
    .pagination { flex-wrap: wrap; gap: .2rem; }
}
</style>

<div class="container-fluid mt-2">
    <div class="page-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <h3 class="mb-0">مدیریت مقالات مجله</h3>
        <a href="add-post.php" class="btn btn-primary">
            <i class="fas fa-plus"></i>
            افزودن مقاله جدید
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>خطا:</strong> <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
        </div>
    <?php endif; ?>

    <div class="search-filter-bar">
        <form method="get" action="manage-posts.php" class="row g-3 align-items-end">
            <div class="col-12 col-lg-5">
                <label class="form-label" for="postSearch">جستجو</label>
                <div class="input-group">
                    <input id="postSearch" type="search" name="search" class="form-control" placeholder="جستجو در عنوان یا محتوا..." value="<?= e($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit" aria-label="جستجو">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label" for="statusFilter">وضعیت</label>
                <select id="statusFilter" name="status" class="form-select">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="publish" <?= $statusFilter === 'publish' ? 'selected' : '' ?>>منتشر شده</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="private" <?= $statusFilter === 'private' ? 'selected' : '' ?>>خصوصی</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <button type="submit" class="btn btn-primary w-100">اعمال فیلتر</button>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <a href="manage-posts.php" class="btn btn-outline-secondary w-100">پاک کردن</a>
            </div>
        </form>
    </div>

    <div class="bulk-actions-bar" id="bulkActionsBar">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="selectAllBulk">
            <label class="form-check-label" for="selectAllBulk">انتخاب همه صفحه</label>
        </div>
        <select id="bulkAction" class="form-select" style="max-width: 220px;">
            <option value="">عملیات گروهی</option>
            <option value="delete">حذف</option>
            <option value="publish">انتشار</option>
            <option value="draft">تبدیل به پیش‌نویس</option>
        </select>
        <button type="button" id="applyBulkActionBtn" class="btn btn-primary">اعمال</button>
        <span class="text-muted" id="selectedCount">0 مورد انتخاب شده</span>
    </div>

    <?php if (!$error && empty($posts)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h4>مقاله‌ای یافت نشد</h4>
            <p>هیچ مقاله‌ای با فیلترهای انتخابی وجود ندارد.</p>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a href="manage-posts.php" class="btn btn-outline-primary">نمایش همه مقالات</a>
            <?php endif; ?>
        </div>
    <?php elseif (!empty($posts)): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="pagination-info">
                <?php
                $from = (($page - 1) * $perPage) + 1;
                $to = min($page * $perPage, $totalPosts);
                ?>
                نمایش <?= $from ?> تا <?= $to ?> از <?= $totalPosts ?> مقاله
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 48px;"><input type="checkbox" id="selectAllTable" class="form-check-input" aria-label="انتخاب همه"></th>
                        <th>عنوان</th>
                        <th style="width: 130px;">وضعیت</th>
                        <th style="width: 160px;">تاریخ</th>
                        <th style="width: 190px;" class="text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $postId = (int)($post['id'] ?? 0);
                    $postStatus = (string)($post['status'] ?? '');
                    $postDate = !empty($post['date']) ? date('Y/m/d H:i', strtotime($post['date'])) : '-';
                    $statusLabel = [
                        'publish' => 'منتشر شده',
                        'draft' => 'پیش‌نویس',
                        'pending' => 'در انتظار',
                        'private' => 'خصوصی',
                    ][$postStatus] ?? $postStatus;
                    $title = (string)($post['title']['rendered'] ?? '(بدون عنوان)');
                    $link = (string)($post['link'] ?? '#');
                    ?>
                    <tr data-post-id="<?= $postId ?>">
                        <td><input type="checkbox" class="form-check-input post-checkbox" value="<?= $postId ?>" aria-label="انتخاب مقاله <?= $postId ?>"></td>
                        <td>
                            <a href="edit-post.php?id=<?= $postId ?>" class="post-title"><?= e($title) ?></a>
                            <div class="post-meta">شناسه: #<?= $postId ?></div>
                        </td>
                        <td><span class="status-badge status-<?= e($postStatus) ?>"><?= e($statusLabel) ?></span></td>
                        <td><small class="text-muted"><?= e($postDate) ?></small></td>
                        <td class="text-center action-buttons">
                            <a href="edit-post.php?id=<?= $postId ?>" class="btn btn-sm btn-outline-primary" title="ویرایش">
                                <i class="fas fa-edit"></i><span class="btn-text">ویرایش</span>
                            </a>
                            <a href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info" title="مشاهده">
                                <i class="fas fa-eye"></i><span class="btn-text">مشاهده</span>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger delete-post-btn" data-post-id="<?= $postId ?>" title="حذف">
                                <i class="fas fa-trash"></i><span class="btn-text">حذف</span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php $start = max(1, $page - 2); $end = min($totalPages, $page + 2); ?>
            <nav aria-label="صفحه‌بندی مقالات">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(magazineListUrl($page - 1, $search, $statusFilter)) ?>">قبلی</a>
                    </li>
                    <?php if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= e(magazineListUrl(1, $search, $statusFilter)) ?>">1</a></li>
                        <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(magazineListUrl($i, $search, $statusFilter)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= e(magazineListUrl($totalPages, $search, $statusFilter)) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(magazineListUrl($page + 1, $search, $statusFilter)) ?>">بعدی</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="spinner-border text-light" role="status"><span class="visually-hidden">در حال پردازش...</span></div>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedPosts = new Set();
const loadingOverlay = document.getElementById('loadingOverlay');
const selectAllTable = document.getElementById('selectAllTable');
const selectAllBulk = document.getElementById('selectAllBulk');

function showLoading() {
    loadingOverlay?.classList.add('active');
    loadingOverlay?.setAttribute('aria-hidden', 'false');
}

function hideLoading() {
    loadingOverlay?.classList.remove('active');
    loadingOverlay?.setAttribute('aria-hidden', 'true');
}

function setAllCheckboxes(checked) {
    document.querySelectorAll('.post-checkbox').forEach(cb => {
        cb.checked = checked;
        const id = Number(cb.value);
        checked ? selectedPosts.add(id) : selectedPosts.delete(id);
    });
    if (selectAllTable) selectAllTable.checked = checked;
    if (selectAllBulk) selectAllBulk.checked = checked;
    updateBulkActionsBar();
}

function updateBulkActionsBar() {
    const bar = document.getElementById('bulkActionsBar');
    const count = document.getElementById('selectedCount');
    const checkboxes = [...document.querySelectorAll('.post-checkbox')];
    const selectedOnPage = checkboxes.filter(cb => cb.checked).length;

    bar?.classList.toggle('active', selectedPosts.size > 0);
    if (count) count.textContent = selectedPosts.size + ' مورد انتخاب شده';

    const allChecked = checkboxes.length > 0 && selectedOnPage === checkboxes.length;
    if (selectAllTable) selectAllTable.checked = allChecked;
    if (selectAllBulk) selectAllBulk.checked = allChecked;
}

selectAllTable?.addEventListener('change', e => setAllCheckboxes(e.target.checked));
selectAllBulk?.addEventListener('change', e => setAllCheckboxes(e.target.checked));

document.querySelectorAll('.post-checkbox').forEach(cb => {
    cb.addEventListener('change', () => {
        const id = Number(cb.value);
        cb.checked ? selectedPosts.add(id) : selectedPosts.delete(id);
        updateBulkActionsBar();
    });
});

async function parseJsonResponse(response) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok && !data.message) {
        data.message = 'خطای HTTP ' + response.status;
    }
    return data;
}

async function deletePost(id) {
    if (!confirm('آیا از حذف این مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.')) return;

    showLoading();
    try {
        const response = await fetch('ajax/delete_magazine_post.php?id=' + encodeURIComponent(id), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
        });
        const data = await parseJsonResponse(response);
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'حذف مقاله ناموفق بود.');
        }

        const row = document.querySelector(`tr[data-post-id="${id}"]`);
        row?.remove();
        selectedPosts.delete(Number(id));
        updateBulkActionsBar();

        if (document.querySelectorAll('tbody tr').length === 0) {
            location.reload();
        }
    } catch (error) {
        alert('خطا در حذف مقاله: ' + error.message);
    } finally {
        hideLoading();
    }
}

document.querySelectorAll('.delete-post-btn').forEach(btn => {
    btn.addEventListener('click', () => deletePost(Number(btn.dataset.postId)));
});

async function applyBulkAction() {
    const action = document.getElementById('bulkAction')?.value || '';
    if (!action) {
        alert('لطفاً یک عملیات انتخاب کنید.');
        return;
    }
    if (selectedPosts.size === 0) {
        alert('لطفاً حداقل یک مقاله انتخاب کنید.');
        return;
    }
    if (action === 'delete' && !confirm(`آیا از حذف ${selectedPosts.size} مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.`)) {
        return;
    }

    showLoading();
    try {
        const response = await fetch('ajax/bulk_action_posts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({ action, post_ids: Array.from(selectedPosts) }),
        });
        const data = await parseJsonResponse(response);
        const successful = Number(data.successful || 0);
        const failed = Number(data.failed || 0);

        if (!response.ok && successful === 0) {
            throw new Error(data.message || 'عملیات انجام نشد.');
        }

        let message = `موفق: ${successful}\nناموفق: ${failed}`;
        if (Array.isArray(data.errors) && data.errors.length) {
            message += '\n\n' + data.errors.slice(0, 5).join('\n');
        }
        alert(message);

        if (successful > 0) {
            location.reload();
        }
    } catch (error) {
        alert('خطا در انجام عملیات: ' + error.message);
    } finally {
        hideLoading();
    }
}

document.getElementById('applyBulkActionBtn')?.addEventListener('click', applyBulkAction);

document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') {
        event.preventDefault();
        document.getElementById('postSearch')?.focus();
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
