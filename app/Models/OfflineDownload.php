<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineDownload extends Model
{
    use HasUlids;

    # Download state is not an authorization token or evidence of live rights.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'bytes_downloaded' => 'integer',
            'rights_checked_at' => 'immutable_datetime',
            'access_expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function chapter(): BelongsTo { return $this->belongsTo(EditionChapter::class, 'edition_chapter_id'); }
    public function revision(): BelongsTo { return $this->belongsTo(ChapterRevision::class, 'chapter_revision_id'); }
    public function rightsGrant(): BelongsTo { return $this->belongsTo(RightsGrant::class); }
}
