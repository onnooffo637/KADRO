<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChapterRevision extends Model
{
    use HasUlids;
    
    protected $fillable = ['edition_chapter_id', 'revision_no', 'submitter_id', 'reviewer_id', 'status', 'asset_manifest_hash', 'import_request_key', 'submitted_at', 'reviewed_at', 'published_at'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }


    public function editionChapter(): BelongsTo { return $this->belongsTo(EditionChapter::class); }
    public function pages(): HasMany { return $this->hasMany(RevisionPage::class); }

}
