<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeriesReview extends Model
{
    use HasUlids;
    use SoftDeletes;

    # Moderation decisions belong to a privileged service, not mass assignment.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_spoiler' => 'boolean'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function series(): BelongsTo { return $this->belongsTo(Series::class); }
}
