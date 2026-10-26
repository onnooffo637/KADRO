<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Series extends Model
{
    use HasUlids;
    use HasFactory;
    use SoftDeletes;
    
    protected $fillable = ['slug', 'primary_title', 'format', 'original_language', 'publication_status', 'completion_status', 'synopsis', 'published_at', 'author_name', 'illustrator_name', 'age_rating', 'cover_media_asset_id', 'is_original_content', 'original_content_declared_at', 'original_content_declared_by'];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'is_original_content' => 'boolean',
            'original_content_declared_at' => 'immutable_datetime',
        ];
    }


    public function coverAsset(): BelongsTo { return $this->belongsTo(MediaAsset::class, 'cover_media_asset_id'); }

    public function chapters(): HasMany { return $this->hasMany(CanonicalChapter::class); }
    public function editions(): HasMany { return $this->hasMany(Edition::class); }
    public function grants(): HasMany { return $this->hasMany(RightsGrant::class); }

    public function scopePubliclyReadableIn(Builder $query, ?string $territory, ?string $language, ?CarbonImmutable $at = null): Builder
    {
        return $query->where('publication_status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at ?? CarbonImmutable::now('UTC'))
            ->whereHas('editions', fn (Builder $edition): Builder =>
                $edition->publiclyReadableIn($territory, $language, $at));
    }
}
