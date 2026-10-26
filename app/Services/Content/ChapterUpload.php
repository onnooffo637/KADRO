<?php

namespace App\Services\Content;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/** Prepare a bounded chapter import before starting the database transaction. */
final class ChapterUpload
{
    private const MAX_UPLOAD_BYTES = 60 * 1024 * 1024;
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly SafeChapterZip $zip,
        private readonly PrivateImages $images,
    ) {}

    public function prepare(Request $request, bool $archiveOnly = false): PreparedChapterPages
    {
        $request->validate([
            'upload' => ['nullable', 'file', 'max:61440'],
            'images' => ['nullable', 'array', 'min:1', 'max:200'],
            'images.*' => ['required', 'file', 'max:12288'],
        ]);

        $upload = $request->file('upload');
        $photos = $request->file('images', []);
        if ($upload !== null && ! $upload instanceof UploadedFile) {
            $this->fail('ملف الفصل غير صالح.');
        }
        if (! is_array($photos)) {
            $this->fail('اختر صور الفصل بطريقة صحيحة.');
        }
        if (($upload === null) === ($photos === [])) {
            $this->fail('اختر ملفًا واحدًا أو مجموعة صور؛ لا تجمع الطريقتين.');
        }
        if ($archiveOnly && ($upload === null || $photos !== [])) {
            $this->fail('المسار القديم يقبل ZIP فقط.');
        }

        if ($photos !== []) {
            return $this->prepareImages($photos);
        }

        $extension = strtolower($upload->getClientOriginalExtension());
        if ($archiveOnly && $extension !== 'zip') {
            $this->fail('المسار القديم يقبل ZIP فقط.');
        }
        if (in_array($extension, ['zip', 'cbz'], true)) {
            // Same container; SafeChapterZip rejects traversal, links, bombs, non-images.
            $entries = $this->zip->read($upload);
            return new PreparedChapterPages(
                fn (callable $consume) => $this->zip->each($upload, $entries, $consume)
            );
        }
        if ($extension === 'pdf' && ! $archiveOnly) {
            return $this->preparePdf($upload);
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && ! $archiveOnly) {
            return $this->prepareImages([$upload]);
        }
        $this->fail('الصيغ المدعومة: JPG، PNG، WebP، ZIP، CBZ، PDF فقط.');
    }

    /** @param list<UploadedFile> $files */
    private function prepareImages(array $files): PreparedChapterPages
    {
        if (count($files) < 1 || count($files) > self::MAX_PAGES) {
            $this->fail('الفصل يحتاج من 1 إلى 200 صورة.');
        }
        $total = 0;
        $seen = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $this->fail('إحدى الصور غير صالحة.');
            }
            if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $this->fail('الصور يجب أن تكون JPG أو PNG أو WebP.');
            }
            $size = $file->getSize();
            $total += $size;
            if ($size < 1 || $size > self::MAX_IMAGE_BYTES || $total > self::MAX_UPLOAD_BYTES) {
                $this->fail('الحد الأقصى 12MB للصورة و60MB لدفعة الصور.');
            }
            $name = mb_strtolower($file->getClientOriginalName());
            if (isset($seen[$name])) {
                $this->fail('أسماء الصور مكررة؛ غيّر أسماءها قبل الرفع.');
            }
            $seen[$name] = true;
            // Validate actual bytes and full image decode before any DB changes.
            $bytes = file_get_contents($file->getRealPath());
            if (! is_string($bytes)) {
                $this->fail('تعذر قراءة الصورة.');
            }
            $this->images->inspect($bytes);
            unset($bytes);
        }
        usort($files, fn (UploadedFile $a, UploadedFile $b): int =>
            strnatcasecmp($a->getClientOriginalName(), $b->getClientOriginalName()));
        return new PreparedChapterPages(function (callable $consume) use ($files): void {
            foreach ($files as $index => $file) {
                $bytes = file_get_contents($file->getRealPath());
                if (! is_string($bytes)) $this->fail('تعذر قراءة صورة أثناء الحفظ.');
                $consume($bytes, $index + 1);
                unset($bytes);
            }
        });
    }

    private function preparePdf(UploadedFile $file): PreparedChapterPages
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            $this->fail('ملف PDF فارغ أو أكبر من 60MB.');
        }
        $handle = fopen($file->getRealPath(), 'rb');
        if (! is_resource($handle)) $this->fail('تعذر قراءة PDF.');
        try { $magic = fread($handle, 5); } finally { fclose($handle); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($magic !== '%PDF-' || $mime !== 'application/pdf') {
            $this->fail('الملف ليس PDF صالحًا.');
        }
        $info = $this->process([
            (string) config('manga_content.pdfinfo_binary', 'pdfinfo'),
            $file->getRealPath(),
        ], 20, 'تعذر فحص PDF أو أداة pdfinfo غير مثبّتة.');
        if (preg_match('/^Encrypted:\s*yes/im', $info)) {
            $this->fail('ملفات PDF المحمية بكلمة مرور غير مدعومة.');
        }
        if (! preg_match('/^Pages:\s*(\d+)\s*$/im', $info, $found)) {
            $this->fail('تعذر معرفة عدد صفحات PDF.');
        }
        $pages = (int) $found[1];
        $maxPdfPages = max(1, min(self::MAX_PAGES, (int) config('manga_content.max_pdf_pages', 40)));
        if ($pages < 1 || $pages > $maxPdfPages) {
            $this->fail("يسمح حاليًا بملفات PDF من صفحة إلى {$maxPdfPages} صفحة؛ قسم الملف الكبير إلى فصول أصغر.");
        }
        // Unique, private, non-web-served directory; no user paths reach the command line.
        $base = storage_path('app/manga-import-tmp');
        if (! is_dir($base) && ! mkdir($base, 0700, true) && ! is_dir($base)) {
            $this->fail('تعذر تجهيز مجلد التحويل الخاص.');
        }
        $dir = $base.DIRECTORY_SEPARATOR.bin2hex(random_bytes(20));
        if (! mkdir($dir, 0700)) $this->fail('تعذر تجهيز مجلد التحويل.');
        try {
            $this->process([
                (string) config('manga_content.pdftoppm_binary', 'pdftoppm'),
                '-f', '1', '-l', (string) $pages,
                '-scale-to', '2200', '-jpeg', '-jpegopt', 'quality=95,optimize=y',
                $file->getRealPath(), $dir.DIRECTORY_SEPARATOR.'page',
            ], max(10, min(300, (int) config('manga_content.pdf_timeout', 180))),
                'تعذر تحويل PDF إلى صور. تأكد من تثبيت pdftoppm وصلاحية الملف.');
            $paths = glob($dir.DIRECTORY_SEPARATOR.'page-*.jpg') ?: [];
            if (count($paths) !== $pages) {
                $this->fail('عدد الصور المحوّلة لا يطابق عدد صفحات PDF.');
            }
            usort($paths, 'strnatcasecmp');
            $total = 0;
            foreach ($paths as $path) {
                if (is_link($path) || ! is_file($path)) $this->fail('ملف PDF أنتج صفحة غير آمنة.');
                $size = filesize($path);
                $total += $size;
                if ($size < 1 || $size > self::MAX_IMAGE_BYTES || $total > 300 * 1024 * 1024) {
                    $this->fail('صور PDF الناتجة تجاوزت حدود حجم الفصل.');
                }
                $bytes = file_get_contents($path);
                if (! is_string($bytes)) $this->fail('تعذر فحص صورة PDF.');
                $this->images->inspect($bytes);
                unset($bytes);
            }
            return new PreparedChapterPages(
                function (callable $consume) use ($paths): void {
                    foreach ($paths as $index => $path) {
                        $bytes = file_get_contents($path);
                        if (! is_string($bytes)) $this->fail('تعذر قراءة صفحة PDF.');
                        $consume($bytes, $index + 1);
                        unset($bytes);
                    }
                },
                fn () => $this->removeTemp($dir)
            );
        } catch (\Throwable $e) {
            $this->removeTemp($dir);
            throw $e;
        }
    }

    /** @param list<string> $command */
    private function process(array $command, int $timeout, string $userMessage): string
    {
        try {
            $process = new Process($command, null, null, null, $timeout);
            $process->run();
            if (! $process->isSuccessful()) $this->fail($userMessage);
            return $process->getOutput();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->fail($userMessage);
        }
    }

    private function removeTemp(string $dir): void
    {
        if (! is_dir($dir)) return;
        // Only delete renderer-generated files in the unique temporary directory.
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) @unlink($path);
        }
        @rmdir($dir);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['upload' => $message]);
    }
}
