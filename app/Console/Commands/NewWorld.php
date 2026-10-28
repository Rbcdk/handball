<?php

namespace App\Console\Commands;

use App\Game\World\WorldBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class NewWorld extends Command
{
    protected $signature = 'game:new-world
        {--seed= : Seed for club and player generation}
        {--start= : First match day (Y-m-d, local time). Defaults to tomorrow}
        {--fresh : Wipe the database first (migrate:fresh)}';

    protected $description = 'Create leagues, AI clubs with squads, and the first season\'s fixtures';

    public function handle(WorldBuilder $builder): int
    {
        if ($this->option('fresh')) {
            Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        }

        $start = $this->option('start')
            ? CarbonImmutable::parse($this->option('start'), config('game.timezone'))
            : null;

        try {
            $season = $builder->build($this->option('seed') ? (int) $this->option('seed') : null, $start);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $first = $season->fixtures()->orderBy('scheduled_at')->first();
        $this->info("Season {$season->number} created: ".$season->fixtures()->count().' fixtures.');
        $this->line('First round kicks off '.$first->localKickoff()->format('l j F Y H:i').' ('.config('game.timezone').').');

        return self::SUCCESS;
    }
}
