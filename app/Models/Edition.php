<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Edition extends Model
{
    use HasUlids;
    use SoftDeletes;
    
    protected $fillable = ['series_id', 'language_code', 'label', 'translation_team_id', 'status', 'reading_direction_hint', 'published_at'];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
        ];
    }


    public function series(): BelongsTo { return $this->belongsTo(Series::class); }
    public function editionChapters(): HasMany { return $this->hasMany(EditionChapter::class); }
    public function rightsGrants(): HasMany { return $this->hasMany(RightsGrant::class); }

    public function scopePubliclyReadableIn(Builder $query, ?string $territory, ?string $language, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now('UTC');

        # A missing or unknown audience territory never grants publication rights.
        if ($territory === null || preg_match('/^[A-Z]{2}$/D', $territory) !== 1
            || $language === null || trim($language) === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('editions.status', 'published')
            ->whereNull('editions.deleted_at')
            ->where('editions.language_code', $language)
            ->whereNotNull('editions.published_at')
            ->where('editions.published_at', '<=', $at)
            ->whereHas('series', fn (Builder $series): Builder =>
                $series->where('publication_status', 'published')
                    ->whereNull('deleted_at')
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', $at))
            ->whereHas('editionChapters', fn (Builder $chapter): Builder =>
                $chapter->where('status', 'published')
                    ->whereNotNull('current_published_revision_id')
                    ->whereNull('deleted_at'))
            ->where(function (Builder $rights) use ($territory, $language, $at): void {
                // A confirmed original work belongs to this platform owner: no third-party
                // license document is necessary. This is intentionally per-series, not global.
                $rights->whereHas('series', fn (Builder $original): Builder => $original
                    ->where('series.is_original_content', true)
                    ->whereNotNull('series.original_content_declared_at')
                    ->whereNotNull('series.original_content_declared_by'))
                    ->orWhereHas('series.grants', function (Builder $grant) use ($territory, $language, $at): void {
                $grant->where('status', 'active')
                    ->where('can_publish', true)
                    ->where(function (Builder $scope): void {
                        $scope->where('can_translate', true)
                            ->orWhereRaw('EXISTS (SELECT 1 FROM series AS license_series WHERE license_series.id = rights_grants.series_id AND license_series.original_language = editions.language_code)');
                    })
                    ->where('valid_from', '<=', $at)
                    ->where('valid_until', '>', $at)
                    ->where(function (Builder $scope): void {
                        $scope->whereNull('edition_id')
                            ->orWhereColumn('rights_grants.edition_id', 'editions.id');
                    })
                    ->whereHas('license', fn (Builder $license): Builder =>
                        $license->where('approval_status', 'approved')
                            ->whereNotNull('approved_by')
                            ->whereNotNull('approved_at')
                            ->where('valid_from', '<=', $at)
                            ->where('valid_until', '>', $at))
                    ->whereHas('territories', fn (Builder $place): Builder =>
                        $place->whereIn('territory_code', [$territory, 'GLOBAL']))
                    ->whereHas('languages', fn (Builder $lang): Builder =>
                        $lang->where('language_code', $language));
                });
            });
    }
}
