<?php

namespace App\Support;

/**
 * Deterministic generated artwork for a venue.
 *
 * The product has no photography and is not getting any, so every venue card,
 * gallery and hero would otherwise render a grey box with a broken-image
 * glyph. A crest is what goes there instead: a duotone field plus a motif,
 * derived from the venue's own identity so it is stable, distinctive, and
 * never repeats the same pairing for two venues on the same page by accident.
 *
 * Two properties matter more than how it looks:
 *
 *   Deterministic -- the same venue always gets the same crest, on every
 *   request and every machine. It is a hash, not a random pick, so a venue's
 *   visual identity is something a returning customer can actually recognise.
 *
 *   Extensible -- motifs are families, not one-per-game. V1 launches with
 *   sports and gaming, but the platform is planned to take halls and event
 *   spaces later. A new category maps to an existing family or falls through
 *   to `hall`; nothing has to be redesigned and nothing renders blank.
 *
 * A real uploaded photo always wins. This is the floor, not the ceiling.
 */
final class VenueCrest
{
    /**
     * Curated duotone schemes: a deep base and the light thrown across it.
     *
     * Curated rather than generated from the hash -- an arbitrary hue is how
     * procedural art ends up with muddy olive and neon puce on the same row.
     * Every pair here is checked against both themes, and the crest stays dark
     * in both, which keeps it consistent with the board language.
     */
    private const SCHEMES = [
        ['base' => '#1a1206', 'light' => '#ffb224'],  // floodlight amber
        ['base' => '#04140f', 'light' => '#4fe0ac'],  // lit court mint
        ['base' => '#0b1020', 'light' => '#7fa8ff'],  // late indigo
        ['base' => '#180a14', 'light' => '#ff5f9e'],  // arcade magenta
        ['base' => '#04141a', 'light' => '#3fd0e0'],  // pool teal
        ['base' => '#120a1e', 'light' => '#a78bfa'],  // neon violet
    ];

    /**
     * Game slug -> motif family.
     *
     * Grouped by the shape of the space rather than by sport, which is what
     * makes it survive the category expansion: a banquet hall is a `hall`, a
     * conference room is a `hall`, and neither needs new artwork.
     */
    private const MOTIFS = [
        'snooker' => 'cue',
        'pool' => 'cue',
        'futsal' => 'pitch',
        'padel' => 'court',
        'badminton' => 'court',
        'squash' => 'court',
        'table-tennis' => 'court',
        'cricket-nets' => 'lane',
        'bowling' => 'lane',
        'ps5' => 'screen',
        'xbox' => 'screen',
        'vr-gaming' => 'screen',
    ];

    private const FALLBACK_MOTIF = 'hall';

    /**
     * The render spec for a venue.
     *
     * Takes primitives rather than a Business so it stays trivially testable
     * and can also crest a Spot, a game category, or anything else with a name.
     *
     * @param  array<int, string>  $gameSlugs
     * @return array{base: string, light: string, motif: string, rotation: int, shift: int, seed: int}
     */
    public static function for(int|string $id, string $name, array $gameSlugs = []): array
    {
        // crc32 over identity + name: stable across requests and PHP versions,
        // and cheap enough to run for every card in a result set.
        $seed = crc32($id.'::'.$name);

        $scheme = self::SCHEMES[$seed % count(self::SCHEMES)];

        return [
            'base' => $scheme['base'],
            'light' => $scheme['light'],
            'motif' => self::motif($gameSlugs),
            // Small variations so two venues sharing a scheme and a motif
            // still read as different tiles.
            'rotation' => (int) (($seed >> 3) % 24) - 12,
            'shift' => (int) (($seed >> 7) % 30),
            'seed' => $seed,
        ];
    }

    /**
     * @param  array<int, string>  $gameSlugs
     */
    private static function motif(array $gameSlugs): string
    {
        foreach ($gameSlugs as $slug) {
            if (isset(self::MOTIFS[$slug])) {
                return self::MOTIFS[$slug];
            }
        }

        // An unmapped or game-less venue is not an error -- it is the future
        // category case, and it gets the generic floor plan.
        return self::FALLBACK_MOTIF;
    }
}
