{{--
    Two small courts on the tactics page: the defense system, and where the attack aims.
    The JS in tactics.ts moves the defenders and the highlighted zone when an option changes.
    Needs $tactics and $club.
--}}
@php
    // Defenders on half a court, goal at the top (200 x 130 = 20 m x 13 m).
    $formations = [
        '6-0' => [[24, 42], [52, 66], [82, 71], [118, 71], [148, 66], [176, 42]],
        '5-1' => [[24, 42], [56, 68], [100, 72], [144, 68], [176, 42], [100, 102]],
        '3-2-1' => [[32, 48], [100, 68], [168, 48], [60, 92], [140, 92], [100, 116]],
    ];
    $court = function () {
        return '<rect width="200" height="130" class="pitch__floor"/>'
            .'<path d="M25 0 A60 60 0 0 0 85 60 H115 A60 60 0 0 0 175 0 Z" class="pitch__area"/>'
            .'<path d="M25 0 A60 60 0 0 0 85 60 H115 A60 60 0 0 0 175 0" class="pitch__line"/>'
            .'<path d="M0 29.6 A90 90 0 0 0 85 90 H115 A90 90 0 0 0 200 29.6" class="pitch__line pitch__line--dash"/>'
            .'<rect x="85" y="-3" width="30" height="6" class="pitch__goal"/>';
    };
    $d = $formations[$tactics['defense']] ?? $formations['6-0'];
@endphp
<section class="formation" data-formation='@json($formations)'>
    <figure class="formation__fig">
        <svg viewBox="0 0 200 130" class="formation__svg" role="img" aria-label="Defense system {{ $tactics['defense'] }}" data-defense-svg>
            {!! $court() !!}
            <circle cx="100" cy="9" r="7" class="formation__gk"/>
            @foreach ($d as $i => [$x, $y])
                <g class="formation__man" data-man="{{ $i }}" style="transform: translate({{ $x }}px, {{ $y }}px)">
                    <circle r="7.5" fill="{{ $club->color }}"/>
                </g>
            @endforeach
        </svg>
        <figcaption><b>Defense</b> <span data-defense-label>{{ $tactics['defense'] }}</span></figcaption>
    </figure>
    <figure class="formation__fig">
        <svg viewBox="0 0 200 130" class="formation__svg" role="img" aria-label="Attack focus" data-focus-svg data-focus="{{ $tactics['focus'] }}">
            {!! $court() !!}
            <g class="zone zone--wings"><path d="M0 0 H22 L36 48 L0 62 Z"/><path d="M200 0 H178 L164 48 L200 62 Z"/></g>
            <g class="zone zone--backs"><path d="M0 62 L36 48 Q100 118 164 48 L200 62 V130 H0 Z"/></g>
            <g class="zone zone--pivot"><path d="M58 62 Q100 86 142 62 L150 82 Q100 108 50 82 Z"/></g>
            @foreach ([[18, 36], [46, 104], [100, 116], [154, 104], [182, 36], [100, 76]] as [$x, $y])
                <circle cx="{{ $x }}" cy="{{ $y }}" r="7.5" fill="{{ $club->color }}" class="formation__att"/>
            @endforeach
        </svg>
        <figcaption><b>Attack aims at</b> <span data-focus-label>{{ \App\Models\Club::TACTIC_OPTIONS['focus']['options'][$tactics['focus']] }}</span></figcaption>
    </figure>
</section>
