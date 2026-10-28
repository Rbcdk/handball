<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fixture extends Model
{
    protected $fillable = [
        'season_id', 'league_id', 'round', 'home_club_id', 'away_club_id', 'scheduled_at', 'played_at',
        'home_score', 'away_score', 'halftime_home', 'halftime_away', 'seed', 'attendance',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'played_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'home_club_id');
    }

    public function away(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'away_club_id');
    }

    public function matchLog(): HasOne
    {
        return $this->hasOne(MatchLog::class);
    }

    public function scopePlayed(Builder $query): void
    {
        $query->whereNotNull('played_at');
    }

    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNull('played_at');
    }

    public function scopeInvolving(Builder $query, Club $club): void
    {
        $query->where(fn ($q) => $q->where('home_club_id', $club->id)->orWhere('away_club_id', $club->id));
    }

    public function isPlayed(): bool
    {
        return $this->played_at !== null;
    }

    public function opponentOf(Club $club): Club
    {
        return (int) $this->home_club_id === (int) $club->id ? $this->away : $this->home;
    }

    /** 'W', 'D' or 'L' for this club, once the match is played. */
    public function resultFor(Club $club): string
    {
        [$mine, $theirs] = $this->isHome($club) ? [$this->home_score, $this->away_score] : [$this->away_score, $this->home_score];

        return $mine > $theirs ? 'W' : ((int) $mine === (int) $theirs ? 'D' : 'L');
    }

    public function isHome(Club $club): bool
    {
        return (int) $this->home_club_id === (int) $club->id;
    }

    /** 'win', 'draw' or 'loss' from the given club's point of view. */
    public function outcomeFor(Club $club): ?string
    {
        if (! $this->isPlayed()) {
            return null;
        }
        [$for, $against] = $this->isHome($club)
            ? [$this->home_score, $this->away_score]
            : [$this->away_score, $this->home_score];

        return $for > $against ? 'win' : ($for === $against ? 'draw' : 'loss');
    }

    public function localKickoff(): \Carbon\CarbonInterface
    {
        return $this->scheduled_at->copy()->setTimezone(config('game.timezone'));
    }

    /** "Today 19:00", "Tomorrow 19:00" or "Thursday 23 Oct, 19:00" in the game's time zone. */
    public function kickoffLabel(): string
    {
        $local = $this->localKickoff();
        $today = now(config('game.timezone'))->startOfDay();
        $day = match (true) {
            $local->isSameDay($today) => 'Today',
            $local->isSameDay($today->copy()->addDay()) => 'Tomorrow',
            default => $local->format('l j M,'),
        };

        return $day.' '.$local->format('H:i');
    }

    /** "Today", "Tomorrow" or "Thursday 23 Oct" in the game's time zone. */
    public function kickoffDay(): string
    {
        return trim(\Illuminate\Support\Str::beforeLast($this->kickoffLabel(), ' '), ',');
    }

    /** "5 hours 12 minutes" until kick-off, or null if it has passed. */
    public function countdown(): ?string
    {
        if ($this->scheduled_at->isPast()) {
            return null;
        }

        return now()->diffForHumans($this->scheduled_at, \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2);
    }
}
