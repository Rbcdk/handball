{{--
    League table in match-sheet style.
    $rows: Standings rows. $highlight: club id to mark. $zones: draw promotion/relegation lines.
--}}
@php
    $zones = $zones ?? false;
    $tier = $tier ?? null;
    $count = $rows->count();
    $spots = config('game.promotion_spots');
@endphp
<div class="sheet-wrap">
    <table class="sheet">
        <thead>
            <tr>
                <th class="pos" scope="col"><span class="visually-hidden">Position</span></th>
                <th class="l" scope="col">Club</th>
                <th scope="col" title="Played">P</th>
                <th scope="col" title="Won">W</th>
                <th scope="col" title="Drawn">D</th>
                <th scope="col" title="Lost">L</th>
                <th class="wide" scope="col" title="Goals for and against">Goals</th>
                <th scope="col" title="Goal difference">+/-</th>
                <th scope="col">Pts</th>
                @if ($showForm ?? true)<th class="l" scope="col">Form</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                @php
                    $classes = [];
                    if (($highlight ?? null) === $r->club->id) $classes[] = 'me';
                    if ($zones && $tier === 2 && $r->position === $spots && $count > $spots) $classes[] = 'zone-up';
                    if ($zones && $tier === 1 && $r->position === $count - 2) $classes[] = 'zone-down';
                @endphp
                <tr class="{{ implode(' ', $classes) }}">
                    <td class="pos">{{ $r->position }}</td>
                    <td class="club">
                        <a class="clublink" href="{{ route('clubs.show', $r->club) }}"><x-crest :club="$r->club" :size="22" /><span>{{ $r->club->name }}</span></a>
                    </td>
                    <td>{{ $r->played }}</td>
                    <td>{{ $r->won }}</td>
                    <td>{{ $r->drawn }}</td>
                    <td>{{ $r->lost }}</td>
                    <td class="wide">{{ $r->for }}–{{ $r->against }}</td>
                    <td>{{ $r->diff > 0 ? '+' : '' }}{{ $r->diff }}</td>
                    <td class="pts">{{ $r->points }}</td>
                    @if ($showForm ?? true)<td class="l"><x-form :form="$r->form" /></td>@endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@if ($zones)
    <p class="zone-key">
        @if ($tier === 2)<span><i style="color: var(--win)"></i>Promoted to the Crown League</span>@endif
        @if ($tier === 1)<span><i style="color: var(--loss)"></i>Relegated to the Ember or Frost League</span>@endif
    </p>
@endif
