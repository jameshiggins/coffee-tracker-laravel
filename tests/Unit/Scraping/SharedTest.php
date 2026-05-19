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

    // ── looksLikeCoffee: Rogue Wave over-capture regression suite ─────────
    //
    // Rogue Wave Coffee sells ~470 non-coffee SKUs (brewers, grinders,
    // kettles, scales, drippers, filter papers, drinkware, merch, water,
    // subscriptions) alongside ~260 real coffees. Stores file gear under a
    // distinct product_type ("Brewer", "Grinder", …) and/or tag it with
    // gear markers ("New Gear & Equipment", "SmartrrFilter:Brewers"), but
    // some gear titles literally contain "coffee"/"espresso" and slip past
    // the legacy gift-card/cleaner guards. These cases were all wrongly
    // imported as coffees before the filter was tightened.

    /** @return array<string, array{0:string,1:string,2:array<int,string>}> */
    public static function rogueWaveGearCases(): array
    {
        return [
            // product_type-based equipment (the bulk of the catalog)
            'brewer type'        => ['UFO - Dripper V3', 'Brewer', ['Brewer', 'SmartrrFilter:Brewers']],
            'grinder type'       => ['1ZPRESSO - K-ULTRA', 'Grinder', ['Grinder', 'Hand Grinder']],
            'kettle type'        => ['Fellow - Stagg EKG Electric Kettle | PRO', 'Kettle', ['Kettle']],
            'scale type'         => ['Acaia Pearl Digital Coffee Scale', 'Scale', ['Scale']],
            'server type'        => ['Hario - V60 Range Server 600 mL', 'Server', ['Carafe', 'Server']],
            'drinkware type'     => ['ORIGAMI - Aroma Cup 200ml', 'Drinkware', ['Cup', 'Mug']],
            'filters type'       => ['Hario - V60-02 Paper Filters 40pack', 'Filters', ['Filter', 'Filters']],
            'tamper type'        => ['Barista Hustle - The Tamper | 58.4mm', 'Tamper', ['Espresso Accessories']],
            'water type'         => ['Aquacode - Coffee Brewing Water (1 Gal)', 'Water', ['Water', 'New Gear & Equipment']],
            'milk jug type'      => ['MHW-3BOMBER - 5.0 Milk Pitcher | 600 mL', 'Milk Jug', []],
            'shirt type'         => ['Rogue Wave Coffee Branded T-shirt', 'Shirt', []],
            'sticker type'       => ['Niche Create - Pour Over Stickers', 'Sticker', []],
            'spoon type'         => ['Umeshiso - Cupping Spoon | Gold', 'Spoon', []],
            'drip maker type'    => ['Fellow - Aiden Precision Coffee Maker', 'Drip Coffee Makers', ['Brewer']],
            'espresso accessory' => ['MHW-3BOMBER - Espresso Puck Screen', 'Espresso Accessories', ['Espresso']],
            'grinder accessory'  => ['Urnex - Grindz Grinder Cleaner', 'Coffee Grinder Accessories', []],
            // gear with NO/empty product_type — caught by title vocabulary
            'dripper title'      => ['Clever Dripper', '', []],
            'negotiator title'   => ['Orea - Negotiator Tool for V4', '', ['Brewer']],
            'wdt tool title'     => ['MHW-3BOMBER - Lightning Needle Distribution Tool', '', ['WDT']],
            'cupping bowl title' => ['Barista Hustle - Cupping Bowls Black', '', []],
            'hand grinder title' => ['Comandante - C40 MK4 Hand Grinder', '', []],
            'french press title' => ['Timemore - Little U French Press', '', []],
            'recipe card title'  => ['Espresso Recipe Card', '', ['merch']],
            'alt milk title'     => ['Alternative M*lk - Oat, Almond, Soy', '', []],
            // gear whose ONLY coffee-ish signal is a loose tag substring
            // ("SmartrrFilter:Brewers" used to match the "filter" marker,
            //  "Espresso Accessories" used to match "espresso")
            'gear via filter tag'   => ['Munieq - Tetra Dripper Stainless', 'Brewer', ['SmartrrFilter:Brewers']],
            'gear via espresso tag' => ['MHW-3BOMBER - Silicone Air Blower', 'Scale', ['Espresso Accessories']],
            'gear via gear tag'     => ['Comandante - Travel Black Bag', 'Grinder', ['New Gear & Equipment']],
            // pure cascara (dried cherry husk tisane) — not roasted beans
            'cascara product'    => ['Cascara Dried Coffee Cherry - Peru', 'Coffee', ['Coffee', 'Tea']],
            'cascara no type'    => ['Cascara Dried Coffee Cherry - Nicaragua', '', ['Coffee', 'Tea']],
            // internal / placeholder SKUs
            'secret shop'        => ['Secret shop', 'Coffee', []],
            'test roast'         => ['Coffee Lab: TEST ROAST', 'Coffee', ['Coffee']],
            'roasters club'      => ["Roaster's Club", 'Coffee', []],
        ];
    }

    /**
     * @param array<int, string> $tags
     */
    #[DataProvider('rogueWaveGearCases')]
    public function test_looks_like_coffee_rejects_rogue_wave_gear(string $title, string $type, array $tags): void
    {
        $this->assertFalse(
            Shared::looksLikeCoffee($title, $type, $tags),
            "Expected gear/merch to be rejected: \"{$title}\" [{$type}]"
        );
    }

    /** @return array<string, array{0:string,1:string,2:array<int,string>}> */
    public static function rogueWaveRealCoffeeCases(): array
    {
        // Representative slice of Rogue Wave's actual coffee catalog. None
        // of these may regress when tightening the gear filters. The hard
        // ones: cascara-as-process, "Filter"/"Espresso" tags, and the
        // wave-named house blends that carry no product_type or tags.
        return [
            'single origin colombia'    => ['Colombia - La Divisa | Pink Bourbon Washed', 'Coffee', ['Beans', 'Coffee', 'Espresso', 'Filter']],
            'single origin ethiopia'    => ['Ethiopia - Halo Beriti | Special Prep Natural', 'Coffee', ['Beans', 'Coffee', 'Filter']],
            'panama geisha'             => ['Panama - Carmen Estate Caturra Lot 4', 'Coffee', ['Beans', 'Coffee', 'Espresso']],
            'decaf'                     => ['Colombia - El Vergel Condor Decaf 2026', 'Coffee', ['Beans', 'Coffee']],
            'house blend "Rogue Wave"'  => ['Rogue Wave', '', []],
            'house blend "Surging Wave"'=> ['Surging Wave', '', []],
            'house blend "Gentle Wave"' => ['Gentle Wave', '', []],
            // "cascara" appears as a PROCESS descriptor on a real bean —
            // must NOT be filtered out like the standalone cascara product.
            'cascara-infused real bean' => ['Ethiopia - Idido Cascara Infused | Washed', 'Coffee', ['Beans', 'Coffee', 'Espresso', 'Filter']],
            'cascara co-ferment bean'   => ['Colombia - El Diviso Cascara Co-Ferment | Natural', 'Coffee', ['Beans', 'Coffee']],
            // brew-method words appearing legitimately in coffee context.
            'filter-tagged single origin' => ['Burundi - Kayanza | Washed', 'Filter', ['Single Origin', 'Filter']],
            'espresso-typed blend'      => ['Nocturnal Espresso', 'Espresso', ['Espresso', 'Blend']],
            'whole bean type'           => ['Kenya - Gichuka Factory | Washed', 'Whole Bean', ['Coffee']],
        ];
    }

    /**
     * @param array<int, string> $tags
     */
    #[DataProvider('rogueWaveRealCoffeeCases')]
    public function test_looks_like_coffee_keeps_real_coffee_through_tightened_filters(string $title, string $type, array $tags): void
    {
        $this->assertTrue(
            Shared::looksLikeCoffee($title, $type, $tags),
            "Expected real coffee to be kept: \"{$title}\" [{$type}]"
        );
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

    // ── looksLikeCoffee: tea / tisane title-level rejection ───────────────
    //
    // Tea sometimes ships under generic product_type ('Loose Leaf', '') so
    // the 'tea'/'matcha' product_type exclusion misses it; its titles
    // often contain "Blend" which used to slip past the positive title
    // regex (an oolong "blend" was being indexed as a coffee). This
    // title-level tea-genus vocabulary closes that hole.

    /** @return array<string, array{0:string,1:string,2:array<int,string>}> */
    public static function teaCases(): array
    {
        return [
            // The original bug: tea with non-Tea type + "Blend" in title
            'oolong blend loose leaf' => ['Royal Oolong Tea Blend', 'Loose Leaf', []],
            'oolong no type'          => ['Premium Oolong', '', []],
            'pu-erh tea type'         => ['Aged Pu-Erh 2015', 'Tea', []],
            'pu-erh no type'          => ['Aged Pu-Erh 2015', '', []],
            'puer alt spelling'       => ['Puer Cake', '', []],
            'sencha green tea'        => ['Sencha Green Tea', '', []],
            'gyokuro'                 => ['Gyokuro Premium', '', []],
            'matcha powder'           => ['Ceremonial Matcha Powder', '', []],
            'rooibos blend'           => ['Vanilla Rooibos Blend', 'Loose Leaf', []],
            'yerba mate'              => ['Argentine Yerba Mate', '', []],
            'chai blend'              => ['Spiced Chai Blend', '', []],
            'herbal tea'              => ['Calming Herbal Tea', 'Loose Leaf', []],
            'green tea generic'       => ['Premium Green Tea', '', []],
            'tisane'                  => ['Bedtime Tisane', '', []],
            'loose leaf descriptor'   => ['House Loose Leaf Selection', '', []],
        ];
    }

    /** @param array<int,string> $tags */
    #[DataProvider('teaCases')]
    public function test_looks_like_coffee_rejects_tea(string $title, string $type, array $tags): void
    {
        $this->assertFalse(
            Shared::looksLikeCoffee($title, $type, $tags),
            "Expected tea/tisane to be rejected: \"{$title}\" [{$type}]"
        );
    }

    /** Real coffee with "blend" in title still passes — no-regression. */
    public function test_looks_like_coffee_keeps_real_blends(): void
    {
        $this->assertTrue(Shared::looksLikeCoffee('Mexico Espresso Blend', 'Coffee', ['Blend']));
        $this->assertTrue(Shared::looksLikeCoffee('Roastmap House Blend', '', ['Coffee', 'Blend']));
        $this->assertTrue(Shared::looksLikeCoffee('Decaf Blend Colombia', 'Coffee', []));
    }

    // ── sanitizeUtf8 ──────────────────────────────────────────────────────

    public function test_sanitizeUtf8_preserves_clean_utf8(): void
    {
        $this->assertSame('Café Saint-Henri', Shared::sanitizeUtf8('Café Saint-Henri'));
        $this->assertSame('Yirgacheffe — washed', Shared::sanitizeUtf8('Yirgacheffe — washed'));
        $this->assertSame('', Shared::sanitizeUtf8(''));
        $this->assertNull(Shared::sanitizeUtf8(null));
    }

    public function test_sanitizeUtf8_scrubs_invalid_byte_sequences_so_json_encode_does_not_throw(): void
    {
        // A lone Latin-1 byte (0xE9 = 'é' in Latin-1) is invalid UTF-8.
        // Raw json_encode of the unsanitized string returns false +
        // JSON_ERROR_UTF8 — which is what caused the prod 500 on
        // /api/roasters before this fix.
        $bad = "Cafe\xE9 Espresso";
        $this->assertFalse(json_encode(['name' => $bad]));
        $this->assertSame(JSON_ERROR_UTF8, json_last_error());

        $cleaned = Shared::sanitizeUtf8($bad);
        $this->assertNotNull($cleaned);
        $encoded = json_encode(['name' => $cleaned]);
        $this->assertIsString($encoded, 'json_encode should not fail after sanitize');
        $this->assertStringContainsString('Espresso', $encoded);
    }
}
