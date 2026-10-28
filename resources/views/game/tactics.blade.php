@extends('layouts.app')

@section('title', 'Tactics')

@php
    $help = [
        'defense' => [
            '6-0' => 'All six defenders on the 6 m line. Shuts down the pivot and breakthroughs, but gives the backs room to shoot from 9 m.',
            '5-1' => 'One defender steps out to disturb the centre back. Good against long shots; leaves more room for the pivot.',
            '3-2-1' => 'Deep and aggressive. Wins more balls and kills shots from 9 m, but opens the wings and the pivot, and costs more 2-minute suspensions.',
        ],
        'tempo' => [
            'slow' => 'Long attacks. Fewer turnovers and fast breaks, lower scoring. Risk of passive play.',
            'normal' => 'Balanced attacks of about 35 seconds.',
            'fast' => 'Quick attacks and more fast breaks. More goals at both ends and more turnovers. Players tire faster.',
        ],
        'focus' => [
            'balanced' => 'Takes whatever the defense gives.',
            'wings' => 'More wing shots. Strong against a 3-2-1.',
            'backs' => 'More shots from 9 m and breakthroughs. Strong against a 6-0, weak against 5-1 and 3-2-1.',
            'pivot' => 'Plays the ball into the pivot. Strong against 5-1 and 3-2-1, weak against a 6-0.',
        ],
        'sevenVsSix' => [
            'never' => 'The goalkeeper always stays in goal.',
            'whenShort' => 'Swap the keeper for an extra court player while you have a player suspended.',
            'whenBehind' => 'As above, and also whenever you are behind in the second half.',
            'always' => 'Attack with seven court players every time. More goals, but turnovers can end in your empty goal.',
        ],
        'rotation' => [
            'low' => 'The starters play as long as possible.',
            'normal' => 'Tired players are replaced at a normal level of fatigue.',
            'high' => 'Rotates early to keep legs fresh. Bench players get more minutes.',
        ],
    ];
@endphp

@section('content')
    <div class="grid-2">
        <form method="POST" action="{{ route('tactics.update') }}">
            @csrf
            @method('PUT')
            <h2 class="section-title narrow">Tactics</h2>
            <p class="muted">Changes apply from your next match. Your opponent sees your tactics when the match starts, not before.</p>

            @foreach (\App\Models\Club::TACTIC_OPTIONS as $key => $def)
                <div class="tactic">
                    <fieldset>
                        <legend>{{ $def['label'] }}</legend>
                        <div class="seg">
                            @foreach ($def['options'] as $value => $label)
                                <label>
                                    <input type="radio" name="{{ $key }}" value="{{ $value }}" id="{{ $key }}-{{ $value }}" @checked($tactics[$key] === $value)>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <p class="tactic__help" data-help="{{ $key }}">
                        @foreach ($def['options'] as $value => $label)
                            <span data-for="{{ $value }}" @if ($tactics[$key] !== $value) hidden @endif><b>{{ $label }}:</b> {{ $help[$key][$value] }}</span>
                        @endforeach
                    </p>
                    @error($key)<p class="error" style="color: var(--loss)">{{ $message }}</p>@enderror
                </div>
            @endforeach

            <div class="btn-row" style="margin-top: 20px">
                <button class="btn btn--primary" type="submit">Save tactics</button>
            </div>
        </form>

        <aside class="stack">
            @include('partials.formation')
            @if ($next)
                <section class="panel">
                    <h2 class="section-title narrow" style="font-size: var(--s-19)">Next opponent</h2>
                    <p style="margin-bottom: 10px">{{ $next->opponentOf($club)->name }}, {{ $next->kickoffLabel() }}.</p>
                    <p class="muted">Their tactics are hidden until kick-off. Their recent matches are on <a href="{{ route('clubs.show', $next->opponentOf($club)) }}">their club page</a>; each replay shows the tactics they used.</p>
                </section>
            @endif
            <section>
                <h2 class="section-title narrow" style="font-size: var(--s-19)">How the matchups work</h2>
                <div class="sheet-wrap" style="margin-top: 10px">
                    <table class="sheet">
                        <caption>Win rate of each attack focus against each defense, between equal teams</caption>
                        <thead><tr><th class="l" scope="col">Attack focus</th><th scope="col">vs 6-0</th><th scope="col">vs 5-1</th><th scope="col">vs 3-2-1</th></tr></thead>
                        <tbody>
                            <tr><td class="l">Balanced</td><td>49%</td><td>46%</td><td>45%</td></tr>
                            <tr><td class="l">Wings</td><td>51%</td><td>48%</td><td class="pts">56%</td></tr>
                            <tr><td class="l">Backs (9 m)</td><td class="pts">61%</td><td>41%</td><td>39%</td></tr>
                            <tr><td class="l">Pivot</td><td>47%</td><td class="pts">55%</td><td class="pts">56%</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </aside>
    </div>
@endsection
