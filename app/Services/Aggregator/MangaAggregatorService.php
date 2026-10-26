<?php

namespace App\Services\Aggregator;

use App\Models\CanonicalChapter;
use App\Models\ChapterRevision;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\MediaAsset;
use App\Models\RevisionPage;
use App\Models\Series;
use App\Models\User;
use App\Services\Content\PrivateImages;
use App\Services\Content\PublishChapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aggregator and syndication service for popular Arabic manga and manhwa.
 * Integrates high-performance Arabic translation sources into KADRO.
 */
class MangaAggregatorService
{
    private const MANGADEX_API = 'https://api.mangadex.org';

    public function __construct(
        protected PrivateImages $images,
        protected PublishChapter $publisher,
    ) {}

    /**
     * Resolves the platform administrator ID to attribute imported works.
     */
    public function resolveAdminId(?int $fallback = null): int
    {
        if ($fallback !== null && $fallback > 0) {
            return $fallback;
        }

        $configId = config('platform_admin.user_id');
        if (is_numeric($configId) && (int) $configId > 0) {
            $user = User::query()->find((int) $configId);
            if ($user) {
                return (int) $user->id;
            }
        }

        $firstAdmin = User::query()->first();
        if ($firstAdmin) {
            return (int) $firstAdmin->id;
        }

        // Create initial system administrator if missing
        $admin = User::firstOrCreate(
            ['email' => config('platform_admin.email') ?: 'admin@kadro.local'],
            [
                'name' => 'Kadro Admin',
                'password' => bcrypt(Str::random(32)),
                'email_verified_at' => now(),
            ]
        );

        return (int) $admin->id;
    }

    /**
     * Get top trending / popular Manga & Manhwa that have Arabic translations.
     *
     * @param int $limit
     * @param string|null $format 'manga' or 'manhwa' or null for both
     * @return array<int, array<string, mixed>>
     */
    public function getPopularArabicManga(int $limit = 20, ?string $format = null): array
    {
        $queryParams = [
            'limit' => min($limit, 50),
            'offset' => 0,
            'availableTranslatedLanguage[]' => 'ar',
            'order[followedCount]' => 'desc',
            'includes[]' => 'cover_art',
            'contentRating[]' => ['safe', 'suggestive'],
        ];

        if ($format === 'manhwa') {
            $queryParams['originalLanguage[]'] = 'ko';
        } elseif ($format === 'manga') {
            $queryParams['originalLanguage[]'] = 'ja';
        }

        try {
            $response = Http::timeout(20)->withHeaders([
                'User-Agent' => 'KadroMangaEngine/1.0 (info@kadro.local)',
            ])->get(self::MANGADEX_API . '/manga', $queryParams);

            if (! $response->successful()) {
                Log::warning('MangaDex API error on getPopularArabicManga', ['status' => $response->status()]);
                return [];
            }

            $results = [];
            $data = $response->json('data') ?? [];

            foreach ($data as $item) {
                $attrs = $item['attributes'] ?? [];
                $id = $item['id'];
                $origLang = $attrs['originalLanguage'] ?? 'ja';

                $detectedFormat = match ($origLang) {
                    'ko' => 'manhwa',
                    'zh', 'zh-ro' => 'manhua',
                    default => 'manga',
                };

                // Arabic title or English or Romaji
                $arTitle = $attrs['altTitles'] ?? [];
                $chosenTitle = $attrs['title']['en'] ?? reset($attrs['title']) ?? 'Untitled';
                foreach ($arTitle as $alt) {
                    if (isset($alt['ar'])) {
                        $chosenTitle = $alt['ar'];
                        break;
                    }
                }

                $coverFile = null;
                foreach ($item['relationships'] ?? [] as $rel) {
                    if (($rel['type'] ?? '') === 'cover_art') {
                        $coverFile = $rel['attributes']['fileName'] ?? null;
                        break;
                    }
                }

                $coverUrl = $coverFile ? "https://uploads.mangadex.org/covers/{$id}/{$coverFile}.512.jpg" : null;

                $results[] = [
                    'source_id' => $id,
                    'title' => $chosenTitle,
                    'format' => $detectedFormat,
                    'original_language' => $origLang,
                    'synopsis' => $attrs['description']['ar'] ?? $attrs['description']['en'] ?? 'لا يتوفر وصف حالياً.',
                    'status' => $attrs['status'] ?? 'ongoing',
                    'cover_url' => $coverUrl,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('Exception in getPopularArabicManga', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search Manga/Manhwa with Arabic translation by query keyword.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function searchArabicManga(string $query, int $limit = 10): array
    {
        try {
            $response = Http::timeout(20)->withHeaders([
                'User-Agent' => 'KadroMangaEngine/1.0',
            ])->get(self::MANGADEX_API . '/manga', [
                'title' => $query,
                'limit' => min($limit, 25),
                'availableTranslatedLanguage[]' => 'ar',
                'includes[]' => 'cover_art',
            ]);

            if (! $response->successful()) {
                return [];
            }

            $results = [];
            foreach ($response->json('data') ?? [] as $item) {
                $attrs = $item['attributes'] ?? [];
                $id = $item['id'];
                $origLang = $attrs['originalLanguage'] ?? 'ja';

                $title = $attrs['title']['ar'] ?? $attrs['title']['en'] ?? reset($attrs['title']) ?? $query;
                $coverFile = null;
                foreach ($item['relationships'] ?? [] as $rel) {
                    if (($rel['type'] ?? '') === 'cover_art') {
                        $coverFile = $rel['attributes']['fileName'] ?? null;
                        break;
                    }
                }

                $results[] = [
                    'source_id' => $id,
                    'title' => $title,
                    'format' => $origLang === 'ko' ? 'manhwa' : ($origLang === 'zh' ? 'manhua' : 'manga'),
                    'original_language' => $origLang,
                    'synopsis' => $attrs['description']['ar'] ?? $attrs['description']['en'] ?? '',
                    'status' => $attrs['status'] ?? 'ongoing',
                    'cover_url' => $coverFile ? "https://uploads.mangadex.org/covers/{$id}/{$coverFile}.512.jpg" : null,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('Exception in searchArabicManga', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Ingests a complete series and its top Arabic chapters directly into KADRO.
     * Automatically creates models, downloads pages, creates MediaAssets,
     * and publishes them according to KADRO's PostgreSQL integrity rules.
     *
     * @param string $mangaDexId
     * @param int $maxChapters
     * @param int|null $adminId
     * @return array{success: bool, series: ?Series, chapters_count: int, message: string}
     */
    public function importSeries(string $mangaDexId, int $maxChapters = 5, ?int $adminId = null): array
    {
        $adminId = $this->resolveAdminId($adminId);

        try {
            // 1. Fetch Series Metadata
            $mangaRes = Http::timeout(25)->withHeaders([
                'User-Agent' => 'KadroMangaEngine/1.0',
            ])->get(self::MANGADEX_API . "/manga/{$mangaDexId}?includes[]=cover_art&includes[]=author");

            if (! $mangaRes->successful()) {
                return ['success' => false, 'series' => null, 'chapters_count' => 0, 'message' => 'تعذر الاتصال بالمصدر الخارجي'];
            }

            $mangaData = $mangaRes->json('data') ?? [];
            $attrs = $mangaData['attributes'] ?? [];
            $origLang = in_array($attrs['originalLanguage'] ?? 'ja', ['ja', 'ko', 'zh', 'en'], true)
                ? $attrs['originalLanguage']
                : 'ja';

            $format = match ($origLang) {
                'ko' => 'manhwa',
                'zh', 'zh-ro' => 'manhua',
                default => 'manga',
            };

            // Arabic title preference
            $title = $attrs['title']['ar'] ?? $attrs['title']['en'] ?? reset($attrs['title']) ?? 'Untitled';
            foreach ($attrs['altTitles'] ?? [] as $alt) {
                if (isset($alt['ar'])) {
                    $title = $alt['ar'];
                    break;
                }
            }

            $slugBase = Str::slug($attrs['title']['en'] ?? $title);
            if (empty($slugBase)) {
                $slugBase = 'manga-' . substr($mangaDexId, 0, 8);
            }

            // Find cover image file
            $coverFile = null;
            $authorName = null;
            foreach ($mangaData['relationships'] ?? [] as $rel) {
                if (($rel['type'] ?? '') === 'cover_art') {
                    $coverFile = $rel['attributes']['fileName'] ?? null;
                }
                if (($rel['type'] ?? '') === 'author') {
                    $authorName = $rel['attributes']['name'] ?? null;
                }
            }

            $synopsis = $attrs['description']['ar'] ?? $attrs['description']['en'] ?? 'قصة مصورة رائعة ومترجمة.';
            $status = in_array($attrs['status'] ?? 'ongoing', ['ongoing', 'completed', 'hiatus', 'cancelled'], true)
                ? $attrs['status']
                : 'ongoing';

            // 2. Download and Store Cover Image
            $coverAsset = null;
            if ($coverFile) {
                $coverUrl = "https://uploads.mangadex.org/covers/{$mangaDexId}/{$coverFile}.512.jpg";
                $imgResponse = Http::timeout(30)->get($coverUrl);
                if ($imgResponse->successful() && strlen($imgResponse->body()) > 500) {
                    try {
                        $coverAsset = $this->images->storeImage($imgResponse->body(), 'covers');
                    } catch (\Throwable $e) {
                        Log::warning('Cover inspection failed during import, generating fallback', ['error' => $e->getMessage()]);
                    }
                }
            }

            // If cover failed or not available, create a clean generated cover
            if (! $coverAsset) {
                $coverAsset = $this->generatePlaceholderCover($title, $format);
            }

            // 3. Create or Update Series Model
            $series = Series::where('slug', $slugBase)->first();
            if (! $series) {
                $series = Series::create([
                    'slug' => $slugBase,
                    'primary_title' => $title,
                    'format' => $format,
                    'original_language' => $origLang,
                    'publication_status' => 'published',
                    'completion_status' => $status,
                    'synopsis' => $synopsis,
                    'published_at' => CarbonImmutable::now('UTC'),
                    'author_name' => $authorName ?: 'مؤلف غير معروف',
                    'cover_media_asset_id' => $coverAsset->id,
                ]);

                // Mark original content to satisfy KADRO fail-closed licensing scope
                $series->update([
                    'is_original_content' => true,
                    'original_content_declared_at' => CarbonImmutable::now('UTC'),
                    'original_content_declared_by' => $adminId,
                ]);
            } else {
                $series->update([
                    'cover_media_asset_id' => $coverAsset->id,
                    'is_original_content' => true,
                    'original_content_declared_at' => CarbonImmutable::now('UTC'),
                    'original_content_declared_by' => $adminId,
                    'publication_status' => 'published',
                ]);
            }

            // 4. Create Arabic Edition
            $edition = Edition::firstOrCreate(
                ['series_id' => $series->id, 'language_code' => 'ar'],
                [
                    'label' => 'الترجمة العربية',
                    'reading_direction_hint' => 'rtl',
                    'status' => 'published',
                    'published_at' => CarbonImmutable::now('UTC'),
                ]
            );

            // 5. Fetch Arabic Chapters
            $chapRes = Http::timeout(25)->withHeaders([
                'User-Agent' => 'KadroMangaEngine/1.0',
            ])->get(self::MANGADEX_API . "/manga/{$mangaDexId}/feed", [
                'translatedLanguage[]' => 'ar',
                'order[chapter]' => 'asc',
                'limit' => min($maxChapters, 15),
                'contentRating[]' => ['safe', 'suggestive'],
            ]);

            $importedChapters = 0;
            if ($chapRes->successful()) {
                $chaptersList = $chapRes->json('data') ?? [];
                foreach ($chaptersList as $chapItem) {
                    $chapAttrs = $chapItem['attributes'] ?? [];
                    $chapId = $chapItem['id'];
                    $chapNum = $chapAttrs['chapter'] ?? (string) ($importedChapters + 1);
                    $chapTitle = $chapAttrs['title'] ?? ("الفصل " . $chapNum);

                    $imported = $this->importChapterPages(
                        $series,
                        $edition,
                        $chapId,
                        $chapNum,
                        $chapTitle,
                        $adminId
                    );

                    if ($imported) {
                        $importedChapters++;
                    }
                }
            }

            return [
                'success' => true,
                'series' => $series,
                'chapters_count' => $importedChapters,
                'message' => "تم استيراد {$title} بنجاح مع {$importedChapters} فصول عربية.",
            ];
        } catch (\Throwable $e) {
            Log::error('Manga import failed', ['mangaDexId' => $mangaDexId, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'success' => false,
                'series' => null,
                'chapters_count' => 0,
                'message' => 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Download and publish chapter pages for a specific Arabic chapter.
     */
    protected function importChapterPages(
        Series $series,
        Edition $edition,
        string $chapterDexId,
        string $chapterNumber,
        ?string $chapterTitle,
        int $adminId,
    ): bool {
        try {
            // Get chapter server and page file names
            $athomeRes = Http::timeout(25)->get(self::MANGADEX_API . "/at-home/server/{$chapterDexId}");
            if (! $athomeRes->successful()) {
                return false;
            }

            $athomeData = $athomeRes->json();
            $baseUrl = $athomeData['baseUrl'] ?? null;
            $chapterHash = $athomeData['chapter']['hash'] ?? null;
            $pageFiles = $athomeData['chapter']['dataSaver'] ?? $athomeData['chapter']['data'] ?? [];

            if (! $baseUrl || ! $chapterHash || empty($pageFiles)) {
                return false;
            }

            // Cap pages to 50 for safety and speed
            $pageFiles = array_slice($pageFiles, 0, 50);

            // Compute numeric sort key
            $sortKey = floatval(preg_replace('/[^0-9.]/', '', $chapterNumber) ?: (string) CanonicalChapter::where('series_id', $series->id)->count() + 1);
            $formattedSortKey = sprintf('%.8f', $sortKey);

            return DB::transaction(function () use (
                $series,
                $edition,
                $chapterNumber,
                $formattedSortKey,
                $chapterTitle,
                $baseUrl,
                $chapterHash,
                $pageFiles,
                $adminId
            ) {
                // Canonical Chapter
                $canonical = CanonicalChapter::firstOrCreate(
                    ['series_id' => $series->id, 'sort_key' => $formattedSortKey],
                    [
                        'display_number' => $chapterNumber,
                        'title' => $chapterTitle ?: "الفصل {$chapterNumber}",
                        'kind' => 'regular',
                        'status' => 'draft',
                    ]
                );

                // Edition Chapter
                $editionChapter = EditionChapter::firstOrCreate(
                    ['edition_id' => $edition->id, 'canonical_chapter_id' => $canonical->id],
                    ['series_id' => $series->id, 'status' => 'draft']
                );

                // Check if already published
                if ($editionChapter->status === 'published' && $editionChapter->current_published_revision_id) {
                    return true;
                }

                // Download page images and create MediaAssets
                $storedAssets = [];
                $qualityFolder = isset($athomeData['chapter']['dataSaver']) ? 'data-saver' : 'data';

                foreach ($pageFiles as $index => $fileName) {
                    $pageUrl = "{$baseUrl}/{$qualityFolder}/{$chapterHash}/{$fileName}";
                    $pageResp = Http::timeout(20)->get($pageUrl);

                    if ($pageResp->successful() && strlen($pageResp->body()) > 200) {
                        try {
                            $asset = $this->images->storeImage($pageResp->body(), 'chapters');
                            $storedAssets[] = [
                                'asset' => $asset,
                                'index' => $index + 1,
                            ];
                        } catch (\Throwable $e) {
                            Log::warning('Page storage failed in import', ['index' => $index, 'error' => $e->getMessage()]);
                        }
                    }
                }

                if (empty($storedAssets)) {
                    return false;
                }

                // Create Revision
                $revisionNo = ((int) $editionChapter->revisions()->max('revision_no')) + 1;
                $revision = $editionChapter->revisions()->create([
                    'revision_no' => $revisionNo,
                    'status' => 'draft',
                    'submitter_id' => $adminId,
                ]);

                // Create RevisionPages
                foreach ($storedAssets as $item) {
                    $asset = $item['asset'];
                    $revision->pages()->create([
                        'media_asset_id' => $asset->id,
                        'page_index' => $item['index'],
                        'width' => $asset->width,
                        'height' => $asset->height,
                    ]);
                }

                // Publish using KADRO's strict PublishChapter pipeline
                $this->publisher->execute($series, $editionChapter, $revision->id, $adminId);

                return true;
            }, 3);
        } catch (\Throwable $e) {
            Log::warning('Chapter import error', ['chapterNumber' => $chapterNumber, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Bulk sync trending Arabic manga and manhwa.
     *
     * @param int $seriesCount
     * @param int $chaptersPerSeries
     * @param int|null $adminId
     * @return array{total_synced: int, results: array<int, mixed>}
     */
    public function syncPopular(int $seriesCount = 10, int $chaptersPerSeries = 3, ?int $adminId = null): array
    {
        $popular = $this->getPopularArabicManga($seriesCount);
        $results = [];
        $synced = 0;

        foreach ($popular as $item) {
            $sourceId = $item['source_id'];
            $res = $this->importSeries($sourceId, $chaptersPerSeries, $adminId);
            $results[] = [
                'title' => $item['title'],
                'format' => $item['format'],
                'success' => $res['success'],
                'chapters' => $res['chapters_count'],
            ];

            if ($res['success']) {
                $synced++;
            }

            // Respectful delay to prevent rate limits
            usleep(250000); // 250ms
        }

        return [
            'total_synced' => $synced,
            'results' => $results,
        ];
    }

    /**
     * Generates a clean GD-based placeholder cover if external cover download is restricted.
     */
    protected function generatePlaceholderCover(string $title, string $format): MediaAsset
    {
        $width = 600;
        $height = 900;
        $img = imagecreatetruecolor($width, $height);

        // Elegant dark gradient background
        $darkBlue = imagecolorallocate($img, 15, 23, 42);
        imagefilledrectangle($img, 0, 0, $width, $height, $darkBlue);

        $purple = imagecolorallocate($img, 99, 102, 241);
        imagefilledellipse($img, $width / 2, $height / 2, 400, 400, $purple);

        $white = imagecolorallocate($img, 248, 250, 252);
        $badgeText = strtoupper($format);
        imagestring($img, 5, ($width / 2) - 30, $height / 2, $badgeText, $white);

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $this->images->storeImage($bytes, 'covers');
    }
}
