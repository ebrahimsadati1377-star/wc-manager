<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$client = new WooCommerceClient();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;

// Search & Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query params
$params = [
    'per_page' => $per_page,
    'page' => $page,
    'orderby' => 'date',
    'order' => 'desc'
];

if (!empty($search)) {
    $params['search'] = $search;
}

if (!empty($status_filter)) {
    $params['status'] = $status_filter;
}

try {
    $response = $client->get('wp-json/wp/v2/posts', $params);
    $posts = $response['body'];
    $total_posts = isset($response['headers']['X-WP-Total']) ? intval($response['headers']['X-WP-Total']) : 0;
    $total_pages = isset($response['headers']['X-WP-TotalPages']) ? intval($response['headers']['X-WP-TotalPages']) : 1;
    $error = null;
} catch (Exception $e) {
    $posts = [];
    $total_posts = 0;
    $total_pages = 1;
    $error = $e->getMessage();
}

require __DIR__ . '/partials/header.php';
?>

<style>
.search-filter-bar {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status-publish {
    background: #d4edda;
    color: #155724;
}

.status-draft {
    background: #fff3cd;
    color: #856404;
}

.status-pending {
    background: #cce5ff;
    color: #004085;
}

.status-private {
    background: #f8d7da;
    color: #721c24;
}

.post-title {
    font-weight: 500;
    color: #2c3e50;
    text-decoration: none;
}

.post-title:hover {
    color: #007bff;
    text-decoration: underline;
}

.post-meta {
    font-size: 0.85rem;
    color: #6c757d;
}

.action-buttons .btn {
    margin-left: 0.25rem;
}

.action-buttons .btn .btn-text {
    display: none;
}

.action-buttons .btn i {
    display: inline-block;
}

.bulk-actions-bar {
    background: #fff;
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    margin-bottom: 1rem;
    display: none;
}

.bulk-actions-bar.active {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.pagination-info {
    color: #6c757d;
    font-size: 0.9rem;
}

.loading-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.loading-overlay.active {
    display: flex;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #007bff;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.3;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.85rem;
    }

    .action-buttons .btn {
        padding: 0.35rem 0.6rem;
        font-size: 0.75rem;
        margin-left: 0.15rem;
        margin-bottom: 0.25rem;
    }

    .action-buttons .btn i {
        display: none;
    }

    .action-buttons .btn .btn-text {
        display: inline-block;
    }

    .post-meta {
        font-size: 0.75rem;
    }

    .status-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }

    .search-filter-bar {
        padding: 1rem;
    }

    .search-filter-bar .col-md-5,
    .search-filter-bar .col-md-3,
    .search-filter-bar .col-md-2 {
        margin-bottom: 0.5rem;
    }

    h3 {
        font-size: 1.3rem;
    }

    .pagination .page-link {
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
    }

    .bulk-actions-bar {
        flex-wrap: wrap;
        font-size: 0.85rem;
    }

    .bulk-actions-bar select,
    .bulk-actions-bar button {
        font-size: 0.85rem;
    }

    .action-buttons {
        white-space: nowrap;
    }

    table th:first-child,
    table td:first-child {
        width: 30px;
    }
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>مدیریت مقالات مجله</h3>
        <a href="add-post.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> افزودن مقاله جدید
        </a>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>خطا!</strong> <?= htmlspecialchars($error) ?>
        <button type="button" class="close" data-dismiss="alert">
            <span>&times;</span>
        </button>
    </div>
    <?php endif; ?>

    <div class="search-filter-bar">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-5">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="جستجو در عنوان یا محتوا..." value="<?= htmlspecialchars($search) ?>">
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-control">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="publish" <?= $status_filter === 'publish' ? 'selected' : '' ?>>منتشر شده</option>
                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="private" <?= $status_filter === 'private' ? 'selected' : '' ?>>خصوصی</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-block">اعمال فیلتر</button>
            </div>
            <div class="col-md-2">
                <a href="manage-posts.php" class="btn btn-secondary btn-block">پاک کردن</a>
            </div>
        </form>
    </div>

    <div class="bulk-actions-bar" id="bulkActionsBar">
        <input type="checkbox" id="selectAll" class="mr-2">
        <label for="selectAll" class="mb-0 mr-3">انتخاب همه</label>
        <select id="bulkAction" class="form-control" style="max-width: 200px;">
            <option value="">عملیات گروهی</option>
            <option value="delete">حذف</option>
            <option value="publish">انتشار</option>
            <option value="draft">تبدیل به پیش‌نویس</option>
        </select>
        <button onclick="applyBulkAction()" class="btn btn-primary">اعمال</button>
        <span class="text-muted" id="selectedCount">0 مورد انتخاب شده</span>
    </div>

    <?php if (empty($posts)): ?>
    <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <h4>مقاله‌ای یافت نشد</h4>
        <p>هیچ مقاله‌ای با فیلترهای انتخابی وجود ندارد.</p>
        <?php if (!empty($search) || !empty($status_filter)): ?>
        <a href="manage-posts.php" class="btn btn-outline-primary">نمایش همه مقالات</a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="pagination-info">
            نمایش <?= (($page - 1) * $per_page) + 1 ?> تا <?= min($page * $per_page, $total_posts) ?> از <?= $total_posts ?> مقاله
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="thead-light">
                <tr>
                    <th width="40">
                        <input type="checkbox" id="selectAllTable">
                    </th>
                    <th>عنوان</th>
                    <th width="120">وضعیت</th>
                    <th width="150">تاریخ</th>
                    <th width="180" class="text-center">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $post):
                    $post_date = date('Y/m/d H:i', strtotime($post['date']));
                    $status_class = 'status-' . $post['status'];
                    $status_label = [
                        'publish' => 'منتشر شده',
                        'draft' => 'پیش‌نویس',
                        'pending' => 'در انتظار',
                        'private' => 'خصوصی'
                    ][$post['status']] ?? $post['status'];
                ?>
                <tr data-post-id="<?= $post['id'] ?>">
                    <td>
                        <input type="checkbox" class="post-checkbox" value="<?= $post['id'] ?>">
                    </td>
                    <td>
                        <a href="edit-post.php?id=<?= $post['id'] ?>" class="post-title">
                            <?= htmlspecialchars($post['title']['rendered']) ?>
                        </a>
                        <div class="post-meta">
                            <?php if (isset($post['author'])): ?>
                            نویسنده: <?= $post['author'] ?> |
                            <?php endif; ?>
                            شناسه: #<?= $post['id'] ?>
                        </div>
                    </td>
                    <td>
                        <span class="status-badge <?= $status_class ?>">
                            <?= $status_label ?>
                        </span>
                    </td>
                    <td>
                        <small class="text-muted"><?= $post_date ?></small>
                    </td>
                    <td class="text-center action-buttons">
                        <a href="edit-post.php?id=<?= $post['id'] ?>" class="btn btn-sm btn-outline-primary" title="ویرایش" data-text="ویرایش">
                            <i class="fas fa-edit"></i>
                            <span class="btn-text">ویرایش</span>
                        </a>
                        <a href="<?= $post['link'] ?? '#' ?>" target="_blank" class="btn btn-sm btn-outline-info" title="مشاهده" data-text="مشاهده">
                            <i class="fas fa-eye"></i>
                            <span class="btn-text">مشاهده</span>
                        </a>
                        <button onclick="deletePost(<?= $post['id'] ?>)" class="btn btn-sm btn-outline-danger" title="حذف" data-text="حذف">
                            <i class="fas fa-trash"></i>
                            <span class="btn-text">حذف</span>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . $status_filter : '' ?>">قبلی</a>
            </li>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);

            if ($start > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . $status_filter : '' ?>">1</a></li>
                <?php if ($start > 2): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . $status_filter : '' ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . $status_filter : '' ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status_filter ? '&status=' . $status_filter : '' ?>">بعدی</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<script>
let selectedPosts = new Set();

function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('active');
}

function updateBulkActionsBar() {
    const bar = document.getElementById('bulkActionsBar');
    const count = document.getElementById('selectedCount');

    if (selectedPosts.size > 0) {
        bar.classList.add('active');
        count.textContent = selectedPosts.size + ' مورد انتخاب شده';
    } else {
        bar.classList.remove('active');
    }
}

document.getElementById('selectAllTable')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.post-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = this.checked;
        if (this.checked) {
            selectedPosts.add(parseInt(cb.value));
        } else {
            selectedPosts.delete(parseInt(cb.value));
        }
    });
    updateBulkActionsBar();
});

document.querySelectorAll('.post-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        if (this.checked) {
            selectedPosts.add(parseInt(this.value));
        } else {
            selectedPosts.delete(parseInt(this.value));
        }
        updateBulkActionsBar();
    });
});

function deletePost(id) {
    if (!confirm('آیا از حذف این مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.')) {
        return;
    }

    showLoading();

    fetch('ajax/delete_magazine_post.php?id=' + id, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const row = document.querySelector(`tr[data-post-id="${id}"]`);
            if (row) {
                row.style.backgroundColor = '#f8d7da';
                setTimeout(() => {
                    row.remove();
                    selectedPosts.delete(id);
                    updateBulkActionsBar();

                    if (document.querySelectorAll('tbody tr').length === 0) {
                        location.reload();
                    }
                }, 300);
            }
        } else {
            alert('خطا در حذف مقاله: ' + (data.message || 'خطای نامشخص'));
        }
    })
    .catch(err => {
        hideLoading();
        alert('خطا در ارتباط با سرور: ' + err.message);
    });
}

function applyBulkAction() {
    const action = document.getElementById('bulkAction').value;

    if (!action) {
        alert('لطفاً یک عملیات انتخاب کنید.');
        return;
    }

    if (selectedPosts.size === 0) {
        alert('لطفاً حداقل یک مقاله انتخاب کنید.');
        return;
    }

    if (action === 'delete') {
        if (!confirm(`آیا از حذف ${selectedPosts.size} مقاله اطمینان دارید؟\n\nاین عمل قابل بازگشت نیست.`)) {
            return;
        }
    }

    showLoading();

    const postIds = Array.from(selectedPosts);

    fetch('ajax/bulk_action_posts.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: action,
            post_ids: postIds
        })
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            alert(`عملیات با موفقیت انجام شد.\nموفق: ${data.successful || 0}\nناموفق: ${data.failed || 0}`);
            location.reload();
        } else {
            alert('خطا در انجام عملیات: ' + (data.message || 'خطای نامشخص'));
        }
    })
    .catch(err => {
        hideLoading();
        alert('خطا در ارتباط با سرور: ' + err.message);
    });
}

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
