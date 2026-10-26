<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InAppNotification extends Model
{
    use HasUlids;

    # Only event identifiers are stored, never unreviewed spoilers or previews.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function series(): BelongsTo { return $this->belongsTo(Series::class); }
    public function chapter(): BelongsTo { return $this->belongsTo(EditionChapter::class, 'edition_chapter_id'); }
    public function report(): BelongsTo { return $this->belongsTo(ContentReport::class, 'content_report_id'); }
}
