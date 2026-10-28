@extends('layouts.app')

@section('title', 'Log in')
@section('heading', 'Log in')
@section('subheading', 'Back to your club.')

@section('content')
    <form class="panel" method="POST" action="{{ route('login') }}" style="max-width: 460px">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
            @error('email')<span class="error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <label class="check"><input type="checkbox" name="remember" value="1" checked> Keep me logged in</label>
        <button class="btn btn--primary" type="submit">Log in</button>
        <p class="muted" style="margin-top: 14px">New here? <a href="{{ route('register') }}">Take over a club</a></p>
    </form>
@endsection
