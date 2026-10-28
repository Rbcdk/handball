@extends('layouts.app')

@section('title', 'Fixtures')

@section('content')
    <div class="section-head">
        <h2 class="section-title narrow">Season {{ $season?->number }} fixtures</h2>
        <span class="muted">{{ $fixtures->whereNotNull('played_at')->count() }} of {{ $fixtures->count() }} played</span>
    </div>
    @if ($fixtures->isNotEmpty())
        @include('partials.results', ['fixtures' => $fixtures, 'showDate' => true])
    @else
        <p class="muted">No fixtures yet. The next season's fixtures appear here when it is scheduled.</p>
    @endif
@endsection
