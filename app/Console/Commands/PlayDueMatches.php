<?php

namespace App\Console\Commands;

use App\Game\MatchRunner;
use App\Game\SeasonManager;
use Illuminate\Console\Command;

class PlayDueMatches extends Command
{
    protected $signature = 'game:play-due';

    protected $description = 'Play every fixture whose kick-off time has passed (run by the scheduler every minute)';

    public function handle(MatchRunner $runner, SeasonManager $seasons): int
    {
        $played = $runner->playDue();
        if ($played > 0) {
            $this->info("Played {$played} match(es).");
        }

        if ($next = $seasons->advanceIfComplete()) {
            $this->info("Season finished. Season {$next->number} is scheduled.");
        }

        return self::SUCCESS;
    }
}
