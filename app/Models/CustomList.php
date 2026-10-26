<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomList extends Model
{
    use HasUlids;

    # Mirror the database default on newly created model instances.
    protected $attributes = ['is_public' => false];

    # Lists are private until authenticated sharing policies have been approved.
    protected $fillable = ['user_id', 'name', 'normalized_name'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(CustomListItem::class); }
}
