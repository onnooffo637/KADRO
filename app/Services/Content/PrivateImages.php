<?php

namespace App\Services\Content;

use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateImages
{
    public const DISK = 'manga_private';

    /** @return array{mime: string, width: int, height: int, bytes: int} */
    public function inspect(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > 12 * 1024 * 1024) {
            throw ValidationException::withMessages(['upload' => 'الصورة فارغة أو أكبر من 12 ميجابايت.']);
        }
        $info = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
        if (! is_array($info) || ! isset($allowed[$mime]) || ($info[2] ?? 0) !== $allowed[$mime]
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 15000 || $info[1] > 30000
            || $info[0] * $info[1] > 12_000_000) {
            throw ValidationException::withMessages(['upload' => 'الملف ليس صورة JPG/PNG/WebP سليمة ضمن الحدود المسموحة.']);
        }
        // Full decode rejects images with valid headers but damaged payloads.
        // Fail closed rather than silently accepting them without GD.
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['upload' => 'مكتبة PHP GD مطلوبة للتحقق الكامل من الصور.']);
        }
        $decoded = @imagecreatefromstring($bytes);
        if (! $decoded instanceof \GdImage) {
            throw ValidationException::withMessages(['upload' => 'تعذّر فك الصورة بالكامل؛ قد تكون تالفة.']);
        }
        imagedestroy($decoded);
        return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1], 'bytes' => strlen($bytes)];
    }

    public function storeImage(string $bytes, string $folder): MediaAsset
    {
        $info = $this->inspect($bytes);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']];
        $key = $folder.'/'.Str::random(42).'.'.$extension;
        $disk = Storage::disk(self::DISK);
        if (! $disk->put($key, $bytes)) {
            throw ValidationException::withMessages(['upload' => 'تعذر تخزين الصورة.']);
        }
        try {
            $asset = MediaAsset::create([
                'storage_disk' => self::DISK, 'storage_key' => $key,
                'mime' => $info['mime'], 'sha256' => hash('sha256', $bytes),
                'bytes' => $info['bytes'], 'width' => $info['width'], 'height' => $info['height'],
                'status' => 'quarantined',
            ]);
            // Files are never served until content, type, dimensions and checksum are validated.
            $asset->update(['status' => 'ready']);
            return $asset;
        } catch (\Throwable $e) {
            $disk->delete($key);
            throw $e;
        }
    }

    public function fromUpload(UploadedFile $file, string $folder): MediaAsset
    {
        if (! $file->isValid() || $file->getSize() > 12 * 1024 * 1024) {
            throw ValidationException::withMessages(['upload' => 'صورة غير صالحة أو كبيرة جدًا.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes)) {
            throw ValidationException::withMessages(['upload' => 'تعذرت قراءة الصورة.']);
        }
        return $this->storeImage($bytes, $folder);
    }

    /** No storage path is ever included in the response. Supports ETag & high-throughput CDN/browser caching. */
    public function stream(MediaAsset $asset, bool $cacheable = true): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($asset->status === 'ready' && $asset->storage_disk === self::DISK, 404);
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($asset->storage_key), 404);

        $etag = '"' . $asset->sha256 . '"';
        $clientEtag = request()->header('If-None-Match');

        // High traffic optimization: return 304 immediately if cached by browser/CDN
        if ($clientEtag && trim($clientEtag) === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => $cacheable ? 'public, max-age=604800, stale-while-revalidate=86400' : 'private, no-cache',
            ]);
        }

        return response()->stream(function () use ($disk, $asset): void {
            $handle = $disk->readStream($asset->storage_key);
            if (! is_resource($handle)) return;
            try { fpassthru($handle); } finally { fclose($handle); }
        }, 200, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) $asset->bytes,
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $etag,
            'Cache-Control' => $cacheable ? 'public, max-age=604800, stale-while-revalidate=86400' : 'private, no-store, max-age=0',
            'Content-Disposition' => 'inline',
        ]);
    }
}
