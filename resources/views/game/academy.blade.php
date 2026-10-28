@extends('layouts.app')

@section('title', 'Academy')

@php
    $money = fn ($v) => \App\Game\Economy::format((int) $v);
    $positions = \App\Models\Player::POSITIONS;
    $levels = \App\Game\Academy::LEVELS;
    $lvl = (int) $club->academy_level;
    $tone = fn ($v) => $v >= 75 ? 'hi' : ($v < 50 ? 'lo' : 'mid');
    $fieldSkills = ['shooting' => 'Sho', 'passing' => 'Pas', 'technique' => 'Tec', 'speed' => 'Spe', 'stamina' => 'Sta', 'jumping' => 'Jum', 'defense' => 'Def', 'composure' => 'Com'];
    $gkSkills = ['reflexes' => 'Ref', 'positioning' => 'Pos', 'seven_meter' => '7 m', 'stamina' => 'Sta', 'composure' => 'Com'];
@endphp

@section('content')
    <div class="section-head">
        <h2 class="section-title narrow">Youth academy</h2>
        <span class="muted">{{ $prospects->count() }} of {{ \App\Game\Academy::MAX_PROSPECTS }} places</span>
    </div>
    <p class="muted" style="max-width: 75ch">New prospects aged 16 and 17 join at the start of every season. They train every match day and cost no wages. Promote the good ones to your first team before they turn {{ \App\Game\Academy::LEAVE_AGE }}, or they leave. The stars show how talented your coaches think a prospect is: how good he can become, not how good he is now.</p>

    <div class="academy">
        <section class="academy__main">
            @if ($prospects->isEmpty())
                <p class="panel muted">No prospects right now. The next ones arrive at the start of next season.</p>
            @endif
            <ul class="prospects">
                @foreach ($prospects as $p)
                    @php
                        $stars = $p->stars($lvl);
                        $skills = $p->isGoalkeeper() ? $gkSkills : $fieldSkills;
                        $leaving = $p->age + 1 >= \App\Game\Academy::LEAVE_AGE;
                    @endphp
                    <li class="prospect panel">
                        <div class="prospect__head">
                            {!! \App\Support\Avatar::svg($p->asPlayer(), 56, $club->color) !!}
                            <div class="prospect__id">
                                <b class="prospect__name">{{ $p->name }}</b>
                                <span class="muted">{{ $positions[$p->position] }}, {{ $p->age }} years</span>
                                <span class="stars" title="Talent: {{ $stars }} of 5" aria-label="Talent {{ $stars }} of 5">@for ($i = 1; $i <= 5; $i++)<i class="{{ $i <= $stars ? 'on' : '' }}">★</i>@endfor</span>
                            </div>
                            <div class="prospect__rating"><b class="narrow">{{ $p->rating() }}</b><span class="muted">now</span></div>
                        </div>
                        <dl class="prospect__skills">
                            @foreach ($skills as $key => $label)
                                <div><dt>{{ $label }}</dt><dd><span>{{ $p->{$key} }}</span><i class="skill skill--{{ $tone($p->{$key}) }}" aria-hidden="true"><b style="width: {{ $p->{$key} }}%"></b></i></dd></div>
                            @endforeach
                        </dl>
                        @if ($leaving)<p class="prospect__warn">Turns {{ \App\Game\Academy::LEAVE_AGE }} at the end of this season. Promote him or he leaves.</p>@endif
                        <div class="btn-row">
                            <form method="POST" action="{{ route('academy.promote', $p) }}">
                                @csrf
                                <button class="btn {{ $stars >= 4 || $leaving ? 'btn--primary' : '' }}" type="submit" @disabled($squadFull) @if ($squadFull) title="Your squad is full" @endif>Promote to first team</button>
                            </form>
                            <form method="POST" action="{{ route('academy.release', $p) }}">
                                @csrf
                                <button class="btn" type="submit">Let him go</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($squadFull && $prospects->isNotEmpty())
                <p class="muted" style="margin-top: 10px">Your squad has {{ \App\Game\Economy::SQUAD_MAX }} players, the most allowed. Sell or release a player to make room for a prospect.</p>
            @endif
        </section>

        <aside class="stack">
            <section class="panel">
                <h3 class="section-title narrow" style="font-size: var(--s-19)">Academy level</h3>
                <ol class="levels" aria-label="Academy levels">
                    @foreach ($levels as $n => $l)
                        <li class="{{ $n <= $lvl ? 'is-done' : '' }} {{ $n === $lvl ? 'is-current' : '' }}"><span>{{ $n }}</span>{{ $l['label'] }}</li>
                    @endforeach
                </ol>
                <dl class="build__facts" style="margin-top: 12px">
                    <dt>New prospects</dt><dd>{{ $level['intake'] }} a season</dd>
                    <dt>Training speed</dt><dd>{{ round($level['xp'] * 100) }}%</dd>
                    <dt>Upkeep</dt><dd>{{ $money($level['upkeep']) }} per match day</dd>
                </dl>
                @if ($next)
                    <form method="POST" action="{{ route('academy.level') }}" class="build" style="margin-top: 14px">
                        @csrf
                        <input type="hidden" name="level" value="{{ $lvl + 1 }}">
                        <p><b>Upgrade to {{ $next['label'] }}:</b> {{ $next['intake'] }} prospects a season, better ones, training {{ round($next['xp'] * 100) }}%, a sharper eye for talent. Upkeep {{ $money($next['upkeep']) }} per match day.</p>
                        <button class="btn btn--primary" type="submit" @disabled($club->balance < $next['cost'])>{{ $club->balance < $next['cost'] ? 'Not enough money' : 'Upgrade for '.$money($next['cost']) }}</button>
                    </form>
                @else
                    <p style="margin-top: 14px">Your academy is at the highest level.</p>
                @endif
                @if ($lvl > 1)
                    <form method="POST" action="{{ route('academy.level') }}" style="margin-top: 10px">
                        @csrf
                        <input type="hidden" name="level" value="{{ $lvl - 1 }}">
                        <button class="linkbutton linkbutton--quiet" type="submit">Scale down to {{ $levels[$lvl - 1]['label'] }} (saves upkeep, no refund)</button>
                    </form>
                @endif
            </section>
            <section class="panel">
                <h3 class="section-title narrow" style="font-size: var(--s-19)">Why promote?</h3>
                <p>A promoted prospect costs a small wage and keeps improving fast until he is about 23. Even if he never makes your starting seven, a young player with talent sells for a good price on the transfer market.</p>
            </section>
        </aside>
    </div>
@endsection
