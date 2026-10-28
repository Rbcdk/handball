@extends('layouts.app')

@section('title', 'Finances')

@php
    $money = fn ($v) => \App\Game\Economy::format((int) $v);
    $types = \App\Models\Transaction::TYPES;
    $income = collect($summary)->filter(fn ($v) => $v > 0);
    $costs = collect($summary)->filter(fn ($v) => $v < 0);
    $net = array_sum($summary);
    $sponsor = \App\Game\Economy::SPONSOR[$tier];
@endphp

@section('content')
    <div class="moneyhead">
        <div>
            <span class="muted">Money in the bank</span>
            <b class="narrow moneyhead__balance {{ $club->balance < 0 ? 'is-negative' : '' }}">{{ $money($club->balance) }}</b>
        </div>
        <div>
            <span class="muted">Wages each match day</span>
            <b class="narrow">{{ $money($wageBill) }}</b>
        </div>
        <div>
            <span class="muted">This season so far</span>
            <b class="narrow {{ $net < 0 ? 'is-negative' : 'is-positive' }}">{{ $net >= 0 ? '+' : '' }}{{ $money($net) }}</b>
        </div>
    </div>

    @if ($club->balance < 0)
        <p class="flash flash--error">Your club is in debt. You can't buy players until the balance is positive again. Sell a player or wait for the next home match.</p>
    @endif

    <div class="grid-2" style="margin-top: 28px">
        <section>
            <h2 class="section-title narrow">Latest payments</h2>
            <div class="sheet-wrap">
                <table class="sheet">
                    <thead><tr><th class="l" scope="col">When</th><th class="l" scope="col">What</th><th scope="col">Amount</th><th scope="col">Balance</th></tr></thead>
                    <tbody>
                        @forelse ($transactions as $t)
                            <tr>
                                <td class="l muted">{{ $t->created_at->copy()->setTimezone(config('game.timezone'))->format('j M') }}</td>
                                <td class="l club">{{ $t->description }}</td>
                                <td class="num" style="color: {{ $t->amount < 0 ? 'var(--loss)' : 'var(--win)' }}">{{ $t->amount > 0 ? '+' : '' }}{{ $money($t->amount) }}</td>
                                <td class="num">{{ $money($t->balance_after) }}</td>
                            </tr>
                        @empty
                            <tr><td class="l muted" colspan="4">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h2 class="section-title narrow" style="margin-top: 28px">Wages by player</h2>
            <table class="sheet">
                <thead><tr><th class="l" scope="col">Player</th><th scope="col">Per match day</th><th scope="col">Contract until</th></tr></thead>
                <tbody>
                    @foreach ($players as $p)
                        <tr>
                            <td class="l"><a href="{{ route('players.show', $p) }}">{{ $p->name }}</a></td>
                            <td class="num">{{ $money($p->wage) }}</td>
                            <td class="{{ $p->contract_until <= ($season?->number ?? 1) ? 'is-negative' : '' }}">Season {{ $p->contract_until }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <section>
            <h2 class="section-title narrow">Season {{ $season?->number }}</h2>
            <table class="sheet">
                <thead><tr><th class="l" scope="col">Income</th><th scope="col">Amount</th></tr></thead>
                <tbody>
                    @forelse ($income as $type => $v)
                        <tr><td class="l">{{ $types[$type] ?? $type }}</td><td class="num" style="color: var(--win)">+{{ $money($v) }}</td></tr>
                    @empty
                        <tr><td class="l muted" colspan="2">No income yet this season.</td></tr>
                    @endforelse
                </tbody>
                <thead><tr><th class="l" scope="col">Costs</th><th scope="col">Amount</th></tr></thead>
                <tbody>
                    @forelse ($costs as $type => $v)
                        <tr><td class="l">{{ $types[$type] ?? $type }}</td><td class="num" style="color: var(--loss)">{{ $money($v) }}</td></tr>
                    @empty
                        <tr><td class="l muted" colspan="2">No costs yet this season.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <h2 class="section-title narrow" style="margin-top: 28px">Where the money comes from</h2>
            <dl class="kv">
                <dt>Ticket sales</dt><dd>Every home match. {{ number_format($club->arena_capacity, 0, ',', '.') }} seats and {{ number_format($club->fans, 0, ',', '.') }} fans; the higher you are in the table, the more of them come. See the <a href="{{ route('arena') }}">Arena</a>.</dd>
                <dt>Sponsor</dt><dd>{{ $money($sponsor) }} a season, paid in parts each match day.</dd>
                <dt>Prize money</dt><dd>Paid at the end of the season. The higher you finish, the more you get.</dd>
                <dt>Selling players</dt><dd>On the transfer market.</dd>
            </dl>
            <p class="muted" style="margin-top: 10px">Wages are paid every match day for every player in your squad. A player's wage is set when he signs his contract.</p>
        </section>

    </div>
@endsection
