from pathlib import Path

path = Path('includes/BasalamSync.php')
text = path.read_text(encoding='utf-8')

old_payload = """        $payload = $this->buildProductPayload(
            $product,
            $variations,
            (int)$categoryMap['basalam_category_id'],
            $creating
        );

        if ($creating && $this->settingBool('basalam_sync_images', true)) {
"""
new_payload = """        $payload = $this->buildProductPayload(
            $product,
            $variations,
            (int)$categoryMap['basalam_category_id'],
            $creating
        );

        if ($this->settingBool('basalam_sync_attributes', true)) {
            $attributeMapper = new BasalamAttributeMapper($this->basalam);
            $attributeResult = $attributeMapper->build(
                $product,
                $variations,
                (int)$categoryMap['basalam_category_id'],
                $creating ? null : (int)($map['basalam_product_id'] ?? 0)
            );
            if (!empty($attributeResult['attributes'])) {
                $payload['product_attribute'] = array_values($attributeResult['attributes']);
            }
            if (!empty($attributeResult['warnings'])) {
                $warnings = array_merge($warnings, $attributeResult['warnings']);
            }
        }

        if ($creating && $this->settingBool('basalam_sync_images', true)) {
"""
if old_payload not in text:
    raise SystemExit('payload insertion point not found')
text = text.replace(old_payload, new_payload, 1)

old_hash = """                'categories' => $product['categories'] ?? [],
                'tags' => $product['tags'] ?? [],
                'images' => array_map(
"""
new_hash = """                'categories' => $product['categories'] ?? [],
                'tags' => $product['tags'] ?? [],
                'attributes' => $product['attributes'] ?? [],
                'dimensions' => $product['dimensions'] ?? [],
                'images' => array_map(
"""
if old_hash not in text:
    raise SystemExit('hash product insertion point not found')
text = text.replace(old_hash, new_hash, 1)

old_settings = """                'unmanaged_stock' => getSetting('basalam_unmanaged_stock', '1'),
                'sync_images' => getSetting('basalam_sync_images', '1'),
"""
new_settings = """                'unmanaged_stock' => getSetting('basalam_unmanaged_stock', '1'),
                'sync_images' => getSetting('basalam_sync_images', '1'),
                'sync_attributes' => getSetting('basalam_sync_attributes', '1'),
                'attribute_sync_version' => '1',
"""
if old_settings not in text:
    raise SystemExit('hash settings insertion point not found')
text = text.replace(old_settings, new_settings, 1)

path.write_text(text, encoding='utf-8')
