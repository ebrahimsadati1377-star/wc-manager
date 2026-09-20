<?php

class ProductAiSeoService
{
    private WooCommerceClient $wc;

    public function __construct(?WooCommerceClient $wc = null)
    {
        $this->wc = $wc ?? new WooCommerceClient();
    }

    public function analyze(string $imageUrl, array $manual = []): array
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || !preg_match('#^https?://#i', $imageUrl)) {
            throw new RuntimeException('آدرس عکس خام محصول معتبر نیست.');
        }

        $apiKey = wcAgentOpenAiKeyFromSession();
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY روی سرور تنظیم نشده است.');
        }

        $categoryMap = $this->categoryMap();
        $selectedIds = $this->validCategoryIds((array)($manual['category_ids'] ?? []), $categoryMap);

        $categoryLines = [];
        foreach ($categoryMap as $id => $name) {
            $categoryLines[] = $id . ': ' . $name;
        }

        $manualText = json_encode([
            'rough_name' => trim((string)($manual['rough_name'] ?? '')),
            'short_description' => trim((string)($manual['short_description'] ?? '')),
            'description' => trim((string)($manual['description'] ?? '')),
            'notes' => trim((string)($manual['notes'] ?? '')),
            'selected_category_ids' => $selectedIds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $instructions = 'You are the Persian e-commerce product editor for BAJI women clothing. '
            . 'Analyze the supplied raw product photo and manual facts. Manual facts are authoritative. '
            . 'Never invent fabric, measurements, size range, construction details, or care instructions when they are not visible or provided. '
            . 'Write natural Persian sales copy, not keyword stuffing. Return valid JSON only.';

        $prompt = "برای این محصول پوشاک زنانه یک خروجی کامل فروشگاهی و SEO بساز.\n"
            . "اطلاعات دستی: {$manualText}\n"
            . "دسته‌بندی‌های مجاز ووکامرس:\n" . implode("\n", $categoryLines) . "\n\n"
            . "فقط JSON با این کلیدها برگردان:\n"
            . "name, short_description, description, focus_keyword, seo_title, meta_description, "
            . "material, color, size, uses, suitable_for, care, other_description, category_ids.\n"
            . "short_description حداکثر دو پاراگراف کوتاه HTML باشد. "
            . "description شامل HTML ساده با h2، p و ul/li و مزایا و مشخصات واقعی باشد. "
            . "seo_title حداکثر 60 کاراکتر و meta_description حداکثر 160 کاراکتر باشد. "
            . "category_ids فقط از شناسه‌های فهرست مجاز باشد. اگر دسته‌بندی دستی انتخاب شده همان را نگه دار.";

        $model = trim((string)getenv('OPENAI_PRODUCT_TEXT_MODEL')) ?: 'gpt-5.6-luna';
        $response = wcAgentOpenAiRequest($apiKey, [
            'model' => $model,
            'store' => false,
            'max_output_tokens' => 3500,
            'instructions' => $instructions,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_image', 'image_url' => $imageUrl, 'detail' => 'high'],
                ],
            ]],
        ]);

        $decoded = $this->decodeJson(wcAgentOutputText($response));
        return $this->normalizeAnalysis($decoded, $manual, $selectedIds, $categoryMap);
    }

    private function categoryMap(): array
    {
        $res = $this->wc->getCategories(['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
        if (!empty($res['error'])) return [];

        $map = [];
        foreach ((array)($res['body'] ?? []) as $category) {
            $id = (int)($category['id'] ?? 0);
            $name = trim((string)($category['name'] ?? ''));
            if ($id > 0 && $name !== '') $map[$id] = $name;
        }
        return $map;
    }

    private function validCategoryIds(array $ids, array $categoryMap): array
    {
        $valid = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($categoryMap[$id])) $valid[] = $id;
        }
        return array_values(array_unique($valid));
    }

    private function decodeJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $text, $m)) $text = trim($m[1]);
        $first = strpos($text, '{');
        $last = strrpos($text, '}');
        if ($first !== false && $last !== false && $last >= $first) {
            $text = substr($text, $first, $last - $first + 1);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('پاسخ تحلیل هوش مصنوعی JSON معتبر نبود.');
        }
        return $decoded;
    }

    private function normalizeAnalysis(array $raw, array $manual, array $selectedIds, array $categoryMap): array
    {
        $manualName = trim((string)($manual['rough_name'] ?? ''));
        $name = self::plain($raw['name'] ?? $manualName, 140);
        if ($name === '') $name = $manualName !== '' ? $manualName : 'محصول جدید باجی';

        $short = self::cleanHtml((string)($raw['short_description'] ?? $manual['short_description'] ?? ''));
        $description = self::cleanHtml((string)($raw['description'] ?? $manual['description'] ?? ''));
        $focus = self::plain($raw['focus_keyword'] ?? $name, 120);
        if ($focus === '') $focus = $name;
        $seoTitle = self::plain($raw['seo_title'] ?? ('خرید ' . $name . ' | باجی'), 60);
        $meta = self::plain($raw['meta_description'] ?? strip_tags($short), 160);
        if ($meta === '') {
            $meta = self::plain('خرید ' . $name . ' از باجی؛ مشاهده مشخصات، تصاویر و جزئیات محصول.', 160);
        }

        $categoryIds = $selectedIds ?: $this->validCategoryIds((array)($raw['category_ids'] ?? []), $categoryMap);

        return [
            'name' => $name,
            'short_description' => $short,
            'description' => $description,
            'focus_keyword' => $focus,
            'seo_title' => $seoTitle,
            'meta_description' => $meta,
            'material' => self::plain($raw['material'] ?? '', 160),
            'color' => self::plain($raw['color'] ?? '', 120),
            'size' => self::plain($raw['size'] ?? '', 160),
            'uses' => self::plain($raw['uses'] ?? '', 180),
            'suitable_for' => self::plain($raw['suitable_for'] ?? '', 180),
            'care' => self::plain($raw['care'] ?? '', 220),
            'other_description' => self::plain($raw['other_description'] ?? '', 240),
            'category_ids' => $categoryIds,
        ];
    }

    public static function buildAttributes(array $analysis): array
    {
        $pairs = [
            'برند' => 'BAJI',
            'مورد استفاده' => $analysis['uses'] ?? '',
            'مناسب برای' => $analysis['suitable_for'] ?? '',
            'جنس' => $analysis['material'] ?? '',
            'رنگ' => $analysis['color'] ?? '',
            'سایز' => $analysis['size'] ?? '',
            'نگهداری و شستشو' => $analysis['care'] ?? '',
            'توضیحات تکمیلی' => $analysis['other_description'] ?? '',
        ];

        $attributes = [];
        foreach ($pairs as $name => $value) {
            $value = self::plain($value, 240);
            if ($value === '') continue;
            $attributes[] = [
                'id' => 0,
                'name' => $name,
                'options' => [$value],
                'visible' => true,
                'variation' => false,
            ];
        }
        return $attributes;
    }

    public static function cleanHtml(string $value): string
    {
        $value = trim($value);
        return strip_tags($value, '<p><br><strong><b><em><h2><h3><ul><ol><li>');
    }

    public static function plain($value, int $max = 200): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)) ?? '');
        if ($max > 0 && function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $max) {
            $value = rtrim(mb_substr($value, 0, $max, 'UTF-8'));
        } elseif ($max > 0 && strlen($value) > $max) {
            $value = rtrim(substr($value, 0, $max));
        }
        return $value;
    }
}
