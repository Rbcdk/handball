@extends('layouts.app')

@section('title', 'How to play')
@section('heading', 'How to play')
@section('subheading', 'Everything a new manager needs, in five minutes.')

@section('content')
    <div class="help">
        <nav class="help__toc" aria-label="On this page">
            <a href="#day">A match day</a>
            <a href="#season">The season</a>
            <a href="#positions">Positions</a>
            <a href="#attributes">Player numbers</a>
            <a href="#tactics">Tactics</a>
            <a href="#training">Line-up, training and fitness</a>
            <a href="#money">Money and transfers</a>
            <a href="#arena">Arena and fans</a>
            <a href="#academy">Youth academy</a>
            <a href="#replay">Reading a replay</a>
        </nav>

        <div class="help__body">
            <section id="day">
                <h2 class="section-title narrow">A match day</h2>
                <p>Your team plays one league match every evening at {{ config('game.match_time') }} ({{ config('game.timezone') }}). The match is played on the server, so you don't need to be online.</p>
                <ol class="help__list">
                    <li>Before kick-off, choose your tactics. They lock at kick-off.</li>
                    <li>At {{ config('game.match_time') }} every match in the league is played at once.</li>
                    <li>A minute later the result, the table and the replay are ready.</li>
                </ol>
                <p>Your best player in each position starts. Tired players are swapped automatically during the match.</p>
            </section>

            <section id="season">
                <h2 class="section-title narrow">The season</h2>
                <p>Each league has {{ $clubsPerLeague }} clubs. You play every club twice, once at home and once away: {{ ($clubsPerLeague - 1) * 2 }} rounds, about four weeks.</p>
                <dl class="kv">
                    <dt>Win</dt><dd>2 points</dd>
                    <dt>Draw</dt><dd>1 point</dd>
                    <dt>Loss</dt><dd>0 points</dd>
                    <dt>Equal points</dt><dd>Goal difference decides, then goals scored</dd>
                </dl>
                <p>There are three leagues. The Crown League is the top; the Ember League and the Frost League are the second tier, where new managers start. The winner of the Ember and of the Frost League go up to the Crown League; the bottom two of the Crown League go down, one to each. Between seasons players get one year older: players over 30 slow down and players retire at 36. Young players improve during the season through training.</p>
            </section>

            <section id="positions">
                <h2 class="section-title narrow">Positions</h2>
                <div class="sheet-wrap">
                    <table class="sheet">
                        <thead><tr><th class="l" scope="col">Short</th><th class="l" scope="col">Position</th><th class="l" scope="col">What he does</th></tr></thead>
                        <tbody>
                            <tr><td class="l"><span class="posbadge">GK</span></td><td class="l">Goalkeeper</td><td class="l club">Stops shots. A good keeper can win a match alone.</td></tr>
                            <tr><td class="l"><span class="posbadge">LW</span> <span class="posbadge">RW</span></td><td class="l">Left and right wing</td><td class="l club">Fast players in the corners. Score from tight angles and on fast breaks.</td></tr>
                            <tr><td class="l"><span class="posbadge">LB</span> <span class="posbadge">RB</span></td><td class="l">Left and right back</td><td class="l club">Tall shooters who score from 9 metres.</td></tr>
                            <tr><td class="l"><span class="posbadge">CB</span></td><td class="l">Centre back</td><td class="l club">The playmaker. Runs the attack with his passing.</td></tr>
                            <tr><td class="l"><span class="posbadge">PV</span></td><td class="l">Pivot</td><td class="l club">Strong player on the 6 m line among the defenders. Scores close to goal and wins 7-metres.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="attributes">
                <h2 class="section-title narrow">Player numbers</h2>
                <p>Every skill goes from 1 to 100. As a guide: <span class="attr attr--hi">75 and up</span> is excellent, 50 to 74 is solid, <span class="attr attr--lo">under 50</span> is weak. <b>Rating</b> sums up how good a player is in his own position.</p>
                <div class="sheet-wrap">
                    <table class="sheet">
                        <thead><tr><th class="l" scope="col">Skill</th><th class="l" scope="col">What it does in a match</th></tr></thead>
                        <tbody>
                            <tr><td class="l">Shooting</td><td class="l club">How often his shots go in</td></tr>
                            <tr><td class="l">Passing</td><td class="l club">Fewer lost balls and more assists</td></tr>
                            <tr><td class="l">Technique</td><td class="l club">Ball handling, shots from hard angles, breakthroughs</td></tr>
                            <tr><td class="l">Speed</td><td class="l club">Fast breaks and getting past defenders</td></tr>
                            <tr><td class="l">Stamina</td><td class="l club">How long he plays before he tires and gets worse</td></tr>
                            <tr><td class="l">Jumping</td><td class="l club">Shots over the defense from 9 metres, and blocks</td></tr>
                            <tr><td class="l">Defense</td><td class="l club">Steals, blocks, and making shots harder for the other team</td></tr>
                            <tr><td class="l">Composure</td><td class="l club">7-metre shots and staying calm: fewer 2-minute suspensions</td></tr>
                            <tr><td class="l">Reflexes</td><td class="l club">Goalkeepers: saves</td></tr>
                            <tr><td class="l">Positioning</td><td class="l club">Goalkeepers: saves, especially from far out</td></tr>
                            <tr><td class="l">7-metre saves</td><td class="l club">Goalkeepers: saving penalty throws</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="tactics">
                <h2 class="section-title narrow">Tactics</h2>
                <p>Tactics work like rock, paper, scissors: every choice is strong against some opponents and weak against others. The most important pair is how you defend and where you attack.</p>
                <dl class="kv">
                    <dt>Defense system</dt><dd>6-0 stands on the 6 m line; 5-1 sends one player out; 3-2-1 presses high and steals more balls</dd>
                    <dt>Attack focus</dt><dd>Shots from the wings, from 9 metres, or through the pivot</dd>
                    <dt>Tempo</dt><dd>Fast play gives more goals at both ends and tires players</dd>
                    <dt>7 vs 6</dt><dd>Swap the goalkeeper for an extra attacker; risky, because your goal is empty</dd>
                    <dt>Rotation</dt><dd>How early tired players are swapped</dd>
                </dl>
                <p>Each option is explained on the tactics page. Your opponent's tactics are shown when the match starts, so look at their last replays to guess what they will do.</p>
            </section>

            <section id="training">
                <h2 class="section-title narrow">Line-up, training and fitness</h2>
                <dl class="kv">
                    <dt>Line-up</dt><dd>Choose who starts in each position and who takes 7-metres, or leave positions on Automatic: then the best rested player starts</dd>
                    <dt>Training</dt><dd>After every match day each player trains the skills in his focus. Players up to 23 learn fastest; from 30 they stop improving. Every player has a limit he can't train past</dd>
                    <dt>Intensity</dt><dd>Hard training: about 50% faster progress, slower recovery, more injuries. Light: the opposite</dd>
                    <dt>Fitness</dt><dd>Playing makes players tired and every day they recover some. A tired player is weaker and is substituted sooner, so rotating your squad pays off</dd>
                    <dt>Injuries</dt><dd>A player can get injured in a match, more often when tired. He is out for 1 to 20 match days</dd>
                </dl>
            </section>

            <section id="money">
                <h2 class="section-title narrow">Money and transfers</h2>
                <p>Money in the game is counted in <b>gilden</b>. Your club earns it from ticket sales at home matches, a sponsor deal paid every match day, and prize money at the end of the season. It pays every player's wage every match day. If your balance goes below zero you can't buy players until it is positive again.</p>
                <dl class="kv">
                    <dt>Market value</dt><dd>What a player is worth. It rises fast with his rating; young players cost more, players over 30 less</dd>
                    <dt>Auction</dt><dd>Bids for 24 hours, the highest bid wins. You pay only if you win</dd>
                    <dt>Fixed price</dt><dd>Buy at once at the asking price</dd>
                    <dt>Free agents</dt><dd>Players without a club. No fee, only their wage</dd>
                    <dt>Contracts</dt><dd>Run until the end of a given season. Extend them on the player's page, or he leaves for free when it runs out</dd>
                    <dt>Squad size</dt><dd>Between {{ \App\Game\Economy::SQUAD_MIN }} and {{ \App\Game\Economy::SQUAD_MAX }} players</dd>
                </dl>
                <p>To sell a player, open his page from your squad and choose a price. Prices must be between half and four times his market value.</p>
            </section>

            <section id="arena">
                <h2 class="section-title narrow">Arena and fans</h2>
                <p>Every club has a number of <b>fans</b>. They grow a little with every win, shrink with every loss, and promotion brings many new ones (relegation costs some). The higher you are in the table, the bigger the share of your fans that comes to a home match. Your arena caps the crowd: when it is sold out, the fans who don't get a ticket are money you miss.</p>
                <p>On the <b>Arena</b> page you can build 250, 500 or 1.000 more seats. You pay at once and the seats are ready after a few match days. Every seat costs upkeep each home match, full or empty, so only build when your arena keeps selling out.</p>
            </section>

            <section id="academy">
                <h2 class="section-title narrow">Youth academy</h2>
                <p>At the start of every season a few 16 and 17 year olds join your academy. They cost no wages and train every match day. Promote a prospect to give him a first-team contract; if you don't, he leaves when he turns 20. The stars are your coaches' guess at how good he can become.</p>
                <p>Upgrade the academy (five levels) for more and better prospects, faster training and a more accurate talent guess. Each level costs more upkeep per match day; you can scale down again to save money.</p>
            </section>

            <section id="replay">
                <h2 class="section-title narrow">Reading a replay</h2>
                <dl class="kv">
                    <dt>Coloured circles</dt><dd>Players in their club colour, with their shirt number</dd>
                    <dt>Dark circles</dt><dd>Goalkeepers</dd>
                    <dt>Yellow dot</dt><dd>The ball. The player holding it has a white ring and his name shown</dd>
                    <dt>Bar under the court</dt><dd>Which team is attacking, in which direction, and what just happened</dd>
                    <dt>Orange area</dt><dd>The 6 m goal area. Only the goalkeeper may stand in it</dd>
                    <dt>Dashed line</dt><dd>The 9 m line. Shots from behind it are long shots</dd>
                </dl>
                <p>Use the speed buttons to watch at 2×, 4× or 8×, or drag the timeline to jump. Matches take about eight minutes at normal speed.</p>
            </section>
        </div>
    </div>
@endsection
