<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

// جلوگیری از اتمام زمان اجرای اسکریپت (۱۸۰ ثانیه زمان برای پردازش تصاویر و تنوع‌ها)
set_time_limit(180);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    jsonResponse(['success' => false, 'message' => 'داده ارسالی نامعتبر است.']);
}

// CSRF check (token sent via header for JSON requests)
$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sentToken)) {
    jsonResponse(['success' => false, 'message' => 'نشست شما نامعتبر است، صفحه را رفرش کنید.'], 419);
}

$id = (int)($data['id'] ?? 0);
$name = trim($data['name'] ?? '');
if ($name === '') {
    jsonResponse(['success' => false, 'message' => 'نام محصول الزامی است.']);
}

$type = ($data['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';

$wc = new WooCommerceClient();

// ---------------- پردازش ویژگی‌ها (اطمینان از وجود Term برای ویژگی‌های سراسری) ----------------
$attributes = [];
foreach ((array)($data['attributes'] ?? []) as $attr) {
    $attrId = (int)($attr['id'] ?? 0);
    $options = array_values(array_filter(array_map('trim', (array)($attr['options'] ?? []))));
    if (empty($options)) {
        continue;
    }

    if ($attrId > 0) {
        // ویژگی سراسری - اطمینان از وجود term برای هر مقدار
        $termsRes = $wc->getAttributeTerms($attrId, ['per_page' => 100]);
        $existingTerms = [];
        if (!$termsRes['error']) {
            foreach ($termsRes['body'] as $t) {
                $existingTerms[] = mb_strtolower($t['name']);
            }
        }
        foreach ($options as $opt) {
            if (!in_array(mb_strtolower($opt), $existingTerms, true)) {
                $wc->createAttributeTerm($attrId, ['name' => $opt]); // اگر از قبل موجود بود، خطای بی‌ضرر برمی‌گردد
            }
        }
        $attributes[] = [
            'id'        => $attrId,
            'options'   => $options,
            'variation' => !empty($attr['variation']),
            'visible'   => true,
        ];
    } else {
        $attrName = trim($attr['name'] ?? '');
        if ($attrName === '') {
            continue;
        }
        $attributes[] = [
            'id'        => 0,
            'name'      => $attrName,
            'options'   => $options,
            'variation' => !empty($attr['variation']),
            'visible'   => true,
        ];
    }
}

// ---------------- هوشمندسازی بخش تصاویر ----------------
$images = [];
if (!empty($data['images']) && is_array($data['images'])) {
    foreach ($data['images'] as $img) {
        // اگر عکس از قبل شناسه ووکامرس دارد، فقط آی‌دی آن را می‌فرستیم تا مجدد دانلود نشود
        if (!empty($img['id']) && (int)$img['id'] > 0) {
            $images[] = [
                'id' => (int)$img['id']
            ];
        } 
        // اگر عکس جدید است و شناسه ندارد، آدرس آن فرستاده می‌شود تا دانلود شود
        elseif (!empty($img['src'])) {
            $images[] = [
                'src' => $img['src']
            ];
        }
    }
}

// ---------------- ساخت payload محصول ----------------
$payload = [
    'name'              => $name,
    'type'              => $type,
    'status'            => in_array($data['status'] ?? '', ['publish', 'draft', 'pending', 'private'], true) ? $data['status'] : 'draft',
    'sku'               => trim($data['sku'] ?? ''),
    'description'       => (string)($data['description'] ?? ''),
    'short_description' => (string)($data['short_description'] ?? ''),
    'categories'        => array_map(fn($c) => ['id' => (int)$c['id']], (array)($data['categories'] ?? [])),
    'images'            => $images, // 👈 استفاده از لیست تصاویر بهینه‌سازی‌شده
    'attributes'        => $attributes,
];

// Handle meta_data (including video attachment ID)
if (!empty($data['meta_data']) && is_array($data['meta_data'])) {
    $payload['meta_data'] = $data['meta_data'];
}

if ($type === 'simple') {
    $payload['regular_price'] = (string)($data['regular_price'] ?? '');
    $payload['sale_price']    = (string)($data['sale_price'] ?? '');
    $payload['stock_status']  = in_array($data['stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? $data['stock_status'] : 'instock';
    $payload['manage_stock']  = !empty($data['manage_stock']);
    if ($payload['manage_stock']) {
        $payload['stock_quantity'] = (int)($data['stock_quantity'] ?? 0);
    }
} else {
    // برای محصول متغیر، قیمت‌گذاری و موجودی در سطح تنوع مدیریت می‌شود
    $payload['manage_stock'] = false;
}

$res = $id > 0 ? $wc->updateProduct($id, $payload) : $wc->createProduct($payload);

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

logActivity($id > 0 ? 'update_product' : 'create_product', 'product', $name);

jsonResponse(['success' => true, 'product' => $res['body']]);