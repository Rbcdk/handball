{{-- A player's name linking to his page, with markers. Pass 'face' => true for his portrait (and 'shirt' => colour to skip a club lookup). --}}
@php $seasonNo = \App\Models\Season::current()?->number ?? 1; @endphp
@if (! empty($face))
<a class="pname" href="{{ route('players.show', $p) }}">{!! \App\Support\Avatar::svg($p, 28, $shirt ?? null) !!}<span>{{ $p->name }}</span></a>
@else
<a href="{{ route('players.show', $p) }}">{{ $p->name }}</a>
@endif
@if ($p->relationLoaded('listing') ? $p->listing : $p->listing()->exists())
    <span class="tag tag--sale">For sale</span>
@endif
@if ($p->isInjured())
    <span class="tag tag--injury" title="{{ $p->injury }}">Injured {{ $p->injured_days }} {{ $p->injured_days === 1 ? 'day' : 'days' }}</span>
@elseif ($p->club_id && $p->fitness < 75)
    <span class="tag tag--tired" title="Fitness {{ $p->fitness }} of 100">Tired</span>
@endif
@if ($p->club_id && $p->contract_until <= $seasonNo)
    <span class="tag tag--warn" title="His contract ends after this season">Contract ends</span>
@endif
