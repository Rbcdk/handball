<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'guide_progress' => 'array',
        ];
    }

    /** Steps of the getting-started guide, in the order they are shown. */
    public const GUIDE_STEPS = ['squad', 'tactics', 'replay', 'league'];

    public function hasDone(string $step): bool
    {
        return in_array($step, $this->guide_progress ?? [], true);
    }

    /** Records a guide step once. Cheap to call on every page view. */
    public function markDone(string $step): void
    {
        if ($this->hasDone($step)) {
            return;
        }
        $this->guide_progress = [...($this->guide_progress ?? []), $step];
        $this->save();
    }

    public function showsGuide(): bool
    {
        if ($this->hasDone('dismissed')) {
            return false;
        }
        foreach (self::GUIDE_STEPS as $step) {
            if (! $this->hasDone($step)) {
                return true;
            }
        }

        return false;
    }

    public function club(): HasOne
    {
        return $this->hasOne(Club::class);
    }
}
