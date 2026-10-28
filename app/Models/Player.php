<?php

namespace App\Models;

use App\Game\Engine\MatchEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    public const POSITIONS = [
        'GK' => 'Goalkeeper',
        'LW' => 'Left wing',
        'LB' => 'Left back',
        'CB' => 'Centre back',
        'RB' => 'Right back',
        'RW' => 'Right wing',
        'PV' => 'Pivot',
    ];

    public const ATTRIBUTES = [
        'shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure',
        'reflexes', 'positioning', 'seven_meter',
    ];

    protected $fillable = [
        'club_id', 'first_name', 'last_name', 'number', 'position', 'age',
        'shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure',
        'reflexes', 'positioning', 'seven_meter', 'potential', 'wage', 'contract_until', 'face',
        'fitness', 'injured_days', 'injury', 'training_focus', 'training_xp',
    ];

    protected $hidden = ['potential'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function seasonStats(): HasMany
    {
        return $this->hasMany(PlayerSeasonStat::class);
    }

    public function listing(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TransferListing::class)->where('status', 'open');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function marketValue(): int
    {
        return \App\Game\Economy::marketValue($this->rating(), $this->age);
    }

    /** The wage this player would ask for on a new contract. */
    public function fairWage(): int
    {
        return \App\Game\Economy::wage($this->marketValue());
    }

    public function isInjured(): bool
    {
        return $this->injured_days > 0;
    }

    public function isFreeAgent(): bool
    {
        return $this->club_id === null;
    }

    public function getNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function isGoalkeeper(): bool
    {
        return $this->position === 'GK';
    }

    /** One number summarising the player in his own position (0-100). */
    public function rating(): int
    {
        if ($this->isGoalkeeper()) {
            return (int) round($this->reflexes * 0.5 + $this->positioning * 0.35 + $this->seven_meter * 0.15);
        }

        return (int) round(MatchEngine::slotRating($this->toEngineArray(), $this->position));
    }

    public function toEngineArray(): array
    {
        return [
            'id' => 'p'.$this->id,
            'name' => $this->name,
            'number' => $this->number,
            'position' => $this->position,
            'age' => $this->age,
            'fitness' => (int) ($this->fitness ?? 100),
            'attrs' => [
                'shooting' => $this->shooting,
                'passing' => $this->passing,
                'technique' => $this->technique,
                'speed' => $this->speed,
                'stamina' => $this->stamina,
                'jumping' => $this->jumping,
                'defense' => $this->defense,
                'composure' => $this->composure,
                'reflexes' => $this->reflexes,
                'positioning' => $this->positioning,
                'sevenMeter' => $this->seven_meter,
            ],
        ];
    }
}
