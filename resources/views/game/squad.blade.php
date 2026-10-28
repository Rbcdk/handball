@extends('layouts.app')

@section('title', 'Squad')

@section('content')
    <div class="section-head">
        <h2 class="section-title narrow">Squad</h2>
        <span class="muted">{{ $players->count() }} players</span>
    </div>
    @include('partials.squad', ['shirt' => $club->color])
@endsection
