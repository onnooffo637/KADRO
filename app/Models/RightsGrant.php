<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RightsGrant extends Model
{
    use HasUlids;
    
    protected $fillable = ['license_id', 'series_id', 'edition_id', 'can_publish', 'can_translate', 'can_offline', 'status', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return [
            'can_publish' => 'boolean',
            'can_translate' => 'boolean',
            'can_offline' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
        ];
    }


    public function license(): BelongsTo { return $this->belongsTo(RightsLicense::class, 'license_id'); }
    public function edition(): BelongsTo { return $this->belongsTo(Edition::class); }
    public function territories(): HasMany { return $this->hasMany(RightsTerritory::class, 'grant_id'); }
    public function languages(): HasMany { return $this->hasMany(RightsLanguage::class, 'grant_id'); }

}
