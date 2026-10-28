<?php

namespace App\Models;

use App\Game\Academy;
use App\Game\Engine\Rng;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A youth player in a club's academy: not in the squad, no wage, trains until promoted or 20. */
class Prospect extends Model
{
    public const SKILLS = ['shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure', 'reflexes', 'positioning', 'seven_meter'];

    protected $fillable = ['club_id', 'first_name', 'last_name', 'position', 'age', ...self::SKILLS, 'potential', 'training_xp', 'face', 'joined_season'];

    protected $hidden = ['potential'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function getNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function isGoalkeeper(): bool
    {
        return $this->position === 'GK';
    }

    /** As a (not saved) player, to reuse ratings and portraits. */
    public function asPlayer(): Player
    {
        $p = new Player(['first_name' => $this->first_name, 'last_name' => $this->last_name, 'position' => $this->position,
            'age' => $this->age, 'number' => 0, 'potential' => $this->potential, ...$this->only(self::SKILLS)]);
        $p->id = $this->face;
        $p->face = $this->face;
        $p->setRelation('club', $this->club);

        return $p;
    }

    public function rating(): int
    {
        return $this->asPlayer()->rating();
    }

    /**
     * Talent in stars (1-5) as the academy coaches see it. A better academy guesses closer
     * to the hidden potential; the guess is fixed per prospect and academy level.
     */
    public function stars(int $academyLevel): int
    {
        $rng = new Rng($this->id * 97 + $academyLevel * 7);
        $guess = $this->potential + $rng->normal(0, Academy::LEVELS[$academyLevel]['eye']);

        return match (true) {
            $guess >= 78 => 5,
            $guess >= 71 => 4,
            $guess >= 64 => 3,
            $guess >= 57 => 2,
            default => 1,
        };
    }
}
