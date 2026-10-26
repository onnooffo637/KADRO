<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChapterRevision;
use App\Models\ChapterImport;
use App\Jobs\ProcessChapterImport;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\MediaAsset;
use App\Models\RightsGrant;
use App\Models\RightsLicense;
use App\Models\Series;
use App\Models\CanonicalChapter;
use App\Models\RevisionPage;
use App\Services\Content\PrivateImages;
use App\Services\Content\PublishChapter;
use App\Services\Content\SafeChapterZip;
use App\Services\Content\ChapterUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** All actions inherit auth + verified + platform.admin from routes. */
class SeriesContentController extends Controller
{
    private function assertEdition(Series $series, Edition $edition): void
    {
        abort_unless($edition->series_id === $series->id, 404);
    }

    private function assertChapter(Series $series, EditionChapter $chapter): void
    {
        abort_unless($chapter->series_id === $series->id, 404);
    }

    private function audit(Request $request, string $type, string $id, string $action): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(), 'actor_id' => $request->user()->id,
            'subject_type' => $type, 'subject_id' => $id,
            'action' => $action, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function show(Request $request, Series $series, PublishChapter $publisher): Response
    {
        $editions = $series->editions()->orderBy('created_at')->get();
        $chapters = EditionChapter::query()->where('series_id', $series->id)
            ->with(['edition', 'canonicalChapter', 'revisions.pages.asset'])
            ->whereNull('deleted_at')->get()
            ->sortBy(fn ($chapter) => $chapter->canonicalChapter->sort_key)->values();
        $licenses = RightsLicense::query()
            ->whereHas('grants', fn ($q) => $q->where('series_id', $series->id))
            ->orderByDesc('created_at')->limit(30)->get();
        $grants = $series->grants()->with(['license', 'territories', 'languages'])->get();
        $chaptersData = $chapters->map(function ($chapter) use ($series, $publisher) {
            $revisions = $chapter->revisions->sortByDesc('revision_no')->values();
            $latest = $revisions->first();
            return [
                'id' => $chapter->id, 'edition_id' => $chapter->edition_id,
                'display_number' => $chapter->canonicalChapter->display_number,
                'sort_key' => $chapter->canonicalChapter->sort_key,
                'title' => $chapter->canonicalChapter->title, 'status' => $chapter->status,
                'published_revision_id' => $chapter->current_published_revision_id,
                'issues' => $publisher->problems($series, $chapter, $latest),
                'revisions' => $revisions->map(fn ($rev) => [
                    'id' => $rev->id, 'revision_no' => $rev->revision_no, 'status' => $rev->status,
                    'pages' => $rev->pages->sortBy('page_index')->values()->map(fn ($page) => [
                        'id' => $page->id, 'asset_id' => $page->media_asset_id,
                        'index' => $page->page_index, 'width' => $page->width, 'height' => $page->height,
                        'url' => route('admin.content.media', [$series, $page->asset]),
                    ])->all(),
                ])->all(),
            ];
        })->all();
        return Inertia::render('admin/series-content', [
            'series' => [
                'id' => $series->id, 'title' => $series->primary_title, 'status' => $series->publication_status,
                'slug' => $series->slug, 'format' => $series->format,
                'is_original_content' => (bool) $series->is_original_content,
                'author_name' => $series->author_name, 'illustrator_name' => $series->illustrator_name,
                'age_rating' => $series->age_rating, 'original_language' => $series->original_language,
                'cover_url' => $series->coverAsset?->status === 'ready'
                    ? route('admin.content.media', [$series, $series->coverAsset]) : null,
            ],
            'editions' => $editions->map(fn ($edition) => [
                'id' => $edition->id, 'label' => $edition->label, 'language' => $edition->language_code,
                'status' => $edition->status, 'reading_direction' => $edition->reading_direction_hint,
            ])->all(),
            'chapters' => $chaptersData,
            'async_imports_enabled' => (bool) config('manga_content.async_imports', false),
            'imports' => ChapterImport::query()->where('series_id', $series->id)
                ->orderByDesc('created_at')->limit(60)
                ->get(['id', 'edition_chapter_id', 'status', 'revision_id', 'error_message', 'created_at'])
                ->map(fn ($import) => [
                    'id' => $import->id, 'chapter_id' => $import->edition_chapter_id,
                    'status' => $import->status, 'revision_id' => $import->revision_id,
                    'error_message' => $import->error_message, 'created_at' => $import->created_at,
                ])->all(),
            'licenses' => $licenses->map(fn ($license) => [
                'id' => $license->id, 'holder_name' => $license->holder_name,
                'status' => $license->approval_status,
                'valid_from' => $license->valid_from, 'valid_until' => $license->valid_until,
            ])->all(),
            'grants' => $grants->map(fn ($grant) => [
                'id' => $grant->id, 'license_id' => $grant->license_id,
                'edition_id' => $grant->edition_id, 'status' => $grant->status,
                'territories' => $grant->territories->pluck('territory_code')->all(),
                'languages' => $grant->languages->pluck('language_code')->all(),
            ])->all(),
            'success' => session('success'),
        ]);
    }

    public function details(Request $request, Series $series): RedirectResponse
    {
        abort_unless(in_array($series->publication_status, ['draft', 'published'], true), 409);
        $data = $request->validate([
            'author_name' => ['nullable', 'string', 'max:200'],
            'illustrator_name' => ['nullable', 'string', 'max:200'],
            'age_rating' => ['nullable', Rule::in(['all', '12+', '16+', '18+'])],
        ]);
        DB::transaction(function () use ($series, $data, $request): void {
            $series->update($data);
            $this->audit($request, 'series', $series->id, 'series.metadata_updated');
        });
        return back()->with('success', 'تم تحديث بيانات العمل.');
    }

    /** One-time per-work declaration for ORIGINAL art owned by the platform operator. */
    public function confirmOriginal(Request $request, Series $series): RedirectResponse
    {
        $request->validate(['confirm_original' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $series): void {
            $locked = Series::query()->lockForUpdate()->findOrFail($series->id);
            if ($locked->is_original_content && $locked->original_content_declared_at) return;
            $locked->forceFill([
                'is_original_content' => true,
                'original_content_declared_at' => now(),
                'original_content_declared_by' => $request->user()->id,
            ])->save();
            $this->audit($request, 'series', $locked->id, 'series.original_ownership_confirmed');
        });
        return back()->with('success', 'تم اعتماد العمل كإنتاج أصلي مملوك لك. يمكنك نشر فصوله بعد مراجعة الغلاف والصفحات.');
    }

    /** An incorrect ownership declaration can be withdrawn, immediately hiding unlicensed public access. */
    public function revokeOriginal(Request $request, Series $series): RedirectResponse
    {
        $request->validate(['confirm_revoke' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $series): void {
            $locked = Series::query()->lockForUpdate()->findOrFail($series->id);
            if (! $locked->is_original_content) return;
            $locked->forceFill([
                'is_original_content' => false,
                'original_content_declared_at' => null,
                'original_content_declared_by' => null,
            ])->save();
            $this->audit($request, 'series', $locked->id, 'series.original_ownership_revoked');
        });
        return back()->with('success', 'تم إلغاء اعتماد الملكية الأصلية. المحتوى غير المرخّص لن يظهر للقراء.');
    }

    public function cover(Request $request, Series $series, PrivateImages $images): RedirectResponse
    {
        abort_unless(in_array($series->publication_status, ['draft', 'published'], true), 409);
        $request->validate(['upload' => ['required', 'file', 'max:12288']]);
        $asset = $images->fromUpload($request->file('upload'), 'covers');
        try {
            DB::transaction(function () use ($series, $asset, $request): void {
                $series->update(['cover_media_asset_id' => $asset->id]);
                $this->audit($request, 'series', $series->id, 'series.cover_updated');
            });
        } catch (\Throwable $e) {
            Storage::disk(PrivateImages::DISK)->delete($asset->storage_key);
            $asset->delete();
            throw $e;
        }
        return back()->with('success', 'تم حفظ الغلاف بصورة خاصة وآمنة.');
    }

    public function edition(Request $request, Series $series): RedirectResponse
    {
        abort_unless(in_array($series->publication_status, ['draft', 'published'], true), 409);
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:180'],
            'language_code' => ['required', 'string', 'max:24', 'regex:/^[a-z]{2,3}(?:-[A-Za-z]{2,8})?$/D'],
            'reading_direction_hint' => ['required', Rule::in(['auto', 'rtl', 'ltr', 'vertical'])],
        ]);
        DB::transaction(function () use ($series, $data, $request): void {
            $edition = $series->editions()->create($data + ['status' => 'draft']);
            $this->audit($request, 'edition', $edition->id, 'edition.draft_created');
        });
        return back()->with('success', 'تم إنشاء الإصدار كمسودة.');
    }

    public function chapter(Request $request, Series $series, Edition $edition): RedirectResponse
    {
        $this->assertEdition($series, $edition);
        abort_unless(in_array($edition->status, ['draft', 'published'], true), 409);
        $data = $request->validate([
            'display_number' => ['required', 'string', 'max:32'],
            'sort_key' => ['required', 'numeric', 'between:0,9999999.99999999'],
            'title' => ['nullable', 'string', 'max:300'],
            'kind' => ['required', Rule::in(['regular', 'extra', 'prologue', 'epilogue', 'special'])],
        ]);
        DB::transaction(function () use ($series, $edition, $data, $request): void {
            $canonical = CanonicalChapter::query()->where('series_id', $series->id)
                ->where('sort_key', $data['sort_key'])->first();
            if ($canonical) {
                if ($canonical->display_number !== $data['display_number'] || $canonical->kind !== $data['kind']) {
                    throw ValidationException::withMessages(['sort_key' => 'ترتيب الفصل مستخدم لرقم أو نوع فصل مختلف.']);
                }
                if ($edition->editionChapters()->where('canonical_chapter_id', $canonical->id)->exists()) {
                    throw ValidationException::withMessages(['sort_key' => 'الفصل موجود في هذا الإصدار بالفعل.']);
                }
            } else {
                $canonical = CanonicalChapter::create([
                    'series_id' => $series->id, 'sort_key' => $data['sort_key'],
                    'display_number' => $data['display_number'], 'title' => $data['title'] ?? null,
                    'kind' => $data['kind'], 'status' => 'draft',
                ]);
            }
            $chapter = EditionChapter::create([
                'series_id' => $series->id, 'edition_id' => $edition->id,
                'canonical_chapter_id' => $canonical->id, 'status' => 'draft',
            ]);
            $this->audit($request, 'edition_chapter', $chapter->id, 'chapter.draft_created');
        });
        return back()->with('success', 'تم إنشاء الفصل، ارفع ZIP الخاص به الآن.');
    }

    // Legacy Gate10 endpoint remains ZIP-only for backward compatibility.
    public function zip(Request $request, Series $series, EditionChapter $chapter, ChapterUpload $imports, PrivateImages $images): RedirectResponse
    {
        return $this->saveChapterUpload($request, $series, $chapter, $imports, $images, true);
    }

    // Gate11 endpoint: synchronous fallback is preserved when background processing is disabled.
    public function upload(Request $request, Series $series, EditionChapter $chapter, ChapterUpload $imports, PrivateImages $images): RedirectResponse
    {
        if (config('manga_content.async_imports', false)) {
            return $this->queueChapterUpload($request, $series, $chapter);
        }
        return $this->saveChapterUpload($request, $series, $chapter, $imports, $images, false);
    }

    private function queueChapterUpload(Request $request, Series $series, EditionChapter $chapter): RedirectResponse
    {
        $this->assertChapter($series, $chapter);
        abort_unless(in_array($chapter->status, ['draft', 'published'], true), 409);
        // Fail closed if a worker cannot receive the job on the same PostgreSQL connection.
        abort_unless(config('queue.default') === 'database'
            && in_array(config('queue.connections.database.connection'), [null, config('database.default')], true),
            503, 'Background uploads require the database queue on the application database.');
        $request->validate([
            'import_request_key' => ['required', 'uuid'],
            'upload' => ['nullable', 'file', 'max:61440'],
            'images' => ['nullable', 'array', 'min:1', 'max:200'],
            'images.*' => ['required', 'file', 'max:12288'],
        ]);
        $key = $request->input('import_request_key');
        $existing = ChapterImport::query()->where('request_key', $key)->first();
        if ($existing) {
            abort_unless($existing->edition_chapter_id === $chapter->id
                && (int) $existing->actor_id === (int) $request->user()->id, 409);
            return back()->with('success', 'الطلب مسجل بالفعل؛ راجع حالة المعالجة بالأسفل.');
        }
        // Keep existing Gate12A synchronous retry semantics for previous completed uploads.
        $previousRevision = ChapterRevision::query()->where('import_request_key', $key)->first();
        if ($previousRevision) {
            abort_unless($previousRevision->edition_chapter_id === $chapter->id
                && (int) $previousRevision->submitter_id === (int) $request->user()->id, 409);
            return back()->with('success', 'المراجعة موجودة بالفعل؛ لم تُكرر عملية الرفع.');
        }
        $single = $request->file('upload');
        $photos = $request->file('images', []);
        if (! is_array($photos) || ($single === null) === ($photos === [])) {
            throw ValidationException::withMessages(['upload' => 'اختر ملفًا واحدًا أو مجموعة صور، وليس الطريقتين.']);
        }
        $files = $single ? [$single] : $photos;
        $allowed = $single ? ['zip', 'cbz', 'pdf', 'jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png', 'webp'];
        $total = 0;
        foreach ($files as $file) {
            if (! $file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid()
                || ! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
                throw ValidationException::withMessages(['upload' => 'صيغة الملف غير مدعومة أو الملف غير صالح.']);
            }
            $size = (int) $file->getSize();
            $total += $size;
            if ($size < 1 || $size > ($single && ! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)
                    ? 60 : 12) * 1024 * 1024 || $total > 60 * 1024 * 1024) {
                throw ValidationException::withMessages(['upload' => 'تم تجاوز حجم الرفع المسموح للفصل.']);
            }
        }
        $prefix = 'import-staging/'.(string) Str::ulid();
        $disk = Storage::disk(PrivateImages::DISK);
        $manifest = [];
        try {
            foreach ($files as $index => $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $keyName = $prefix.'/'.sprintf('%03d', $index).'-'.Str::random(20).'.'.$extension;
                $saved = $disk->putFileAs($prefix, $file, basename($keyName));
                if (! is_string($saved) || $saved !== $keyName) {
                    throw new \RuntimeException('Private staging failed.');
                }
                $originalName = preg_replace('/[\x00-\x1F\x7F]/', '_', str_replace(['\\', '/'], '_', $file->getClientOriginalName()));
                $manifest[] = ['key' => $saved, 'name' => mb_substr($originalName, 0, 190), 'is_image_batch' => $single === null];
            }
            DB::transaction(function () use ($series, $chapter, $request, $key, $prefix, $manifest): void {
                $import = ChapterImport::query()->create([
                    'series_id' => $series->id, 'edition_chapter_id' => $chapter->id,
                    'actor_id' => $request->user()->id, 'request_key' => $key,
                    'status' => 'queued', 'staging_prefix' => $prefix,
                    'files' => $manifest, 'archive_only' => false,
                ]);
                // Job row and import receipt are inserted into the SAME DB transaction.
                ProcessChapterImport::dispatch($import->id)
                    ->onConnection('database')->onQueue('manga-imports')->beforeCommit();
                $this->audit($request, 'chapter_import', $import->id, 'chapter.import_queued');
            });
        } catch (\Throwable $e) {
            $disk->deleteDirectory($prefix);
            throw $e;
        }
        return back()->with('success', 'تم استقبال الملف؛ سيبدأ عامل الخلفية تحويل الصفحات. تابع الحالة أسفل نموذج الرفع.');
    }

    private function saveChapterUpload(
        Request $request, Series $series, EditionChapter $chapter,
        ChapterUpload $imports, PrivateImages $images, bool $archiveOnly
    ): RedirectResponse {
        $this->assertChapter($series, $chapter);
        abort_unless(in_array($chapter->status, ['draft', 'published'], true), 409);
        // Optional retry key: legacy ZIP clients remain compatible without this field.
        // A database uniqueness constraint makes retries safe even across PHP workers.
        $request->validate(['import_request_key' => ['nullable', 'uuid']]);
        $key = $request->input('import_request_key');
        $key = is_string($key) && $key !== '' ? $key : null;
        if ($key !== null) {
            $existing = ChapterRevision::query()->where('import_request_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->edition_chapter_id === $chapter->id
                    && (string) $existing->submitter_id === (string) $request->user()->id, 409);
                return back()->with('success', 'تم استلام نفس الطلب سابقًا؛ المراجعة موجودة ولن يتم تكرارها.');
            }
        }
        // Preparation checks the complete input and rasterizes PDF before opening a DB transaction.
        $prepared = $imports->prepare($request, $archiveOnly);
        $stored = [];
        $alreadyImported = false;
        try {
            DB::transaction(function () use ($series, $chapter, $prepared, $images, $request, $key, &$stored, &$alreadyImported): void {
                $locked = EditionChapter::query()->lockForUpdate()->findOrFail($chapter->id);
                abort_unless($locked->series_id === $series->id, 404);
                // Locking the chapter serializes concurrent requests with the same token.
                if ($key !== null) {
                    $existing = ChapterRevision::query()->where('import_request_key', $key)->first();
                    if ($existing !== null) {
                        abort_unless($existing->edition_chapter_id === $locked->id
                            && (string) $existing->submitter_id === (string) $request->user()->id, 409);
                        $alreadyImported = true;
                        return;
                    }
                }
                $no = ((int) $locked->revisions()->max('revision_no')) + 1;
                $revision = $locked->revisions()->create([
                    'revision_no' => $no, 'status' => 'draft', 'submitter_id' => $request->user()->id,
                    'import_request_key' => $key,
                ]);
                $prepared->each(function (string $bytes, int $index) use ($images, $revision, &$stored): void {
                    $asset = $images->storeImage($bytes, 'chapters');
                    $stored[] = $asset->storage_key;
                    $revision->pages()->create([
                        'media_asset_id' => $asset->id, 'page_index' => $index,
                        'width' => $asset->width, 'height' => $asset->height,
                    ]);
                });
                $this->audit($request, 'chapter_revision', $revision->id, 'chapter.revision_uploaded');
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk(PrivateImages::DISK)->delete($stored);
            throw $e;
        } finally {
            $prepared->close();
        }
        return back()->with('success', $alreadyImported
            ? 'تم استلام نفس الطلب سابقًا؛ المراجعة موجودة ولن يتم تكرارها.'
            : 'تم تجهيز صفحات الفصل في مراجعة خاصة جديدة؛ راجع الصور والترتيب قبل النشر.');
    }

    public function reorder(Request $request, Series $series, ChapterRevision $revision): RedirectResponse
    {
        $chapter = $revision->editionChapter;
        $this->assertChapter($series, $chapter);
        abort_unless($revision->status === 'draft', 409, 'Reviewed or published pages are immutable.');
        $data = $request->validate(['page_ids' => ['required', 'array', 'min:1', 'max:200'],
            'page_ids.*' => ['required', 'ulid', 'distinct']]);
        DB::transaction(function () use ($revision, $data, $request): void {
            $locked = ChapterRevision::query()->lockForUpdate()->findOrFail($revision->id);
            abort_unless($locked->status === 'draft', 409);
            $pages = $locked->pages()->orderBy('page_index')->get();
            abort_unless($pages->count() === count($data['page_ids'])
                && $pages->pluck('id')->sort()->values()->all() === collect($data['page_ids'])->sort()->values()->all(), 422);
            foreach ($pages as $i => $page) $page->update(['page_index' => $i + 100001]);
            foreach ($data['page_ids'] as $i => $id) RevisionPage::query()->whereKey($id)->update(['page_index' => $i + 1]);
            $this->audit($request, 'chapter_revision', $locked->id, 'chapter.pages_reordered');
        });
        return back()->with('success', 'تم حفظ ترتيب الصفحات.');
    }

    public function ready(Request $request, Series $series, EditionChapter $chapter, PublishChapter $publisher): \Illuminate\Http\JsonResponse
    {
        $this->assertChapter($series, $chapter);
        $revision = $chapter->revisions()->orderByDesc('revision_no')->first();
        $issues = $publisher->problems($series, $chapter, $revision);
        return response()->json(['ready' => $issues === [], 'issues' => $issues, 'revision_id' => $revision?->id]);
    }

    public function publish(Request $request, Series $series, EditionChapter $chapter, PublishChapter $publisher): RedirectResponse
    {
        $this->assertChapter($series, $chapter);
        $data = $request->validate(['revision_id' => ['required', 'ulid']]);
        $publisher->execute($series, $chapter, $data['revision_id'], $request->user()->id);
        return back()->with('success', 'تم النشر بعد التحقق من الحقوق والصفحات.');
    }

    public function withdraw(Request $request, Series $series): RedirectResponse
    {
        $request->validate(['confirm' => ['required', Rule::in(['WITHDRAW'])]]);
        DB::transaction(function () use ($series, $request): void {
            $series = Series::query()->lockForUpdate()->findOrFail($series->id);
            if ($series->publication_status === 'revoked') return;
            $series->update(['publication_status' => 'revoked']);
            $this->audit($request, 'series', $series->id, 'series.publication_withdrawn');
        });
        return back()->with('success', 'تم سحب العمل من العرض العام دون حذف الصفحات أو السجل.');
    }

    public function license(Request $request, Series $series): RedirectResponse
    {
        $data = $request->validate([
            'holder_name' => ['required', 'string', 'max:200'],
            'valid_from' => ['required', 'date'], 'valid_until' => ['required', 'date', 'after:valid_from'],
            'document' => ['required', 'file', 'max:10240'],
        ]);
        $file = $request->file('document');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['document' => 'مستند الحقوق يجب أن يكون PDF أو JPG أو PNG صالحًا.']);
        }
        $key = 'rights/'.Str::random(50).(['application/pdf' => '.pdf', 'image/jpeg' => '.jpg', 'image/png' => '.png'][$mime]);
        Storage::disk(PrivateImages::DISK)->put($key, file_get_contents($file->getRealPath()));
        try {
            DB::transaction(function () use ($data, $key, $series, $request): void {
                $license = RightsLicense::create([
                    'holder_name' => $data['holder_name'], 'document_ref' => 'private:'.$key,
                    'approval_status' => 'pending', 'valid_from' => $data['valid_from'],
                    'valid_until' => $data['valid_until'],
                ]);
                // Initial pending grant gives access to the license in this series' workbench.
                $grant = RightsGrant::create([
                    'license_id' => $license->id, 'series_id' => $series->id,
                    'status' => 'pending', 'can_publish' => false, 'can_translate' => false,
                    'can_offline' => false, 'valid_from' => $data['valid_from'],
                    'valid_until' => $data['valid_until'],
                ]);
                $this->audit($request, 'rights_license', $license->id, 'license.proof_uploaded');
            });
        } catch (\Throwable $e) {
            Storage::disk(PrivateImages::DISK)->delete($key);
            throw $e;
        }
        return back()->with('success', 'تم رفع مستند الترخيص كمسودة معلقة للمراجعة.');
    }


    public function proof(Series $series, RightsLicense $license): StreamedResponse
    {
        abort_unless($license->grants()->where('series_id', $series->id)->exists(), 404);
        abort_unless(str_starts_with($license->document_ref, 'private:'), 404);
        $key = substr($license->document_ref, 8);
        $disk = Storage::disk(PrivateImages::DISK);
        abort_unless($disk->exists($key), 404);
        $mime = match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', default => null,
        };
        abort_unless(in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true), 404);
        return response()->stream(function () use ($disk, $key): void {
            $handle = $disk->readStream($key);
            if (! is_resource($handle)) return;
            try { fpassthru($handle); } finally { fclose($handle); }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, Series $series, RightsLicense $license): RedirectResponse
    {
        abort_unless($license->grants()->where('series_id', $series->id)->exists(), 404);
        $request->validate(['confirmed_rights' => ['required', 'accepted']]);
        abort_unless($license->approval_status === 'pending' && str_starts_with($license->document_ref, 'private:'), 409);
        abort_unless(Storage::disk(PrivateImages::DISK)->exists(substr($license->document_ref, 8)), 422);
        DB::transaction(function () use ($license, $request): void {
            $locked = RightsLicense::query()->lockForUpdate()->findOrFail($license->id);
            abort_unless($locked->approval_status === 'pending', 409);
            $locked->update(['approval_status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->audit($request, 'rights_license', $locked->id, 'license.explicitly_approved');
        });
        return back()->with('success', 'تم اعتماد المستند بعد تأكيد الحقوق صراحةً؛ لم يُنشر المحتوى بعد.');
    }

    public function grant(Request $request, Series $series): RedirectResponse
    {
        $data = $request->validate([
            'license_id' => ['required', 'ulid'], 'edition_id' => ['required', 'ulid'],
            'territory_code' => ['required', 'string', 'regex:/^(?:[A-Z]{2}|GLOBAL)$/D'],
            'can_translate' => ['required', 'boolean'],
        ]);
        $license = RightsLicense::findOrFail($data['license_id']);
        abort_unless($license->grants()->where('series_id', $series->id)->exists() && $license->approval_status === 'approved', 403);
        $edition = Edition::findOrFail($data['edition_id']);
        $this->assertEdition($series, $edition);
        DB::transaction(function () use ($license, $edition, $series, $data, $request): void {
            $grant = RightsGrant::create([
                'license_id' => $license->id, 'series_id' => $series->id, 'edition_id' => $edition->id,
                'can_publish' => true, 'can_translate' => (bool) $data['can_translate'],
                'can_offline' => false, 'status' => 'active',
                'valid_from' => $license->valid_from, 'valid_until' => $license->valid_until,
            ]);
            DB::table('rights_territories')->insert(['grant_id' => $grant->id, 'territory_code' => $data['territory_code']]);
            DB::table('rights_languages')->insert(['grant_id' => $grant->id, 'language_code' => $edition->language_code]);
            $this->audit($request, 'rights_grant', $grant->id, 'rights.grant_activated');
        });
        return back()->with('success', 'تم تفعيل نطاق التصريح للإصدار. تحقق من الجاهزية قبل النشر.');
    }

    public function media(Series $series, MediaAsset $asset, PrivateImages $images): StreamedResponse
    {
        $isCover = $series->cover_media_asset_id === $asset->id;
        $isChapter = RevisionPage::query()->where('media_asset_id', $asset->id)
            ->whereHas('revision.editionChapter', fn ($q) => $q->where('series_id', $series->id))->exists();
        abort_unless($isCover || $isChapter, 404);
        return $images->stream($asset);
    }
}
