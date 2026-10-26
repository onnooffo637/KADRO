<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingProgress extends Model
{
    use HasUlids;

    # Writes go through ReadingSyncService to preserve optimistic-revision and max-progress invariants.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sync_revision' => 'integer',
            'last_page_index' => 'integer',
            'furthest_page_index' => 'integer',
            'last_read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function edition(): BelongsTo { return $this->belongsTo(Edition::class); }
    public function lastChapter(): BelongsTo { return $this->belongsTo(EditionChapter::class, 'last_edition_chapter_id'); }
    public function furthestChapter(): BelongsTo { return $this->belongsTo(EditionChapter::class, 'furthest_edition_chapter_id'); }
}
