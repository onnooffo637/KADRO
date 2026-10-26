<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingSyncOperation extends Model
{
    use HasUlids;

    # Append-only acknowledgments; never expose raw write access to clients.
    protected $guarded = ['*'];

    public function progress(): BelongsTo { return $this->belongsTo(ReadingProgress::class, 'reading_progress_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
