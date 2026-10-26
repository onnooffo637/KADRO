<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A durable private upload processing receipt; never publicly exposed. */
class ChapterImport extends Model
{
    use HasUlids;

    protected $fillable = [
        'series_id', 'edition_chapter_id', 'actor_id', 'request_key',
        'status', 'staging_prefix', 'files', 'archive_only',
        'revision_id', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array', 'archive_only' => 'boolean',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }
}
