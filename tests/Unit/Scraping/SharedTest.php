<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\Shared;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SharedTest extends TestCase
{
    // ── parseGrams ────────────────────────────────────────────────────────

    #[DataProvider('gramCases')]
    public function test_parse_grams(string $input, ?int $expected): void
    {
        $this->assertSame($expected, Shared::parseGrams($input));
    }

    public static function gramCases(): array
    {
        return [
            ['250g',                 250],
            ['340 g',                340],
            ['1000g / Whole Bean',   1000],
            ['12oz',                 340],
            ['12 oz / Ground',       340],
            ['8oz',                  227],
            ['1lb',                  454],
            ['1 lb / Whole Bean',    454],
            ['2lb',                  907],
            ['5lb',                  2268],
            ['1kg',                  1000],
            ['2 kg / Whole Bean',    2000],
            ['Whole Bean',           null],
            ['Default Title',        null],
            ['',                     null],
        ];
    }

    // ── dedupe ────────────────────────────────────────────────────────────

    public function test_dedupe_collapses_same_grams_and_sorts_ascending(): void
    {
        $variants = [
            ['grams' => 340,  'available' => true,  'price' => 24.0],
            ['grams' => 340,  'available' => true,  'price' => 24.0], // dup
            ['grams' => 2268, 'available' => true,  'price' => 99.0],
        ];
        $result = Shared::dedupeVariantsByGrams($variants);
        $this->assertSame([340, 2268], array_column($result, 'grams'));
    }

    public function test_dedupe_prefers_available_over_unavailable(): void
    {
        $variants = [
            ['grams' => 340, 'available' => false, 'price' => 24.0, 'tag' => 'A'],
            ['grams' => 340, 'available' => true,  'price' => 24.0, 'tag' => 'B'],
        ];
        $result = Shared::dedupeVariantsByGrams($variants);
        $this->assertCount(1, $result);
        $this->assertSame('B', $result[0]['tag']);
        $this->assertTrue($result[0]['available']);
    }

    // ── looksLikeCoffee ───────────────────────────────────────────────────

    public function test_looks_like_coffee_excludes_non_coffee_types(): void
    {
        $this->assertFalse(Shared::looksLikeCoffee('V60 Dripper', 'Equipment'));
        $this->assertFalse(Shared::looksLikeCoffee('Logo Tee', 'Apparel'));
        $this->assertFalse(Shared::looksLikeCoffee('Anything', 'Gift Card'));
    }

    public function test_looks_like_coffee_excludes_subscriptions_and_samples_by_title(): void
    {
        $this->assertFalse(Shared::looksLikeCoffee('Coffee Gift Card', 'Coffee'));
        $this->assertFalse(Shared::looksLikeCoffee('Monthly Subscription', 'Coffee'));
        $this->assertFalse(Shared::looksLikeCoffee('Sample Sets', 'Coffee'));
        $this->assertFalse(Shared::looksLikeCoffee('Single Sample Bags || Add-On', 'Coffee'));
    }

    public function test_looks_like_coffee_accepts_real_beans(): void
    {
        $this->assertTrue(Shared::looksLikeCoffee('Ethiopia Yirgacheffe', 'Coffee'));
        $this->assertTrue(Shared::looksLikeCoffee('House Blend', 'Coffee'));
        // No product_type set — pass through.
        $this->assertTrue(Shared::looksLikeCoffee('Ethiopia Yirgacheffe', ''));
    }

    // ── isBlend ───────────────────────────────────────────────────────────

    public function test_is_blend_explicit_signals(): void
    {
        $this->assertTrue(Shared::isBlend('House Blend', 'Coffee', []));
        $this->assertTrue(Shared::isBlend('Espresso', 'Coffee', ['Blend']));
        $this->assertTrue(Shared::isBlend('X', 'Espresso Blend', []));
    }

    public function test_is_blend_espresso_without_single_origin_is_blend(): void
    {
        $this->assertTrue(Shared::isBlend('Nocturnal Espresso', 'Coffee', ['Dark', 'Espresso']));
        $this->assertTrue(Shared::isBlend('Equilibrium Espresso', 'Coffee', ['Espresso', 'Medium']));
    }

    public function test_is_blend_single_origin_espresso_is_not_blend(): void
    {
        $this->assertFalse(Shared::isBlend(
            'Ethiopia Guji Daannisa Espresso',
            'Coffee',
            ['Espresso', 'Single Origin']
        ));
    }

    public function test_is_blend_single_origin_is_not_blend(): void
    {
        $this->assertFalse(Shared::isBlend('Ethiopia Yirgacheffe', 'Coffee', ['Single Origin']));
        $this->assertFalse(Shared::isBlend('Brazil Natural', 'Coffee', []));
    }
}
