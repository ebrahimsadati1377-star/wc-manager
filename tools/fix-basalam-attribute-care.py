from pathlib import Path

path = Path('includes/BasalamAttributeMapper.php')
text = path.read_text(encoding='utf-8')

old_detect = r'''    private function detectCare(string $text): string
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
'''
new_detect = r'''    private function detectCare(string $text): string
    {
        // Woo descriptions often keep several bullets inside one HTML block. Split on
        // sentence/list punctuation too, so a single washing keyword never causes the
        // whole marketing description to be copied into Basalam's care field.
        $segments = preg_split('/(?:\R+|[؛;]+|(?<=[.!؟])\s+)/u', $text) ?: [];
        $parts = [];
        foreach ($segments as $segment) {
            $segment = trim((string)$segment, " \t\n\r\0\x0B•-*–—");
            if ($segment === '') {
                continue;
            }
            if (!preg_match('/(شستشو|آب سرد|حداکثر\s*[۰-۹0-9]+\s*درجه|پشت[‌ -]?ورو|سفیدکننده|اتو|خشک[‌ -]?کن)/u', $segment)) {
                continue;
            }
            if (preg_match('/^(راهنمای|نحوه).*(شستشو|نگهداری)\s*:?$/u', $segment)) {
                continue;
            }
            $parts[] = $segment;
        }
        $parts = array_values(array_unique($parts));
        return $parts ? implode('؛ ', array_slice($parts, 0, 6)) : '';
    }
'''
if old_detect not in text:
    raise SystemExit('detectCare block not found')
text = text.replace(old_detect, new_detect, 1)

old_plain = r'''    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }
'''
new_plain = r'''    private function plainText(string $html): string
    {
        // Preserve HTML block boundaries before stripping tags. This keeps labeled
        // Woo fields and list items independently parseable.
        $html = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|li|ul|ol|h[1-6]|tr)>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }
'''
if old_plain not in text:
    raise SystemExit('plainText block not found')
text = text.replace(old_plain, new_plain, 1)

path.write_text(text, encoding='utf-8')
