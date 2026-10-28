<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    public const TYPES = [
        'start' => 'Starting money',
        'wages' => 'Wages',
        'tickets' => 'Ticket sales',
        'sponsor' => 'Sponsor',
        'prize' => 'Prize money',
        'transfer_in' => 'Player bought',
        'transfer_out' => 'Player sold',
        'arena_build' => 'Arena expansion',
        'upkeep' => 'Arena upkeep',
    ];

    protected $fillable = ['club_id', 'season_id', 'amount', 'type', 'description', 'balance_after'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
