<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferBid extends Model
{
    protected $fillable = ['listing_id', 'club_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(TransferListing::class, 'listing_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
