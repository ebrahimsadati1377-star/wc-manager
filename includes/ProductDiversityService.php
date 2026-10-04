<?php

class ProductDiversityService
{
    private ProductWorkflowRepository $repo;

    public function __construct(?ProductWorkflowRepository $repo = null)
    {
        $this->repo = $repo ?? new ProductWorkflowRepository();
    }

    public function run(int $jobId): array
    {
        $job = $this->repo->get($jobId);
        $images = $this->repo->images($jobId);
        $required = max(1, (int)getSetting('required_product_images_count', '7'));
        if (count($images) !== $required) {
            throw new RuntimeException("Diversity Check به دقیقاً {$required} تصویر نیاز دارد.");
        }

        $hashes = [];
        foreach ($images as $image) {
            if (($image['qc_status'] ?? '') !== 'technical_pass') {
                throw new RuntimeException('قبل از Diversity Check همه تصاویر باید QC فنی را پاس کنند.');
            }
            $hashes[(int)$image['image_index']] = $this->dHash((string)$image['public_url']);
        }

        $pairs = [];
        $hardFailPairs = [];
        $semanticFailPairs = [];
        $semanticPenalty = 0;
        $maxSimilarity = 0.0;

        $count = count($images);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = (int)$images[$i]['image_index'];
                $b = (int)$images[$j]['image_index'];
                $distance = $this->hamming($hashes[$a], $hashes[$b]);
                $similarity = round(1 - ($distance / 64), 4);
                $maxSimilarity = max($maxSimilarity, $similarity);

                $visualA = (array)($images[$i]['visual_qc_json'] ?? []);
                $visualB = (array)($images[$j]['visual_qc_json'] ?? []);
                $samePose = $this->sameLabel($visualA['pose_label'] ?? '', $visualB['pose_label'] ?? '');
                $sameBackground = $this->sameLabel($visualA['background_label'] ?? '', $visualB['background_label'] ?? '');
                $sameAngle = $this->sameLabel($visualA['camera_angle'] ?? '', $visualB['camera_angle'] ?? '');

                if ($samePose && $sameBackground && $sameAngle) {
                    $semanticPenalty += 8;
                    $semanticFailPairs[] = ['a'=>$a,'b'=>$b,'reason'=>'pose_background_angle'];
                } elseif (($samePose && $sameAngle) || ($samePose && $sameBackground)) {
                    $semanticPenalty += 4;
                }

                $pair = [
                    'a' => $a,
                    'b' => $b,
                    'perceptual_similarity' => $similarity,
                    'same_pose' => $samePose,
                    'same_background' => $sameBackground,
                    'same_camera_angle' => $sameAngle,
                    'too_similar' => $similarity >= 0.94,
                ];
                $pairs[] = $pair;
                if ($pair['too_similar']) $hardFailPairs[] = $pair;
            }
        }

        $score = 100;
        foreach ($pairs as $pair) {
            if ($pair['perceptual_similarity'] >= 0.94) $score -= 25;
            elseif ($pair['perceptual_similarity'] >= 0.90) $score -= 10;
            elseif ($pair['perceptual_similarity'] >= 0.86) $score -= 4;
        }
        $score -= min(30, $semanticPenalty);
        $score = max(0, min(100, $score));

        $visual = (array)($job['visual_qc_json'] ?? []);
        $semanticAvailable = !empty($visual['available']);
        $minScore = max(50, min(100, (int)getSetting('product_diversity_min_score', '75')));
        $pass = !$hardFailPairs && ($semanticAvailable ? $score >= $minScore : true);

        $summary = [
            'all_diversity_pass' => $pass,
            'score' => $score,
            'threshold' => $minScore,
            'semantic_analysis_available' => $semanticAvailable,
            'semantic_review_required' => !$semanticAvailable,
            'max_perceptual_similarity' => $maxSimilarity,
            'too_similar_pairs' => $hardFailPairs,
            'semantic_similar_pairs' => $semanticFailPairs,
            'actionable_pairs' => array_values(array_merge($hardFailPairs, $semanticFailPairs)),
            'pairs' => $pairs,
            'checked_at' => date('c'),
        ];

        $this->repo->update($jobId, [
            'workflow_status' => $pass ? 'diversity_passed' : 'needs_review',
            'current_step' => $pass ? 'diversity' : 'needs_review',
            'progress_percent' => $pass ? 77 : 72,
            'diversity_qc_json' => $summary,
            'error_message' => $pass ? null : 'حداقل دو تصویر بیش از حد شبیه هستند.',
        ]);
        $this->repo->event(
            $jobId,
            $pass ? 'info' : 'warning',
            'diversity_qc',
            $pass ? 'Image diversity check passed.' : 'Image diversity check failed.',
            ['score' => $score, 'too_similar_pairs' => $hardFailPairs]
        );

        return $summary;
    }

    private function dHash(string $url): string
    {
        $raw = $this->download($url);
        $src = @imagecreatefromstring($raw);
        if ($src === false) throw new RuntimeException('تصویر برای Diversity قابل پردازش نیست.');

        $small = imagecreatetruecolor(9, 8);
        if ($small === false) {
            imagedestroy($src);
            throw new RuntimeException('ساخت تصویر کوچک برای Diversity ناموفق بود.');
        }

        imagecopyresampled(
            $small,
            $src,
            0, 0, 0, 0,
            9, 8,
            imagesx($src),
            imagesy($src)
        );
        imagedestroy($src);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($small, $x, $y);
                $right = imagecolorat($small, $x + 1, $y);
                $l = $this->gray($left);
                $r = $this->gray($right);
                $bits .= $l > $r ? '1' : '0';
            }
        }
        imagedestroy($small);
        return $bits;
    }

    private function hamming(string $a, string $b): int
    {
        $len = min(strlen($a), strlen($b));
        $distance = abs(strlen($a) - strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) $distance++;
        }
        return $distance;
    }

    private function gray(int $color): float
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        return (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
    }

    private function sameLabel($a, $b): bool
    {
        $a = mb_strtolower(trim((string)$a), 'UTF-8');
        $b = mb_strtolower(trim((string)$b), 'UTF-8');
        return $a !== '' && $b !== '' && $a === $b;
    }

    private function download(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('آدرس تصویر Diversity معتبر نیست.');
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $allowed = array_filter([
            strtolower((string)parse_url((string)getSetting('store_url', ''), PHP_URL_HOST)),
            'manage.bajistyle.ir',
        ]);
        if ($host === '' || !in_array($host, $allowed, true)) {
            throw new RuntimeException('دامنه تصویر برای Diversity مجاز نیست.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'BAJI-WC-Manager/Diversity',
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new RuntimeException('دریافت تصویر Diversity ناموفق بود: ' . ($error ?: 'HTTP ' . $status));
        }
        if (strlen($raw) > 12582912) {
            throw new RuntimeException('فایل تصویر برای Diversity بیش از حد بزرگ است.');
        }
        return $raw;
    }
}
