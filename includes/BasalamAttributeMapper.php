<?php

class BasalamAttributeMapper
{
    private BasalamClient $basalam;

    public function __construct(?BasalamClient $basalam = null)
    {
        $this->basalam = $basalam ?? new BasalamClient();
    }

    public function build(
        array $product,
        array $variations,
        int $categoryId,
        ?int $basalamProductId = null
    ): array {
        $result = $this->basalam->getCategoryAttributes(
            $categoryId,
            $basalamProductId && $basalamProductId > 0 ? $basalamProductId : null,
            false
        );

        if (!empty($result['error'])) {
            return [
                'attributes' => [],
                'warnings' => ['خواندن ویژگی‌های دسته باسلام ناموفق بود: ' . $result['error']],
            ];
        }

        $definitions = $this->flattenDefinitions(is_array($result['body'] ?? null) ? $result['body'] : []);
        if (!$definitions) {
            return ['attributes' => [], 'warnings' => []];
        }

        $sources = $this->buildSources($product, $variations);
        $attributes = [];
        $warnings = [];

        foreach ($definitions as $definition) {
            $id = (int)($definition['id'] ?? $definition['attribute_id'] ?? 0);
            $title = trim((string)($definition['title'] ?? $definition['name'] ?? ''));
            if ($id <= 0 || $title === '') {
                continue;
            }

            $value = $this->valueForDefinition($title, $sources);
            if ($value === '') {
                // Preserve a manually-entered Basalam value when Woo has no source for it.
                $value = $this->existingDefinitionValue($definition);
            }

            if ($value !== '') {
                $attributes[] = [
                    'attribute_id' => $id,
                    'value' => $this->limitText($value, 1000),
                ];
                continue;
            }

            $required = !empty($definition['required']) || !empty($definition['is_required']);
            if ($required) {
                $warnings[] = 'ویژگی اجباری باسلام «' . $title . '» در WooCommerce مقدار قابل‌اعتماد ندارد.';
            }
        }

        return [
            'attributes' => $attributes,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function flattenDefinitions(array $body): array
    {
        $root = $body['data'] ?? $body['attributes'] ?? $body;
        if (!is_array($root)) {
            return [];
        }

        $out = [];
        $walk = function ($items) use (&$walk, &$out): void {
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int)($item['id'] ?? $item['attribute_id'] ?? 0);
                $title = trim((string)($item['title'] ?? $item['name'] ?? ''));
                if ($id > 0 && $title !== '') {
                    $out[] = $item;
                }
                foreach (['attributes', 'children', 'data', 'items'] as $key) {
                    if (isset($item[$key]) && is_array($item[$key])) {
                        $walk($item[$key]);
                    }
                }
            }
        };
        $walk($root);

        $unique = [];
        foreach ($out as $definition) {
            $id = (int)($definition['id'] ?? $definition['attribute_id'] ?? 0);
            if ($id > 0) {
                $unique[$id] = $definition;
            }
        }
        return array_values($unique);
    }

    private function buildSources(array $product, array $variations): array
    {
        $exact = [];

        foreach (($product['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $name = trim((string)($attribute['name'] ?? ''));
            $options = is_array($attribute['options'] ?? null) ? $attribute['options'] : [];
            $values = [];
            foreach ($options as $option) {
                $option = trim((string)$option);
                if ($option !== '') {
                    $values[] = $option;
                }
            }
            if ($name !== '' && $values) {
                $exact[$this->canonical($name)] = implode('، ', array_values(array_unique($values)));
            }
        }

        $variationValues = [];
        foreach ($variations as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            foreach (($variation['attributes'] ?? []) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $name = trim((string)($attribute['name'] ?? ''));
                $value = trim((string)($attribute['option'] ?? ''));
                if ($name === '' || $value === '') {
                    continue;
                }
                $key = $this->canonical($name);
                $variationValues[$key][$value] = true;
            }
        }
        foreach ($variationValues as $key => $values) {
            if (!isset($exact[$key])) {
                $exact[$key] = implode('، ', array_keys($values));
            }
        }

        $name = $this->plainText((string)($product['name'] ?? ''));
        $short = $this->plainText((string)($product['short_description'] ?? ''));
        $description = $this->plainText((string)($product['description'] ?? ''));
        $text = trim($name . "\n" . $short . "\n" . $description);

        $tags = [];
        foreach (($product['tags'] ?? []) as $tag) {
            $tagName = trim((string)($tag['name'] ?? ''));
            if ($tagName !== '') {
                $tags[] = $tagName;
            }
        }
        $categories = [];
        foreach (($product['categories'] ?? []) as $category) {
            $categoryName = trim((string)($category['name'] ?? ''));
            if ($categoryName !== '') {
                $categories[] = $categoryName;
            }
        }
        $searchText = trim($text . "\n" . implode("\n", $tags) . "\n" . implode("\n", $categories));

        $semantic = [
            'brand' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['برند', 'brand']),
                $this->extractLabeledValue($text, ['برند']),
            ]),
            'material' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['جنس', 'متریال', 'پارچه', 'material', 'fabric']),
                $this->extractLabeledValue($text, ['جنس', 'متریال', 'پارچه']),
                $this->detectPhrase($searchText, [
                    'نخ پنبه', 'پنبه', 'کتان', 'لینن', 'ویسکوز', 'پلی استر', 'پلی‌استر',
                    'کرپ', 'ساتن', 'ابریشم', 'مخمل', 'جین', 'دورس', 'فوتر', 'چرم', 'پری', 'کوک دوزی'
                ]),
            ]),
            'color' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['رنگ', 'color']),
                $this->extractLabeledValue($text, ['رنگ']),
                $this->detectPhrase($searchText, [
                    'آبی آسمانی', 'آبی شیری', 'سبز ماشی', 'سرمه‌ای', 'سرمه ای', 'طوسی', 'خاکستری',
                    'مشکی', 'سفید', 'صورتی', 'قرمز', 'زرشکی', 'قهوه‌ای', 'قهوه ای', 'کرم', 'بژ',
                    'سبز', 'آبی', 'بنفش', 'نارنجی', 'زرد', 'طلایی', 'نقره‌ای', 'نقره ای'
                ]),
            ]),
            'size' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['سایز', 'اندازه', 'سایزبندی', 'size']),
                $this->extractLabeledValue($text, ['سایزبندی', 'سایز', 'اندازه']),
                $this->detectSize($searchText),
            ]),
            'audience' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['مناسب برای', 'مخاطب', 'گروه سنی', 'gender']),
                $this->extractLabeledValue($text, ['مناسب برای', 'مخاطب']),
                $this->detectAudience($searchText),
            ]),
            'usage' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['موارد استفاده', 'کاربرد', 'استایل']),
                $this->extractLabeledValue($text, ['موارد استفاده', 'کاربرد', 'استایل']),
                $this->detectUsage($searchText),
            ]),
            'care' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['نحوه نگهداری و شستشو', 'نگهداری و شستشو', 'شستشو', 'care']),
                $this->extractLabeledValue($text, ['نحوه نگهداری و شستشو', 'نگهداری و شستشو']),
                $this->detectCare($description !== '' ? $description : $short),
            ]),
            'dimensions' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['ابعاد', 'dimensions']),
                $this->extractLabeledValue($text, ['ابعاد']),
            ]),
            'other' => $this->firstNonEmpty([
                $this->exactAlias($exact, ['سایر توضیحات', 'توضیحات تکمیلی']),
                $this->extractLabeledValue($text, ['سایر توضیحات', 'توضیحات تکمیلی']),
                $this->prefixedLabeledValue($text, 'فرم لباس'),
            ]),
        ];

        return ['exact' => $exact, 'semantic' => $semantic];
    }

    private function valueForDefinition(string $title, array $sources): string
    {
        $canonical = $this->canonical($title);
        if ($canonical !== '' && !empty($sources['exact'][$canonical])) {
            return trim((string)$sources['exact'][$canonical]);
        }

        $key = $this->semanticKey($canonical);
        if ($key !== '' && !empty($sources['semantic'][$key])) {
            return trim((string)$sources['semantic'][$key]);
        }

        return '';
    }

    private function semanticKey(string $canonical): string
    {
        $aliases = [
            'brand' => ['برند', 'brand'],
            'usage' => ['موارداستفاده', 'کاربرد', 'استایل'],
            'audience' => ['مناسببرای', 'مخاطب', 'گروهسنی'],
            'other' => ['سایرتوضیحات', 'توضیحاتتکمیلی'],
            'material' => ['جنس', 'متریال', 'پارچه', 'material', 'fabric'],
            'color' => ['رنگ', 'color'],
            'size' => ['سایز', 'اندازه', 'سایزبندی', 'size'],
            'dimensions' => ['ابعاد', 'dimensions'],
            'care' => ['نحوهینگهداریوشستشو', 'نحوهنگهداریوشستشو', 'نگهداریوشستشو', 'شستشو', 'care'],
        ];
        foreach ($aliases as $key => $names) {
            foreach ($names as $name) {
                if ($canonical === $this->canonical($name)) {
                    return $key;
                }
            }
        }
        return '';
    }

    private function exactAlias(array $exact, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $key = $this->canonical($alias);
            if ($key !== '' && !empty($exact[$key])) {
                return trim((string)$exact[$key]);
            }
        }
        return '';
    }

    private function extractLabeledValue(string $text, array $labels): string
    {
        $lines = preg_split('/\R+/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            foreach ($labels as $label) {
                $pattern = '/^\s*' . preg_quote($label, '/') . '\s*[:：\-–]\s*(.+)$/u';
                if (preg_match($pattern, $line, $match)) {
                    return trim((string)$match[1]);
                }
            }
        }
        return '';
    }

    private function prefixedLabeledValue(string $text, string $label): string
    {
        $value = $this->extractLabeledValue($text, [$label]);
        return $value !== '' ? $label . ': ' . $value : '';
    }

    private function detectAudience(string $text): string
    {
        $normalized = $this->normalize($text);
        if (preg_match('/(زنانه|بانوان|خانم|دخترانه)/u', $normalized)) {
            return 'بانوان';
        }
        if (preg_match('/(مردانه|آقایان|آقا|پسرانه)/u', $normalized)) {
            return 'آقایان';
        }
        if (preg_match('/(بچگانه|کودک|نوزاد)/u', $normalized)) {
            return 'کودکان';
        }
        return '';
    }

    private function detectUsage(string $text): string
    {
        $matches = [];
        foreach (['اسپرت', 'روزمره', 'مجلسی', 'باشگاهی', 'ورزشی', 'اداری', 'مهمانی'] as $word) {
            if ($this->contains($text, $word)) {
                $matches[] = $word;
            }
        }
        return implode('، ', array_values(array_unique($matches)));
    }

    private function detectSize(string $text): string
    {
        if (preg_match('/(فری[\s‌-]*سایز[^\n،؛.]*)/u', $text, $match)) {
            return trim((string)$match[1]);
        }
        if (preg_match('/سایز(?:های)?\s*([0-9۰-۹]+)\s*(?:تا|الی|-)\s*([0-9۰-۹]+)/u', $text, $match)) {
            return 'سایز ' . $match[1] . ' تا ' . $match[2];
        }
        return '';
    }

    private function detectCare(string $text): string
    {
        $lines = preg_split('/\R+/u', $text) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            $line = trim((string)$line, " \t\n\r\0\x0B•-*–—");
            if ($line === '') {
                continue;
            }
            if (preg_match('/(شستشو|آب سرد|درجه|پشت[‌ -]?ورو|سفیدکننده|اتو|خشک)/u', $line)) {
                if (preg_match('/^(راهنمای|نحوه).*(شستشو|نگهداری)$/u', $line)) {
                    continue;
                }
                $parts[] = $line;
            }
        }
        $parts = array_values(array_unique($parts));
        return $parts ? implode('؛ ', array_slice($parts, 0, 6)) : '';
    }

    private function detectPhrase(string $text, array $phrases): string
    {
        foreach ($phrases as $phrase) {
            if ($this->contains($text, $phrase)) {
                return $phrase;
            }
        }
        return '';
    }

    private function contains(string $text, string $needle): bool
    {
        $text = $this->normalize($text);
        $needle = $this->normalize($needle);
        if ($needle === '') {
            return false;
        }
        if (function_exists('mb_strpos')) {
            return mb_strpos($text, $needle, 0, 'UTF-8') !== false;
        }
        return strpos($text, $needle) !== false;
    }

    private function existingDefinitionValue(array $definition): string
    {
        $value = $definition['value'] ?? null;
        if (is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }

        $selected = $definition['selected_values'] ?? null;
        if (!is_array($selected)) {
            return '';
        }
        $values = [];
        foreach ($selected as $item) {
            if (is_scalar($item)) {
                $candidate = trim((string)$item);
            } elseif (is_array($item)) {
                $candidate = trim((string)($item['title'] ?? $item['name'] ?? $item['value'] ?? ''));
            } else {
                $candidate = '';
            }
            if ($candidate !== '') {
                $values[] = $candidate;
            }
        }
        return implode('، ', array_values(array_unique($values)));
    }

    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function normalize(string $text): string
    {
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    private function canonical(string $text): string
    {
        $text = $this->normalize($text);
        $text = str_replace("\u{200C}", '', $text);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
    }

    private function limitText(string $text, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }
}
