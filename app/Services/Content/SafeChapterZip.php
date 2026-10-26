<?php

namespace App\Services\Content;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** ZIP is scanned before writes; data is streamed per-entry, not loaded all at once. */
final class SafeChapterZip
{
    /** @return list<array{name: string, index: int, size: int}> */
    public function read(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['upload' => 'امتداد PHP zip غير مفعّل على الخادم.']);
        }
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 60 * 1024 * 1024) {
            throw ValidationException::withMessages(['upload' => 'الحد الأقصى لملف ZIP هو 60 ميجابايت.']);
        }
        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['upload' => 'تعذّر فتح ملف ZIP.']);
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 260) {
                throw ValidationException::withMessages(['upload' => 'ZIP يتجاوز عدد العناصر المسموح.']);
            }
            $files = []; $names = []; $sum = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if (! is_string($name) || strlen($name) > 350 || preg_match('/[\\x00-\\x1f\\\\]/', $name)
                    || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name)
                    || in_array('..', explode('/', $name), true)
                    || in_array('.', explode('/', $name), true)) {
                    throw ValidationException::withMessages(['upload' => 'ZIP يحتوي مسارًا غير آمن.']);
                }
                $opsys = 0; $attrs = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attrs);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attrs >> 16) & 0170000) === 0120000) {
                    throw ValidationException::withMessages(['upload' => 'ZIP يحتوي رابطًا رمزيًا ممنوعًا.']);
                }
                if (str_ends_with($name, '/')) continue;
                $base = basename($name);
                if (str_starts_with($name, '__MACOSX/') || in_array($base, ['.DS_Store', 'Thumbs.db'], true)) continue;
                if (! preg_match('/\.(?:jpe?g|png|webp)$/iD', $base)) {
                    throw ValidationException::withMessages(['upload' => 'ZIP يحتوي ملفًا غير صورة: '.mb_substr($base, 0, 55)]);
                }
                $size = (int) ($stat['size'] ?? 0);
                $compressed = max(1, (int) ($stat['comp_size'] ?? 0));
                $sum += $size;
                if ($size < 1 || $size > 12 * 1024 * 1024 || $sum > 300 * 1024 * 1024 || $size / $compressed > 120) {
                    throw ValidationException::withMessages(['upload' => 'ZIP يتجاوز حدود فك الضغط الآمن.']);
                }
                $lower = mb_strtolower($name);
                if (isset($names[$lower])) throw ValidationException::withMessages(['upload' => 'ZIP يحتوي أسماء صور مكررة.']);
                $names[$lower] = true;
                $files[] = ['name' => $name, 'index' => $i, 'size' => $size];
            }
            if (count($files) < 1 || count($files) > 200) {
                throw ValidationException::withMessages(['upload' => 'الفصل يحتاج من صورة واحدة إلى 200 صورة.']);
            }
            usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            return $files;
        } finally { $zip->close(); }
    }

    /**
     * Read one bounded entry at a time; callback may validate and store it transactionally.
     * @param list<array{name:string,index:int,size:int}> $files
     */
    public function each(UploadedFile $file, array $files, callable $consume): void
    {
        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['upload' => 'تعذّر إعادة فتح ZIP للتحقق.']);
        }
        try {
            foreach ($files as $order => $entry) {
                $content = $zip->getFromIndex($entry['index'], $entry['size']);
                if (! is_string($content) || strlen($content) !== $entry['size']) {
                    throw ValidationException::withMessages(['upload' => 'ZIP يحتوي صورة تالفة.']);
                }
                $consume($content, $order + 1);
                unset($content);
            }
        } finally { $zip->close(); }
    }
}
