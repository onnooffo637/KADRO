<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RightsLicense extends Model
{
    use HasUlids;
    
    protected $fillable = ['holder_name', 'document_ref', 'approval_status', 'approved_by', 'valid_from', 'valid_until', 'approved_at'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }


    public function grants(): HasMany { return $this->hasMany(RightsGrant::class, 'license_id'); }

}
