<?php

namespace App\Services\Content;

use App\Models\ChapterRevision;
use App\Models\EditionChapter;
use App\Models\RightsGrant;
use App\Models\Series;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Single, fail-closed publication boundary. Never trust frontend readiness flags. */
final class PublishChapter
{
    /** @return list<string> */
    public function problems(Series $series, EditionChapter $chapter, ?ChapterRevision $revision): array
    {
        $issues = [];
        $edition = $chapter->edition;
        $territory = config('manga_catalog.public_territory');
        if (! is_string($territory) || ! preg_match('/^[A-Z]{2}$/D', $territory)) {
            $issues[] = 'لم تُحدَّد منطقة الجمهور الموثوقة MANGA_PUBLIC_TERRITORY على الخادم.';
        }
        if (! $series->coverAsset || $series->coverAsset->status !== 'ready'
            || $series->coverAsset->storage_disk !== PrivateImages::DISK
            || ! Storage::disk(PrivateImages::DISK)->exists($series->coverAsset->storage_key)) {
            $issues[] = 'ارفع غلافًا صالحًا أولًا.';
        }
        if ($series->deleted_at || $edition->deleted_at || $chapter->deleted_at || $chapter->canonicalChapter->deleted_at) {
            $issues[] = 'لا يمكن نشر عمل أو إصدار أو فصل محذوف.';
        }
        if (! in_array($series->publication_status, ['draft', 'reviewed', 'published'], true)
            || ! in_array($edition->status, ['draft', 'submitted', 'reviewed', 'published'], true)
            || ! in_array($chapter->status, ['draft', 'submitted', 'reviewed', 'published'], true)) {
            $issues[] = 'حالة العمل أو الإصدار أو الفصل لا تسمح بالنشر.';
        }
        if ($revision === null || ! in_array($revision->status, ['draft', 'submitted', 'reviewed', 'published'], true)) {
            $issues[] = 'لا توجد مراجعة قابلة للنشر.';
        } else {
            $pages = $revision->pages()->with('asset')->orderBy('page_index')->get();
            if ($pages->isEmpty()) $issues[] = 'الفصل لا يحتوي صفحات.';
            foreach ($pages as $i => $page) {
                if ((int) $page->page_index !== $i + 1) {
                    $issues[] = 'ترتيب الصفحات يجب أن يكون متصلًا من 1 إلى N.';
                    break;
                }
                if (! $page->asset || $page->asset->status !== 'ready'
                    || $page->asset->storage_disk !== PrivateImages::DISK
                    || ! $page->asset->width || ! $page->asset->height
                    || ! Storage::disk(PrivateImages::DISK)->exists($page->asset->storage_key)) {
                    $issues[] = 'بعض صور الفصل غير جاهزة.';
                    break;
                }
            }
        }
        $now = CarbonImmutable::now('UTC');
        $originalOwned = $series->is_original_content === true
            && $series->original_content_declared_at !== null
            && $series->original_content_declared_by !== null;
        // Self-owned originals need a one-time declaration from the verified admin,
        // not a third-party rights document. All other publication checks remain.
        if (! $originalOwned && is_string($territory) && preg_match('/^[A-Z]{2}$/D', $territory)) {
            $grant = RightsGrant::query()
                ->where('series_id', $series->id)
                ->where(fn ($query) => $query->whereNull('edition_id')->orWhere('edition_id', $edition->id))
                ->where('status', 'active')->where('can_publish', true)
                ->where('valid_from', '<=', $now)->where('valid_until', '>', $now)
                ->when($edition->language_code !== $series->original_language, fn ($q) => $q->where('can_translate', true))
                ->whereHas('license', fn ($q) => $q->where('approval_status', 'approved')
                    ->whereNotNull('approved_by')->whereNotNull('approved_at')
                    ->where('valid_from', '<=', $now)->where('valid_until', '>', $now))
                ->whereHas('territories', fn ($q) => $q->whereIn('territory_code', [$territory, 'GLOBAL']))
                ->whereHas('languages', fn ($q) => $q->where('language_code', $edition->language_code))
                ->exists();
            if (! $grant) $issues[] = 'لا يوجد تصريح نشر وترجمة فعّال ومعتمد لهذه اللغة والمنطقة والفترة.';
        }
        return $issues;
    }

    public function execute(Series $series, EditionChapter $chapter, string $revisionId, int $actorId): void
    {
        DB::transaction(function () use ($series, $chapter, $revisionId, $actorId): void {
            $series = Series::query()->lockForUpdate()->findOrFail($series->id);
            $chapter = EditionChapter::query()->lockForUpdate()->findOrFail($chapter->id);
            abort_unless($chapter->series_id === $series->id, 404);
            $revision = ChapterRevision::query()->lockForUpdate()
                ->where('edition_chapter_id', $chapter->id)->findOrFail($revisionId);
            if ($chapter->status === 'published' && $chapter->current_published_revision_id === $revision->id
                && $revision->status === 'published') return; // idempotent retry
            $issues = $this->problems($series, $chapter, $revision);
            if ($issues !== []) throw ValidationException::withMessages(['publish' => implode(' ', $issues)]);
            $pages = $revision->pages()->with('asset')->orderBy('page_index')->get();
            $manifest = hash('sha256', $pages->map(fn ($page) => implode('|', [
                $page->page_index, $page->asset->id, $page->asset->sha256,
            ]))->implode("\n"));
            $now = now();
            if (in_array($revision->status, ['draft', 'submitted'], true)) {
                $revision->update([
                    'status' => 'reviewed', 'asset_manifest_hash' => $manifest,
                    'reviewed_at' => $now, 'submitted_at' => $revision->submitted_at ?? $now,
                ]);
            } else {
                abort_unless(hash_equals((string) $revision->asset_manifest_hash, $manifest), 409,
                    'Manifest was changed after review.');
            }
            // Trigger manga_revision_immutable permits exactly reviewed -> published.
            if ($revision->status === 'reviewed') $revision->update(['status' => 'published', 'published_at' => $now]);
            $chapter->canonicalChapter->update(['status' => 'published']);
            $chapter->update(['status' => 'published', 'current_published_revision_id' => $revision->id]);
            $chapter->edition->update(['status' => 'published', 'published_at' => $chapter->edition->published_at ?? $now]);
            $series->update(['publication_status' => 'published', 'published_at' => $series->published_at ?? $now]);
            DB::table('audit_logs')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'actor_id' => $actorId,
                'subject_type' => 'edition_chapter', 'subject_id' => $chapter->id,
                'action' => 'chapter.published', 'occurred_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }, 3);
    }
}
