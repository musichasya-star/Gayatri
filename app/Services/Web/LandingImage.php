<?php

namespace App\Services\Web;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LandingImage
{
    private const PATH = 'landing/cms/';

    public static function store(UploadedFile $file): string
    {
        $bytes = file_get_contents($file->getPathname());
        $source = $bytes !== false && function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
        if (! $source || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['file' => 'Gambar tidak bisa diproses. Coba file JPG, PNG, atau WebP lain.']);
        }
        $main = self::resize($source, 1440);
        $width = imagesx($main);
        $prefix = self::PATH.Str::uuid();
        $path = $prefix.'-'.$width.'.webp';
        $outputs = [$path => self::encode($main)];
        if ($width > 480) {
            $outputs[$prefix.'-480.webp'] = self::encode(self::resize($main, 480, 1440));
        }
        // A JPEG derivative is readable by social link previews that do not support WebP.
        $share = self::resize($main, 1200);
        $opaque = imagecreatetruecolor(imagesx($share), imagesy($share));
        imagefill($opaque, 0, 0, imagecolorallocate($opaque, 250, 243, 234));
        imagecopy($opaque, $share, 0, 0, 0, 0, imagesx($share), imagesy($share));
        $outputs[$prefix.'-share.jpg'] = self::encode($opaque, true);

        $disk = Storage::disk('public');
        foreach ($outputs as $name => $image) {
            if ($image === '' || ! $disk->put($name, $image)) {
                $disk->delete(array_keys($outputs));
                throw ValidationException::withMessages(['file' => 'Gambar belum tersimpan. Silakan coba lagi.']);
            }
        }

        return '/storage/'.$path;
    }

    public static function srcset(string $url): string
    {
        if (! preg_match('~^(/storage/landing/cms/[a-f0-9-]{36})-(\d+)\.webp$~D', $url, $matches) || (int) $matches[2] <= 480) {
            return '';
        }

        return $matches[1].'-480.webp 480w, '.$url.' '.$matches[2].'w';
    }

    public static function share(string $url): ?string
    {
        return preg_match('~^(/storage/landing/cms/[a-f0-9-]{36})-\d+\.webp$~D', $url, $matches) ? $matches[1].'-share.jpg' : null;
    }

    private static function resize(\GdImage $image, int $maxWidth, int $maxHeight = 1440): \GdImage
    {
        $scale = min(1, $maxWidth / imagesx($image), $maxHeight / imagesy($image));
        $resized = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), imagesx($image), imagesy($image));

        return $resized;
    }

    private static function encode(\GdImage $image, bool $jpeg = false): string
    {
        ob_start();
        $ok = $jpeg ? imagejpeg($image, null, 82) : imagewebp($image, null, 80);
        $content = ob_get_clean();

        return $ok ? $content : '';
    }
}
