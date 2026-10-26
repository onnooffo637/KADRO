<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Metadata-only public catalog. Access is always evaluated server-side against
 * publication, licensed territory/language, and published revision state.
 * No media storage keys or private assets leave these endpoints.
 */
class PublicCatalogController extends Controller
{
    protected function audienceTerritory(): ?string
    {
        // A user-supplied region/header is NOT proof of a licensed territory.
        // Only a trusted server setting is accepted in this local development phase.
        $territory = config('manga_catalog.public_territory');

        return is_string($territory) && preg_match('/^[A-Z]{2}$/D', $territory) === 1
            ? $territory
            : null;
    }

    protected function language(Request $request): string
    {
        $validated = $request->validate([
            'lang' => ['sometimes', 'string', 'max:24', 'regex:/^[a-z]{2,3}(?:-[A-Za-z]{2,8})?$/D'],
        ]);

        return $validated['lang'] ?? 'ar';
    }

    /** @param Builder<EditionChapter> $query */
    protected function eligibleChapter(Builder $query): void
    {
        $query->where('edition_chapters.status', 'published')
            ->whereNull('edition_chapters.deleted_at')
            ->whereNotNull('edition_chapters.current_published_revision_id')
            ->whereHas('canonicalChapter', fn (Builder $chapter) => $chapter
                ->where('status', 'published')->whereNull('deleted_at'))
            ->whereHas('currentPublishedRevision', fn (Builder $revision) => $revision
                ->where('status', 'published')
                ->whereNotNull('asset_manifest_hash')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->whereHas('pages', fn (Builder $page) => $page
                    ->whereHas('asset', fn (Builder $asset) => $asset->where('status', 'ready')))
                ->whereDoesntHave('pages', fn (Builder $page) => $page
                    ->whereDoesntHave('asset', fn (Builder $asset) => $asset->where('status', 'ready'))));
    }

    /** @param Builder<Edition> $query */
    protected function eligibleEdition(Builder $query, ?string $territory, string $language): void
    {
        $query->publiclyReadableIn($territory, $language)
            ->whereHas('editionChapters', fn (Builder $chapter) => $this->eligibleChapter($chapter));
    }

    /** @return Builder<Series> */
    protected function eligibleSeries(?string $territory, string $language): Builder
    {
        return Series::query()
            ->publiclyReadableIn($territory, $language)
            ->whereHas('editions', fn (Builder $edition) => $this->eligibleEdition($edition, $territory, $language));
    }

    private function publicSeriesData(Series $series, string $language): array
    {
        return [
            'id' => $series->id,
            'slug' => $series->slug,
            'title' => $series->primary_title,
            'format' => $series->format,
            'original_language' => $series->original_language,
            'completion_status' => $series->completion_status,
            'synopsis' => $series->synopsis,
            'published_at' => $series->published_at?->toIso8601String(),
            'cover_url' => $series->coverAsset?->status === 'ready'
                ? route('api.v1.catalog.cover', ['slug' => $series->slug, 'lang' => $language]) : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $language = $this->language($request);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'format' => ['sometimes', Rule::in(['manga', 'manhwa', 'manhua', 'webtoon'])],
            'genre' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $query = $this->eligibleSeries($this->audienceTerritory(), $language)->with('coverAsset');

        if (isset($filters['format'])) {
            $query->where('series.format', $filters['format']);
        }
        if (isset($filters['genre'])) {
            $query->whereIn('series.id', DB::table('series_genres')
                ->join('genres', 'genres.id', '=', 'series_genres.genre_id')
                ->select('series_genres.series_id')
                ->where('genres.code', $filters['genre']));
        }
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = mb_strtolower(trim($filters['search']), 'UTF-8');
            $query->where(function (Builder $byTitle) use ($term): void {
                $byTitle->whereRaw('LOWER(series.primary_title) LIKE ?', ['%'.$term.'%'])
                    ->orWhereIn('series.id', DB::table('title_aliases')
                        ->select('series_id')
                        ->whereRaw('LOWER(value) LIKE ?', ['%'.$term.'%']));
            });
        }

        $page = $query->orderByDesc('series.published_at')
            ->orderBy('series.id')
            ->paginate($filters['per_page'] ?? 20, ['series.*'], 'page', $filters['page'] ?? 1);

        return response()->json([
            'data' => collect($page->items())->map(fn (Series $series) => $this->publicSeriesData($series, $language))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'language' => $language,
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $language = $this->language($request);
        $territory = $this->audienceTerritory();

        $series = $this->eligibleSeries($territory, $language)
            ->where('series.slug', $slug)->firstOrFail();

        $editions = Edition::query()
            ->where('series_id', $series->id)
            ->publiclyReadableIn($territory, $language)
            ->whereHas('editionChapters', fn (Builder $chapter) => $this->eligibleChapter($chapter))
            ->orderBy('label')
            ->get(['id', 'language_code', 'label', 'reading_direction_hint'])
            ->map(fn (Edition $edition) => [
                'id' => $edition->id,
                'language' => $edition->language_code,
                'label' => $edition->label,
                'reading_direction' => $edition->reading_direction_hint,
            ])->all();

        return response()->json(['data' => [
            ...$this->publicSeriesData($series, $language),
            'editions' => $editions,
        ]]);
    }

    public function chapters(Request $request, string $slug): JsonResponse
    {
        $language = $this->language($request);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $territory = $this->audienceTerritory();
        $series = $this->eligibleSeries($territory, $language)
            ->where('series.slug', $slug)->firstOrFail();

        $query = EditionChapter::query()->where('edition_chapters.series_id', $series->id)
            ->whereHas('edition', fn (Builder $edition) => $this->eligibleEdition($edition, $territory, $language));
        $this->eligibleChapter($query);

        $page = $query->join('canonical_chapters', 'canonical_chapters.id', '=', 'edition_chapters.canonical_chapter_id')
            ->with(['canonicalChapter', 'edition'])
            ->orderBy('canonical_chapters.sort_key')
            ->orderBy('edition_chapters.id')
            ->paginate($filters['per_page'] ?? 30, ['edition_chapters.*'], 'page', $filters['page'] ?? 1);

        return response()->json([
            'data' => collect($page->items())->map(fn (EditionChapter $chapter) => [
                'id' => $chapter->id,
                'edition_id' => $chapter->edition_id,
                'language' => $chapter->edition->language_code,
                'display_number' => $chapter->canonicalChapter->display_number,
                'title' => $chapter->canonicalChapter->title,
                'kind' => $chapter->canonicalChapter->kind,
                'sort_key' => $chapter->canonicalChapter->sort_key,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'language' => $language,
            ],
        ]);
    }

    public function genres(Request $request): JsonResponse
    {
        $language = $this->language($request);
        $publicSeriesIds = $this->eligibleSeries($this->audienceTerritory(), $language)
            ->select('series.id');

        $genres = DB::table('genres')
            ->whereIn('genres.id', DB::table('series_genres')
                ->select('genre_id')->whereIn('series_id', $publicSeriesIds))
            ->orderBy('name')->limit(100)
            ->get(['code', 'name']);

        return response()->json(['data' => $genres]);
    }
}
