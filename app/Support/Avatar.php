<?php

namespace App\Support;

use App\Game\Engine\Rng;
use App\Models\Player;

/**
 * A drawn portrait for every player: head and shoulders in his club's shirt.
 * Built from the player's id, so it never changes and needs no stored image.
 */
final class Avatar
{
    private const SKIN = ['#f3d5bb', '#e9bb92', '#d6a070', '#b67c4c', '#8d5b36', '#62402a'];

    private const HAIR = ['#1f1a17', '#3a2a20', '#5e4129', '#8a3f22', '#b7894a', '#d9c08a', '#b5562b'];

    private const STYLES = ['crop', 'crop', 'buzz', 'side', 'side', 'curly', 'long', 'bald', 'mohawk', 'swept', 'swept'];

    private const BEARDS = ['none', 'none', 'none', 'none', 'stubble', 'stubble', 'full', 'goatee', 'mustache'];

    /** @var array<string, string> */
    private static array $cache = [];

    public static function svg(Player $player, int $size = 40, ?string $shirt = null): string
    {
        $shirt ??= $player->club?->color ?? '#7d8995';
        $seed = (int) ($player->face ?: $player->id);
        $key = $seed.'|'.$size.'|'.$shirt.'|'.$player->number.'|'.$player->age;

        return self::$cache[$key] ??= self::draw($seed, (int) $player->number, (int) $player->age, $shirt, $size, $player->name);
    }

    public static function draw(int $id, int $number, int $age, string $shirt, int $size, string $label): string
    {
        $rng = new Rng($id * 7919 + 17);
        $skin = $rng->pick(self::SKIN);
        $hair = $rng->pick(self::HAIR);
        if ($age >= 31 && $rng->chance(($age - 28) / 12)) {
            $hair = $rng->pick(['#8f8f8f', '#a8a39b', '#6f6a64']);
        }
        $style = $rng->pick(self::STYLES);
        if ($age >= 32 && $rng->chance(0.25)) {
            $style = 'bald';
        }
        $beard = $rng->pick(self::BEARDS);
        $headRy = 13 + $rng->range(-0.8, 0.8);
        $headRx = 11 + $rng->range(-0.8, 0.6);
        $eyeY = 28 + $rng->range(-0.5, 0.5);
        $smile = $rng->chance(0.55);
        $uid = 'a'.$id.'s'.$size;
        $dark = self::shade($skin, -0.18);
        $ink = '#22201d';
        $label = htmlspecialchars($label, ENT_QUOTES);

        $back = $style === 'long'
            ? "<path d='M19.5 25c-1-12 26-12 25 0l1.5 15h-4.5l-1-11H23.5l-1 11H18z' fill='{$hair}'/>"
            : '';

        $top = match ($style) {
            'crop' => "<path d='M20.6 26.5c-.8-12.8 23.6-12.8 22.8 0-2.9-6.2-19.9-6.2-22.8 0z' fill='{$hair}'/>",
            'buzz' => "<path d='M20.8 26c-.4-12 22.8-12 22.4 0-3-5.4-19.4-5.4-22.4 0z' fill='{$hair}' opacity='.55'/>",
            'side' => "<path d='M20 27.5c-1.3-14 25.2-15 24.1-1.4-3.7-7.3-12.6-8.5-17.9-4.7l-3.8 6.1z' fill='{$hair}'/>",
            'curly' => "<g fill='{$hair}'><circle cx='22.5' cy='22' r='3.6'/><circle cx='26.5' cy='17.6' r='3.9'/><circle cx='32' cy='16' r='4.1'/><circle cx='37.5' cy='17.6' r='3.9'/><circle cx='41.5' cy='22' r='3.6'/><circle cx='29' cy='19.5' r='3'/><circle cx='35' cy='19.5' r='3'/></g>",
            'long' => "<path d='M20.4 27c-.8-13.6 24-13.6 23.2 0-3.4-7.4-19.8-7.4-23.2 0z' fill='{$hair}'/>",
            'mohawk' => "<rect x='29.2' y='11.5' width='5.6' height='12' rx='2.8' fill='{$hair}'/>",
            'swept' => "<path d='M20.7 26.2c-1-11.6 17.8-15.4 23.6-5.4-6.3-2.4-15.4-1.3-23.6 5.4z' fill='{$hair}'/>",
            default => "<ellipse cx='28' cy='19.5' rx='3' ry='1.4' fill='#fff' opacity='.18'/>",
        };

        $jaw = 'M21 30c.9 12 21.1 12 22 0';
        $chin = match ($beard) {
            'full' => "<path d='{$jaw}c-.4 7.6-5.8 11.4-11 11.4S21.4 37.6 21 30z' fill='{$hair}'/>",
            'stubble' => "<path d='{$jaw}c-.4 7.6-5.8 11.4-11 11.4S21.4 37.6 21 30z' fill='{$hair}' opacity='.3'/>",
            'goatee' => "<path d='M29.4 36.4h5.2l-.8 4.4h-3.6z' fill='{$hair}'/>",
            'mustache' => "<path d='M27.6 33.4c1.6-1.4 3.2-1.1 4.4-.3 1.2-.8 2.8-1.1 4.4.3-1.6.6-3 .5-4.4-.2-1.4.7-2.8.8-4.4.2z' fill='{$hair}'/>",
            default => '',
        };
        $mouth = $smile ? "<path d='M29 35.2q3 2.4 6 0' fill='none' stroke='{$ink}' stroke-width='1' stroke-linecap='round'/>"
            : "<path d='M29.4 35.6h5.2' stroke='{$ink}' stroke-width='1' stroke-linecap='round'/>";
        $num = $number > 0 ? "<text x='32' y='61.5' text-anchor='middle' font-size='7' font-weight='800' font-family='Archivo, sans-serif' fill='#fff' opacity='.92'>{$number}</text>" : '';
        $ey = round($eyeY, 2);
        $by = round($eyeY - 3.2, 2);

        return <<<SVG
<svg class="avatar" width="{$size}" height="{$size}" viewBox="0 0 64 64" role="img" aria-label="{$label}"><defs><clipPath id="{$uid}"><circle cx="32" cy="32" r="32"/></clipPath></defs><g clip-path="url(#{$uid})"><rect width="64" height="64" fill="{$shirt}" opacity=".22"/><rect width="64" height="64" fill="#0b1520" opacity=".12"/>{$back}<rect x="27" y="37" width="10" height="11" rx="3" fill="{$dark}"/><path d="M5 64c1.6-12.4 11.6-17.6 27-17.6S57.4 51.6 59 64z" fill="{$shirt}"/><path d="M25.6 46.8 32 53l6.4-6.2" fill="none" stroke="#fff" stroke-width="1.6" opacity=".8"/>{$num}<ellipse cx="20.8" cy="29.5" rx="2.4" ry="3.2" fill="{$dark}"/><ellipse cx="43.2" cy="29.5" rx="2.4" ry="3.2" fill="{$dark}"/><ellipse cx="32" cy="28" rx="{$headRx}" ry="{$headRy}" fill="{$skin}"/>{$chin}{$top}<path d="M26.4 {$by}h3.6M34 {$by}h3.6" stroke="{$hair}" stroke-width="1.3" stroke-linecap="round"/><circle cx="28.2" cy="{$ey}" r="1.25" fill="{$ink}"/><circle cx="35.8" cy="{$ey}" r="1.25" fill="{$ink}"/><path d="M32 29.6v3.2h-1.4" fill="none" stroke="{$dark}" stroke-width="1" stroke-linecap="round"/>{$mouth}</g></svg>
SVG;
    }

    /** Lighter (positive) or darker (negative) version of a hex colour. */
    private static function shade(string $hex, float $amount): string
    {
        $c = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
        $f = fn ($v) => (int) max(0, min(255, round($amount < 0 ? $v * (1 + $amount) : $v + (255 - $v) * $amount)));

        return sprintf('#%02x%02x%02x', $f($c[0]), $f($c[1]), $f($c[2]));
    }
}
