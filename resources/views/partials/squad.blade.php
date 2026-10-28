{{--
    Squad tables: who starts, positions in words, skills with a colour scale, this season's numbers.
    Needs $players, $stats and $starters (player id => position he starts in).
--}}
@php
    $starters = $starters ?? [];
    $level = fn ($v) => $v >= 75 ? 'hi' : ($v < 50 ? 'lo' : 'mid');
    $keepers = $players->filter(fn ($p) => $p->isGoalkeeper());
    $field = $players->reject(fn ($p) => $p->isGoalkeeper());
    $fieldSkills = ['shooting' => 'Shooting', 'passing' => 'Passing', 'technique' => 'Technique', 'speed' => 'Speed', 'stamina' => 'Stamina', 'jumping' => 'Jumping', 'defense' => 'Defense', 'composure' => 'Composure'];
    $gkSkills = ['reflexes' => 'Reflexes', 'positioning' => 'Positioning', 'seven_meter' => '7 m saves', 'stamina' => 'Stamina', 'composure' => 'Composure'];
@endphp

<div class="squadkey">
    <p>The best player in each position starts the match; the rest wait on the bench and come on when a starter gets tired. Skills go from 1 to 100. <b>Rating</b> sums up how good a player is in his own position. Click a name to sell him or extend his contract.</p>
    <p class="squadkey__scale">
        <span><i class="skill skill--hi"><b style="width: 85%"></b></i>75+ excellent</span>
        <span><i class="skill skill--mid"><b style="width: 62%"></b></i>50 to 74 solid</span>
        <span><i class="skill skill--lo"><b style="width: 40%"></b></i>under 50 weak</span>
        <a href="{{ route('help') }}#attributes">What each skill does</a>
    </p>
</div>

<h3 class="section-title narrow squad__title">Court players</h3>
<div class="sheet-wrap">
    <table class="sheet squad">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th class="l" scope="col">Player</th>
                <th class="l" scope="col">Plays</th>
                <th scope="col">Age</th>
                <th scope="col">Rating</th>
                @foreach ($fieldSkills as $label)<th class="l" scope="col">{{ $label }}</th>@endforeach
                <th scope="col" title="Matches played this season">Matches</th>
                <th scope="col" title="Goals and shots this season">Goals</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($field as $p)
                @php $s = $stats[$p->id] ?? null; $slot = $starters[$p->id] ?? null; @endphp
                <tr class="{{ $slot ? 'is-starter' : '' }}">
                    <td class="muted">{{ $p->number }}</td>
                    <td class="l">@include('partials.player-name', ['p' => $p, 'face' => true, 'shirt' => $shirt ?? null])</td>
                    <td class="l">
                        @if ($slot)
                            <span class="role role--start">Starts</span> {{ \App\Models\Player::POSITIONS[$slot === 'X7' ? 'CB' : $slot] }}
                        @else
                            <span class="role">Bench</span> {{ \App\Models\Player::POSITIONS[$p->position] }}
                        @endif
                    </td>
                    <td>{{ $p->age }}</td>
                    <td class="pts">{{ $p->rating() }}</td>
                    @foreach (array_keys($fieldSkills) as $a)
                        @php $v = $p->{$a}; @endphp
                        <td class="l"><span class="skillcell"><span class="skillcell__n">{{ $v }}</span><i class="skill skill--{{ $level($v) }}" aria-hidden="true"><b style="width: {{ $v }}%"></b></i></span></td>
                    @endforeach
                    <td>{{ $s?->matches ?? 0 }}</td>
                    <td>{{ $s ? $s->goals.' of '.$s->shots : '–' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<h3 class="section-title narrow squad__title" style="margin-top: 28px">Goalkeepers</h3>
<div class="sheet-wrap">
    <table class="sheet squad">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th class="l" scope="col">Player</th>
                <th class="l" scope="col">Plays</th>
                <th scope="col">Age</th>
                <th scope="col">Rating</th>
                @foreach ($gkSkills as $label)<th class="l" scope="col">{{ $label }}</th>@endforeach
                <th scope="col" title="Matches played this season">Matches</th>
                <th scope="col" title="Saves out of shots on target this season">Saves</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($keepers as $p)
                @php $s = $stats[$p->id] ?? null; $starts = isset($starters[$p->id]); @endphp
                <tr class="{{ $starts ? 'is-starter' : '' }}">
                    <td class="muted">{{ $p->number }}</td>
                    <td class="l">@include('partials.player-name', ['p' => $p, 'face' => true, 'shirt' => $shirt ?? null])</td>
                    <td class="l">@if ($starts)<span class="role role--start">Starts</span>@else<span class="role">Back-up</span>@endif</td>
                    <td>{{ $p->age }}</td>
                    <td class="pts">{{ $p->rating() }}</td>
                    @foreach (array_keys($gkSkills) as $a)
                        @php $v = $p->{$a}; @endphp
                        <td class="l"><span class="skillcell"><span class="skillcell__n">{{ $v }}</span><i class="skill skill--{{ $level($v) }}" aria-hidden="true"><b style="width: {{ $v }}%"></b></i></span></td>
                    @endforeach
                    <td>{{ $s?->matches ?? 0 }}</td>
                    <td>{{ $s && $s->shots_faced ? $s->saves.' of '.$s->shots_faced.' ('.round($s->saves / $s->shots_faced * 100).'%)' : '–' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
