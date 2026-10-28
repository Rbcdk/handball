@extends('layouts.app')

@section('title', $fixture->home->name.' – '.$fixture->away->name)

@section('band')
    <div class="board" @if ($log) data-scoreboard @endif>
        <div class="board__team">
            <x-crest :club="$fixture->home" :size="48" />
            <div style="min-width: 0">
                <a class="board__name narrow" href="{{ route('clubs.show', $fixture->home) }}"><span class="full">{{ $fixture->home->name }}</span><span class="abbr">{{ $fixture->home->short }}</span></a>
                <div class="board__susp" data-susp="home"></div>
            </div>
        </div>
        <div>
            <div class="board__score narrow">
                @if ($fixture->isPlayed())
                    <span data-score="home">{{ $fixture->home_score }}</span>–<span data-score="away">{{ $fixture->away_score }}</span>
                @else
                    {{ $fixture->localKickoff()->format('H:i') }}
                @endif
            </div>
            <div class="board__clock" data-clock>
                @if ($fixture->isPlayed()) Full time, half-time {{ $fixture->halftime_home }}–{{ $fixture->halftime_away }} @else {{ $fixture->kickoffLabel() }} @endif
            </div>
        </div>
        <div class="board__team board__team--away">
            <x-crest :club="$fixture->away" :size="48" />
            <div style="min-width: 0">
                <a class="board__name narrow" href="{{ route('clubs.show', $fixture->away) }}"><span class="full">{{ $fixture->away->name }}</span><span class="abbr">{{ $fixture->away->short }}</span></a>
                <div class="board__susp" data-susp="away"></div>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <p class="muted" style="margin-bottom: 16px">{{ $fixture->league->name }}, season {{ $fixture->season->number }}, round {{ $fixture->round }}.</p>

    @if ($log)
        <div class="viewer" data-match-viewer="full">
            <div>
                <div class="court">
                    <canvas aria-label="Top-down replay of the match"></canvas>
                    <div class="court__overlay" hidden></div>
                </div>
                <div class="playbar" data-playbar aria-live="polite">
                    <span class="playbar__who" data-attack>Kick-off</span>
                    <span class="playbar__now" data-now>The match starts when you press Play.</span>
                </div>
                <p class="courtkey">
                    <span><i class="courtkey__dot" style="background: {{ $fixture->home->color }}"></i>{{ $fixture->home->short }}</span>
                    <span><i class="courtkey__dot" style="background: {{ $fixture->away->color }}"></i>{{ $fixture->away->short }}</span>
                    <span><i class="courtkey__dot courtkey__dot--gk"></i>Goalkeeper</span>
                    <span><i class="courtkey__dot courtkey__dot--ball"></i>Ball</span>
                    <span><i class="courtkey__dot courtkey__dot--ring"></i>Has the ball</span>
                    <a href="{{ route('help') }}#replay">How to read the replay</a>
                </p>
                <div class="controls">
                    <button class="btn btn--primary" type="button" data-play>Play</button>
                    <div class="speeds" role="group" aria-label="Replay speed">
                        <button type="button" data-speed="1">1×</button>
                        <button type="button" data-speed="2">2×</button>
                        <button type="button" data-speed="4">4×</button>
                        <button type="button" data-speed="8">8×</button>
                    </div>
                    <span class="spacer"></span>
                    <button class="btn" type="button" data-skip>Skip to full time</button>
                </div>
                <label class="seek">
                    <span data-seek-clock>00:00</span>
                    <input type="range" min="0" max="100" step="0.05" value="0" data-seek aria-label="Match timeline">
                    <span>60:00</span>
                </label>
            </div>
            <div class="sidepanel">
                <div class="sidetabs" role="tablist">
                    <button type="button" role="tab" aria-selected="true" data-tab="feed">Play-by-play</button>
                    <button type="button" role="tab" aria-selected="false" data-tab="stats">Stats</button>
                </div>
                <div class="sidebody" data-panel="feed" role="tabpanel">
                    <ol class="feed" data-feed></ol>
                    <p class="muted" data-feed-empty style="padding-top: 12px">Press Play to start the replay.</p>
                </div>
                <div class="sidebody" data-panel="stats" role="tabpanel" hidden></div>
            </div>
        </div>
        <script type="application/json" id="match-data">{!! str_replace('</', '<\/', $log) !!}</script>
    @else
        <div class="grid-2">
            <section>
                <h2 class="section-title narrow">Match preview</h2>
                <div class="sheet-wrap">
                    <table class="sheet">
                        <thead><tr><th class="l" scope="col"></th><th scope="col">{{ $fixture->home->short }}</th><th scope="col">{{ $fixture->away->short }}</th></tr></thead>
                        <tbody>
                            <tr><td class="l">Table position</td><td>{{ $homeRow?->position ?? '–' }}</td><td>{{ $awayRow?->position ?? '–' }}</td></tr>
                            <tr><td class="l">Points</td><td>{{ $homeRow?->points ?? 0 }}</td><td>{{ $awayRow?->points ?? 0 }}</td></tr>
                            <tr><td class="l">Goals</td><td>{{ $homeRow ? $homeRow->for.'–'.$homeRow->against : '–' }}</td><td>{{ $awayRow ? $awayRow->for.'–'.$awayRow->against : '–' }}</td></tr>
                            <tr><td class="l">Form</td><td><x-form :form="$homeRow?->form ?? []" /></td><td><x-form :form="$awayRow?->form ?? []" /></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section>
                <h2 class="section-title narrow">Kick-off {{ $fixture->kickoffLabel() }}</h2>
                <p class="preview-note">Both teams' tactics are revealed when the match starts. The replay and commentary appear here after the final whistle.</p>
                @if ($myClubId && in_array($myClubId, [$fixture->home_club_id, $fixture->away_club_id]))
                    <p style="margin-top: 16px"><a class="btn btn--primary" href="{{ route('tactics.edit') }}">Set your tactics</a></p>
                @endif
            </section>
        </div>
    @endif
@endsection
