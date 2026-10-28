<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerSeasonStat extends Model
{
    protected $fillable = [
        'player_id', 'season_id', 'club_id', 'matches', 'goals', 'shots', 'assists',
        'seven_m_goals', 'saves', 'shots_faced', 'suspensions',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
