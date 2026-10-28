@extends('layouts.app')

@section('title', 'Line-up')

@php
    $slotNames = ['GK' => 'Goalkeeper'] + \App\Http\Controllers\TeamController::SLOT_NAMES;
    $positions = \App\Models\Player::POSITIONS;
    $chosen = $saved['slots'] ?? [];
    // Where each position stands on the drawing, in % of the court's width and height.
    $spots = [
        'LW' => [11, 14], 'RW' => [89, 14], 'PV' => [50, 24],
        'LB' => [21, 42], 'CB' => [50, 47], 'RB' => [79, 42], 'GK' => [50, 84],
    ];
    $groups = ['Goalkeepers' => fn ($c) => $c['gk'], 'Court players' => fn ($c) => ! $c['gk']];
@endphp

@section('content')
    <form method="POST" action="{{ route('lineup.update') }}" class="lineup2" data-lineup
          data-players="{{ json_encode($cards->values()) }}" data-chosen="{{ json_encode(['slots' => (object) $chosen, 'gk' => $saved['gk'] ?? null]) }}">
        @csrf
        @method('PUT')
        @foreach (array_keys($slotNames) as $slot)
            @if ($slot === 'GK')
                <input type="hidden" name="gk" value="{{ $saved['gk'] ?? '' }}" data-input="GK">
            @else
                <input type="hidden" name="slots[{{ $slot }}]" value="{{ $chosen[$slot] ?? '' }}" data-input="{{ $slot }}">
            @endif
        @endforeach

        <div class="section-head">
            <h2 class="section-title narrow">Starting seven</h2>
            @if ($next)<span class="muted">Next match: {{ $next->opponentOf($club)->name }}, {{ $next->kickoffLabel() }}</span>@endif
        </div>
        <p class="muted lineup2__intro">Drag a player onto a position on the court, or tap a player and then a position. Drag between positions to swap players. A position you don't fill is <b>automatic</b>: the best rested player starts there, shown with a dashed ring. The number under each player is how good he is <em>in that position</em>.</p>

        @error('slots')<p class="flash flash--error">{{ $message }}</p>@enderror

        <div class="lineup2__grid">
            <div class="lineup2__courtcol">
                <div class="pitch" data-pitch>
                    <svg class="pitch__lines" viewBox="0 0 200 270" preserveAspectRatio="none" aria-hidden="true">
                        <rect x="0" y="0" width="200" height="270" class="pitch__floor"/>
                        <path d="M25 0 A60 60 0 0 0 85 60 H115 A60 60 0 0 0 175 0 Z" class="pitch__area"/>
                        <path d="M0 29.6 A90 90 0 0 0 85 90 H115 A90 90 0 0 0 200 29.6" class="pitch__line pitch__line--dash"/>
                        <path d="M25 0 A60 60 0 0 0 85 60 H115 A60 60 0 0 0 175 0" class="pitch__line"/>
                        <path d="M95 70 H105 M98 40 H102" class="pitch__line"/>
                        <rect x="85" y="-3" width="30" height="6" class="pitch__goal"/>
                        <path d="M0 192 H200" class="pitch__line"/>
                        <path d="M0 206 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4 l10 -4 l10 4" class="pitch__cut"/>
                        <path d="M25 270 A60 60 0 0 1 85 210 H115 A60 60 0 0 1 175 270 Z" class="pitch__area"/>
                        <path d="M25 270 A60 60 0 0 1 85 210 H115 A60 60 0 0 1 175 270" class="pitch__line"/>
                        <rect x="85" y="267" width="30" height="6" class="pitch__goal"/>
                    </svg>
                    <span class="pitch__label">↑ Attacking this way</span>

                    @foreach ($spots as $slot => [$x, $y])
                        <div class="spot" data-slot="{{ $slot }}" style="left: {{ $x }}%; top: {{ $y }}%">
                            <button type="button" class="spot__main" data-spot="{{ $slot }}" aria-label="{{ $slotNames[$slot] }}">
                                <span class="spot__disc" data-disc></span>
                                <span class="spot__pos">{{ $slot }}</span>
                                <span class="spot__name" data-name>…</span>
                                <span class="spot__meta" data-meta></span>
                                <span class="spot__preview" data-preview hidden></span>
                            </button>
                            <button type="button" class="spot__clear" data-clear="{{ $slot }}" aria-label="Set {{ $slotNames[$slot] }} to automatic" hidden>×</button>
                        </div>
                    @endforeach
                    <div class="pitch__ghost" data-ghost hidden></div>
                </div>

                <div class="lineup2__seven">
                    <label for="sevenMeter">7-metre taker</label>
                    <select id="sevenMeter" name="sevenMeter" data-seven>
                        <option value="">Automatic (best penalty shooter on court)</option>
                        @foreach ($players->reject(fn ($p) => $p->isGoalkeeper())->sortByDesc(fn ($p) => $p->shooting + $p->composure) as $p)
                            <option value="{{ $p->id }}" @selected(($saved['sevenMeter'] ?? null) == $p->id)>{{ $p->name }} · shooting {{ $p->shooting }}, composure {{ $p->composure }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="btn-row lineup2__actions">
                    <button class="btn btn--primary" type="submit" data-save>Save line-up</button>
                    <button class="btn" type="button" data-auto-all>Set everything to automatic</button>
                    <span class="lineup2__dirty" data-dirty hidden>Unsaved changes</span>
                </div>
            </div>

            <aside class="bench" data-bench aria-label="Squad">
                <div class="bench__head">
                    <h3 class="section-title narrow">Squad</h3>
                    <span class="muted bench__hint" data-hint>Drag onto the court, or tap to pick</span>
                </div>
                @foreach ($groups as $title => $test)
                    <h4 class="bench__group">{{ $title }}</h4>
                    <ul class="bench__list">
                        @foreach ($cards->filter($test) as $c)
                            @php $p = $players->firstWhere('id', $c['id']); @endphp
                            <li>
                                <button type="button" class="pcard {{ $c['injured'] ? 'is-injured' : '' }}" data-card="{{ $c['id'] }}" @if ($c['injured']) aria-disabled="true" @endif>
                                    <span class="pcard__face" data-face>{!! \App\Support\Avatar::svg($p, 40, $club->color) !!}</span>
                                    <span class="pcard__body">
                                        <span class="pcard__name">{{ $c['name'] }}</span>
                                        <span class="pcard__meta">
                                            <span>{{ $positions[$c['pos']] }}</span>
                                            @if ($c['injured'])
                                                <span class="tag tag--injury">Injured {{ $c['days'] }} {{ $c['days'] === 1 ? 'day' : 'days' }}</span>
                                            @else
                                                <span class="fit {{ $c['fitness'] < 75 ? 'fit--low' : '' }}" title="Fitness {{ $c['fitness'] }} of 100"><i style="width: {{ $c['fitness'] }}%"></i></span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="pcard__rating" title="Rating in his own position">{{ $c['rating'] }}</span>
                                    <span class="pcard__where" data-where></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
                <p class="muted bench__foot">Players lose fitness when they play and win some back every day. A tired player is weaker; resting him for one match helps. Light <a href="{{ route('training') }}">training</a> speeds up recovery.</p>
            </aside>
        </div>
    </form>
@endsection
