<?php

return [

    /*
    | Time zone used for scheduling matches. Match times are stored in UTC.
    */
    'timezone' => env('GAME_TIMEZONE', 'Europe/Copenhagen'),

    /*
    | Local kick-off time for the daily league round (medium pace: one match a day).
    */
    'match_time' => env('GAME_MATCH_TIME', '19:00'),

    /*
    | Clubs per league. Every club plays the others home and away,
    | so 14 clubs gives 26 rounds, roughly four weeks per season.
    */
    'clubs_per_league' => (int) env('GAME_CLUBS_PER_LEAGUE', 14),

    /*
    | Promotion and relegation spots between the top league and each second-tier league.
    */
    'promotion_spots' => 1,

    /*
    | Days of off-season between the last round and the new season's first round.
    */
    'off_season_days' => 2,

];
