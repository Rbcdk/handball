@extends('layouts.app')

@section('title', 'Take over a club')
@section('heading', 'Take over a club')
@section('subheading', 'Create your manager account. Next you choose your club.')

@section('content')
    <div class="grid-2">
        <form class="panel" method="POST" action="{{ route('register') }}" style="max-width: 460px">
            @csrf
            <div class="field">
                <label for="name">Manager name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="40" autocomplete="nickname" autofocus>
                @error('name')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                @error('email')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                @error('password')<span class="error">{{ $message }}</span>@enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Repeat password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <button class="btn btn--primary" type="submit">Create account</button>
            <p class="muted" style="margin-top: 14px">Already a manager? <a href="{{ route('login') }}">Log in</a></p>
        </form>
        <div class="stack">
            <h2 class="section-title narrow">What you get</h2>
            <dl class="kv">
                <dt>Club</dt><dd>A club in the Ember or Frost League, with 16 players</dd>
                <dt>Matches</dt><dd>One league match every evening at {{ config('game.match_time') }}</dd>
                <dt>Season</dt><dd>26 rounds, about four weeks</dd>
                <dt>Goal</dt><dd>Win your league and go up to the Crown League</dd>
            </dl>
        </div>
    </div>
@endsection
