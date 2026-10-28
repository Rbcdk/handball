@extends('layouts.app')

@section('title', $club->name)

@section('band')
    <div class="clubhead">
        <x-crest :club="$club" :size="56" />
        <div>
            <h1 class="clubhead__name narrow">{{ $club->name }}</h1>
            <div class="clubhead__meta">
                <span>{{ $club->league->name }}@if ($row), <b>{{ $row->position }}{{ \App\Support\Ordinal::suffix($row->position) }}</b> with {{ $row->points }} pts @endif</span>
                <span>Manager: <b>{{ $club->user?->name ?? 'Computer' }}</b></span>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="stack" style="gap: 40px">
        @if (! $club->isManaged() && auth()->check() && ! auth()->user()->club && (int) $club->league->tier === 2)
            <form class="panel" method="POST" action="{{ route('clubs.take-over', $club) }}" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap">
                @csrf
                <p>This club has no manager yet.</p>
                <button class="btn btn--primary" type="submit">Take over {{ $club->name }}</button>
            </form>
        @endif

        <section>
            <h2 class="section-title narrow">Recent results</h2>
            @if ($results->isNotEmpty())
                @include('partials.results', ['fixtures' => $results, 'showDate' => true])
            @else
                <p class="muted">No matches played yet.</p>
            @endif
        </section>

        <section>
            <h2 class="section-title narrow">Squad</h2>
            @include('partials.squad', ['shirt' => $club->color])
        </section>
    </div>
@endsection
