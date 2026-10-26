<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationAction extends Model
{
    use HasUlids;

    # Authorization and action logging will be enforced by the moderation service.
    protected $guarded = ['*'];

    public function report(): BelongsTo { return $this->belongsTo(ContentReport::class, 'content_report_id'); }
    public function moderator(): BelongsTo { return $this->belongsTo(User::class, 'moderator_id'); }
}
