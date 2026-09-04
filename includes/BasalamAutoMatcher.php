<?php

class BasalamAutoMatcher
{
    private WooCommerceClient $wc;
    private BasalamClient $basalam;
    private PDO $db;

    public function __construct(?WooCommerceClient $wc = null, ?BasalamClient $basalam = null)
    {
        $this->wc = $wc ?? new WooCommerceClient();
        $this->basalam = $basalam ?? new BasalamClient();
        new BasalamSync($this->wc, $this->basalam);
        $this->db = Database::get();
    }

    public function run(): array
    {
        if (!$this->wc->isConfigured()) return $this->failure('اتصال ووکامرس تنظیم نشده است.');
        if (!$this->basalam->isConfigured()) return $this->failure('اتصال باسلام تنظیم نشده است.');

        $wooResult = $this->loadWooProducts();
        if (isset($wooResult['error'])) return $this->failure((string)$wooResult['error']);
        $basalamResult = $this->loadBasalamProducts();
        if (isset($basalamResult['error'])) return $this->failure((string)$basalamResult['error']);

        $wooProducts = $wooResult['products'];
        $basalamProducts = $basalamResult['products'];
        $existingMaps = $this->loadExistingMaps();

        $usedBasalamIds = [];
        foreach ($existingMaps as $map) {
            $id = (int)($map['basalam_product_id'] ?? 0);
            if ($id > 0) $usedBasalamIds[$id] = (int)$map['wc_product_id'];
        }

        $bySku = [];
        $byTitle = [];
        $byCompact = [];
        foreach ($basalamProducts as $product) {
            $id = (int)($product['id'] ?? 0);
            if ($id <= 0) continue;
            $sku = $this->normalizeSku($product['sku'] ?? '');
            if ($sku !== '') $bySku[$sku][] = $product;
            $title = $this->normalizeTitle((string)($product['name'] ?? $product['title'] ?? ''));
            if ($title !== '') {
                $byTitle[$title][] = $product;
                $byCompact[$this->compactTitle($title)][] = $product;
            }
        }

        $stats = [
            'woo_total' => count($wooProducts), 'basalam_total' => count($basalamProducts),
            'already_mapped' => 0, 'matched' => 0, 'matched_by_sku' => 0,
            'matched_by_title' => 0, 'matched_by_compact_title' => 0,
            'needs_review' => 0, 'not_found' => 0,
        ];
        $matched = []; $review = []; $notFound = [];

        foreach ($wooProducts as $woo) {
            $wcId = (int)($woo['id'] ?? 0);
            if ($wcId <= 0) continue;
            $existing = $existingMaps[$wcId] ?? null;
            if ($existing && (int)($existing['basalam_product_id'] ?? 0) > 0) {
                $stats['already_mapped']++;
                continue;
            }

            $candidate = null; $method = null; $ambiguous = [];
            $sku = $this->normalizeSku($woo['sku'] ?? '');
            if ($sku !== '') {
                $c = $this->availableCandidates($bySku[$sku] ?? [], $usedBasalamIds, $wcId);
                if (count($c) === 1) { $candidate = $c[0]; $method = 'sku'; }
                elseif (count($c) > 1) $ambiguous = $c;
            }

            $title = $this->normalizeTitle((string)($woo['name'] ?? ''));
            if ($candidate === null && !$ambiguous && $title !== '') {
                $c = $this->availableCandidates($byTitle[$title] ?? [], $usedBasalamIds, $wcId);
                if (count($c) === 1) { $candidate = $c[0]; $method = 'title'; }
                elseif (count($c) > 1) $ambiguous = $c;
            }
            if ($candidate === null && !$ambiguous && $title !== '') {
                $c = $this->availableCandidates($byCompact[$this->compactTitle($title)] ?? [], $usedBasalamIds, $wcId);
                if (count($c) === 1) { $candidate = $c[0]; $method = 'compact_title'; }
                elseif (count($c) > 1) $ambiguous = $c;
            }

            if ($candidate !== null) {
                $basalamId = (int)($candidate['id'] ?? 0);
                try {
                    $this->saveMatch($wcId, $basalamId, $method ?: 'title');
                } catch (Throwable $e) {
                    $stats['needs_review']++;
                    $review[] = ['wc_product_id'=>$wcId,'woo_name'=>(string)($woo['name']??''),'reason'=>'ذخیره مپ ناموفق بود: '.$e->getMessage(),'candidates'=>[]];
                    continue;
                }
                $usedBasalamIds[$basalamId] = $wcId;
                $stats['matched']++;
                if ($method === 'sku') $stats['matched_by_sku']++;
                elseif ($method === 'compact_title') $stats['matched_by_compact_title']++;
                else $stats['matched_by_title']++;
                if (count($matched) < 100) $matched[] = [
                    'wc_product_id'=>$wcId,'basalam_product_id'=>$basalamId,
                    'woo_name'=>(string)($woo['name']??''),
                    'basalam_name'=>(string)($candidate['name']??$candidate['title']??''),
                    'method'=>$method,
                ];
                continue;
            }

            if ($ambiguous) {
                $stats['needs_review']++;
                if (count($review) < 100) $review[] = [
                    'wc_product_id'=>$wcId,'woo_name'=>(string)($woo['name']??''),
                    'reason'=>'بیش از یک کاندیدای مطمئن پیدا شد.',
                    'candidates'=>array_map(fn(array $x)=>['id'=>(int)($x['id']??0),'name'=>(string)($x['name']??$x['title']??'')], array_slice($ambiguous,0,5)),
                ];
                continue;
            }

            $fuzzy = $this->findReviewCandidates($title, $basalamProducts, $usedBasalamIds);
            if ($fuzzy) {
                $stats['needs_review']++;
                if (count($review) < 100) $review[] = [
                    'wc_product_id'=>$wcId,'woo_name'=>(string)($woo['name']??''),
                    'reason'=>'فقط تطبیق مشابه پیدا شد؛ برای جلوگیری از مپ اشتباه خودکار نشد.','candidates'=>$fuzzy,
                ];
            } else {
                $stats['not_found']++;
                if (count($notFound) < 100) $notFound[] = ['wc_product_id'=>$wcId,'woo_name'=>(string)($woo['name']??''),'sku'=>(string)($woo['sku']??'')];
            }
        }

        logActivity('basalam_bulk_auto_match','catalog',sprintf(
            'Woo %d / Basalam %d | matched %d | already %d | review %d | not found %d',
            $stats['woo_total'],$stats['basalam_total'],$stats['matched'],$stats['already_mapped'],$stats['needs_review'],$stats['not_found']
        ));

        return ['success'=>true,'message'=>'تطبیق خودکار امن انجام شد. هیچ محصول جدیدی در باسلام ساخته نشد.','stats'=>$stats,'matched'=>$matched,'needs_review'=>$review,'not_found'=>$notFound];
    }

    private function loadWooProducts(): array
    {
        $products = [];
        for ($page=1; $page<=50; $page++) {
            $res = $this->wc->getProducts(['page'=>$page,'per_page'=>100,'orderby'=>'id','order'=>'asc']);
            if ($res['error']) return ['error'=>'خواندن محصولات Woo ناموفق بود: '.$res['error']];
            $batch = is_array($res['body'] ?? null) ? $res['body'] : [];
            foreach ($batch as $product) {
                if (!is_array($product)) continue;
                if (!in_array((string)($product['type']??''), ['simple','variable'], true)) continue;
                $products[] = $product;
            }
            $totalPages = max(1,(int)($res['headers']['total_pages']??1));
            if ($page >= $totalPages || count($batch) < 100) break;
        }
        return ['products'=>$products];
    }

    private function loadBasalamProducts(): array
    {
        $products = [];
        for ($page=1; $page<=50; $page++) {
            $res = $this->basalam->getVendorProducts(['page'=>$page,'per_page'=>100]);
            if ($res['error']) return ['error'=>'خواندن محصولات باسلام ناموفق بود: '.$res['error']];
            $body = $res['body'] ?? [];
            $batch = $body['data'] ?? $body['products'] ?? $body;
            if (!is_array($batch) || !$batch) break;
            $count = 0;
            foreach ($batch as $product) {
                if (!is_array($product)) continue;
                $count++;
                if ((int)($product['id']??0) > 0) $products[] = $product;
            }
            if ($count < 100) break;
        }
        return ['products'=>$products];
    }

    private function loadExistingMaps(): array
    {
        $rows = $this->db->query('SELECT * FROM basalam_product_map')->fetchAll();
        $maps=[];
        foreach ($rows as $row) $maps[(int)$row['wc_product_id']] = $row;
        return $maps;
    }

    private function saveMatch(int $wcProductId, int $basalamProductId, string $method): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO basalam_product_map (wc_product_id, basalam_product_id, last_wc_hash, sync_status, sync_error, last_synced_at)
             VALUES (:wc, :basalam, NULL, :status, :error, NULL)
             ON DUPLICATE KEY UPDATE basalam_product_id=VALUES(basalam_product_id), last_wc_hash=NULL, sync_status=VALUES(sync_status), sync_error=VALUES(sync_error), last_synced_at=NULL'
        );
        $stmt->execute(['wc'=>$wcProductId,'basalam'=>$basalamProductId,'status'=>'matched','error'=>'Auto-matched by '.$method.'; awaiting sync']);
    }

    private function availableCandidates(array $candidates, array $usedBasalamIds, int $wcId): array
    {
        return array_values(array_filter($candidates, function(array $c) use($usedBasalamIds,$wcId){
            $id=(int)($c['id']??0); return $id>0 && (!isset($usedBasalamIds[$id]) || (int)$usedBasalamIds[$id] === $wcId);
        }));
    }

    private function normalizeSku(mixed $sku): string
    {
        $sku=trim((string)$sku); return function_exists('mb_strtolower') ? mb_strtolower($sku,'UTF-8') : strtolower($sku);
    }

    private function normalizeTitle(string $title): string
    {
        $title=html_entity_decode(strip_tags($title),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $title=str_replace(["\u{200c}","\u{200d}","\u{200e}","\u{200f}",'ي','ى','ك','ة','ۀ'],['','','','','ی','ی','ک','ه','ه'],$title);
        $title=function_exists('mb_strtolower') ? mb_strtolower($title,'UTF-8') : strtolower($title);
        $title=preg_replace('/[^\p{L}\p{N}]+/u',' ',$title) ?? $title;
        return preg_replace('/\s+/u',' ',trim($title)) ?? trim($title);
    }

    private function compactTitle(string $title): string
    {
        return preg_replace('/\s+/u','',$title) ?? $title;
    }

    private function findReviewCandidates(string $wooTitle, array $basalamProducts, array $usedBasalamIds): array
    {
        if ($wooTitle === '' || (function_exists('mb_strlen') ? mb_strlen($wooTitle,'UTF-8') : strlen($wooTitle)) < 8) return [];
        $wooCompact=$this->compactTitle($wooTitle); $scored=[];
        foreach ($basalamProducts as $product) {
            $id=(int)($product['id']??0); if ($id<=0 || isset($usedBasalamIds[$id])) continue;
            $name=(string)($product['name']??$product['title']??'');
            $candidate=$this->normalizeTitle($name); if ($candidate==='') continue;
            similar_text($wooCompact,$this->compactTitle($candidate),$percent);
            if ($percent>=82.0) $scored[]=['id'=>$id,'name'=>$name,'score'=>round($percent,1)];
        }
        usort($scored,fn(array $a,array $b)=>$b['score']<=>$a['score']);
        return array_slice($scored,0,5);
    }

    private function failure(string $message): array
    {
        return ['success'=>false,'message'=>$message,'stats'=>[],'matched'=>[],'needs_review'=>[],'not_found'=>[]];
    }
}
