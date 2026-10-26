<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasUlids;
    
    protected $fillable = ['storage_disk', 'storage_key', 'mime', 'sha256', 'bytes', 'width', 'height', 'status'];


    public function pages(): HasMany { return $this->hasMany(RevisionPage::class); }

}
