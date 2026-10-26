<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryEntry extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'series_id', 'shelf', 'is_favorite', 'last_opened_at'];

    protected function casts(): array
    {
        return ['is_favorite' => 'boolean', 'last_opened_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function series(): BelongsTo { return $this->belongsTo(Series::class); }
}
