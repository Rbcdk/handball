@extends('layouts.app')

@section('title', 'Arena')

@php
    $money = fn ($v) => \App\Game\Economy::format((int) $v);
    $n = fn ($v) => number_format((int) $v, 0, ',', '.');
    $cap = (int) $club->arena_capacity;
    $wanting = $forecast['wanting'];
    $shut = max(0, $wanting - $cap);
    $fillPct = min(100, round($forecast['attendance'] / max(1, $cap) * 100));
    $soldOutGames = $homeGames->filter(fn ($f) => $f->attendance >= $cap)->count();
    $avg = $homeGames->isEmpty() ? null : (int) round($homeGames->avg('attendance'));
@endphp

@section('content')
    <div class="section-head">
        <h2 class="section-title narrow">Arena</h2>
    </div>
    <p class="muted" style="max-width: 75ch">Your fans grow when you win and shrink when you lose; promotion brings many new fans. The higher you are in the table, the more of them come to a home match. More seats only pay off when the arena is sold out and fans are turned away.</p>

    <div class="grid-2" style="margin-top: 20px">
        <section>
            <div class="playerfacts">
                <div><span class="muted">Seats</span><b class="narrow">{{ $n($cap) }}</b></div>
                <div><span class="muted">Fans</span><b class="narrow">{{ $n($club->fans) }}</b></div>
                <div><span class="muted">Ticket price</span><b class="narrow">{{ $money($price) }}</b></div>
                <div><span class="muted">Upkeep per home match</span><b class="narrow">{{ $money($upkeep) }}</b></div>
            </div>

            <h3 class="section-title narrow" style="font-size: var(--s-19); margin-top: 24px">Next home match</h3>
            <div class="crowd" role="img" aria-label="{{ $n($forecast['attendance']) }} of {{ $n($cap) }} seats expected to be filled">
                <div class="crowd__bar"><i style="width: {{ $fillPct }}%"></i></div>
                <div class="crowd__legend">
                    <span><b>{{ $n($forecast['attendance']) }}</b> fans expected, {{ $fillPct }}% full</span>
                    <span>{{ $money($forecast['income']) }} in tickets</span>
                </div>
            </div>
            @if ($shut > 0)
                <p class="crowd__note crowd__note--hot">Sold out: about <b>{{ $n($shut) }}</b> more fans would come if you had the seats. That's {{ $money($shut * $price) }} of tickets you can't sell each home match.</p>
            @else
                <p class="crowd__note">{{ $n($cap - $forecast['attendance']) }} seats will stay empty. Win more matches to grow your fans before you build.</p>
            @endif

            <h3 class="section-title narrow" style="font-size: var(--s-19); margin-top: 24px">Home matches this season</h3>
            @if ($homeGames->isEmpty())
                <p class="muted">No home matches played yet.</p>
            @else
                <p class="muted" style="margin-bottom: 8px">Average crowd {{ $n($avg) }}. Sold out {{ $soldOutGames }} of {{ $homeGames->count() }}.</p>
                <table class="sheet">
                    <thead><tr><th class="l" scope="col">Opponent</th><th scope="col">Crowd</th><th scope="col">Full</th></tr></thead>
                    <tbody>
                        @foreach ($homeGames as $f)
                            <tr>
                                <td class="l"><a href="{{ route('matches.show', $f) }}">{{ $f->away->name }}</a></td>
                                <td class="num">{{ $n($f->attendance) }}</td>
                                <td>@if ($f->attendance >= $cap)<span class="tag tag--sale">Sold out</span>@else{{ round($f->attendance / $cap * 100) }}%@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section>
            <h3 class="section-title narrow" style="font-size: var(--s-19)">Build more seats</h3>
            @if ($club->arena_expansion)
                <div class="panel build">
                    <p><b>{{ $n($club->arena_expansion) }} new seats</b> are being built.</p>
                    <div class="crowd__bar crowd__bar--build"><i style="width: {{ round((1 - $club->arena_build_left / \App\Game\Economy::EXPANSIONS[$club->arena_expansion]) * 100) }}%"></i></div>
                    <p class="muted">Ready after {{ $club->arena_build_left }} more {{ $club->arena_build_left === 1 ? 'match day' : 'match days' }}. Then the arena holds {{ $n($cap + $club->arena_expansion) }}.</p>
                </div>
            @else
                <p class="muted" style="margin-bottom: 12px">You pay now; the seats are ready after a few match days. Every seat costs a little upkeep, full or empty. The bigger the arena, the more each new seat costs.</p>
                <div class="builds">
                    @foreach ($options as $o)
                        <form method="POST" action="{{ route('arena.expand') }}" class="panel build">
                            @csrf
                            <input type="hidden" name="seats" value="{{ $o['seats'] }}">
                            <div class="build__head"><b class="narrow">+{{ $n($o['seats']) }} seats</b><span class="muted">{{ $o['days'] }} match days</span></div>
                            <dl class="build__facts">
                                <dt>Costs</dt><dd>{{ $money($o['cost']) }}</dd>
                                <dt>Extra upkeep</dt><dd>{{ $money($o['upkeep']) }} per home match</dd>
                                <dt>If sold out</dt><dd>+{{ $money($o['income']) }} per home match</dd>
                            </dl>
                            @if (! $o['possible'])
                                <span class="muted">The arena can't grow this much.</span>
                            @elseif ($club->balance < $o['cost'])
                                <button class="btn" type="submit" disabled>Not enough money</button>
                            @else
                                <button class="btn {{ $shut >= $o['seats'] / 2 ? 'btn--primary' : '' }}" type="submit">Build for {{ $money($o['cost']) }}</button>
                            @endif
                        </form>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
