<?php

/**
 * Generates independent BAJI product images through Arena API when configured, with OpenAI fallback.
 * Secrets are read only from server environment variables; nothing is persisted here.
 */
class ProductImageGenerator
{
    private WooCommerceClient $wc;
    private ChatImageService $images;

    public function __construct(?WooCommerceClient $wc = null, ?ChatImageService $images = null)
    {
        $this->wc = $wc ?? new WooCommerceClient();
        $this->images = $images ?? new ChatImageService();
    }

    public function generate(array $arguments): array
    {
        $productName = trim((string)($arguments['product_name'] ?? ''));
        if ($productName === '') throw new RuntimeException('product_name is required.');
        $productReference = $this->referenceUrl($this->referenceInput($arguments, 'product_reference'), 'product_reference_image');
        $faceInput = $this->referenceInput($arguments, 'face_reference');
        if (!$faceInput) {
            $storedFaceReference = trim((string)getSetting('baji_face_reference_url', ''));
            if ($storedFaceReference !== '') $faceInput = $storedFaceReference;
        }
        $faceReference = $faceInput ? $this->referenceUrl($faceInput, 'face_reference_image') : $productReference;
        $count = isset($arguments['count']) ? (int)$arguments['count'] : 7;
        if ($count < 1 || $count > 10) throw new RuntimeException('count must be between 1 and 10.');
        $aspectRatio = trim((string)($arguments['aspect_ratio'] ?? '9:16')) ?: '9:16';
        if (!in_array($aspectRatio, ['9:16', '16:9', '1:1'], true)) throw new RuntimeException('aspect_ratio must be 9:16, 16:9, or 1:1.');
        $instructions = trim((string)($arguments['instructions'] ?? ''));
        $wordpressUpload = (bool)($arguments['wordpress_upload'] ?? false);
        $arenaKey = trim((string)getenv('ARENA_API_KEY'));
        if ($arenaKey === '') $arenaKey = trim((string)getSetting('arena_api_key', ''));
        $openaiKey = trim((string)getenv('OPENAI_API_KEY'));
        if ($openaiKey === '') $openaiKey = trim((string)getSetting('openai_api_key', ''));
        if ($openaiKey === '' && function_exists('wcAgentOpenAiKeyFromSession')) $openaiKey = wcAgentOpenAiKeyFromSession();
        $provider = $arenaKey !== '' ? 'arena' : 'openai';
        if ($arenaKey === '' && $openaiKey === '') {
            throw new RuntimeException('ARENA_API_KEY or OPENAI_API_KEY must be configured on the server.');
        }

        $jobs = [];
        for ($index = 1; $index <= $count; $index++) {
            $jobs[] = $provider === 'arena'
                ? $this->runArenaIndependentJob($arenaKey, $productName, $productReference, $faceReference, $aspectRatio, $instructions, $wordpressUpload, $index, $count)
                : $this->runIndependentJob($openaiKey, $productName, $productReference, $faceReference, $aspectRatio, $instructions, $wordpressUpload, $index, $count);
        }
        if (count($jobs) !== $count) throw new RuntimeException('Image generation did not return the requested number of independent files.');
        return ['success'=>true,'provider'=>$provider,'product_name'=>$productName,'count'=>$count,'aspect_ratio'=>$aspectRatio,'wordpress_upload'=>$wordpressUpload,'files'=>$jobs];
    }

    public function generateSingle(array $arguments, int $index, int $count = 7): array
    {
        $productName = trim((string)($arguments['product_name'] ?? ''));
        if ($productName === '') throw new RuntimeException('product_name is required.');
        if ($index < 1 || $index > 10 || $count < 1 || $count > 10 || $index > $count) {
            throw new RuntimeException('Invalid image job index/count.');
        }

        $productReference = $this->referenceUrl($this->referenceInput($arguments, 'product_reference'), 'product_reference_image');
        $faceInput = $this->referenceInput($arguments, 'face_reference');
        if (!$faceInput) {
            $storedFaceReference = trim((string)getSetting('baji_face_reference_url', ''));
            if ($storedFaceReference !== '') $faceInput = $storedFaceReference;
        }
        $faceReference = $faceInput ? $this->referenceUrl($faceInput, 'face_reference_image') : $productReference;
        $aspectRatio = trim((string)($arguments['aspect_ratio'] ?? '9:16')) ?: '9:16';
        if (!in_array($aspectRatio, ['9:16', '16:9', '1:1'], true)) throw new RuntimeException('Invalid aspect ratio.');
        $instructions = trim((string)($arguments['instructions'] ?? ''));
        $wordpressUpload = (bool)($arguments['wordpress_upload'] ?? false);

        $arenaKey = trim((string)getenv('ARENA_API_KEY'));
        if ($arenaKey === '') $arenaKey = trim((string)getSetting('arena_api_key', ''));
        $openaiKey = trim((string)getenv('OPENAI_API_KEY'));
        if ($openaiKey === '') $openaiKey = trim((string)getSetting('openai_api_key', ''));
        if ($openaiKey === '' && function_exists('wcAgentOpenAiKeyFromSession')) $openaiKey = wcAgentOpenAiKeyFromSession();
        if ($arenaKey === '' && $openaiKey === '') {
            throw new RuntimeException('ARENA_API_KEY or OPENAI_API_KEY must be configured on the server.');
        }

        $provider = $arenaKey !== '' ? 'arena' : 'openai';
        $job = $provider === 'arena'
            ? $this->runArenaIndependentJob($arenaKey, $productName, $productReference, $faceReference, $aspectRatio, $instructions, $wordpressUpload, $index, $count)
            : $this->runIndependentJob($openaiKey, $productName, $productReference, $faceReference, $aspectRatio, $instructions, $wordpressUpload, $index, $count);

        return ['success'=>true, 'provider'=>$provider, 'job'=>$job];
    }

    public function createProductWithImages(array $arguments): array
    {
        $name = trim((string)($arguments['product_name'] ?? ''));
        if ($name === '') throw new RuntimeException('product_name is required.');
        $price = trim((string)($arguments['price'] ?? ''));
        if ($price === '' || !is_numeric($price) || (float)$price < 0) throw new RuntimeException('price must be a non-negative number.');
        $status = trim((string)($arguments['status'] ?? 'publish')) ?: 'publish';
        if (!in_array($status, ['draft','pending','publish'], true)) throw new RuntimeException('status must be draft, pending, or publish.');
        $arguments['wordpress_upload'] = true;
        $generated = $this->generate($arguments);
        $images = [];
        foreach ($generated['files'] as $job) {
            $id = (int)($job['wordpress_media']['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Generated WordPress media ID is missing.');
            $images[] = ['id'=>$id];
        }
        $product = ['name'=>$name,'type'=>'simple','status'=>$status,'regular_price'=>$price,'images'=>$images];
        foreach (['description','short_description','sku'] as $key) if (isset($arguments[$key]) && trim((string)$arguments[$key]) !== '') $product[$key]=(string)$arguments[$key];
        if (isset($arguments['manage_stock'])) $product['manage_stock']=(bool)$arguments['manage_stock'];
        if (isset($arguments['stock_quantity'])) $product['stock_quantity']=(int)$arguments['stock_quantity'];
        $created = $this->wc->createProduct($product);
        $code = (int)($created['status'] ?? 0);
        if ($code < 200 || $code >= 300) throw new RuntimeException('WooCommerce product creation failed.');
        apiLogActivity('mcp_create_product_with_ai_images', $name, 'count=' . count($images) . ' status=' . $status);
        return ['success'=>true,'generated'=>$generated,'product'=>$created['data'] ?? $created];
    }

    private function runIndependentJob(string $apiKey, string $productName, string $productReference, string $faceReference, string $aspectRatio, string $instructions, bool $wordpressUpload, int $index, int $count): array
    {
        $poses = ['natural full-body front three-quarter standing pose','natural walking pose, full body','relaxed side three-quarter pose, full body','editorial standing pose with one hand relaxed, full body','natural seated or leaning fashion pose while keeping the garment fully visible','back three-quarter fashion pose with face naturally visible','confident straight-on full-body catalog pose','dynamic but realistic street-style full-body pose','minimal studio full-body pose with relaxed arms','natural turning pose, one single camera view'];
        $pose = $poses[($index - 1) % count($poses)];
        $prompt = "Create exactly ONE final product photograph for BAJI.\nProduct: {$productName}.\nThis is independent job {$index} of {$count}; output ONE image only, ONE frame only, ONE camera view only, ONE pose only.\nPose for this job: {$pose}.\nUse the first reference image as the authoritative garment reference and preserve it exactly: color, fabric appearance, silhouette/form, stitching, seams, pockets, collar, hood, buttons/zippers, trims, proportions, prints and every visible construction detail. Do not redesign the garment.\nUse the second reference image as the face/identity reference and keep the same model identity and facial features.\nCompose for {$aspectRatio}. Photorealistic professional fashion photography, natural anatomy, realistic fabric texture, clean lighting.\nSTRICTLY FORBIDDEN: collage, grid, contact sheet, diptych, triptych, split screen, multiple panels, multiple views, before/after layout, duplicated person, or more than one photo in the output.\n";
        if ($instructions !== '') $prompt .= "Additional instructions: {$instructions}\n";
        $size = $aspectRatio === '16:9' ? '1536x1024' : ($aspectRatio === '1:1' ? '1024x1024' : '1024x1536');
        $agentModel = trim((string)getenv('OPENAI_IMAGE_AGENT_MODEL')) ?: 'gpt-5.6-luna';
        $imageModel = trim((string)getenv('OPENAI_IMAGE_MODEL')) ?: 'gpt-image-2';
        $payload = ['model'=>$agentModel,'store'=>false,'input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>$prompt],['type'=>'input_image','image_url'=>$productReference,'detail'=>'high'],['type'=>'input_image','image_url'=>$faceReference,'detail'=>'high']]]],'tools'=>[['type'=>'image_generation','action'=>'edit','model'=>$imageModel,'quality'=>'high','size'=>$size,'output_format'=>'png','input_fidelity'=>'high']],'tool_choice'=>['type'=>'image_generation']];
        $response = $this->postJson('https://api.openai.com/v1/responses', $apiKey, $payload);
        $base64 = $this->extractImageBase64($response);
        if ($aspectRatio !== '1:1') $base64 = $this->cropToExactAspect($base64, $aspectRatio);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $productName);
        $slug = trim((string)$slug, '-_') ?: 'baji-product';
        $imported = $this->images->import(['filename'=>$slug . '-image-' . $index . '.png','base64'=>$base64]);
        $public = $imported; unset($public['local_path']);
        $result = ['job'=>$index,'file'=>$public];
        if ($wordpressUpload) {
            $media = $this->wc->uploadMedia($imported['local_path'], $imported['filename']);
            $status = (int)($media['status'] ?? 0);
            if ($status < 200 || $status >= 300 || !is_array($media['data'] ?? null)) throw new RuntimeException('WordPress Media upload failed for independent job ' . $index . '.');
            $result['wordpress_media'] = $media['data'];
        }
        apiLogActivity('mcp_generate_product_image', $productName, 'job=' . $index . '/' . $count . ' wordpress_upload=' . ($wordpressUpload ? 'true' : 'false'));
        return $result;
    }

    private function runArenaIndependentJob(string $apiKey, string $productName, string $productReference, string $faceReference, string $aspectRatio, string $instructions, bool $wordpressUpload, int $index, int $count): array
    {
        $poses = ['natural full-body front three-quarter standing pose','natural walking pose, full body','relaxed side three-quarter pose, full body','editorial standing pose with one hand relaxed, full body','natural seated or leaning fashion pose while keeping the garment fully visible','back three-quarter fashion pose with face naturally visible','confident straight-on full-body catalog pose','dynamic but realistic street-style full-body pose','minimal studio full-body pose with relaxed arms','natural turning pose, one single camera view'];
        $pose = $poses[($index - 1) % count($poses)];
        $prompt = "Create exactly ONE final product photograph for BAJI.
Product: {$productName}.
This is independent job {$index} of {$count}; output ONE image only, ONE frame only, ONE camera view only, ONE pose only.
Pose for this job: {$pose}.
Use image 1 as the authoritative garment reference and preserve it exactly: color, fabric appearance, silhouette/form, stitching, seams, pockets, collar, hood, buttons/zippers, trims, proportions, prints and every visible construction detail. Do not redesign the garment.
Use image 2 as the face/identity reference and keep the same model identity and facial features.
Compose for {$aspectRatio}. Photorealistic professional fashion photography, natural anatomy, realistic fabric texture, clean lighting.
STRICTLY FORBIDDEN: collage, grid, contact sheet, diptych, triptych, split screen, multiple panels, multiple views, before/after layout, duplicated person, or more than one photo in the output.
";
        if ($instructions !== '') $prompt .= "Additional instructions: {$instructions}
";
        $size = $aspectRatio === '16:9' ? '1536x1024' : ($aspectRatio === '1:1' ? '1024x1024' : '1024x1536');
        $model = trim((string)getenv('ARENA_IMAGE_MODEL'));
        if ($model === '') $model = trim((string)getSetting('arena_image_model', 'gpt-image-1.5')) ?: 'gpt-image-1.5';

        $productFile = $this->downloadReferenceImage($productReference, 'arena-product-reference');
        $faceFile = $faceReference === $productReference
            ? $productFile
            : $this->downloadReferenceImage($faceReference, 'arena-face-reference');

        try {
            $response = $this->postArenaImageEdit($apiKey, $model, $prompt, $size, $productFile, $faceFile);
        } finally {
            @unlink($productFile);
            if ($faceFile !== $productFile) @unlink($faceFile);
        }

        $base64 = $this->extractArenaImageBase64($response);
        if ($aspectRatio !== '1:1') $base64 = $this->cropToExactAspect($base64, $aspectRatio);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $productName);
        $slug = trim((string)$slug, '-_') ?: 'baji-product';
        $imported = $this->images->import(['filename'=>$slug . '-image-' . $index . '.png','base64'=>$base64]);
        $public = $imported; unset($public['local_path']);
        $result = ['job'=>$index,'provider'=>'arena','model'=>$model,'file'=>$public];
        if ($wordpressUpload) {
            $media = $this->wc->uploadMedia($imported['local_path'], $imported['filename']);
            $status = (int)($media['status'] ?? 0);
            if ($status < 200 || $status >= 300 || !is_array($media['data'] ?? null)) {
                throw new RuntimeException('WordPress Media upload failed for Arena independent job ' . $index . '.');
            }
            $result['wordpress_media'] = $media['data'];
        }
        apiLogActivity('mcp_generate_product_image', $productName, 'provider=arena model=' . $model . ' job=' . $index . '/' . $count . ' wordpress_upload=' . ($wordpressUpload ? 'true' : 'false'));
        return $result;
    }

    private function downloadReferenceImage(string $url, string $label): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'baji_arena_');
        if ($tmp === false) throw new RuntimeException('Could not create a temporary file for ' . $label . '.');
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            throw new RuntimeException('Could not open a temporary file for ' . $label . '.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh); @unlink($tmp);
            throw new RuntimeException('Could not initialize reference image download.');
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE=>$fh, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
            CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>60, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT=>'BAJI-WC-Manager/ArenaImageReference'
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        $size = is_file($tmp) ? (int)filesize($tmp) : 0;
        if ($ok === false || $status < 200 || $status >= 300 || $size < 1 || $size > 25 * 1024 * 1024) {
            @unlink($tmp);
            throw new RuntimeException('Could not download ' . $label . ': ' . ($error ?: ('HTTP ' . $status)));
        }
        if ($type !== '' && stripos($type, 'image/') !== 0) {
            @unlink($tmp);
            throw new RuntimeException($label . ' did not resolve to an image.');
        }
        return $tmp;
    }

    private function postArenaImageEdit(string $apiKey, string $model, string $prompt, string $size, string $productFile, string $faceFile): array
    {
        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'response_format' => 'b64_json',
            'image[0]' => new CURLFile($productFile, 'image/png', 'product-reference.png'),
            'image[1]' => new CURLFile($faceFile, 'image/png', 'face-reference.png'),
        ];
        $ch = curl_init('https://api.preview.arena.ai/v1/images/edits');
        if ($ch === false) throw new RuntimeException('Could not initialize Arena image request.');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$fields,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>240,
            CURLOPT_HTTPHEADER=>[
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_USERAGENT=>'BAJI-WC-Manager/ArenaProductImageGenerator'
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Arena image request failed: ' . ($error ?: 'network error'));
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new RuntimeException('Arena returned invalid JSON.');
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Arena image generation failed: ' . (string)($decoded['error']['message'] ?? ('HTTP ' . $status)));
        }
        return $decoded;
    }

    private function extractArenaImageBase64(array $response): string
    {
        $item = is_array($response['data'][0] ?? null) ? $response['data'][0] : [];
        $base64 = trim((string)($item['b64_json'] ?? $item['base64'] ?? ''));
        if ($base64 !== '') return $base64;
        $url = trim((string)($item['url'] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $binary = @file_get_contents($url);
            if (is_string($binary) && $binary !== '') return base64_encode($binary);
        }
        throw new RuntimeException('Arena response did not contain image data.');
    }

    private function referenceInput(array $arguments, string $prefix)
    {
        $urlKey=$prefix . '_image'; $fileKey=$prefix . '_file'; $refsKey=$prefix . '_openaiFileIdRefs';
        if (isset($arguments[$fileKey])) {
            $file=$arguments[$fileKey];
            if (is_array($file)) return ['download_url'=>$file['download_url'] ?? $file['download_link'] ?? '', 'url'=>$file['url'] ?? '', 'image_url'=>$file['image_url'] ?? ''];
            if (is_string($file) && preg_match('#^https?://#i', trim($file))) return trim($file);
        }
        $refs=$arguments[$refsKey] ?? [];
        if (is_array($refs) && isset($refs[0]) && is_array($refs[0])) return ['download_url'=>$refs[0]['download_link'] ?? $refs[0]['download_url'] ?? '', 'url'=>$refs[0]['url'] ?? '', 'image_url'=>$refs[0]['image_url'] ?? ''];
        return $arguments[$urlKey] ?? null;
    }

    private function referenceUrl($value, string $field): string
    {
        if (is_string($value)) $url = trim($value);
        elseif (is_array($value)) $url = trim((string)($value['download_url'] ?? $value['url'] ?? $value['image_url'] ?? ''));
        else $url = '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) throw new RuntimeException($field . ' must be a public/temporary http(s) image URL or a hydrated file object containing download_url.');
        return $url;
    }

    private function postJson(string $url, string $apiKey, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Could not encode OpenAI image request.');
        $ch = curl_init($url); if ($ch === false) throw new RuntimeException('Could not initialize OpenAI request.');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer ' . $apiKey],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'BAJI-WC-Manager/ProductImageGenerator']);
        $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
        if ($raw === false) throw new RuntimeException('OpenAI image request failed: ' . ($error ?: 'network error'));
        $decoded = json_decode((string)$raw, true); if (!is_array($decoded)) throw new RuntimeException('OpenAI returned invalid JSON.');
        if ($status < 200 || $status >= 300) throw new RuntimeException('OpenAI image generation failed: ' . (string)($decoded['error']['message'] ?? ('HTTP ' . $status)));
        return $decoded;
    }

    private function extractImageBase64(array $response): string
    {
        foreach (($response['output'] ?? []) as $item) if (is_array($item) && ($item['type'] ?? '') === 'image_generation_call' && trim((string)($item['result'] ?? '')) !== '') return trim((string)$item['result']);
        throw new RuntimeException('OpenAI response did not contain an image_generation_call result.');
    }

    private function cropToExactAspect(string $base64, string $aspectRatio): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) throw new RuntimeException('PHP GD is required to enforce exact ' . $aspectRatio . ' output files.');
        $binary = base64_decode($base64, true); $src = $binary !== false ? @imagecreatefromstring($binary) : false;
        if ($src === false) throw new RuntimeException('Could not decode generated image for aspect-ratio crop.');
        $w=imagesx($src); $h=imagesy($src); [$rw,$rh]=array_map('intval',explode(':',$aspectRatio,2)); $target=$rw/$rh; $current=$w/$h;
        if ($current > $target) { $cropH=$h; $cropW=max(1,(int)round($h*$target)); $x=(int)floor(($w-$cropW)/2); $y=0; }
        else { $cropW=$w; $cropH=max(1,(int)round($w/$target)); $x=0; $y=(int)floor(($h-$cropH)/2); }
        $cropped=imagecrop($src,['x'=>$x,'y'=>$y,'width'=>$cropW,'height'=>$cropH]); imagedestroy($src);
        if ($cropped===false) throw new RuntimeException('Could not crop generated image to exact ' . $aspectRatio . '.');
        ob_start(); imagepng($cropped,null,6); $png=ob_get_clean(); imagedestroy($cropped);
        if (!is_string($png)||$png==='') throw new RuntimeException('Could not encode cropped image.');
        return base64_encode($png);
    }
}
