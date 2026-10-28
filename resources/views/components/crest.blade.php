@props(['club', 'size' => 40])
{{-- Club crest: a shield in the club colour with the 6 m arc and the short name. --}}
<svg class="crest" width="{{ $size }}" height="{{ round($size * 1.15) }}" viewBox="0 0 40 46" role="img" aria-label="{{ $club->name }}">
    <path d="M20 1.5 L37.5 6 V22 C37.5 33.5 30 40.5 20 44.5 C10 40.5 2.5 33.5 2.5 22 V6 Z" fill="{{ $club->color }}" stroke="#ffffff" stroke-opacity="0.85" stroke-width="1.5"/>
    <path d="M9 38 A13 13 0 0 1 31 38" fill="none" stroke="#ffffff" stroke-opacity="0.45" stroke-width="1.5"/>
    <text x="20" y="25" text-anchor="middle" font-size="12.5" fill="#ffffff">{{ $club->short }}</text>
</svg>
