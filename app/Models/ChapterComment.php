<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChapterComment extends Model
{
    use HasUlids;
    use SoftDeletes;

    # No unreviewed comment is exposed by a public query.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_spoiler' => 'boolean'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function chapter(): BelongsTo { return $this->belongsTo(EditionChapter::class, 'edition_chapter_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function replies(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
}
