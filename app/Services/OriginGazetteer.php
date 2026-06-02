<?php

namespace App\Services;

/**
 * Lookup table of well-known coffee-growing regions, estates, and grades
 * mapped to their country. Specialty roasters routinely title coffees by
 * region or estate ("Yirgacheffe Natural", "Sumatra Mandheling", "Antigua
 * Bourbon") rather than country, which left our origin-by-country filter
 * blind to most of the catalog. Q5 expands the lookup so titles like
 * "Yirgacheffe" resolve to Ethiopia, "Tarrazú" to Costa Rica, etc.
 *
 * Order matters: longer matches first so "Sumatra Mandheling" finds
 * "Sumatra" before single-word countries that contain a letter prefix.
 */
final class OriginGazetteer
{
    /**
     * Try to resolve a country from a free-form coffee title. Returns the
     * country name if anything matches, empty string otherwise.
     */
    public static function inferCountry(string $title): string
    {
        $haystack = strtolower($title);

        // Most-specific (longest needle) wins, not first-match. A coffee titled
        // "Volcan Azul (Natural SL28), Costa Rica" must resolve to Costa Rica,
        // not Panama — the short region alias "volcan" is a substring, but the
        // explicit country name "costa rica" is longer and should take
        // precedence. Longest-match also protects famously-ambiguous short
        // needles ("java", "kona", "guji") from beating an explicit country.
        // On a length tie, aliases() order breaks it (declared first wins).
        $best = '';
        $bestLen = 0;
        foreach (self::aliases() as $needle => $country) {
            if (str_contains($haystack, $needle)) {
                $len = mb_strlen($needle);
                if ($len > $bestLen) {
                    $best = $country;
                    $bestLen = $len;
                }
            }
        }
        return $best;
    }

    /**
     * The full gazetteer. Each pattern is matched as a case-insensitive
     * substring; longer / more-specific patterns are listed first so
     * "Sumatra Mandheling" picks up Sumatra before any short-prefix
     * accidents.
     */
    public static function aliases(): array
    {
        return [
            // ── Ethiopia (regions/villages) ─────────────────────────────
            'yirgacheffe' => 'Ethiopia',
            'yirgachefe'  => 'Ethiopia',
            'sidamo'      => 'Ethiopia',
            'sidama'      => 'Ethiopia',
            'kochere'     => 'Ethiopia',
            'guji'        => 'Ethiopia',
            'limu'        => 'Ethiopia',
            'jimma'       => 'Ethiopia',
            'harar'       => 'Ethiopia',
            'gedeo'       => 'Ethiopia',
            'shakiso'     => 'Ethiopia',

            // ── Kenya ───────────────────────────────────────────────────
            'nyeri'       => 'Kenya',
            'kirinyaga'   => 'Kenya',
            'kiambu'      => 'Kenya',
            'embu'        => 'Kenya',

            // ── Colombia ────────────────────────────────────────────────
            'huila'       => 'Colombia',
            'nariño'      => 'Colombia',
            'narino'      => 'Colombia',
            'cauca'       => 'Colombia',
            'antioquia'   => 'Colombia',
            'tolima'      => 'Colombia',
            'quindio'     => 'Colombia',
            'quindío'     => 'Colombia',
            'popayan'     => 'Colombia',
            'popayán'     => 'Colombia',

            // ── Brazil ──────────────────────────────────────────────────
            'minas gerais' => 'Brazil',
            'sul de minas' => 'Brazil',
            'cerrado'     => 'Brazil',
            'mogiana'     => 'Brazil',
            'santos'      => 'Brazil',

            // ── Guatemala ───────────────────────────────────────────────
            'huehuetenango' => 'Guatemala',
            'antigua'     => 'Guatemala',
            'cobán'       => 'Guatemala',
            'coban'       => 'Guatemala',
            'atitlán'     => 'Guatemala',
            'atitlan'     => 'Guatemala',

            // ── Costa Rica ──────────────────────────────────────────────
            'tarrazú'     => 'Costa Rica',
            'tarrazu'     => 'Costa Rica',
            'tres rios'   => 'Costa Rica',
            'tres ríos'   => 'Costa Rica',

            // ── Honduras ────────────────────────────────────────────────
            'marcala'     => 'Honduras',
            'santa bárbara' => 'Honduras',
            'santa barbara' => 'Honduras',
            'copán'       => 'Honduras',
            'copan'       => 'Honduras',
            'lempira'     => 'Honduras',

            // ── Panama ──────────────────────────────────────────────────
            'boquete'     => 'Panama',
            'volcan'      => 'Panama',
            'volcán'      => 'Panama',

            // ── El Salvador ─────────────────────────────────────────────
            'apaneca'     => 'El Salvador',
            'chalatenango' => 'El Salvador',

            // ── Indonesia ───────────────────────────────────────────────
            'sumatra'     => 'Indonesia',
            'java'        => 'Indonesia',
            'sulawesi'    => 'Indonesia',
            'aceh'        => 'Indonesia',
            'mandheling'  => 'Indonesia',
            'toraja'      => 'Indonesia',

            // ── Yemen ───────────────────────────────────────────────────
            'mocha'       => 'Yemen',
            'sanaani'     => 'Yemen',

            // ── Jamaica ─────────────────────────────────────────────────
            'blue mountain' => 'Jamaica',

            // ── US (Hawaii) ─────────────────────────────────────────────
            'kona'        => 'United States',

            // ── Country-level fallbacks (some roasters do title by country) ─
            'ethiopia'    => 'Ethiopia',
            'kenya'       => 'Kenya',
            'colombia'    => 'Colombia',
            'brazil'      => 'Brazil',
            'guatemala'   => 'Guatemala',
            'costa rica'  => 'Costa Rica',
            'honduras'    => 'Honduras',
            'mexico'      => 'Mexico',
            'peru'        => 'Peru',
            'rwanda'      => 'Rwanda',
            'burundi'     => 'Burundi',
            'indonesia'   => 'Indonesia',
            'yemen'       => 'Yemen',
            'panama'      => 'Panama',
            'el salvador' => 'El Salvador',
            'nicaragua'   => 'Nicaragua',
            'tanzania'    => 'Tanzania',
            'uganda'      => 'Uganda',
            'bolivia'     => 'Bolivia',
            'ecuador'     => 'Ecuador',
            'jamaica'     => 'Jamaica',
            'india'       => 'India',
            // Distinctive multi-word / long country names — safe as bare
            // substrings (no short-prefix collisions) and common enough in
            // specialty catalogs to be worth resolving. PNG in particular is
            // a frequent origin some roasters title by country only.
            'papua new guinea' => 'Papua New Guinea',
            'papua'       => 'Papua New Guinea',
            'democratic republic of congo' => 'DR Congo',
            'dr congo'    => 'DR Congo',
            'vietnam'     => 'Vietnam',
            'thailand'    => 'Thailand',
            'myanmar'     => 'Myanmar',
            'philippines' => 'Philippines',
            'timor-leste' => 'Timor-Leste',
            'timor leste' => 'Timor-Leste',
            'east timor'  => 'Timor-Leste',
        ];
    }
}
