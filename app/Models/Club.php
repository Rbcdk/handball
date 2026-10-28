<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    public const DEFAULT_TACTICS = [
        'defense' => '6-0',
        'tempo' => 'normal',
        'focus' => 'balanced',
        'sevenVsSix' => 'whenShort',
        'rotation' => 'normal',
    ];

    /** Allowed values per tactic, with player-facing labels. */
    public const TACTIC_OPTIONS = [
        'defense' => ['label' => 'Defense system', 'options' => ['6-0' => '6-0', '5-1' => '5-1', '3-2-1' => '3-2-1']],
        'tempo' => ['label' => 'Tempo', 'options' => ['slow' => 'Slow', 'normal' => 'Normal', 'fast' => 'Fast']],
        'focus' => ['label' => 'Attack focus', 'options' => ['balanced' => 'Balanced', 'wings' => 'Wings', 'backs' => 'Backs (9 m)', 'pivot' => 'Pivot']],
        'sevenVsSix' => ['label' => '7 vs 6', 'options' => ['never' => 'Never', 'whenShort' => 'When short-handed', 'whenBehind' => 'When behind in the 2nd half', 'always' => 'Always']],
        'rotation' => ['label' => 'Rotation', 'options' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']],
    ];

    protected $fillable = ['league_id', 'user_id', 'name', 'short', 'city', 'color', 'tactics', 'balance', 'arena_capacity', 'training_intensity', 'lineup', 'taken_over_at'];

    protected function casts(): array
    {
        return [
            'tactics' => 'array',
            'lineup' => 'array',
            'balance' => 'integer',
            'arena_capacity' => 'integer',
            'taken_over_at' => 'datetime',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(TransferListing::class, 'seller_club_id');
    }

    /** Total wages paid each match day. */
    public function wageBill(): int
    {
        return (int) $this->players()->sum('wage');
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    public function isManaged(): bool
    {
        return $this->user_id !== null;
    }

    /** All fixtures this club plays in. */
    public function fixtures(): Builder
    {
        return Fixture::query()->where(fn ($q) => $q->where('home_club_id', $this->id)->orWhere('away_club_id', $this->id));
    }

    /**
     * Who starts, as the match engine will pick it: player id => position played.
     * Uses the manager's chosen line-up where it names fit players; injured players never start.
     *
     * @return array<int, string>
     */
    public function startingLineup(): array
    {
        $players = $this->matchSquad()->map(fn (Player $p) => $p->toEngineArray())->values()->all();
        if (count($players) < 7) {
            return [];
        }
        $lineup = \App\Game\Engine\MatchEngine::startingLineup($players, $this->engineLineup());
        $out = [(int) substr($lineup['gk'], 1) => 'GK'];
        foreach ($lineup['slots'] as $slot => $id) {
            $out[(int) substr($id, 1)] = $slot;
        }

        return $out;
    }

    /**
     * Players available for a match: everyone who isn't injured. If injuries leave fewer
     * than seven, the least injured play anyway rather than the match failing.
     */
    public function matchSquad(): \Illuminate\Support\Collection
    {
        $all = $this->players()->orderBy('id')->get();
        $fit = $all->reject(fn (Player $p) => $p->isInjured());

        return $fit->count() >= 7 ? $fit->values() : $all->sortBy('injured_days')->take(max(7, $fit->count()))->values();
    }

    /** The saved line-up in engine form (player ids prefixed with 'p'), or null for automatic. */
    public function engineLineup(): ?array
    {
        $l = $this->lineup;
        if (! $l) {
            return null;
        }
        $p = fn ($id) => $id ? 'p'.$id : null;

        return [
            'slots' => array_filter(array_map($p, $l['slots'] ?? [])),
            'gk' => $p($l['gk'] ?? null),
            'sevenMeter' => $p($l['sevenMeter'] ?? null),
        ];
    }

    /** Builds the team array the match engine expects. */
    public function toEngineTeam(): array
    {
        $players = $this->matchSquad();

        return [
            'id' => 'c'.$this->id,
            'name' => $this->name,
            'short' => $this->short,
            'color' => $this->color,
            'tactics' => array_merge(self::DEFAULT_TACTICS, $this->tactics ?? []),
            'lineup' => $this->engineLineup(),
            'players' => $players->map(fn (Player $p) => $p->toEngineArray())->values()->all(),
        ];
    }
}
