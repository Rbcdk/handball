@extends('layouts.app')

@section('title', $league->name)

@section('content')
    <nav class="switcher" aria-label="Leagues">
        @foreach ($leagues as $l)
            <a href="{{ route('leagues.show', $l) }}" @if ($l->id === $league->id) aria-current="page" @endif>{{ $l->name }}</a>
        @endforeach
    </nav>

    <div class="grid-2">
        <section>
            <div class="section-head">
                <h2 class="section-title narrow">{{ $league->name }}</h2>
                @if ($season)<span class="muted">Season {{ $season->number }}</span>@endif
            </div>
            @include('partials.table', ['rows' => $table, 'highlight' => $myClubId, 'zones' => true, 'tier' => (int) $league->tier])
        </section>

        <section>
            <div class="section-head">
                <h2 class="section-title narrow">Round {{ $round }}</h2>
                @if ($rounds > 0)
                    <div class="pager">
                        @if ($round > 1)<a class="btn" href="{{ route('leagues.show', [$league, 'round' => $round - 1]) }}" aria-label="Previous round">Previous</a>@endif
                        @if ($round < $rounds)<a class="btn" href="{{ route('leagues.show', [$league, 'round' => $round + 1]) }}" aria-label="Next round">Next</a>@endif
                    </div>
                @endif
            </div>
            @if ($roundFixtures->isNotEmpty())
                <p class="muted" style="margin-bottom: 8px">{{ $roundFixtures->first()->isPlayed() ? $roundFixtures->first()->localKickoff()->format('l j F') : $roundFixtures->first()->kickoffLabel() }}</p>
                @include('partials.results', ['fixtures' => $roundFixtures])
            @else
                <p class="muted">No fixtures scheduled.</p>
            @endif
        </section>
    </div>
@endsection
