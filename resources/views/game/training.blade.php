@extends('layouts.app')

@section('title', 'Training')

@php
    $focus = \App\Game\Training::FOCUS;
    $levels = \App\Game\Training::INTENSITY;
    $intensityHelp = [
        'light' => 'Slower progress, but players recover more between matches and get injured less.',
        'normal' => 'Steady progress and normal recovery.',
        'hard' => 'Players improve about 50% faster, but recover less between matches and get injured more often.',
    ];
@endphp

@section('content')
    <form method="POST" action="{{ route('training.update') }}">
        @csrf
        @method('PUT')
        <div class="section-head">
            <h2 class="section-title narrow">Training</h2>
            <button class="btn btn--primary" type="submit">Save training plan</button>
        </div>
        <p class="muted" style="max-width: 75ch">Players train after every match day. Each player trains the skills in his focus; his weakest skill in the focus improves first. Young players learn fastest; from 30 they stop improving. Every player has a limit he can't train past.</p>

        <div class="tactic">
            <fieldset>
                <legend>Intensity for the whole squad</legend>
                <div class="seg">
                    @foreach ($levels as $key => $def)
                        <label>
                            <input type="radio" name="intensity" value="{{ $key }}" id="intensity-{{ $key }}" @checked($intensity === $key)>
                            <span>{{ $def['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <p class="tactic__help" data-help="intensity">
                @foreach ($levels as $key => $def)
                    <span data-for="{{ $key }}" @if ($intensity !== $key) hidden @endif><b>{{ $def['label'] }}:</b> {{ $intensityHelp[$key] }}</span>
                @endforeach
            </p>
        </div>

        <h3 class="section-title narrow squad__title" style="margin-top: 24px">Each player's focus</h3>
        <div class="sheet-wrap">
            <table class="sheet training">
                <thead>
                    <tr>
                        <th class="l" scope="col">Player</th>
                        <th class="l" scope="col">Position</th>
                        <th scope="col">Age</th>
                        <th class="l" scope="col">Learns</th>
                        <th scope="col">Rating</th>
                        <th class="l" scope="col">Focus</th>
                        <th class="l" scope="col">Next skill point</th>
                        <th scope="col">Fitness</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($players as $p)
                        @php $growth = \App\Http\Controllers\TeamController::growthLabel($p->age); @endphp
                        <tr>
                            <td class="l">@include('partials.player-name', ['p' => $p, 'face' => true, 'shirt' => $club->color])</td>
                            <td class="l">{{ \App\Models\Player::POSITIONS[$p->position] }}</td>
                            <td>{{ $p->age }}</td>
                            <td class="l"><span class="growth growth--{{ strtolower($growth) }}">{{ $growth }}</span></td>
                            <td class="pts">{{ $p->rating() }}</td>
                            <td class="l">
                                @if ($p->isGoalkeeper())
                                    <span class="muted">Goalkeeping</span>
                                @else
                                    <label class="visually-hidden" for="focus-{{ $p->id }}">Focus for {{ $p->name }}</label>
                                    <select id="focus-{{ $p->id }}" name="focus[{{ $p->id }}]">
                                        @foreach ($focus as $key => $def)
                                            @continue($key === 'goalkeeping')
                                            <option value="{{ $key }}" @selected($p->training_focus === $key)>{{ $def['label'] }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                            <td class="l">
                                @if ($growth === 'None')
                                    <span class="muted">Not improving</span>
                                @else
                                    <span class="skillcell"><i class="skill skill--mid skill--wide" style="width: 120px" aria-hidden="true"><b style="width: {{ min(100, $p->training_xp) }}%"></b></i><span class="muted" style="font-size: var(--s-13)">{{ min(100, $p->training_xp) }}%</span></span>
                                @endif
                            </td>
                            <td class="{{ $p->fitness < 75 ? 'is-negative' : '' }}">{{ $p->isInjured() ? '–' : $p->fitness }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top: 10px; font-size: var(--s-13)">Players who played in the match learn a little more. What each focus trains:
            @foreach ($focus as $key => $def)
                @continue($key === 'balanced')
                <b>{{ $def['label'] }}</b> ({{ implode(', ', array_map(fn ($s) => $s === 'seven_meter' ? '7-metre saves' : $s, $def['skills'])) }}){{ $loop->last ? '.' : ';' }}
            @endforeach
            <b>Balanced</b> trains all court skills.
        </p>
        <div class="btn-row" style="margin-top: 18px">
            <button class="btn btn--primary" type="submit">Save training plan</button>
        </div>
    </form>
@endsection
