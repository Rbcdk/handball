<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransferListing extends Model
{
    protected $fillable = ['player_id', 'seller_club_id', 'kind', 'price', 'ends_at', 'status', 'buyer_club_id', 'final_price', 'closed_at'];

    protected function casts(): array
    {
        return ['ends_at' => 'datetime', 'closed_at' => 'datetime', 'price' => 'integer', 'final_price' => 'integer'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'seller_club_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'buyer_club_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(TransferBid::class, 'listing_id');
    }

    public function highestBid(): HasOne
    {
        return $this->hasOne(TransferBid::class, 'listing_id')->ofMany(['amount' => 'max', 'id' => 'min']);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    public function isAuction(): bool
    {
        return $this->kind === 'auction';
    }
}
