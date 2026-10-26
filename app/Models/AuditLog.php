<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUlids;

    # Append-only storage; never mass assign or update a recorded action.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
