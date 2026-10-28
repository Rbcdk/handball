<?php

namespace App\Console\Commands;

use App\Game\MatchRunner;
use App\Game\SeasonManager;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Console\Command;

class FastForward extends Command
{
    protected $signature = 'game:fast-forward {rounds=1 : Number of match days to play now}';

    protected $description = 'Development helper: play the next match days immediately';

    public function handle(MatchRunner $runner, SeasonManager $seasons, \App\Game\TransferMarket $market, \App\Game\AiMarket $ai): int
    {
        if (app()->isProduction() && ! $this->confirm('This changes kick-off times on a live game. Continue?')) {
            return self::FAILURE;
        }

        $total = 0;
        for ($i = 0; $i < (int) $this->argument('rounds'); $i++) {
            $season = Season::current();
            $next = $season ? Fixture::where('season_id', $season->id)->upcoming()->orderBy('scheduled_at')->first() : null;
            if (! $next) {
                break;
            }
            Fixture::where('season_id', $season->id)->upcoming()
                ->where('scheduled_at', $next->scheduled_at)
                ->update(['scheduled_at' => now()->subSecond()]);
            $total += $runner->playDue();
            // A match day is a day on the market too: AI clubs trade through the "day"
            // (the hourly check, compressed), then listings close as if 24 hours had passed.
            for ($h = 0; $h < 8; $h++) {
                $ai->tick();
            }
            $market->settleDue(now()->addDay());
            if ($new = $seasons->advanceIfComplete()) {
                $this->info("Season {$season->number} finished. Season {$new->number} is scheduled.");
            }
        }
        $this->info("Played {$total} match(es).");

        return self::SUCCESS;
    }
}
