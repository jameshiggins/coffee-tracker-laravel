<?php

namespace Tests\Unit;

use App\Services\OriginGazetteer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OriginGazetteerTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_resolves_country_from_title(string $title, string $expected): void
    {
        $this->assertSame($expected, OriginGazetteer::inferCountry($title));
    }

    public static function cases(): array
    {
        return [
            // Region/estate-titled coffees that the old country-only matcher missed.
            ['Yirgacheffe Natural', 'Ethiopia'],
            ['Sidamo Washed', 'Ethiopia'],
            ['Guji Heirloom Lot #2', 'Ethiopia'],
            ['Sumatra Mandheling', 'Indonesia'],
            ['Java Frinsa Estate', 'Indonesia'],
            ['Sulawesi Toraja Honey', 'Indonesia'],
            ['Antigua Bourbon', 'Guatemala'],
            ['Huehuetenango Reserve', 'Guatemala'],
            ['Tarrazú Honey', 'Costa Rica'],
            ['Tarrazu Honey', 'Costa Rica'],
            ['Cauca Popayan', 'Colombia'],
            ['Huila Pink Bourbon', 'Colombia'],
            ['Nyeri AA', 'Kenya'],
            ['Boquete Geisha', 'Panama'],
            ['Kona Reserve', 'United States'],
            ['Blue Mountain', 'Jamaica'],

            // Country-level fallbacks still work.
            ['Ethiopia Yirgacheffe', 'Ethiopia'],
            ['Brazil Santos', 'Brazil'],

            // Precedence: an explicit country name in the title beats a SHORT,
            // ambiguous region alias that also happens to be a substring.
            // "Volcan Azul" is a famous Costa Rica farm, but "volcan" is also a
            // Panama region alias — the longer "costa rica" must win.
            ['Volcan Azul (Natural Anaerobic SL28), Costa Rica', 'Costa Rica'],
            ['Java City Reserve, Colombia', 'Colombia'],
            ['Kona Style, Guatemala', 'Guatemala'],

            // Junk / blends with no signal return empty string.
            ['House Blend', ''],
            ['Mystery Box', ''],
            ['', ''],
        ];
    }
}
