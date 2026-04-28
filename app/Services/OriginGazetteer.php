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
        foreach (self::aliases() as $needle => $country) {
            if (str_contains($haystack, $needle)) return $country;
        }
        return '';
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
        ];
    }
}
