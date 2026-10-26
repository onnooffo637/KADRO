<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Series;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Gate 09A: drafts only. No publication, rights approvals or media uploads. */
class SeriesDraftController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'format' => ['sometimes', 'nullable', Rule::in(['manga', 'manhwa', 'manhua', 'webtoon'])],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'reviewed', 'published', 'archived', 'revoked'])],
            'completion' => ['sometimes', 'nullable', Rule::in(['ongoing', 'completed', 'hiatus', 'cancelled'])],
        ]);

        $series = Series::query()
            ->with('coverAsset')
            ->when(filled($filter['search'] ?? null), fn ($query) => $query
                ->where('primary_title', 'ilike', '%'.addcslashes(trim($filter['search']), '%_\\').'%'))
            ->when(filled($filter['format'] ?? null), fn ($query) => $query->where('format', $filter['format']))
            ->when(filled($filter['status'] ?? null), fn ($query) => $query->where('publication_status', $filter['status']))
            ->when(filled($filter['completion'] ?? null), fn ($query) => $query->where('completion_status', $filter['completion']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15, ['id', 'slug', 'primary_title', 'format', 'publication_status', 'completion_status', 'cover_media_asset_id', 'updated_at'])
            ->withQueryString();

        $ids = $series->getCollection()->pluck('id')->all();
        $published = $ids === [] ? collect() : DB::table('edition_chapters')
            ->whereIn('series_id', $ids)->where('status', 'published')
            ->whereNotNull('current_published_revision_id')
            ->whereNull('deleted_at')
            ->selectRaw('series_id, COUNT(*) AS total')->groupBy('series_id')->pluck('total', 'series_id');

        $series->through(fn (Series $work) => [
            'id' => $work->id,
            'slug' => $work->slug,
            'primary_title' => $work->primary_title,
            'format' => $work->format,
            'publication_status' => $work->publication_status,
            'completion_status' => $work->completion_status,
            'published_chapters' => (int) ($published[$work->id] ?? 0),
            'cover_url' => $work->coverAsset?->status === 'ready'
                ? route('admin.content.media', [$work, $work->coverAsset]) : null,
            'updated_at' => $work->updated_at,
        ]);

        return Inertia::render('admin/series-index', [
            'series' => $series,
            'filters' => [
                'search' => $filter['search'] ?? '',
                'format' => $filter['format'] ?? '',
                'status' => $filter['status'] ?? '',
                'completion' => $filter['completion'] ?? '',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/series-form', [
            'series' => null,
            'genres' => $this->availableGenres(),
            'tags' => $this->availableTags(),
        ]);
    }

    public function edit(Series $series): Response
    {
        abort_unless($series->publication_status === 'draft' && $series->published_at === null, 409,
            'Only unpublished drafts may be edited in Gate 09A.');

        return Inertia::render('admin/series-form', [
            'series' => [
                'id' => $series->id,
                'slug' => $series->slug,
                'primary_title' => $series->primary_title,
                'format' => $series->format,
                'original_language' => $series->original_language,
                'completion_status' => $series->completion_status,
                'synopsis' => $series->synopsis ?? '',
                'author_name' => $series->author_name ?? '',
                'illustrator_name' => $series->illustrator_name ?? '',
                'age_rating' => $series->age_rating ?? '',
                'cover_url' => $series->coverAsset?->status === 'ready'
                    ? route('admin.content.media', [$series, $series->coverAsset]) : null,
                'genre_codes' => DB::table('genres')
                    ->join('series_genres', 'genres.id', '=', 'series_genres.genre_id')
                    ->where('series_genres.series_id', $series->id)->pluck('genres.code')->all(),
                'tag_codes' => DB::table('tags')
                    ->join('series_tags', 'tags.id', '=', 'series_tags.tag_id')
                    ->where('series_tags.series_id', $series->id)->pluck('tags.code')->all(),
                'aliases' => DB::table('title_aliases')->where('series_id', $series->id)
                    ->orderBy('locale')->orderBy('value')->get(['locale', 'value'])
                    ->map(fn ($row) => (array) $row)->all(),
            ],
            'genres' => $this->availableGenres(),
            'tags' => $this->availableTags(),
            'success' => session('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateDraft($request);

        $series = DB::transaction(function () use ($data, $request): Series {
            $series = Series::create($this->draftAttributes($data));
            $this->syncGenres($series, $data);
            $this->syncTags($series, $data);
            $this->syncAliases($series, $data);
            $this->audit($request, $series, 'series.draft_created');

            return $series;
        });

        return redirect()->route('admin.series.edit', $series)
            ->with('success', 'تم حفظ العمل كمسودة، ولم يتم نشره.');
    }

    public function update(Request $request, Series $series): RedirectResponse
    {
        // A previously published/approved series cannot silently become a draft.
        abort_unless($series->publication_status === 'draft' && $series->published_at === null, 409,
            'Only unpublished drafts may be edited in Gate 09A.');

        $data = $this->validateDraft($request, $series);
        DB::transaction(function () use ($data, $request, $series): void {
            // Row-lock prevents concurrent publication from racing this update.
            $locked = Series::query()->lockForUpdate()->findOrFail($series->id);
            abort_unless($locked->publication_status === 'draft' && $locked->published_at === null, 409);
            $locked->update($this->draftAttributes($data));
            $this->syncGenres($locked, $data);
            $this->syncTags($locked, $data);
            $this->syncAliases($locked, $data);
            $this->audit($request, $locked, 'series.draft_updated');
        });

        return redirect()->route('admin.series.edit', $series)
            ->with('success', 'تم حفظ تعديلات المسودة.');
    }

    private function availableGenres(): array
    {
        return DB::table('genres')->orderBy('code')->limit(200)
            ->get(['code', 'name'])->map(fn ($genre) => (array) $genre)->all();
    }

    private function availableTags(): array
    {
        return DB::table('tags')->orderBy('code')->limit(200)
            ->get(['code', 'name'])->map(fn ($tag) => (array) $tag)->all();
    }

    private function validateDraft(Request $request, ?Series $series = null): array
    {
        return $request->validate([
            'slug' => [
                'required', 'string', 'min:2', 'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('series', 'slug')->ignore($series?->id),
            ],
            'primary_title' => ['required', 'string', 'min:2', 'max:300'],
            'format' => ['required', Rule::in(['manga', 'manhwa', 'manhua', 'webtoon'])],
            'original_language' => ['required', 'string', 'max:24', 'regex:/^[a-z]{2,3}(?:-[A-Za-z]{2,8})?$/D'],
            'completion_status' => ['required', Rule::in(['ongoing', 'completed', 'hiatus', 'cancelled'])],
            'synopsis' => ['nullable', 'string', 'max:10000'],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'illustrator_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'age_rating' => ['sometimes', 'nullable', Rule::in(['all', '12+', '16+', '18+'])],
            'aliases' => ['sometimes', 'array', 'max:8'],
            'aliases.*.locale' => ['required', 'string', 'max:24', 'regex:/^[a-z]{2,3}(?:-[A-Za-z]{2,8})?$/D'],
            'aliases.*.value' => ['required', 'string', 'min:2', 'max:300'],
            'tag_codes' => ['sometimes', 'array', 'max:12'],
            'tag_codes.*' => ['string', 'distinct', 'max:80', Rule::exists('tags', 'code')],
            'new_tag_code' => ['nullable', 'required_with:new_tag_name', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'],
            'new_tag_name' => ['nullable', 'required_with:new_tag_code', 'string', 'max:140'],
            'genre_codes' => ['sometimes', 'array', 'max:12'],
            'genre_codes.*' => ['string', 'distinct', 'max:80', Rule::exists('genres', 'code')],
            'new_genre_code' => ['nullable', 'required_with:new_genre_name', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'],
            'new_genre_name' => ['nullable', 'required_with:new_genre_code', 'string', 'max:140'],
        ]);
    }

    private function draftAttributes(array $data): array
    {
        // Explicit allow-list. Never accept publication_status/published_at from the request.
        $attributes = [
            'slug' => $data['slug'],
            'primary_title' => $data['primary_title'],
            'format' => $data['format'],
            'original_language' => $data['original_language'],
            'completion_status' => $data['completion_status'],
            'synopsis' => $data['synopsis'] ?? null,
        ];
        foreach (['author_name', 'illustrator_name', 'age_rating'] as $key) {
            // Legacy Gate09 callers omit these fields: don't erase their existing metadata.
            if (array_key_exists($key, $data)) $attributes[$key] = $data[$key];
        }
        return $attributes;
    }

    private function syncGenres(Series $series, array $data): void
    {
        $codes = $data['genre_codes'] ?? [];
        if (filled($data['new_genre_code'] ?? null)) {
            $code = $data['new_genre_code'];
            DB::table('genres')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'code' => $code,
                'name' => $data['new_genre_name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $codes[] = $code;
        }

        $genreIds = DB::table('genres')->whereIn('code', array_unique($codes))->pluck('id')->all();
        DB::table('series_genres')->where('series_id', $series->id)->delete();
        if ($genreIds !== []) {
            DB::table('series_genres')->insert(array_map(
                fn ($id) => ['series_id' => $series->id, 'genre_id' => $id],
                $genreIds
            ));
        }
    }

    private function syncTags(Series $series, array $data): void
    {
        if (! array_key_exists('tag_codes', $data) && blank($data['new_tag_code'] ?? null)) return;
        $codes = $data['tag_codes'] ?? [];
        if (filled($data['new_tag_code'] ?? null)) {
            DB::table('tags')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'code' => $data['new_tag_code'],
                'name' => $data['new_tag_name'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $codes[] = $data['new_tag_code'];
        }
        $ids = DB::table('tags')->whereIn('code', array_unique($codes))->pluck('id')->all();
        DB::table('series_tags')->where('series_id', $series->id)->delete();
        if ($ids !== []) {
            DB::table('series_tags')->insert(array_map(
                fn ($id) => ['series_id' => $series->id, 'tag_id' => $id], $ids
            ));
        }
    }

    private function syncAliases(Series $series, array $data): void
    {
        if (! array_key_exists('aliases', $data)) return;
        $rows = [];
        $seen = [];
        foreach ($data['aliases'] as $alias) {
            $locale = $alias['locale'];
            $value = trim($alias['value']);
            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $value), 'UTF-8');
            $key = $locale.'|'.$normalized;
            if (isset($seen[$key])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'aliases' => 'يوجد اسم بديل مكرر بنفس اللغة.',
                ]);
            }
            $seen[$key] = true;
            $rows[] = [
                'id' => (string) Str::ulid(), 'series_id' => $series->id,
                'locale' => $locale, 'value' => $value, 'normalized_value' => $normalized,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('title_aliases')->where('series_id', $series->id)->delete();
        if ($rows !== []) DB::table('title_aliases')->insert($rows);
    }

    private function audit(Request $request, Series $series, string $action): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_id' => $request->user()->getAuthIdentifier(),
            'subject_type' => 'series',
            'subject_id' => $series->id,
            'action' => $action,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
