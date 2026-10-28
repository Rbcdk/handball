@extends('layouts.app')

@section('title', 'Transfers')

@php
    $money = fn ($v) => \App\Game\Economy::format((int) $v);
    $endsIn = function ($at) {
        if ($at->isPast()) return 'ending now';
        $mins = (int) now()->diffInMinutes($at, true);
        return $mins < 60 ? "{$mins} min" : (intdiv($mins, 60) < 48 ? intdiv($mins, 60).' h '.($mins % 60).' min' : intdiv($mins, 1440).' days');
    };
@endphp

@section('content')
    <div class="section-head">
        <h2 class="section-title narrow">Transfers</h2>
        <span class="muted">Your money: <b class="num">{{ $money($club->balance) }}</b> · squad {{ $club->players()->count() }} of {{ \App\Game\Economy::SQUAD_MAX }}</span>
    </div>
    <p class="muted" style="max-width: 75ch; margin-bottom: 18px">
        Players are sold in two ways. <b>Auction</b>: bid within 24 hours, the highest bid wins. <b>Fixed price</b>: buy at once at the asking price.
        <b>Free agents</b> have no club and only cost their wage. To sell one of your own players, open his page from your squad.
    </p>

    <nav class="switcher" aria-label="Transfer views">
        <a href="{{ route('transfers', ['tab' => 'market', 'position' => $position]) }}" @if ($tab === 'market') aria-current="page" @endif>For sale ({{ $listings->count() }})</a>
        <a href="{{ route('transfers', ['tab' => 'free', 'position' => $position]) }}" @if ($tab === 'free') aria-current="page" @endif>Free agents ({{ $free->count() }})</a>
        <a href="{{ route('transfers', ['tab' => 'mine']) }}" @if ($tab === 'mine') aria-current="page" @endif>My sales and bids</a>
    </nav>

    @if ($tab !== 'mine')
        <form class="filters" method="GET" action="{{ route('transfers') }}">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <label for="f-position">Position</label>
            <select id="f-position" name="position" onchange="this.form.submit()">
                <option value="">All positions</option>
                @foreach (\App\Models\Player::POSITIONS as $code => $name)
                    <option value="{{ $code }}" @selected($position === $code)>{{ $name }}</option>
                @endforeach
            </select>
            @if ($tab === 'market')
                <label for="f-max">Max price</label>
                <select id="f-max" name="max" onchange="this.form.submit()">
                    <option value="">Any price</option>
                    @foreach ([100000, 250000, 500000, 1000000, 2000000] as $m)
                        <option value="{{ $m }}" @selected($maxPrice === $m)>Up to {{ $money($m) }}</option>
                    @endforeach
                </select>
            @endif
            <noscript><button class="btn" type="submit">Filter</button></noscript>
        </form>
    @endif

    @if ($tab === 'market')
        @if ($listings->isEmpty())
            <p class="preview-note">No players for sale with these filters right now. Computer clubs put players up for sale through the day.</p>
        @else
            <div class="sheet-wrap">
                <table class="sheet market">
                    <thead>
                        <tr>
                            <th class="l" scope="col">Player</th>
                            <th class="l" scope="col">Position</th>
                            <th scope="col">Age</th>
                            <th scope="col">Rating</th>
                            <th class="l" scope="col">Club</th>
                            <th scope="col">Value</th>
                            <th class="l" scope="col">Sale</th>
                            <th scope="col">Price now</th>
                            <th scope="col">Ends in</th>
                            <th class="l" scope="col"><span class="visually-hidden">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($listings as $l)
                            @php
                                $p = $l->player;
                                $mine = (int) $l->seller_club_id === (int) $club->id;
                                $leading = $l->highestBid && (int) $l->highestBid->club_id === (int) $club->id;
                                $now = $l->isAuction() ? \App\Game\Economy::minimumBid($l->price, $l->highestBid?->amount) : $l->price;
                            @endphp
                            <tr class="{{ $mine ? 'me' : '' }}">
                                <td class="l"><a class="pname" href="{{ route('players.show', $p) }}">{!! \App\Support\Avatar::svg($p, 28) !!}<span>{{ $p->name }}</span></a></td>
                                <td class="l">{{ \App\Models\Player::POSITIONS[$p->position] }}</td>
                                <td>{{ $p->age }}</td>
                                <td class="pts">{{ $p->rating() }}</td>
                                <td class="l"><a class="clublink" href="{{ route('clubs.show', $l->seller) }}"><x-crest :club="$l->seller" :size="18" /><span>{{ $l->seller->short }}</span></a></td>
                                <td>{{ $money($p->marketValue()) }}</td>
                                <td class="l">
                                    @if ($l->isAuction())
                                        <span class="role">Auction</span>
                                        <span class="muted">{{ $l->bids()->count() }} {{ $l->bids()->count() === 1 ? 'bid' : 'bids' }}</span>
                                    @else
                                        <span class="role">Fixed price</span>
                                    @endif
                                </td>
                                <td class="pts">{{ $money($now) }}@if ($l->isAuction() && $l->highestBid)<span class="muted" style="display:block;font-weight:400;font-size:var(--s-13)">next bid</span>@endif</td>
                                <td>{{ $endsIn($l->ends_at) }}</td>
                                <td class="l">
                                    @if ($mine)
                                        <span class="muted">Your player</span>
                                    @elseif ($leading)
                                        <span class="role role--start">You lead</span>
                                    @elseif ($l->isAuction())
                                        <a class="btn btn--small" href="{{ route('players.show', $p) }}#buy">Bid</a>
                                    @else
                                        <form method="POST" action="{{ route('listings.buy', $l) }}">
                                            @csrf
                                            <button class="btn btn--small btn--primary" type="submit">Buy for {{ $money($l->price) }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @elseif ($tab === 'free')
        <p class="muted" style="margin-bottom: 12px">No transfer fee: you only pay their wage. They sign at once.</p>
        <div class="sheet-wrap">
            <table class="sheet market">
                <thead>
                    <tr>
                        <th class="l" scope="col">Player</th>
                        <th class="l" scope="col">Position</th>
                        <th scope="col">Age</th>
                        <th scope="col">Rating</th>
                        <th scope="col">Wage per match day</th>
                        <th class="l" scope="col"><span class="visually-hidden">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($free as $p)
                        <tr>
                            <td class="l"><a class="pname" href="{{ route('players.show', $p) }}">{!! \App\Support\Avatar::svg($p, 28) !!}<span>{{ $p->name }}</span></a></td>
                            <td class="l">{{ \App\Models\Player::POSITIONS[$p->position] }}</td>
                            <td>{{ $p->age }}</td>
                            <td class="pts">{{ $p->rating() }}</td>
                            <td>{{ $money($p->fairWage()) }}</td>
                            <td class="l">
                                <form class="inline-form" method="POST" action="{{ route('players.sign', $p) }}">
                                    @csrf
                                    <label class="visually-hidden" for="sign-{{ $p->id }}">Contract length</label>
                                    <select id="sign-{{ $p->id }}" name="seasons">
                                        <option value="1">1 season</option>
                                        <option value="2" selected>2 seasons</option>
                                        <option value="3">3 seasons</option>
                                    </select>
                                    <button class="btn btn--small btn--primary" type="submit">Sign</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="l" colspan="6">No free agents in this position right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="grid-2">
            <section>
                <h3 class="section-title narrow" style="font-size: var(--s-19)">Players you are selling</h3>
                @if ($myListings->isEmpty())
                    <p class="muted">You haven't put anyone up for sale. Open a player from your squad and choose Sell.</p>
                @else
                    <ul class="results plain">
                        @foreach ($myListings as $l)
                            <li class="row3">
                                <a href="{{ route('players.show', $l->player) }}">{{ $l->player->name }}</a>
                                <span class="muted">{{ $l->isAuction() ? 'Auction' : 'Fixed price' }}, {{ $money($l->price) }}</span>
                                <span>
                                    @if ($l->status === 'open')
                                        @if ($l->highestBid) Highest bid {{ $money($l->highestBid->amount) }} @else Open, ends in {{ $endsIn($l->ends_at) }} @endif
                                    @elseif ($l->status === 'sold')
                                        <b>Sold to {{ $l->buyer?->short }} for {{ $money($l->final_price) }}</b>
                                    @else
                                        <span class="muted">{{ $l->status === 'expired' ? 'Not sold' : 'Taken off the list' }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section>
                <h3 class="section-title narrow" style="font-size: var(--s-19)">Your bids</h3>
                @if ($myBids->isEmpty())
                    <p class="muted">You haven't bid on anyone yet.</p>
                @else
                    <ul class="results plain">
                        @foreach ($myBids as $b)
                            @php $l = $b->listing; $lead = $l->highestBid && (int) $l->highestBid->club_id === (int) $club->id; @endphp
                            <li class="row3">
                                <a href="{{ route('players.show', $l->player) }}">{{ $l->player->name }}</a>
                                <span class="muted">Your bid {{ $money($b->amount) }}</span>
                                <span>
                                    @if ($l->status === 'open')
                                        @if ($lead)<span class="role role--start">You lead</span>@else<b style="color: var(--loss)">Outbid: {{ $money($l->highestBid->amount) }}</b>@endif
                                    @elseif ((int) $l->buyer_club_id === (int) $club->id)
                                        <b>You won him</b>
                                    @else
                                        <span class="muted">Auction ended</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
        @if ($recent->isNotEmpty())
            <section style="margin-top: 32px">
                <h3 class="section-title narrow" style="font-size: var(--s-19)">Latest transfers in the game</h3>
                <ul class="results plain">
                    @foreach ($recent as $t)
                        <li class="row3">
                            <a href="{{ route('players.show', $t->player) }}">{{ $t->player->name }}</a>
                            <span class="muted">{{ $t->from?->short }} to {{ $t->to?->short }}</span>
                            <span class="num">{{ $money($t->fee) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
@endsection
