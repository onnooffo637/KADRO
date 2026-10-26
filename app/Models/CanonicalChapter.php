<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CanonicalChapter extends Model
{
    use HasUlids;
    use SoftDeletes;
    
    protected $fillable = ['series_id', 'display_number', 'sort_key', 'title', 'kind', 'status'];

    protected function casts(): array
    {
        return [
            'sort_key' => 'decimal:8',
        ];
    }


    public function series(): BelongsTo { return $this->belongsTo(Series::class); }
    public function editionChapters(): HasMany { return $this->hasMany(EditionChapter::class); }

}
