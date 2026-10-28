{{-- List of fixtures: played ones link to the replay, upcoming ones show kick-off. --}}
<ul class="results">
    @foreach ($fixtures as $f)
        @php $homeWon = $f->isPlayed() && $f->home_score > $f->away_score; $awayWon = $f->isPlayed() && $f->away_score > $f->home_score; @endphp
        <li>
            <a class="clublink h {{ $homeWon ? 'won' : '' }}" href="{{ route('clubs.show', $f->home) }}" style="justify-self: end"><span>{{ $f->home->name }}</span><x-crest :club="$f->home" :size="20" /></a>
            @if ($f->isPlayed())
                <a class="score" href="{{ route('matches.show', $f) }}" aria-label="{{ $f->home->name }} {{ $f->home_score }}, {{ $f->away->name }} {{ $f->away_score }}. Watch the replay">{{ $f->home_score }}–{{ $f->away_score }}</a>
            @else
                <a class="score muted" href="{{ route('matches.show', $f) }}">{{ $f->localKickoff()->format('H:i') }}</a>
            @endif
            <a class="clublink {{ $awayWon ? 'won' : '' }}" href="{{ route('clubs.show', $f->away) }}"><x-crest :club="$f->away" :size="20" /><span>{{ $f->away->name }}</span></a>
            @if ($showDate ?? false)
                <span class="when">Round {{ $f->round }}, {{ $f->isPlayed() ? $f->localKickoff()->format('l j M') : $f->kickoffLabel() }}</span>
            @endif
        </li>
    @endforeach
</ul>
