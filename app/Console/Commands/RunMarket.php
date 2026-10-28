<?php

namespace App\Console\Commands;

use App\Game\AiMarket;
use App\Game\TransferMarket;
use Illuminate\Console\Command;

class RunMarket extends Command
{
    protected $signature = 'game:market {--ai : Also let computer clubs buy and sell}';

    protected $description = 'Close ended auctions and expired listings; with --ai, run the computer clubs\' transfer activity';

    public function handle(TransferMarket $market, AiMarket $ai): int
    {
        $closed = $market->settleDue();
        if ($closed > 0) {
            $this->info("Closed {$closed} listing(s).");
        }
        if ($this->option('ai')) {
            $done = $ai->tick();
            $this->info("AI clubs: {$done['listed']} listed, {$done['bought']} bought, {$done['bids']} bids, {$done['signed']} free agents signed.");
        }

        return self::SUCCESS;
    }
}
