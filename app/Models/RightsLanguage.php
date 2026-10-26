<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RightsLanguage extends Model
{
    
    protected $table = 'rights_languages';
    protected $fillable = ['grant_id', 'language_code'];

    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $keyType = 'string';

    public function save(array $options = []): bool { throw new \LogicException('Use relationship query builder for composite-key rights scopes.'); }


    public function grant(): BelongsTo { return $this->belongsTo(RightsGrant::class, 'grant_id'); }

}
