<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use HasUlids;
    use SoftDeletes;
    
    protected $fillable = ['name', 'status'];


    public function editions(): HasMany { return $this->hasMany(Edition::class, 'translation_team_id'); }

}
