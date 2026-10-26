<?php

namespace App\Domain\Reading;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReadingSyncService
{
    # Internal domain service: authentication and publication rights are NOT enforced here.
    # Gates 07/10 must authorize the user and chapter before exposing any API endpoint.
    # Offline clients must send queued operations sequentially and rebase each acknowledgment.
    # Client timestamps are never used to resolve conflicts.
    public function apply(
        int $userId,
        string $editionId,
        string $editionChapterId,
        int $pageIndex,
        int $baseRevision,
        string $clientOperationId
    ): array {
        if (DB::getDriverName() !== 'pgsql') {
            throw new DomainException('PostgreSQL is required for reading sync.');
        }
        if ($userId < 1 || $pageIndex < 1 || $pageIndex > 1000000 || $baseRevision < 0) {
            throw new DomainException('Invalid reading progress arguments.');
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $clientOperationId) !== 1) {
            throw new DomainException('Client operation must be a UUID.');
        }

        return DB::transaction(function () use ($userId, $editionId, $editionChapterId, $pageIndex, $baseRevision, $clientOperationId): array {
            $chapter = DB::table('edition_chapters')
                ->where('id', $editionChapterId)
                ->where('edition_id', $editionId)
                ->first(['id', 'canonical_chapter_id']);
            if ($chapter === null) {
                throw new DomainException('Chapter does not belong to the specified edition.');
            }

            $now = now('UTC');
            DB::table('reading_progress')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'user_id' => $userId, 'edition_id' => $editionId,
                'sync_revision' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $progress = DB::table('reading_progress')
                ->where('user_id', $userId)->where('edition_id', $editionId)
                ->lockForUpdate()->first();
            if ($progress === null) {
                throw new DomainException('Unable to initialize reading progress.');
            }

            # Lock the per-edition progress row before checking the operation ledger.
            # This serializes multiple devices and prevents duplicate writes racing.
            $replay = DB::table('reading_sync_operations')
                ->where('user_id', $userId)->where('client_operation_id', $clientOperationId)->first();
            if ($replay !== null) {
                if ($replay->edition_id !== $editionId || $replay->edition_chapter_id !== $editionChapterId
                    || (int) $replay->page_index !== $pageIndex || (int) $replay->base_revision !== $baseRevision) {
                    throw new DomainException('Client operation ID was reused with a different payload.');
                }
                return [
                    'operation_id' => $clientOperationId,
                    'revision' => (int) $replay->applied_revision,
                    'updated_last' => (bool) $replay->updated_last_position,
                    'advanced_furthest' => (bool) $replay->advanced_furthest_position,
                    'replay' => true,
                ];
            }

            $currentRevision = (int) $progress->sync_revision;
            if ($baseRevision > $currentRevision) {
                throw new DomainException('Client base revision is ahead of the server.');
            }
            $updatedLast = $baseRevision === $currentRevision;
            $advancedFurthest = $this->isFurtherAhead($editionChapterId, $pageIndex, $progress);
            $changes = ['sync_revision' => $currentRevision + 1, 'updated_at' => $now];
            if ($updatedLast) {
                $changes['last_edition_chapter_id'] = $editionChapterId;
                $changes['last_page_index'] = $pageIndex;
                $changes['last_read_at'] = $now;
            }
            if ($advancedFurthest) {
                $changes['furthest_edition_chapter_id'] = $editionChapterId;
                $changes['furthest_page_index'] = $pageIndex;
            }
            DB::table('reading_progress')->where('id', $progress->id)->update($changes);
            DB::table('reading_sync_operations')->insert([
                'id' => (string) Str::ulid(),
                'reading_progress_id' => $progress->id,
                'user_id' => $userId,
                'edition_id' => $editionId,
                'edition_chapter_id' => $editionChapterId,
                'client_operation_id' => $clientOperationId,
                'page_index' => $pageIndex,
                'base_revision' => $baseRevision,
                'applied_revision' => $currentRevision + 1,
                'updated_last_position' => $updatedLast,
                'advanced_furthest_position' => $advancedFurthest,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return [
                'operation_id' => $clientOperationId,
                'revision' => $currentRevision + 1,
                'updated_last' => $updatedLast,
                'advanced_furthest' => $advancedFurthest,
                'replay' => false,
            ];
        }, 3);
    }

    private function isFurtherAhead(string $chapterId, int $pageIndex, object $progress): bool
    {
        if ($progress->furthest_edition_chapter_id === null) {
            return true;
        }
        $newRank = DB::table('edition_chapters AS ec')
            ->join('canonical_chapters AS cc', 'cc.id', '=', 'ec.canonical_chapter_id')
            ->where('ec.id', $chapterId)->value('cc.sort_key');
        $oldRank = DB::table('edition_chapters AS ec')
            ->join('canonical_chapters AS cc', 'cc.id', '=', 'ec.canonical_chapter_id')
            ->where('ec.id', $progress->furthest_edition_chapter_id)->value('cc.sort_key');
        if ($newRank === null || $oldRank === null) {
            throw new DomainException('A referenced reading chapter is no longer available.');
        }
        # Numeric comparison in PostgreSQL avoids float precision loss for DECIMAL(20,8).
        $comparison = DB::selectOne(
            'SELECT CASE WHEN CAST(? AS numeric) > CAST(? AS numeric) THEN 1 WHEN CAST(? AS numeric) < CAST(? AS numeric) THEN -1 ELSE 0 END AS result',
            [$newRank, $oldRank, $newRank, $oldRank]
        );
        $rankResult = (int) $comparison->result;
        return $rankResult > 0 || ($rankResult === 0 && $pageIndex > (int) $progress->furthest_page_index);
    }
}
