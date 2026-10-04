<?php

class ProductCategoryRuleService
{
    private WooCommerceClient $wc;

    public function __construct(?WooCommerceClient $wc = null)
    {
        $this->wc = $wc ?? new WooCommerceClient();
    }

    public function resolve(array $job, array $analysis = []): array
    {
        $signals = [];
        foreach ([
            $job['product_name'] ?? '',
            $analysis['name'] ?? '',
            $analysis['focus_keyword'] ?? '',
        ] as $value) {
            $value = trim((string)$value);
            if ($value !== '') $signals[] = $value;
        }

        $categoryName = $this->categoryName((int)($job['category_id'] ?? 0));
        if ($categoryName !== '') $signals[] = $categoryName;

        $haystack = mb_strtolower(implode(' | ', $signals), 'UTF-8');
        $type = 'generic';

        $map = [
            'bag' => ['کیف','bag'],
            'pants' => ['شلوار','pants','trouser','jean'],
            'skirt' => ['دامن','skirt'],
            'coat' => ['کت','مانتو','بارانی','پالتو','اورشرت','coat','jacket','manteau'],
            'blouse' => ['شومیز','بلوز','پیراهن','تیشرت','تاپ','کراپ','blouse','shirt','top'],
            'set' => ['ست','دو تکه','سه تکه','set'],
            'overall' => ['اورال','overall','jumpsuit'],
        ];

        foreach ($map as $candidate => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($haystack, mb_strtolower($keyword, 'UTF-8')) !== false) {
                    $type = $candidate;
                    break 2;
                }
            }
        }

        return $this->rulesFor($type, $categoryName);
    }

    private function rulesFor(string $type, string $categoryName): array
    {
        $base = [
            'category_type' => $type,
            'category_name' => $categoryName,
            'prompt_constraints' => [
                'Preserve the exact garment color, silhouette, proportions and all visible construction details.',
                'Do not add or remove pockets, buttons, zippers, seams, prints, labels, trims or accessories attached to the product.',
                'Keep the complete product clearly visible and unobstructed.',
                'Use realistic fabric texture, anatomy, lighting and proportions.',
                'One model only, one frame only, no collage, no grid and no text.',
            ],
            'visual_checks' => [
                'color_fidelity',
                'silhouette_fidelity',
                'construction_details',
                'single_frame',
                'anatomy',
                'photorealism',
                'product_visibility',
            ],
            'styling' => 'Use simple styling that does not cover the product.',
        ];

        $specific = match ($type) {
            'bag' => [
                'styling' => 'Keep the bag scale realistic relative to the model. Preserve handle length, strap, buckle, closure, shape, quilting, folds and hardware. Do not enlarge the bag.',
                'visual_checks' => ['bag_scale','handle_and_strap','hardware_and_closure','shape_fidelity'],
            ],
            'pants' => [
                'styling' => 'Show the full pants from waistband to hem in most frames. Pair with simple varied tops. Do not hide the waistband or leg shape.',
                'visual_checks' => ['waistband','leg_shape','hem','full_length_visibility'],
            ],
            'skirt' => [
                'styling' => 'Preserve skirt length, pleats, slit, flare and waist construction. Keep the skirt fully visible.',
                'visual_checks' => ['skirt_length','pleats_or_slit','waist_shape','hem'],
            ],
            'coat' => [
                'styling' => 'Use a simple inner layer. Preserve coat length, collar or hood, pocket orientation, closure, cuffs and back construction.',
                'visual_checks' => ['length','pockets','closure','collar_or_hood','cuffs','back_construction'],
            ],
            'blouse' => [
                'styling' => 'Pair with tasteful varied pants or skirts. Keep neckline, sleeves, cuffs, hem and front details visible.',
                'visual_checks' => ['neckline','sleeves_and_cuffs','hem','front_details'],
            ],
            'set' => [
                'styling' => 'Keep every piece of the set visible and preserve the relationship, colors and proportions between pieces.',
                'visual_checks' => ['all_set_pieces_present','piece_color_match','piece_proportions'],
            ],
            'overall' => [
                'styling' => 'Show the full one-piece garment and preserve torso-to-leg proportions, waistline, straps or sleeves and hem.',
                'visual_checks' => ['full_length_visibility','waistline','top_to_bottom_proportion'],
            ],
            default => [],
        };

        if (isset($specific['styling'])) $base['styling'] = $specific['styling'];
        if (!empty($specific['visual_checks'])) {
            $base['visual_checks'] = array_values(array_unique(array_merge(
                $base['visual_checks'],
                $specific['visual_checks']
            )));
        }

        $base['prompt_constraints'][] = $base['styling'];
        return $base;
    }

    private function categoryName(int $categoryId): string
    {
        if ($categoryId < 1) return '';
        try {
            $res = $this->wc->getCategories(['include' => [$categoryId], 'per_page' => 10]);
            foreach ((array)($res['body'] ?? []) as $cat) {
                if ((int)($cat['id'] ?? 0) === $categoryId) {
                    return trim((string)($cat['name'] ?? ''));
                }
            }
        } catch (Throwable $e) {
        }
        return '';
    }
}
