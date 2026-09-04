# Basalam bulk auto-match

The bulk matcher only stores WooCommerce → Basalam mappings. It never creates Basalam products.

Matching order:
1. unique exact SKU
2. unique normalized title
3. unique compact normalized title (spacing / half-space differences)
4. fuzzy candidates are report-only and require review

Already-mapped Basalam product IDs are never reassigned to another Woo product.
