@extends('layouts.app')

@section('title', 'No leagues yet')
@section('heading', 'No leagues yet')
@section('subheading', 'The game world hasn\'t been created on this server.')

@section('content')
    <section class="panel" style="max-width: 640px">
        <h2 class="section-title narrow" style="font-size: var(--s-19)">Create the world</h2>
        <p>Run this once in the project folder. It creates the three leagues, 42 clubs with their squads, and the first season's fixtures:</p>
        <pre class="code"><code>php artisan game:new-world</code></pre>
        <p class="muted">Then reload this page. To play a few match days straight away, run <code>php artisan game:fast-forward 3</code>.</p>
    </section>
@endsection
