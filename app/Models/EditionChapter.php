<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EditionChapter extends Model
{
    use HasUlids;
    use SoftDeletes;
    
    protected $fillable = ['series_id', 'edition_id', 'canonical_chapter_id', 'current_published_revision_id', 'status'];


    public function edition(): BelongsTo { return $this->belongsTo(Edition::class); }
    public function canonicalChapter(): BelongsTo { return $this->belongsTo(CanonicalChapter::class); }
    public function revisions(): HasMany { return $this->hasMany(ChapterRevision::class); }
    public function currentPublishedRevision(): BelongsTo { return $this->belongsTo(ChapterRevision::class, 'current_published_revision_id'); }

}
