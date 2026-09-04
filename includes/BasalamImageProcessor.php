<?php

class BasalamImageProcessor
{
    private const MAX_BYTES = 12582912; // 12 MB
    private const MAX_SIDE = 1600;
    private const JPEG_QUALITY = 92;

    public static function upload(BasalamClient $client, string $url): array
    {
        $download = self::downloadPublicRemoteFile($url, self::MAX_BYTES);
        if ($download['error'] !== null) {
            return self::failure($download['error']);
        }

        $source = $download['path'];
        $square = null;
        try {
            $prepared = self::makeSquare($source);
            if ($prepared['error'] !== null) {
                return self::failure($prepared['error']);
            }
            $square = $prepared['path'];
            return $client->uploadFile($square, 'product.photo');
        } finally {
            @unlink($source);
            if (is_string($square) && $square !== '' && $square !== $source) {
                @unlink($square);
            }
        }
    }

    private static function makeSquare(string $source): array
    {
        $info = @getimagesize($source);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return ['path' => '', 'error' => 'فایل دانلودشده تصویر معتبر نیست.'];
        }

        $width = (int)$info[0];
        $height = (int)$info[1];
        $side = min(self::MAX_SIDE, max($width, $height));
        if ($side <= 0) {
            return ['path' => '', 'error' => 'ابعاد تصویر معتبر نیست.'];
        }

        // Always create a square copy. The product photo is fitted proportionally
        // on a white canvas, so Basalam never has to stretch a portrait image.
        $dest = tempnam(sys_get_temp_dir(), 'basalam_square_');
        if ($dest === false) {
            return ['path' => '', 'error' => 'ساخت فایل موقت مربع برای باسلام ناموفق بود.'];
        }
        $jpg = $dest . '.jpg';
        @unlink($dest);

        if (class_exists('Imagick')) {
            try {
                $image = new Imagick($source);
                if (method_exists($image, 'autoOrientImage')) {
                    $image->autoOrientImage();
                }
                $image->setIteratorIndex(0);
                $image->setImageBackgroundColor('white');
                if (method_exists($image, 'mergeImageLayers')) {
                    $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                }

                $iw = $image->getImageWidth();
                $ih = $image->getImageHeight();
                $scale = min($side / max(1, $iw), $side / max(1, $ih), 1.0);
                $newW = max(1, (int)round($iw * $scale));
                $newH = max(1, (int)round($ih * $scale));
                $image->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);

                $canvas = new Imagick();
                $canvas->newImage($side, $side, new ImagickPixel('white'));
                $canvas->setImageFormat('jpeg');
                $canvas->setImageCompressionQuality(self::JPEG_QUALITY);
                $x = (int)floor(($side - $newW) / 2);
                $y = (int)floor(($side - $newH) / 2);
                $canvas->compositeImage($image, Imagick::COMPOSITE_OVER, $x, $y);
                $canvas->stripImage();
                $ok = $canvas->writeImage($jpg);
                $image->clear();
                $canvas->clear();

                if ($ok && is_file($jpg)) {
                    return ['path' => $jpg, 'error' => null];
                }
            } catch (Throwable $e) {
                @unlink($jpg);
                // Fall through to GD when available.
            }
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $bytes = @file_get_contents($source);
            $src = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
            if ($src === false) {
                @unlink($jpg);
                return ['path' => '', 'error' => 'خواندن تصویر برای تبدیل مربع ناموفق بود.'];
            }

            $iw = imagesx($src);
            $ih = imagesy($src);
            $scale = min($side / max(1, $iw), $side / max(1, $ih), 1.0);
            $newW = max(1, (int)round($iw * $scale));
            $newH = max(1, (int)round($ih * $scale));

            $canvas = imagecreatetruecolor($side, $side);
            if ($canvas === false) {
                imagedestroy($src);
                @unlink($jpg);
                return ['path' => '', 'error' => 'ساخت بوم مربع تصویر ناموفق بود.'];
            }
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            $x = (int)floor(($side - $newW) / 2);
            $y = (int)floor(($side - $newH) / 2);
            imagecopyresampled($canvas, $src, $x, $y, 0, 0, $newW, $newH, $iw, $ih);
            imageinterlace($canvas, true);
            $ok = imagejpeg($canvas, $jpg, self::JPEG_QUALITY);
            imagedestroy($src);
            imagedestroy($canvas);

            if ($ok && is_file($jpg)) {
                return ['path' => $jpg, 'error' => null];
            }
            @unlink($jpg);
            return ['path' => '', 'error' => 'ذخیره نسخه مربع تصویر ناموفق بود.'];
        }

        @unlink($jpg);
        return ['path' => '', 'error' => 'برای تبدیل تصویر باسلام، افزونه GD یا Imagick روی PHP لازم است.'];
    }

    private static function downloadPublicRemoteFile(string $url, int $maxBytes): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return ['path' => '', 'error' => 'آدرس تصویر معتبر نیست.'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['path' => '', 'error' => 'آدرس تصویر نباید شامل اطلاعات ورود باشد.'];
        }

        $scheme = strtolower((string)$parts['scheme']);
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            return ['path' => '', 'error' => 'پورت آدرس تصویر مجاز نیست.'];
        }

        $host = (string)$parts['host'];
        $ips = gethostbynamel($host);
        if (!$ips) {
            return ['path' => '', 'error' => 'دامنه تصویر قابل resolve نیست.'];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['path' => '', 'error' => 'دانلود تصویر از آدرس خصوصی/رزرو شده مجاز نیست.'];
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'basalam_img_');
        if ($tmpPath === false) {
            return ['path' => '', 'error' => 'ساخت فایل موقت برای تصویر ناموفق بود.'];
        }
        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            return ['path' => '', 'error' => 'باز کردن فایل موقت ناموفق بود.'];
        }

        $downloaded = 0;
        $tooLarge = false;
        $ch = curl_init();
        if ($ch === false) {
            fclose($fp);
            @unlink($tmpPath);
            return ['path' => '', 'error' => 'راه‌اندازی cURL برای تصویر ناموفق بود.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($fp, $maxBytes, &$downloaded, &$tooLarge) {
                $downloaded += strlen($chunk);
                if ($downloaded > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                return fwrite($fp, $chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($tmpPath);
            return ['path' => '', 'error' => $tooLarge ? 'حجم تصویر بیشتر از حد مجاز داخلی (۱۲ مگابایت) است.' : 'دانلود تصویر برای باسلام ناموفق بود: ' . ($error ?: ('HTTP ' . $status))];
        }

        return ['path' => $tmpPath, 'error' => null];
    }

    private static function failure(string $message): array
    {
        return ['status' => 0, 'body' => [], 'headers' => [], 'error' => $message];
    }
}
