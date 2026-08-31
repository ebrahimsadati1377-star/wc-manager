<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

$productId = (int)($_POST['product_id'] ?? 0);
$attributesRaw = json_decode($_POST['attributes'] ?? '[]', true);

if ($productId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه محصول نامعتبر است.']);
}
if (!is_array($attributesRaw)) {
    $attributesRaw = [];
}

// فقط ویژگی‌هایی که برای تنوع علامت خورده‌اند
$variationAttrs = [];
foreach ($attributesRaw as $attr) {
    if (empty($attr['variation'])) {
        continue;
    }
    $options = array_values(array_filter(array_map('trim', (array)($attr['options'] ?? []))));
    if (empty($options)) {
        continue;
    }
    $key = (int)($attr['id'] ?? 0) > 0 ? (int)$attr['id'] : trim((string)($attr['name'] ?? ''));
    $variationAttrs[] = [
        'id'      => (int)($attr['id'] ?? 0),
        'name'    => $attr['name'] ?? null,
        'options' => $options,
    ];
}

if (empty($variationAttrs)) {
    jsonResponse(['success' => false, 'message' => 'حداقل یک ویژگی با گزینه «استفاده برای تنوع» و حداقل یک مقدار لازم است.']);
}

// محاسبه ضرب دکارتی مقادیر
function cartesianProduct(array $arrays): array
{
    $result = [[]];
    foreach ($arrays as $attr) {
        $append = [];
        foreach ($result as $combo) {
            foreach ($attr['options'] as $opt) {
                $newCombo = $combo;
                $newCombo[] = ['id' => $attr['id'], 'name' => $attr['name'], 'option' => $opt];
                $append[] = $newCombo;
            }
        }
        $result = $append;
    }
    return $result;
}

$combos = cartesianProduct($variationAttrs);

$wc = new WooCommerceClient();

// دریافت تنوع‌های موجود برای جلوگیری از تکرار
$existingRes = $wc->getVariations($productId, ['per_page' => 100]);
$existingCombos = [];
if (!$existingRes['error']) {
    foreach ($existingRes['body'] as $v) {
        $sig = [];
        foreach (($v['attributes'] ?? []) as $a) {
            $sig[] = mb_strtolower(($a['name'] ?? $a['id'] ?? '') . ':' . $a['option']);
        }
        sort($sig);
        $existingCombos[implode('|', $sig)] = true;
    }
}

$created = 0;
$errors = [];
foreach ($combos as $combo) {
    $sig = [];
    $wcAttrs = [];
    foreach ($combo as $c) {
        $sig[] = mb_strtolower(($c['name'] ?? $c['id']) . ':' . $c['option']);
        $entry = ['option' => $c['option']];
        if ($c['id'] > 0) {
            $entry['id'] = $c['id'];
        } else {
            $entry['name'] = $c['name'];
        }
        $wcAttrs[] = $entry;
    }
    sort($sig);
    $key = implode('|', $sig);
    if (isset($existingCombos[$key])) {
        continue; // قبلاً موجود است
    }

    $res = $wc->createVariation($productId, ['attributes' => $wcAttrs]);
    if ($res['error']) {
        $errors[] = $res['error'];
    } else {
        $created++;
    }
}

// دریافت لیست نهایی و به‌روز تنوع‌ها
$finalRes = $wc->getVariations($productId, ['per_page' => 100]);
$variations = $finalRes['error'] ? [] : $finalRes['body'];

logActivity('generate_variations', 'product', "product_id={$productId}, created={$created}");

jsonResponse([
    'success'    => true,
    'created'    => $created,
    'errors'     => $errors,
    'variations' => $variations,
    'message'    => $created > 0 ? "{$created} ترکیب جدید ساخته شد." : 'همه ترکیب‌های ممکن از قبل موجود بودند.',
]);
