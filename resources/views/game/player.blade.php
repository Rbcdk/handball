@extends('layouts.app')

@section('title', $player->name)

@php
    $money = fn ($v) => \App\Game\Economy::format((int) $v);
    $level = fn ($v) => $v >= 75 ? 'hi' : ($v < 50 ? 'lo' : 'mid');
    $skills = $player->isGoalkeeper()
        ? ['reflexes' => 'Reflexes', 'positioning' => 'Positioning', 'seven_meter' => '7-metre saves', 'stamina' => 'Stamina', 'composure' => 'Composure']
        : ['shooting' => 'Shooting', 'passing' => 'Passing', 'technique' => 'Technique', 'speed' => 'Speed', 'stamina' => 'Stamina', 'jumping' => 'Jumping', 'defense' => 'Defense', 'composure' => 'Composure'];
    $seasonNo = $season?->number ?? 1;
    $contractLeft = $player->contract_until - $seasonNo + 1;
    $leading = $listing && $listing->highestBid && $myClub && (int) $listing->highestBid->club_id === (int) $myClub->id;
@endphp

@section('band')
    <div class="clubhead">
        <span class="clubhead__face">{!! \App\Support\Avatar::svg($player, 76) !!}</span>
        <div>
            <h1 class="clubhead__name narrow">{{ $player->name }}</h1>
            <div class="clubhead__meta">
                <span>#{{ $player->number }} {{ \App\Models\Player::POSITIONS[$player->position] }}, {{ $player->age }} years</span>
                <span>
                    @if ($player->club)
                        <a class="clublink" href="{{ route('clubs.show', $player->club) }}"><x-crest :club="$player->club" :size="18" /><b>{{ $player->club->name }}</b></a>
                    @else
                        <b>Free agent</b>
                    @endif
                </span>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="grid-2">
        <section>
            <div class="playerfacts">
                <div><span class="muted">Rating</span><b class="narrow">{{ $player->rating() }}</b></div>
                <div><span class="muted">Market value</span><b class="narrow">{{ $money($value) }}</b></div>
                <div><span class="muted">Wage per match day</span><b class="narrow">{{ $money($player->club ? $player->wage : $player->fairWage()) }}</b></div>
                @if ($player->club)
                    <div><span class="muted">Contract</span><b class="narrow">{{ $contractLeft <= 1 ? 'Ends this season' : "Until season {$player->contract_until}" }}</b></div>
                @endif
            </div>

            <h2 class="section-title narrow" style="font-size: var(--s-19); margin-top: 24px">Skills</h2>
            <dl class="skilllist">
                @foreach ($skills as $key => $label)
                    @php $v = $player->{$key}; @endphp
                    <dt>{{ $label }}</dt>
                    <dd><span class="skillcell__n">{{ $v }}</span><i class="skill skill--{{ $level($v) }} skill--wide" aria-hidden="true"><b style="width: {{ $v }}%"></b></i></dd>
                @endforeach
            </dl>
            <p class="muted" style="margin-top: 8px; font-size: var(--s-13)">Green is excellent (75+), grey is weak (under 50). <a href="{{ route('help') }}#attributes">What each skill does</a></p>

            @if ($stats->isNotEmpty())
                <h2 class="section-title narrow" style="font-size: var(--s-19); margin-top: 24px">Seasons</h2>
                <table class="sheet">
                    <thead><tr><th class="l" scope="col">Season</th><th class="l" scope="col">Club</th><th scope="col">Matches</th><th scope="col">{{ $player->isGoalkeeper() ? 'Saves' : 'Goals' }}</th><th scope="col">{{ $player->isGoalkeeper() ? 'Save %' : 'Assists' }}</th></tr></thead>
                    <tbody>
                        @foreach ($stats as $s)
                            <tr>
                                <td class="l">{{ $s->season_id }}</td>
                                <td class="l">{{ $s->club?->short }}</td>
                                <td>{{ $s->matches }}</td>
                                <td>{{ $player->isGoalkeeper() ? $s->saves : $s->goals }}</td>
                                <td>{{ $player->isGoalkeeper() ? ($s->shots_faced ? round($s->saves / $s->shots_faced * 100).'%' : '–') : $s->assists }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <aside class="stack" id="buy">
            {{-- For sale by someone else --}}
            @if ($listing && $myClub && ! $isMine)
                <section class="panel action">
                    @if ($listing->isAuction())
                        <h2 class="section-title narrow" style="font-size: var(--s-19)">On auction</h2>
                        <p>{{ $listing->seller->name }} is auctioning him. Ends {{ $listing->ends_at->copy()->setTimezone(config('game.timezone'))->format('l H:i') }}.</p>
                        <dl class="kv" style="margin: 12px 0">
                            <dt>Starting price</dt><dd>{{ $money($listing->price) }}</dd>
                            <dt>Highest bid</dt><dd>{{ $listing->highestBid ? $money($listing->highestBid->amount).' ('.$listing->highestBid->club->short.')' : 'No bids yet' }}</dd>
                            <dt>Your money</dt><dd>{{ $money($myClub->balance) }}</dd>
                        </dl>
                        @if ($leading)
                            <p><span class="role role--start">You have the highest bid</span></p>
                        @else
                            <form method="POST" action="{{ route('listings.bid', $listing) }}" class="bidform">
                                @csrf
                                <label for="amount">Your bid (at least {{ $money($minBid) }})</label>
                                <div class="bidform__row">
                                    <input id="amount" name="amount" type="text" inputmode="numeric" value="{{ $minBid }}" required>
                                    <button class="btn btn--primary" type="submit">Place bid</button>
                                </div>
                            </form>
                            <p class="muted" style="font-size: var(--s-13); margin-top: 8px">The money leaves your account only if you win. A bid in the last five minutes adds five minutes to the auction.</p>
                        @endif
                    @else
                        <h2 class="section-title narrow" style="font-size: var(--s-19)">For sale</h2>
                        <p>{{ $listing->seller->name }} sells him for <b>{{ $money($listing->price) }}</b>. Buy now and he joins your squad at once.</p>
                        <form method="POST" action="{{ route('listings.buy', $listing) }}" style="margin-top: 12px">
                            @csrf
                            <button class="btn btn--primary" type="submit">Buy for {{ $money($listing->price) }}</button>
                        </form>
                        <p class="muted" style="font-size: var(--s-13); margin-top: 8px">Your money: {{ $money($myClub->balance) }}. His wage: {{ $money($player->wage) }} per match day.</p>
                    @endif
                </section>
            @endif

            {{-- Free agent --}}
            @if ($player->isFreeAgent() && $myClub)
                <section class="panel action">
                    <h2 class="section-title narrow" style="font-size: var(--s-19)">Free agent</h2>
                    <p>He has no club. Sign him for no fee; you pay {{ $money($player->fairWage()) }} per match day.</p>
                    <form class="inline-form" method="POST" action="{{ route('players.sign', $player) }}" style="margin-top: 12px">
                        @csrf
                        <label for="seasons">Contract</label>
                        <select id="seasons" name="seasons">
                            <option value="1">1 season</option>
                            <option value="2" selected>2 seasons</option>
                            <option value="3">3 seasons</option>
                        </select>
                        <button class="btn btn--primary" type="submit">Sign him</button>
                    </form>
                </section>
            @endif

            {{-- My own player --}}
            @if ($isMine)
                @if ($listing)
                    <section class="panel action">
                        <h2 class="section-title narrow" style="font-size: var(--s-19)">On the transfer list</h2>
                        <p>{{ $listing->isAuction() ? 'Auction' : 'Fixed price' }}, {{ $money($listing->price) }}. Ends {{ $listing->ends_at->copy()->setTimezone(config('game.timezone'))->format('l H:i') }}.</p>
                        @if ($listing->highestBid)
                            <p style="margin-top: 8px">Highest bid: <b>{{ $money($listing->highestBid->amount) }}</b> from {{ $listing->highestBid->club->name }}.</p>
                        @else
                            <form method="POST" action="{{ route('listings.cancel', $listing) }}" style="margin-top: 12px">
                                @csrf
                                <button class="btn" type="submit">Take him off the list</button>
                            </form>
                        @endif
                    </section>
                @else
                    <section class="panel action">
                        <h2 class="section-title narrow" style="font-size: var(--s-19)">Sell</h2>
                        <p>His market value is {{ $money($value) }}. You can ask between {{ $money($priceRange[0]) }} and {{ $money($priceRange[1]) }}.</p>
                        <form method="POST" action="{{ route('players.list', $player) }}" class="sellform">
                            @csrf
                            <fieldset class="seg">
                                <legend class="visually-hidden">How to sell</legend>
                                <label><input type="radio" name="kind" value="fixed" checked><span>Fixed price</span></label>
                                <label><input type="radio" name="kind" value="auction"><span>Auction, 24 hours</span></label>
                            </fieldset>
                            <label for="price">Price (fixed) or starting price (auction)</label>
                            <div class="bidform__row">
                                <input id="price" name="price" type="number" min="{{ $priceRange[0] }}" max="{{ $priceRange[1] }}" step="1000" value="{{ $value }}" required>
                                <button class="btn btn--primary" type="submit">Put up for sale</button>
                            </div>
                        </form>
                    </section>
                @endif

                <section class="panel action">
                    <h2 class="section-title narrow" style="font-size: var(--s-19)">Contract</h2>
                    <p>
                        @if ($contractLeft <= 1)
                            <b style="color: var(--loss)">His contract ends after this season.</b> If you don't extend it, he leaves for free.
                        @else
                            His contract runs until season {{ $player->contract_until }}.
                        @endif
                        A new contract costs {{ $money($player->fairWage()) }} per match day (now {{ $money($player->wage) }}).
                    </p>
                    <form class="inline-form" method="POST" action="{{ route('players.extend', $player) }}" style="margin-top: 12px">
                        @csrf
                        <label for="extend">New contract</label>
                        <select id="extend" name="seasons">
                            <option value="1">1 more season</option>
                            <option value="2" selected>2 more seasons</option>
                            <option value="3">3 more seasons</option>
                        </select>
                        <button class="btn" type="submit">Extend</button>
                    </form>
                    <form method="POST" action="{{ route('players.release', $player) }}" style="margin-top: 16px">
                        @csrf
                        <button class="linkbutton linkbutton--quiet" type="submit">Release him (costs {{ $money($player->wage * 10) }})</button>
                    </form>
                </section>
            @endif

            @if ($player->transfers->isNotEmpty())
                <section>
                    <h2 class="section-title narrow" style="font-size: var(--s-19)">Transfer history</h2>
                    <ul class="results plain">
                        @foreach ($player->transfers->sortByDesc('id') as $t)
                            <li class="row3">
                                <span>{{ $t->from?->short ?? 'No club' }} to {{ $t->to?->short ?? 'no club' }}</span>
                                <span class="muted">{{ \App\Models\Transfer::KINDS[$t->kind] }}</span>
                                <span class="num">{{ $t->fee ? $money($t->fee) : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
@endsection
