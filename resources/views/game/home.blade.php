@extends('layouts.app')

@section('band')
    <div class="hero">
        <div>
            <h1 class="hero__title narrow">One match every evening.</h1>
            <p class="hero__text">Take over a club in the Ember or Frost League, set the tactics, and watch your team play at {{ config('game.match_time') }}. Ten minutes a day is enough.</p>
            <div class="hero__cta btn-row">
                <a class="btn btn--primary" href="{{ route('register') }}">Take over a club</a>
                <a class="btn btn--onband" href="{{ route('leagues.show') }}">See the leagues</a>
            </div>
            <p class="hero__facts">
                <span><b>{{ $freeClubs }}</b>clubs without a manager</span>
                @if ($nextKickoff)
                    <span><b>{{ $nextKickoff->copy()->setTimezone(config('game.timezone'))->format('H:i') }}</b>next kick-off</span>
                @endif
            </p>
        </div>

        <div class="hero__replay">
            @if ($replay && $replay->matchLog)
                <div class="court" data-match-viewer="hero">
                    <canvas aria-label="Replay of {{ $replay->home->name }} against {{ $replay->away->name }}"></canvas>
                    <div class="court__overlay" hidden></div>
                </div>
                <script type="application/json" id="match-data">{!! str_replace('</', '<\/', $replay->matchLog->json()) !!}</script>
                <p class="hero__caption">
                    <span>Replay: {{ $replay->home->name }} {{ $replay->home_score }}–{{ $replay->away_score }} {{ $replay->away->name }}, {{ $replay->league->name }} round {{ $replay->round }}</span>
                    <a href="{{ route('matches.show', $replay) }}">Watch with commentary</a>
                </p>
            @else
                <div class="court" data-match-viewer="empty">
                    <canvas aria-label="Empty handball court"></canvas>
                    <div class="court__overlay">
                        <div>
                            <h3 class="narrow">The first round hasn't been played yet</h3>
                            @if ($nextKickoff)
                                <p>Kick-off {{ $nextKickoff->copy()->setTimezone(config('game.timezone'))->format('l H:i') }}.</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="stack" style="gap: 48px">
        <section>
            <h2 class="section-title narrow">How a season works</h2>
            <ol class="steps">
                <li>
                    <h3>Take over a club</h3>
                    <p>Every club starts with a computer manager. Pick one in the Ember or Frost League and it's yours, with a full squad of 16 players.</p>
                </li>
                <li>
                    <h3>Set your tactics</h3>
                    <p>Choose the defense system, tempo and where to attack. A 6-0 defense invites shots from 9 metres; a 3-2-1 leaves the wings open.</p>
                </li>
                <li>
                    <h3>Watch the match at {{ config('game.match_time') }}</h3>
                    <p>Matches are played on the server every evening. Replay them in 2D with commentary, then climb the table. Win your league to go up to the Crown League.</p>
                </li>
            </ol>
        </section>

        @if ($top && $table->isNotEmpty())
            <section>
                <div class="section-head">
                    <h2 class="section-title narrow">{{ $top->name }} right now</h2>
                    <a href="{{ route('leagues.show', $top) }}">Full table and results</a>
                </div>
                @include('partials.table', ['rows' => $table, 'showForm' => true])
            </section>
        @endif
    </div>
@endsection
