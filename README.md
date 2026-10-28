# Handball Manager (working title)

Browser handball manager inspired by cs-manager.com. Laravel 12 backend, server-side match engine,
2D replays in the browser.

## Setup

Requirements: PHP 8.2+, Composer, Node 18+.

```
composer install
composer run setup        # .env, app key, SQLite database, migrations, creates the world
npm install
npm run build             # or: npm run dev while working on the frontend
php artisan serve
```

Open http://localhost:8000, register, and take over a club in the 2. division.

To play matches automatically, keep the scheduler running in a second terminal:

```
php artisan schedule:work
```

Or run everything at once with `composer run dev` (server, scheduler and Vite).

On a server, add the standard cron entry: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.

## Game commands

| Command | What it does |
|---|---|
| `php artisan game:new-world [--seed=] [--start=Y-m-d] [--fresh]` | Creates 3 leagues, 42 AI clubs with 16 players each, and season 1's fixtures. `--fresh` wipes the database first. |
| `php artisan game:play-due` | Plays every match whose kick-off has passed; ends the season when all are played. The scheduler runs this every minute. |
| `php artisan game:fast-forward {rounds=1}` | Development helper: plays the next match days immediately. `game:fast-forward 26` plays a whole season. |
| `php artisan game:calibrate {matches=500} [--home=65] [--away=65]` | Simulates many matches and prints average statistics, for balancing. |
| `php artisan game:market [--ai]` | Closes ended auctions and expired listings (scheduler: every minute). With `--ai`, computer clubs buy, sell and sign free agents (scheduler: hourly). |
| `php artisan game:rename-world` | Gives every club and player a new invented name; keeps results, stats and replays. |
| `php artisan game:setup-economy` | One-off: gives a world created before money existed its starting money, wages, contracts and free agents. Safe to run twice. |

New managers see a getting-started checklist on the overview, and there is a How to play page at /how-to-play.

Dark mode is the default. The switch in the header changes to light mode and is remembered in a cookie.

Settings are in `config/game.php` (time zone, kick-off time, clubs per league, promotion spots).

## How it fits together

```
app/Game/Engine/        Match engine (plain PHP, no Laravel dependencies)
  MatchEngine.php       Possession-by-possession simulation. BALANCE holds every tuning value.
  Rng.php               Seeded generator, bit-identical to the TypeScript prototype's.
app/Game/World/         World creation: names, players, fixtures (double round robin)
app/Game/MatchRunner    Plays due fixtures, stores the compressed event log and player stats
app/Game/Standings      League tables from played fixtures (2 points for a win, 1 for a draw)
app/Game/SeasonManager  Season end: promotion/relegation, player ageing, retirements, next season
resources/js/match/     2D replay: timeline builder + canvas renderer (reads the stored log)
resources/css/app.css   Design system (tokens at the top)
```

- Every fixture gets a random seed when it is scheduled. The engine plus that seed fully decide the
  match, so a replay is the stored event log, not a re-simulation.
- Logs are gzip-compressed (about 8 KB per match).
- Tactics stay hidden until kick-off; the replay shows both teams' tactics at the start.
- The PHP engine was checked against the TypeScript prototype: identical results on 40 test matches.

## Money and transfers

All money rules live in `app/Game/Economy.php`. The currency is the invented *gilden* (`Economy::CURRENCY`):

- Income: ticket sales at home matches (seats × how full × price; higher in the table = fuller),
  a sponsor deal paid each match day, prize money by final position.
- Costs: every player's wage, each match day. Wage = market value / 140, fixed when he signs.
- Market value grows ×3 per 10 rating points; young players cost more, players over 30 less.
- A simulated AI-only season: no club in debt, ~25 transfers and ~20 free-agent signings.
  Total money in the game still grows each season; arena upgrades (roadmap Phase 3) are meant to absorb it.

Transfer rules (`app/Game/TransferMarket.php`): sellers pick auction (24 h, bids in the last 5 minutes
extend it) or fixed price (3 days). Prices must be 0.5–4× market value. Squads hold 14–20 players and
keep two goalkeepers. Managers on the same IP address can't trade with each other.
Free agents cost only their wage. Contracts that run out at season end make the player a free agent.

## Line-ups, training and fitness

- Line-up (`/lineup`): a court drawing with seven positions. Managers drag players onto positions
  (mouse or touch, `resources/js/lineup.ts`), or tap a player then a position; dragging a player
  back to the squad list sets that position to Automatic (best rested player, shown with a dashed
  ring and previewed in the browser with the same rule as `MatchEngine::startingLineup`).
  Goalkeepers only go in goal, court players only on court, injured players can't be picked.
- Player portraits (`app/Support/Avatar.php`): an SVG face drawn from the player's id, in his club's
  shirt and number, so it never changes and needs no stored image. Shown on the squad, line-up,
  training, transfer and player pages.
- Tactics page: two small courts show the chosen defense system and the attack focus zone,
  and move when the options change.
- Training (`app/Game/Training.php`), after every match: each player trains the skills in his focus;
  100 training points = +1 to his weakest focus skill, up to his hidden potential + 6.
  Points per match day: ≤20 years 60, 21–23 42, 24–26 26, 27–29 12, 30+ 0; ×0.6 light / ×1.5 hard;
  +20% for players who played. A simulated season: under-21s gain about +2 rating, 24–26 about +0.6.
- Fitness carries over: minutes played tire a player, every day he recovers some (light training
  recovers most). Starters average about 85 before a match; a never-rested player drops towards 40.
- Injuries: about 0.6% per player per match, more when tired or training hard; 1–20 match days.
- Club news on the overview lists injuries, recoveries and training progress.

## Arena and fans (`app/Game/Arena.php`)

- Each club has `fans`. After every match: win +1%, draw +0.2%, loss -0.7%; promotion +15%, relegation -10%.
- Crowd at a home match = min(seats, fans × (0.55 + 0.35 × table form)); stored on `fixtures.attendance`.
- Expansions: +250 / +500 / +1000 seats, built in 3 / 5 / 8 match days, cost seats × (900 + 0.4 × current seats).
  Upkeep 2 gilden per seat per match day, charged at home matches. Max 12.000 seats.
- AI clubs expand at season end when they sold out at least half their home matches and have twice the cost.
- Simulated: a top team sells out in its second season; an average team never does with 1.300 seats.

## Leagues and seasons

- The Crown League (top) and two second-tier leagues, the Ember League and the Frost League, 14 clubs each.
- 26 rounds, one round every evening at 19:00 Copenhagen time.
- The winner of each second-tier league goes up; the bottom two of the Crown League go down (one to each).
- New managers take over AI clubs in the Ember and Frost Leagues.
- At season end players age; players over 30 lose speed and stamina, 36-year-olds retire and are
  replaced by youth players, and injuries and tiredness heal. Young players improve through training.

## Tests

```
php artisan test
```

Unit tests cover the engine (determinism, realistic scores, strength) and the fixture generator.
Feature tests run the whole loop: world creation, registration, taking over a club, tactics,
playing a round, replays, and a full season with promotion.

## Not built yet

- Transfers, training, youth academy and finances
- Manual line-ups
- Forum and chat
- Email verification and password reset
