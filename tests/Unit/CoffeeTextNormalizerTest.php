<?php

namespace Tests\Unit;

use App\Services\CoffeeTextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for the pure text-cleaning helpers every import
 * passes through. These were previously only exercised indirectly via the
 * importer feature tests, which made regressions in individual transforms
 * hard to localize.
 */
class CoffeeTextNormalizerTest extends TestCase
{
    // ── inferNameFromUrl ────────────────────────────────────────────────

    public function test_infer_name_strips_www_and_shop_prefixes(): void
    {
        $this->assertSame('Rogue Wave Coffee', CoffeeTextNormalizer::inferNameFromUrl('https://www.rogue-wave-coffee.ca'));
        $this->assertSame('Detour', CoffeeTextNormalizer::inferNameFromUrl('https://shop.detour.coffee/products'));
        $this->assertSame('Monogram Co', CoffeeTextNormalizer::inferNameFromUrl('https://monogram_co.com'));
    }

    // ── sanitizeText ────────────────────────────────────────────────────

    public function test_sanitize_decodes_html_entities(): void
    {
        $this->assertSame('Fruity & sweet', CoffeeTextNormalizer::sanitizeText('Fruity &amp; sweet'));
        $this->assertSame("It's a peña", CoffeeTextNormalizer::sanitizeText('It&#039;s a pe&ntilde;a'));
    }

    public function test_sanitize_normalizes_typography(): void
    {
        $this->assertSame(
            'It\'s "great" - honest',
            CoffeeTextNormalizer::sanitizeText("It\u{2019}s \u{201C}great\u{201D} \u{2014} honest")
        );
    }

    public function test_sanitize_collapses_whitespace_and_trims_cruft(): void
    {
        $this->assertSame('Kenya AA', CoffeeTextNormalizer::sanitizeText("  Kenya \n\t AA  "));
        $this->assertSame('Chocolate', CoffeeTextNormalizer::sanitizeText('• Chocolate,'));
        $this->assertSame('A B', CoffeeTextNormalizer::sanitizeText("A\u{00A0}B"));
    }

    public function test_sanitize_strips_control_and_replacement_chars(): void
    {
        $this->assertSame('AB', CoffeeTextNormalizer::sanitizeText("A\x07B"));
        $this->assertSame('caf', CoffeeTextNormalizer::sanitizeText("caf\u{FFFD}"));
    }

    // ── cleanCoffeeName ─────────────────────────────────────────────────

    /** @dataProvider coffeeNameProvider */
    public function test_clean_coffee_name(string $raw, string $expected): void
    {
        $this->assertSame($expected, CoffeeTextNormalizer::cleanCoffeeName($raw));
    }

    public static function coffeeNameProvider(): array
    {
        return [
            'parenthesised weight' => ['Brazil Santos (454 g)', 'Brazil Santos'],
            'dash weight' => ['Colombia Huila - 340g', 'Colombia Huila'],
            'bare trailing weight' => ['Kenya AA 250g', 'Kenya AA'],
            'trailing process chip' => ['Peru Marshell | Washed', 'Peru Marshell'],
            'trailing roast chip' => ['Foundry - Light Roast', 'Foundry'],
            'both process and roast' => ['Kenya - Light Roast - Washed', 'Kenya'],
            'parenthesised process' => ['La Palma (Anaerobic)', 'La Palma'],
            // Known limitation: weight stripping runs before process
            // stripping and doesn't re-run, so a weight "unmasked" by a
            // removed process chip survives.
            'weight unmasked by process strip stays' => ['Yirgacheffe 250g | Natural', 'Yirgacheffe 250g'],
            'standalone process name kept' => ['Washed Coffee', 'Washed Coffee'],
            'mid-title lot number kept' => ['Anaerobic Lot 12', 'Anaerobic Lot 12'],
            'entities decoded' => ['Cream &amp; Sugar', 'Cream & Sugar'],
        ];
    }

    public function test_clean_coffee_name_never_returns_empty(): void
    {
        // A title that is nothing but a weight annotation cleans to '' —
        // the original must come back rather than an empty name.
        $this->assertSame('(250g)', CoffeeTextNormalizer::cleanCoffeeName('(250g)'));
    }

    // ── cleanDescription ────────────────────────────────────────────────

    public function test_clean_description_returns_null_for_empty_input(): void
    {
        $this->assertNull(CoffeeTextNormalizer::cleanDescription(''));
        $this->assertNull(CoffeeTextNormalizer::cleanDescription("  \n\t  "));
    }

    public function test_clean_description_cuts_brew_recipes(): void
    {
        $out = CoffeeTextNormalizer::cleanDescription(
            'A juicy Colombian with cherry sweetness. Brewing guide: use 15g of coffee to 250ml water at 94°C.'
        );

        $this->assertSame('A juicy Colombian with cherry sweetness.', $out);
    }

    public function test_clean_description_strips_labelled_spec_blocks(): void
    {
        $out = CoffeeTextNormalizer::cleanDescription(
            "A juicy Ethiopian everyone loves. Origin: Ethiopia. Altitude: 2100m. Great with milk."
        );

        $this->assertStringContainsString('A juicy Ethiopian everyone loves', $out);
        $this->assertStringContainsString('Great with milk', $out);
        $this->assertStringNotContainsString('Origin:', $out);
        $this->assertStringNotContainsString('2100m', $out);
    }

    public function test_clean_description_strips_urls_and_emails(): void
    {
        $out = CoffeeTextNormalizer::cleanDescription(
            'Smooth chocolate body all the way down. Visit https://example.com or write to hello@example.com anytime.'
        );

        $this->assertStringNotContainsString('http', $out);
        $this->assertStringNotContainsString('@', $out);
    }

    public function test_clean_description_caps_length_at_sentence_boundary(): void
    {
        $sentence = 'This coffee tastes like cherries and cola with a long syrupy finish on every sip.';
        $out = CoffeeTextNormalizer::cleanDescription(implode(' ', array_fill(0, 8, $sentence)));

        $this->assertLessThanOrEqual(320, mb_strlen($out));
        $this->assertMatchesRegularExpression('/[.!?…]$/', $out);
    }

    public function test_clean_description_sentence_cases_shouty_intros(): void
    {
        $this->assertSame(
            'Delicious and sweet. Very fruity.',
            CoffeeTextNormalizer::cleanDescription('DELICIOUS AND SWEET. VERY FRUITY.')
        );
    }

    // ── extractTastingNotes ─────────────────────────────────────────────

    public function test_extract_tasting_notes_normalizes_separators(): void
    {
        $this->assertSame(
            'Golden berry, Jasmine, Pear',
            CoffeeTextNormalizer::extractTastingNotes('Tasting Notes: Golden berry • Jasmine • Pear')
        );
        $this->assertSame(
            'Chocolate, Caramel',
            CoffeeTextNormalizer::extractTastingNotes('Notes: Chocolate / Caramel')
        );
    }

    public function test_extract_tasting_notes_returns_null_when_absent(): void
    {
        $this->assertNull(CoffeeTextNormalizer::extractTastingNotes(null));
        $this->assertNull(CoffeeTextNormalizer::extractTastingNotes('A lovely coffee with no labelled list.'));
    }

    // ── inferOrigin ─────────────────────────────────────────────────────

    public function test_infer_origin_delegates_to_gazetteer(): void
    {
        $this->assertSame('Colombia', CoffeeTextNormalizer::inferOrigin('Huila Reserve, Colombia'));
        $this->assertSame('', CoffeeTextNormalizer::inferOrigin('Mystery Espresso'));
    }
}
