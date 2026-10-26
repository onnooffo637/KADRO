<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevisionPage extends Model
{
    use HasUlids;
    
    protected $fillable = ['chapter_revision_id', 'media_asset_id', 'page_index', 'width', 'height'];


    public function revision(): BelongsTo { return $this->belongsTo(ChapterRevision::class, 'chapter_revision_id'); }
    public function asset(): BelongsTo { return $this->belongsTo(MediaAsset::class, 'media_asset_id'); }

}
