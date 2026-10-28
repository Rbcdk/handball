<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = ['number', 'status', 'finished_at'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public static function current(): ?self
    {
        return static::where('status', 'active')->orderByDesc('number')->first();
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }
}
