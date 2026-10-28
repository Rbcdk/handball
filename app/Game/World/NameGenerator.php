<?php

namespace App\Game\World;

use App\Game\Engine\Rng;

/**
 * Invented names for a fantasy handball world: no real towns, clubs or people.
 * Names are built from syllables, so there are many thousands of combinations.
 */
final class NameGenerator
{
    // Player first names: a start and an ending that read well together.
    private const FIRST_START = [
        'Ael', 'Bran', 'Cae', 'Dar', 'Eld', 'Fen', 'Gar', 'Hal', 'Ith', 'Jor', 'Kael', 'Lor',
        'Mor', 'Nor', 'Or', 'Rha', 'Sor', 'Tor', 'Ul', 'Var', 'Wyn', 'Yor', 'Zan', 'Bel', 'Cal',
        'Dro', 'Ery', 'Fal', 'Hro', 'Ka', 'Lu', 'Ma', 'Ny', 'Sel', 'Tha', 'Ve', 'As', 'Ber',
    ];

    private const FIRST_END = [
        'ric', 'vin', 'mund', 'dor', 'nik', 'ian', 'wald', 'rik', 'len', 'ion', 'ar', 'ulf',
        'is', 'eth', 'an', 'or', 'en', 'ren', 'gar', 'mir', 'dan', 'tor', 'vald', 'sin',
    ];

    // Family names: two parts of a made-up place or trade.
    private const LAST_START = [
        'Aeth', 'Brim', 'Calda', 'Dun', 'Esk', 'Fyr', 'Grim', 'Hael', 'Isen', 'Kald', 'Lorn',
        'Myr', 'Nidh', 'Orm', 'Ruun', 'Skjal', 'Thorn', 'Ulv', 'Vael', 'Wyr', 'Yrs', 'Ember',
        'Frost', 'Gale', 'Hearth', 'Raven', 'Stone', 'Storm', 'Ash', 'Iron', 'Silver', 'Wolf',
    ];

    private const LAST_END = [
        'gard', 'vane', 'hollow', 'brand', 'mere', 'wold', 'fell', 'rik', 'stad', 'mar', 'vik',
        'dun', 'holm', 'crest', 'helm', 'ward', 'shield', 'bane', 'more', 'wick', 'ridge', 'thorne',
    ];

    // Towns for the clubs.
    private const TOWN_START = [
        'Ash', 'Brim', 'Cael', 'Dra', 'Eld', 'Fen', 'Gloam', 'Hal', 'Ir', 'Kor', 'Lys', 'Mor',
        'Nyx', 'Ost', 'Quel', 'Rav', 'Sil', 'Tyr', 'Umb', 'Vyr', 'Wyn', 'Zar', 'Thal', 'Dun',
        'Ember', 'Frost', 'Gold', 'Iron', 'Storm', 'Wolf',
    ];

    private const TOWN_END = [
        'moor', 'hold', 'haven', 'reach', 'spire', 'fall', 'gate', 'heim', 'ford', 'mark',
        'vale', 'crag', 'holm', 'wick', 'mere', 'stead', 'watch', 'deep',
    ];

    // What the team is called after its town.
    private const TEAM = [
        'Wolves', 'Ravens', 'Griffins', 'Wyverns', 'Stags', 'Bears', 'Falcons', 'Titans',
        'Sentinels', 'Lynx', 'Serpents', 'Owls', 'Rangers', 'Hammers', 'Thunder', 'Eagles',
        'Krakens', 'Foxes', 'Phoenix', 'Giants', 'Valkyries', 'Druids', 'Comets', 'Boars',
    ];

    public function __construct(private Rng $rng)
    {
    }

    /** @return array{0: string, 1: string} first name, last name */
    public function playerName(): array
    {
        return [
            $this->rng->pick(self::FIRST_START).$this->rng->pick(self::FIRST_END),
            $this->rng->pick(self::LAST_START).$this->rng->pick(self::LAST_END),
        ];
    }

    /**
     * @param  array<int, string>  $takenNames
     * @param  array<int, string>  $takenShorts
     * @return array{name: string, city: string, short: string}
     */
    public function club(array $takenNames, array $takenShorts = []): array
    {
        $takenCities = array_map(fn ($n) => explode(' ', $n)[0], $takenNames);
        do {
            $city = $this->rng->pick(self::TOWN_START).$this->rng->pick(self::TOWN_END);
            $short = $this->shortCode($city, $takenShorts);
        } while (in_array($city, $takenCities, true) || $short === null);

        return ['name' => $city.' '.$this->rng->pick(self::TEAM), 'city' => $city, 'short' => $short];
    }

    /** Three-letter code, unique among $taken: first letters, then first letter + later letters. */
    private function shortCode(string $city, array $taken): ?string
    {
        $letters = mb_str_split(mb_strtoupper($city));
        $candidates = [implode('', array_slice($letters, 0, 3))];
        for ($i = 1; $i < count($letters); $i++) {
            for ($j = $i + 1; $j < count($letters); $j++) {
                $candidates[] = $letters[0].$letters[$i].$letters[$j];
            }
        }
        foreach ($candidates as $c) {
            if (! in_array($c, $taken, true)) {
                return $c;
            }
        }

        return null;
    }
}
