<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentReport extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }
    public function review(): BelongsTo { return $this->belongsTo(SeriesReview::class, 'series_review_id'); }
    public function comment(): BelongsTo { return $this->belongsTo(ChapterComment::class, 'chapter_comment_id'); }
    public function actions(): HasMany { return $this->hasMany(ModerationAction::class, 'content_report_id'); }
}
