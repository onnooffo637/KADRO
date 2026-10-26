<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'series_updates' => 'boolean',
            'moderation_updates' => 'boolean',
            'local_notifications_opt_in' => 'boolean',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
