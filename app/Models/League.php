<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class League extends Model
{
    protected $fillable = ['name', 'slug', 'tier', 'sort'];

    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
