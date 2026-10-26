<?php

namespace App\Services\Content;

use App\Models\ChapterRevision;
use App\Models\EditionChapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Shared synchronous/queued revision writer. Never edits published revisions. */
final class ChapterRevisionImporter
{
    /** @return array{revision: ChapterRevision, repeated: bool} */
    public function persist(
        EditionChapter $chapter,
        PreparedChapterPages $prepared,
        PrivateImages $images,
        int $actorId,
        ?string $requestKey,
    ): array {
        $stored = [];
        $revisionId = null;
        $repeated = false;
        try {
            DB::transaction(function () use ($chapter, $prepared, $images, $actorId, $requestKey, &$stored, &$revisionId, &$repeated): void {
                $locked = EditionChapter::query()->lockForUpdate()->findOrFail($chapter->id);
                abort_unless(in_array($locked->status, ['draft', 'published'], true), 409);
                if ($requestKey !== null) {
                    $previous = ChapterRevision::query()->where('import_request_key', $requestKey)->first();
                    if ($previous !== null) {
                        abort_unless($previous->edition_chapter_id === $locked->id && (int) $previous->submitter_id === $actorId, 409);
                        $revisionId = $previous->id;
                        $repeated = true;
                        return;
                    }
                }
                $revision = $locked->revisions()->create([
                    'revision_no' => ((int) $locked->revisions()->max('revision_no')) + 1,
                    'status' => 'draft', 'submitter_id' => $actorId,
                    'import_request_key' => $requestKey,
                ]);
                $revisionId = $revision->id;
                $prepared->each(function (string $bytes, int $index) use ($images, $revision, &$stored): void {
                    $asset = $images->storeImage($bytes, 'chapters');
                    $stored[] = $asset->storage_key;
                    $revision->pages()->create([
                        'media_asset_id' => $asset->id, 'page_index' => $index,
                        'width' => $asset->width, 'height' => $asset->height,
                    ]);
                });
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::ulid(), 'actor_id' => $actorId,
                    'subject_type' => 'chapter_revision', 'subject_id' => $revision->id,
                    'action' => 'chapter.revision_uploaded', 'occurred_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }, 3);
        } catch (\Throwable $e) {
            // Database rolls back on failure; clean up unreferenced private files.
            Storage::disk(PrivateImages::DISK)->delete($stored);
            throw $e;
        } finally {
            $prepared->close();
        }
        return ['revision' => ChapterRevision::query()->findOrFail($revisionId), 'repeated' => $repeated];
    }
}
