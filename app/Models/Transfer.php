<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    public const KINDS = [
        'auction' => 'Auction',
        'fixed' => 'Bought at asking price',
        'free_agent' => 'Signed as free agent',
        'released' => 'Released',
        'contract_end' => 'Contract ended',
    ];

    protected $fillable = ['player_id', 'season_id', 'from_club_id', 'to_club_id', 'fee', 'kind'];

    protected function casts(): array
    {
        return ['fee' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'from_club_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'to_club_id');
    }
}
