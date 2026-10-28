<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class News extends Model
{
    protected $table = 'news';

    protected $fillable = ['club_id', 'kind', 'message'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
