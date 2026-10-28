@extends('layouts.app')

@section('title', 'Choose your club')
@section('heading', 'Choose your club')
@section('subheading', 'These clubs in the Ember and Frost Leagues have a computer manager. Take one over and it plays its next match under you.')

@section('content')
    <div class="grid-2">
        @foreach ($groups as $group)
            <section>
                <h2 class="section-title narrow">{{ $group->league->name }}</h2>
                @forelse ($group->clubs as $row)
                    <div class="clubrow">
                        <x-crest :club="$row->club" :size="36" />
                        <div>
                            <a class="clubrow__name narrow" href="{{ route('clubs.show', $row->club) }}">{{ $row->club->name }}</a>
                            <div class="clubrow__facts">
                                <span>Squad rating <b class="num">{{ $row->rating }}</b></span>
                                @if ($row->position)<span>{{ $row->position }}{{ \App\Support\Ordinal::suffix($row->position) }} with {{ $row->points }} pts</span>@endif
                            </div>
                        </div>
                        <form method="POST" action="{{ route('clubs.take-over', $row->club) }}">
                            @csrf
                            <button class="btn" type="submit">Take over</button>
                        </form>
                    </div>
                @empty
                    <p class="muted">Every club in this league has a manager.</p>
                @endforelse
            </section>
        @endforeach
    </div>
@endsection
