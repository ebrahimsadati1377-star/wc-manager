<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();

// جلوگیری از اتمام زمان اجرای اسکریپت (۱۸۰ ثانیه زمان برای پردازش تصاویر و تنوع‌ها)
set_time_limit(180);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    jsonResponse(['success' => false, 'message' => 'داده ارسالی نامعتبر است.'], 422);
}

$id = (int)($data['id'] ?? 0);
$name = trim((string)($data['name'] ?? ''));
if ($name === '') {
    jsonResponse(['success' => false, 'message' => 'نام محصول الزامی است.'], 422);
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
                $termName = (string)($t['name'] ?? '');
                $existingTerms[] = function_exists('mb_strtolower')
                    ? mb_strtolower($termName, 'UTF-8')
                    : strtolower($termName);
            }
        }
        foreach ($options as $opt) {
            $normalizedOpt = function_exists('mb_strtolower')
                ? mb_strtolower($opt, 'UTF-8')
                : strtolower($opt);
            if (!in_array($normalizedOpt, $existingTerms, true)) {
                $wc->createAttributeTerm($attrId, ['name' => $opt]);
            }
        }
        $attributes[] = [
            'id'        => $attrId,
            'options'   => $options,
            'variation' => !empty($attr['variation']),
            'visible'   => true,
        ];
    } else {
        $attrName = trim((string)($attr['name'] ?? ''));
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
        if (!is_array($img)) {
            continue;
        }
        if (!empty($img['id']) && (int)$img['id'] > 0) {
            $images[] = ['id' => (int)$img['id']];
        } elseif (!empty($img['src']) && is_string($img['src'])) {
            $src = trim($img['src']);
            if (filter_var($src, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $images[] = ['src' => $src];
            }
        }
    }
}

// ---------------- ساخت payload محصول ----------------
$payload = [
    'name'              => $name,
    'type'              => $type,
    'status'            => in_array($data['status'] ?? '', ['publish', 'draft', 'pending', 'private'], true) ? $data['status'] : 'draft',
    'sku'               => trim((string)($data['sku'] ?? '')),
    'description'       => (string)($data['description'] ?? ''),
    'short_description' => (string)($data['short_description'] ?? ''),
    'categories'        => array_values(array_filter(array_map(
        static function ($c): ?array {
            $categoryId = is_array($c) ? (int)($c['id'] ?? 0) : 0;
            return $categoryId > 0 ? ['id' => $categoryId] : null;
        },
        (array)($data['categories'] ?? [])
    ))),
    'images'            => $images,
    'attributes'        => $attributes,
];

// Only metadata explicitly supported by this panel may be written. Arbitrary
// meta keys from the browser are intentionally ignored.
$allowedMetaKeys = ['_bajistyle_product_video_id'];
$metaData = [];
foreach ((array)($data['meta_data'] ?? []) as $meta) {
    if (!is_array($meta)) {
        continue;
    }
    $key = (string)($meta['key'] ?? '');
    if (!in_array($key, $allowedMetaKeys, true)) {
        continue;
    }

    if ($key === '_bajistyle_product_video_id') {
        $videoId = (int)($meta['value'] ?? 0);
        $metaData[] = ['key' => $key, 'value' => $videoId > 0 ? $videoId : ''];
    }
}
if ($metaData) {
    $payload['meta_data'] = $metaData;
}

if ($type === 'simple') {
    $payload['regular_price'] = (string)($data['regular_price'] ?? '');
    $payload['sale_price']    = (string)($data['sale_price'] ?? '');
    $payload['stock_status']  = in_array($data['stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? $data['stock_status'] : 'instock';
    $payload['manage_stock']  = !empty($data['manage_stock']);
    if ($payload['manage_stock']) {
        $payload['stock_quantity'] = max(0, (int)($data['stock_quantity'] ?? 0));
    }
} else {
    $payload['manage_stock'] = false;
}

$res = $id > 0 ? $wc->updateProduct($id, $payload) : $wc->createProduct($payload);

if ($res['error']) {
    error_log('[wc-manager] product save failed id=' . $id . ': ' . $res['error']);
    jsonResponse(['success' => false, 'message' => 'ذخیره محصول در ووکامرس ناموفق بود.'], 502);
}

logActivity($id > 0 ? 'update_product' : 'create_product', 'product', $name);
jsonResponse(['success' => true, 'product' => $res['body']]);
