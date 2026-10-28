@php
    // Dark is the default; the header switch stores 'light' or 'dark' in a cookie.
    $theme = request()->cookie('theme') === 'light' ? 'light' : 'dark';
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="color-scheme" content="{{ $theme }}">
    <meta name="theme-color" content="{{ $theme === 'dark' ? '#143c66' : '#1f5a96' }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim($__env->yieldContent('title')) ? trim($__env->yieldContent('title')).' – ' : '' }}Handball Manager</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='14' fill='%23e8762c'/%3E%3Cpath d='M4 13c6 3 18 3 24 0M7 24c5-4 13-4 18 0M16 2c-4 6-4 22 0 28' stroke='%23fff' stroke-width='2' fill='none'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <header class="band">
        {{-- The goal end of a handball court: 6 m goal area, dashed 9 m line, 7 m mark. --}}
        <svg class="band__lines" viewBox="0 0 300 400" aria-hidden="true" focusable="false">
            <path class="dash" d="M300 -10 A180 180 0 0 0 120 170 L120 230 A180 180 0 0 0 300 410" />
            <path class="area" d="M300 50 A120 120 0 0 0 180 170 L180 230 A120 120 0 0 0 300 350 Z" />
            <line x1="160" y1="190" x2="160" y2="210" />
            <line x1="220" y1="197" x2="220" y2="203" />
        </svg>

        <div class="wrap">
            <div class="topbar">
                <a class="brand narrow" href="{{ route('home') }}">
                    <svg class="brand__ball" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="14" fill="#e8762c"/><path d="M4 13c6 3 18 3 24 0M7 24c5-4 13-4 18 0M16 2c-4 6-4 22 0 28" stroke="#fff" stroke-width="2" fill="none"/></svg>
                    Handball Manager
                </a>
                <div class="topbar__user">
                    <button class="themeswitch" type="button" data-theme-switch aria-pressed="{{ $theme === 'dark' ? 'true' : 'false' }}">
                        <svg class="themeswitch__moon" viewBox="0 0 20 20" aria-hidden="true"><path d="M15.5 12.6A6.5 6.5 0 0 1 7.4 4.5a6.5 6.5 0 1 0 8.1 8.1Z"/></svg>
                        <svg class="themeswitch__sun" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="3.6"/><path d="M10 1.5v2.2M10 16.3v2.2M1.5 10h2.2M16.3 10h2.2M4 4l1.6 1.6M14.4 14.4 16 16M4 16l1.6-1.6M14.4 5.6 16 4"/></svg>
                        <span data-theme-label>{{ $theme === 'dark' ? 'Light mode' : 'Dark mode' }}</span>
                    </button>
                    @auth
                        <span>{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="linkbutton" type="submit">Log out</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}">Log in</a>
                    @endauth
                </div>
            </div>

            @hasSection('band')
                @yield('band')
            @elseif ($myClub)
                <div class="clubhead">
                    <x-crest :club="$myClub" :size="56" />
                    <div>
                        <h1 class="clubhead__name narrow">{{ $myClub->name }}</h1>
                        <div class="clubhead__meta">
                            <span>{{ $myClub->league->name }}@if ($myPosition), <b>{{ $myPosition }}{{ \App\Support\Ordinal::suffix($myPosition) }}</b>@endif</span>
                            <span>Money: <b class="{{ $myClub->balance < 0 ? 'is-negative' : '' }}">{{ \App\Game\Economy::format((int) $myClub->balance) }}</b></span>
                            @if ($myNext)
                                <span>Next: {{ $myNext->opponentOf($myClub)->name }}, <b>{{ $myNext->kickoffLabel() }}</b></span>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="clubhead">
                    <div>
                        <h1 class="clubhead__name narrow">@yield('heading', 'Handball Manager')</h1>
                        @hasSection('subheading')<div class="clubhead__meta"><span>@yield('subheading')</span></div>@endif
                    </div>
                </div>
            @endif

            <nav class="tabs" aria-label="Main">
                @if ($myClub)
                    <a href="{{ route('office') }}" @if (request()->routeIs('office')) aria-current="page" @endif>Overview</a>
                    <a href="{{ route('squad') }}" @if (request()->routeIs('squad')) aria-current="page" @endif>Squad</a>
                    <a href="{{ route('lineup') }}" @if (request()->routeIs('lineup*')) aria-current="page" @endif>Line-up</a>
                    <a href="{{ route('tactics.edit') }}" @if (request()->routeIs('tactics.*')) aria-current="page" @endif>Tactics</a>
                    <a href="{{ route('training') }}" @if (request()->routeIs('training*')) aria-current="page" @endif>Training</a>
                    <a href="{{ route('fixtures') }}" @if (request()->routeIs('fixtures')) aria-current="page" @endif>Fixtures</a>
                    <a href="{{ route('transfers') }}" @if (request()->routeIs('transfers')) aria-current="page" @endif>Transfers</a>
                    <a href="{{ route('finances') }}" @if (request()->routeIs('finances')) aria-current="page" @endif>Finances</a>
                    <a href="{{ route('arena') }}" @if (request()->routeIs('arena')) aria-current="page" @endif>Arena</a>
                @endif
                <a href="{{ route('leagues.show') }}" @if (request()->routeIs('leagues.*')) aria-current="page" @endif>Leagues</a>
                <a href="{{ route('help') }}" @if (request()->routeIs('help')) aria-current="page" @endif>How to play</a>
                @guest
                    <a href="{{ route('register') }}" @if (request()->routeIs('register')) aria-current="page" @endif>Take over a club</a>
                @endguest
            </nav>
        </div>
    </header>

    <main class="wrap">
        @if (session('status'))
            <p class="flash" role="status">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p class="flash flash--error" role="alert">{{ session('error') }}</p>
        @endif

        @yield('content')
    </main>

    <footer class="site">
        <div class="wrap">All clubs and players are fictional. Matches are played every evening at {{ config('game.match_time') }} ({{ config('game.timezone') }}).</div>
    </footer>
</body>
</html>
