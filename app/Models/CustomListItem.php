<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomListItem extends Model
{
    use HasUlids;

    protected $fillable = ['custom_list_id', 'series_id'];

    public function list(): BelongsTo { return $this->belongsTo(CustomList::class, 'custom_list_id'); }
    public function series(): BelongsTo { return $this->belongsTo(Series::class); }
}
