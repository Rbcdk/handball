@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    <div class="stack" style="gap: 40px">
        @if ($guide)
            @php $doneCount = count(array_filter($guide)); @endphp
            <section class="guide" aria-labelledby="guide-title">
                <div class="guide__head">
                    <h2 class="section-title narrow" id="guide-title">Getting started</h2>
                    <span class="muted">{{ $doneCount }} of 4 done</span>
                </div>
                <p class="guide__lede">Four things to do as a new manager. Each one ticks itself off when you have done it.</p>
                <ol class="guide__steps">
                    <li class="{{ $guide['squad'] ? 'is-done' : '' }}">
                        <span class="guide__mark" aria-hidden="true"></span>
                        <div>
                            <a href="{{ route('squad') }}">Meet your squad</a>
                            <p>See your players, their positions and who starts. Set your own line-up on the Line-up page, or let the best rested player start.</p>
                        </div>
                    </li>
                    <li class="{{ $guide['tactics'] ? 'is-done' : '' }}">
                        <span class="guide__mark" aria-hidden="true"></span>
                        <div>
                            <a href="{{ route('tactics.edit') }}">Choose your tactics</a>
                            <p>Pick how your team defends and attacks, then press Save. This is the main decision you make before each match.</p>
                        </div>
                    </li>
                    <li class="{{ $guide['replay'] ? 'is-done' : '' }}">
                        <span class="guide__mark" aria-hidden="true"></span>
                        <div>
                            @if ($last)
                                <a href="{{ route('matches.show', $last) }}">Watch a match replay</a>
                            @else
                                <span class="guide__label">Watch a match replay</span>
                            @endif
                            <p>Matches play by themselves at {{ config('game.match_time') }}. Afterwards you can watch the replay with commentary.</p>
                        </div>
                    </li>
                    <li class="{{ $guide['league'] ? 'is-done' : '' }}">
                        <span class="guide__mark" aria-hidden="true"></span>
                        <div>
                            <a href="{{ route('leagues.show', $club->league) }}">Check the league table</a>
                            <p>A win gives 2 points, a draw 1. Finish first in your league to go up to the Crown League.</p>
                        </div>
                    </li>
                </ol>
                <div class="guide__foot">
                    <a href="{{ route('help') }}">Read how the game works</a>
                    <form method="POST" action="{{ route('guide.dismiss') }}">
                        @csrf
                        <button class="linkbutton linkbutton--quiet" type="submit">Hide this guide</button>
                    </form>
                </div>
            </section>
        @endif

        <section aria-labelledby="next-title">
            <div class="section-head">
                <h2 class="section-title narrow" id="next-title">Next match</h2>
                @if ($next)<span class="muted">{{ $next->league->name }}, round {{ $next->round }}</span>@endif
            </div>
            @if ($next)
                <div class="fixture-hero">
                    <a class="fixture-hero__team clublink" href="{{ route('clubs.show', $next->home) }}">
                        <x-crest :club="$next->home" :size="52" />
                        <span class="fixture-hero__name narrow">{{ $next->home->name }}</span>
                    </a>
                    <div class="fixture-hero__when">
                        <div class="fixture-hero__time">{{ $next->localKickoff()->format('H:i') }}</div>
                        <div class="fixture-hero__day">{{ $next->kickoffDay() }}</div>
                        @if ($next->countdown())
                            <div class="countdown">in {{ $next->countdown() }}</div>
                        @endif
                    </div>
                    <a class="fixture-hero__team fixture-hero__team--away clublink" href="{{ route('clubs.show', $next->away) }}">
                        <x-crest :club="$next->away" :size="52" />
                        <span class="fixture-hero__name narrow">{{ $next->away->name }}</span>
                    </a>
                </div>
                <ul class="howit">
                    <li>The match is played automatically at {{ $next->localKickoff()->format('H:i') }}. You don't need to be online.</li>
                    <li>Your team uses the tactics saved at kick-off. You can change them until then.</li>
                    <li>The result and the replay appear here a minute after kick-off.</li>
                </ul>
                <div class="btn-row" style="margin-top: 16px">
                    <a class="btn btn--primary" href="{{ route('tactics.edit') }}">Set tactics</a>
                    <a class="btn" href="{{ route('matches.show', $next) }}">Match preview</a>
                </div>
            @else
                <p class="muted">The season is over. The next season's fixtures appear here within a few days.</p>
            @endif
        </section>

        <div class="grid-2">
            <section aria-labelledby="table-title">
                <div class="section-head">
                    <h2 class="section-title narrow" id="table-title">{{ $club->league->name }}</h2>
                    <a href="{{ route('leagues.show', $club->league) }}">Results by round</a>
                </div>
                @include('partials.table', ['rows' => $table, 'highlight' => $club->id, 'zones' => true, 'tier' => (int) $club->league->tier, 'showForm' => false])
            </section>

            <div class="stack" style="gap: 32px">
                <section aria-labelledby="news-title">
                    <h2 class="section-title narrow" id="news-title">Club news</h2>
                    @if ($news->isEmpty())
                        <p class="muted">Injuries, recoveries and training progress appear here after each match.</p>
                    @else
                        <ul class="news">
                            @foreach ($news as $n)
                                <li class="{{ $n->kind }}"><span class="dot" aria-hidden="true"></span><span>{{ $n->message }}</span><time datetime="{{ $n->created_at->toIso8601String() }}">{{ $n->created_at->copy()->setTimezone(config('game.timezone'))->format('j M') }}</time></li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($injured->isNotEmpty())
                        <p class="muted" style="margin-top: 8px">Injured now: {{ $injured->map(fn ($p) => $p->name.' ('.$p->injured_days.' '.($p->injured_days === 1 ? 'day' : 'days').')')->implode(', ') }}.</p>
                    @endif
                </section>

                <section aria-labelledby="last-title">
                    <h2 class="section-title narrow" id="last-title">Last result</h2>
                    @if ($last)
                        @php $outcome = $last->outcomeFor($club); @endphp
                        <p style="margin-bottom: 8px">
                            @if ($outcome === 'win') You beat {{ $last->opponentOf($club)->name }}.
                            @elseif ($outcome === 'draw') Draw against {{ $last->opponentOf($club)->name }}.
                            @else You lost to {{ $last->opponentOf($club)->name }}.
                            @endif
                        </p>
                        @include('partials.results', ['fixtures' => collect([$last])])
                        <p style="margin-top: 10px"><a href="{{ route('matches.show', $last) }}">Watch the replay</a></p>
                    @else
                        <p class="muted">No matches played yet. Your first match is above.</p>
                    @endif
                </section>

                @if ($recent->count() > 1)
                    <section aria-labelledby="form-title">
                        <h2 class="section-title narrow" id="form-title">Form</h2>
                        <x-form :form="$recent->reverse()->map(fn ($f) => ['win' => 'W', 'draw' => 'D', 'loss' => 'L'][$f->outcomeFor($club)])->values()->all()" />
                        @if ($myRow)
                            <p class="muted" style="margin-top: 8px">{{ $myRow->won }} {{ \Illuminate\Support\Str::plural('win', $myRow->won) }}, {{ $myRow->drawn }} {{ \Illuminate\Support\Str::plural('draw', $myRow->drawn) }} and {{ $myRow->lost }} {{ $myRow->lost === 1 ? 'loss' : 'losses' }} this season.</p>
                        @endif
                    </section>
                @endif

                <section aria-labelledby="scorers-title">
                    <h2 class="section-title narrow" id="scorers-title">Top scorers</h2>
                    @if ($scorers->isNotEmpty())
                        <table class="sheet">
                            <thead><tr><th class="l" scope="col">Player</th><th scope="col">Goals</th><th scope="col">Shots</th><th scope="col">Assists</th></tr></thead>
                            <tbody>
                                @foreach ($scorers as $s)
                                    <tr><td class="l">{{ $s->player?->name ?? 'Former player' }}</td><td class="pts">{{ $s->goals }}</td><td>{{ $s->shots }}</td><td>{{ $s->assists }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="muted">Goal scorers appear after your first match.</p>
                    @endif
                </section>
            </div>
        </div>
    </div>
@endsection
