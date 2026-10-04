<?php

class ProductVisualQualityService
{
    private ProductWorkflowRepository $repo;
    private ProductCategoryRuleService $rules;

    public function __construct(
        ?ProductWorkflowRepository $repo = null,
        ?ProductCategoryRuleService $rules = null
    ) {
        $this->repo = $repo ?? new ProductWorkflowRepository();
        $this->rules = $rules ?? new ProductCategoryRuleService();
    }

    public function run(int $jobId): array
    {
        $job = $this->repo->get($jobId);
        $images = $this->repo->images($jobId);
        $required = max(1, (int)getSetting('required_product_images_count', '7'));
        if (count($images) !== $required) {
            throw new RuntimeException("Visual QC به دقیقاً {$required} تصویر نیاز دارد.");
        }
        foreach ($images as $image) {
            if (($image['qc_status'] ?? '') !== 'technical_pass') {
                throw new RuntimeException('قبل از Visual QC همه تصاویر باید QC فنی را پاس کنند.');
            }
        }

        $analysis = (array)$job['analysis_json'];
        $categoryRules = (array)($job['category_rules_json'] ?? []);
        if (!$categoryRules) {
            $categoryRules = $this->rules->resolve($job, $analysis);
            $this->repo->update($jobId, ['category_rules_json' => $categoryRules]);
        }
        $apiKey = wcAgentOpenAiKeyFromSession();

        if ($apiKey === '') {
            $summary = [
                'available' => false,
                'manual_review_required' => true,
                'all_visual_pass' => null,
                'threshold' => (int)getSetting('product_visual_qc_min_score', '85'),
                'category_rules' => $categoryRules,
                'reason' => 'OpenAI Vision API روی سرور فعال نیست؛ تأیید بصری در Preview انجام می‌شود.',
                'checked_at' => date('c'),
            ];
            $this->repo->update($jobId, [
                'workflow_status' => 'visual_manual_required',
                'current_step' => 'visual_quality',
                'progress_percent' => 72,
                'visual_qc_json' => $summary,
                'error_message' => null,
            ]);
            $this->repo->event(
                $jobId,
                'warning',
                'visual_qc_manual_required',
                $summary['reason']
            );
            return $summary;
        }

        try {
            $summary = $this->runVision($apiKey, $job, $images, $categoryRules);
        } catch (Throwable $e) {
            $summary = [
                'available' => false,
                'manual_review_required' => true,
                'all_visual_pass' => null,
                'threshold' => (int)getSetting('product_visual_qc_min_score', '85'),
                'category_rules' => $categoryRules,
                'reason' => 'Visual QC خودکار خطا داد؛ تأیید بصری در Preview اجباری باقی ماند.',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'checked_at' => date('c'),
            ];
            $this->repo->update($jobId, [
                'workflow_status' => 'visual_manual_required',
                'current_step' => 'visual_quality',
                'progress_percent' => 72,
                'visual_qc_json' => $summary,
                'error_message' => null,
            ]);
            $this->repo->event(
                $jobId,
                'warning',
                'visual_qc_unavailable',
                $summary['reason'],
                ['error' => $summary['error']]
            );
            return $summary;
        }

        $this->repo->update($jobId, [
            'workflow_status' => $summary['all_visual_pass'] ? 'visual_passed' : 'needs_review',
            'current_step' => $summary['all_visual_pass'] ? 'visual_quality' : 'needs_review',
            'progress_percent' => $summary['all_visual_pass'] ? 74 : 70,
            'visual_qc_json' => $summary,
            'error_message' => $summary['all_visual_pass']
                ? null : 'حداقل یک تصویر Visual QC را پاس نکرد.',
        ]);
        $this->repo->event(
            $jobId,
            $summary['all_visual_pass'] ? 'info' : 'warning',
            'visual_qc',
            $summary['all_visual_pass']
                ? 'All images passed visual QC.'
                : 'One or more images failed visual QC.',
            ['scores' => array_column($summary['images'], 'score', 'image_index')]
        );

        return $summary;
    }

    private function runVision(
        string $apiKey,
        array $job,
        array $images,
        array $categoryRules
    ): array {
        $threshold = max(50, min(100, (int)getSetting('product_visual_qc_min_score', '85')));
        $model = trim((string)getenv('OPENAI_PRODUCT_VISION_MODEL')) ?: 'gpt-5.6-luna';

        $checks = implode(', ', (array)$categoryRules['visual_checks']);
        $constraints = implode("\n- ", (array)$categoryRules['prompt_constraints']);

        $prompt = "You are a strict fashion e-commerce image quality inspector for BAJI.\n"
            . "Image 0 is the authoritative RAW PRODUCT REFERENCE. Images 1-7 are generated catalog images.\n"
            . "Compare every generated image against image 0. Garment fidelity is more important than beauty.\n"
            . "Category-specific checks: " . $checks . ".\n"
            . "Rules:\n- " . $constraints . "\n"
            . "Also inspect correct color, exact pockets/buttons/zippers/collar/hood/cuffs/prints, product scale, anatomy, "
            . "single person and single frame, no collage, no text, no obvious AI artifacts, full product visibility and photorealism.\n"
            . "For each generated image return integer score 0-100, critical=true if garment/color/construction is materially wrong, "
            . "short issue strings, and semantic labels pose_label, background_label, camera_angle.\n"
            . "Return JSON only with shape: {\"images\":[{\"image_index\":1,\"score\":0,\"critical\":false,"
            . "\"garment_fidelity\":0,\"color_fidelity\":0,\"construction_fidelity\":0,\"anatomy\":0,"
            . "\"photorealism\":0,\"single_frame\":true,\"product_visible\":true,\"pose_label\":\"\","
            . "\"background_label\":\"\",\"camera_angle\":\"\",\"issues\":[]}]}.";

        $content = [
            ['type' => 'input_text', 'text' => $prompt],
            ['type' => 'input_image', 'image_url' => (string)$job['raw_product_image_url'], 'detail' => 'high'],
        ];
        foreach ($images as $image) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => (string)$image['public_url'],
                'detail' => 'high',
            ];
        }

        $response = wcAgentOpenAiRequest($apiKey, [
            'model' => $model,
            'store' => false,
            'max_output_tokens' => 5000,
            'instructions' => 'Return valid JSON only. Be strict and deterministic.',
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
        ]);

        $decoded = $this->decodeJson(wcAgentOutputText($response));
        $rows = (array)($decoded['images'] ?? []);
        if (count($rows) !== count($images)) {
            throw new RuntimeException('Visual QC تعداد نتیجه کامل برنگرداند.');
        }

        $normalized = [];
        $allPass = true;
        $byIndex = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $idx = (int)($row['image_index'] ?? 0);
            if ($idx >= 1 && $idx <= count($images)) $byIndex[$idx] = $row;
        }

        foreach ($images as $image) {
            $idx = (int)$image['image_index'];
            if (!isset($byIndex[$idx])) {
                throw new RuntimeException("Visual QC نتیجه تصویر {$idx} را برنگرداند.");
            }
            $row = $byIndex[$idx];
            $score = max(0, min(100, (int)($row['score'] ?? 0)));
            $critical = !empty($row['critical']);
            $pass = $score >= $threshold && !$critical;

            $result = [
                'image_index' => $idx,
                'score' => $score,
                'pass' => $pass,
                'critical' => $critical,
                'garment_fidelity' => $this->score($row['garment_fidelity'] ?? 0),
                'color_fidelity' => $this->score($row['color_fidelity'] ?? 0),
                'construction_fidelity' => $this->score($row['construction_fidelity'] ?? 0),
                'anatomy' => $this->score($row['anatomy'] ?? 0),
                'photorealism' => $this->score($row['photorealism'] ?? 0),
                'single_frame' => !empty($row['single_frame']),
                'product_visible' => !empty($row['product_visible']),
                'pose_label' => ProductAiSeoService::plain($row['pose_label'] ?? '', 80),
                'background_label' => ProductAiSeoService::plain($row['background_label'] ?? '', 100),
                'camera_angle' => ProductAiSeoService::plain($row['camera_angle'] ?? '', 80),
                'issues' => array_values(array_filter(array_map(
                    static fn($v) => ProductAiSeoService::plain($v, 180),
                    (array)($row['issues'] ?? [])
                ))),
            ];

            $allPass = $allPass && $pass;
            $normalized[] = $result;
            $this->repo->upsertImage((int)$job['id'], $idx, array_merge($image, [
                'visual_qc_json' => $result,
            ]));
        }

        return [
            'available' => true,
            'manual_review_required' => true,
            'all_visual_pass' => $allPass,
            'threshold' => $threshold,
            'model' => $model,
            'category_rules' => $categoryRules,
            'images' => $normalized,
            'checked_at' => date('c'),
        ];
    }

    private function score($value): int
    {
        return max(0, min(100, (int)$value));
    }

    private function decodeJson(string $text): array
    {
        $text = trim($text);
        $first = strpos($text, '{');
        $last = strrpos($text, '}');
        if ($first !== false && $last !== false && $last >= $first) {
            $text = substr($text, $first, $last - $first + 1);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('پاسخ Visual QC JSON معتبر نبود.');
        }
        return $decoded;
    }
}
