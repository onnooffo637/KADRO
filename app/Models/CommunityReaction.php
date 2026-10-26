<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityReaction extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function review(): BelongsTo { return $this->belongsTo(SeriesReview::class, 'series_review_id'); }
    public function comment(): BelongsTo { return $this->belongsTo(ChapterComment::class, 'chapter_comment_id'); }
}
