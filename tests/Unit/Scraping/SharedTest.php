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

    public function test_dedupe_prefers_the_whole_bean_variant_among_same_size_grinds(): void
    {
        // The Agro pattern: one variant per grind at the same bag size, whole
        // bean FIRST, French Press LAST. The old last-wins dedupe shipped the
        // French Press deep link to a whole-bean buyer — a real prod incident.
        $variants = [
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'variant_title' => '340g / Wholebean', 'tag' => 'WB'],
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'variant_title' => '340g / Espresso (No Refund/Exchange)', 'tag' => 'ESP'],
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'variant_title' => '340g / French Press (No Refund/Exchange)', 'tag' => 'FP'],
        ];
        $result = Shared::dedupeVariantsByGrams($variants);
        $this->assertCount(1, $result);
        $this->assertSame('WB', $result[0]['tag'], 'whole bean must win regardless of feed order');

        // Also when whole bean is NOT first ("Whole Bean" spelled with a space).
        $variants = [
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'variant_title' => '340g / Drip', 'tag' => 'DRIP'],
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'variant_title' => '340g / Whole Bean', 'tag' => 'WB'],
        ];
        $this->assertSame('WB', Shared::dedupeVariantsByGrams($variants)[0]['tag']);
    }

    public function test_dedupe_without_grind_titles_keeps_the_first_variant(): void
    {
        // No whole-bean signal anywhere → ties resolve to the FIRST seen
        // (shops list their default option first), not the old last-wins.
        $variants = [
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'tag' => 'FIRST'],
            ['grams' => 340, 'available' => true, 'price' => 24.0, 'tag' => 'SECOND'],
        ];
        $this->assertSame('FIRST', Shared::dedupeVariantsByGrams($variants)[0]['tag']);
    }

    // ── looksLikeCoffee ───────────────────────────────────────────────────

    public function test_rejects_tea_sold_with_innocent_titles_via_steeping_spec_description(): void
    {
        // Real prod leak (Anchored): entire tea line with EMPTY product_type
        // and tags, titles that carry zero tea signal — the body is the only
        // tell. Descriptions below are the live shop's actual copy.
        $chamomile = 'Soft florals, subtly sweet, and a calming finish. Ingredients Organic Chamomile '
            . 'Specifications Serving Size : 2.5g /cup Steeping Temp : 100°C Steeping Time : 5 mins. '
            . 'Origin : France / Albania / Croatia Caffeine : Caffeine Free Infusion Aroma : Floral';
        $jadeCloud = 'Fresh mountain mist with a sweet umami finish. Ingredients Organic Green Tea (Camelia Sinensis) '
            . 'Specifications Serving Size : 2.5g /cup Steeping Temp : 88°C Steeping Time: 1 to 2 mins.';
        $earlGrey = 'Bold flavours, bright petals, and a hit of bergamot. Ingredients Black tea (Camelia Sinensis), '
            . 'cornflower petals Specifications Steeping Temp : 100°C Steeping Time : 2 to 5 mins.';

        $this->assertFalse(Shared::looksLikeCoffee('Chamomile', '', [], $chamomile));
        $this->assertFalse(Shared::looksLikeCoffee('Jade Cloud', '', [], $jadeCloud));
        $this->assertFalse(Shared::looksLikeCoffee('Earl Grey', '', [], $earlGrey));
        $this->assertFalse(Shared::looksLikeCoffee('Irish Breakfast', '', [], $jadeCloud));
    }

    public function test_keeps_coffee_that_cites_tea_as_a_tasting_note(): void
    {
        // "black tea" as a flavour descriptor is everywhere in coffee copy
        // (Luna/Monogram pattern) — it must NOT trip the tea rejection.
        $desc = 'A washed Gesha from Alasitas. Notes of black tea, bergamot and honeysuckle. '
            . 'Medium-light roast, best as filter.';
        $this->assertTrue(Shared::looksLikeCoffee('Takesi Gesha', '', [], $desc));
    }

    public function test_keeps_coffee_whose_brew_guide_says_steep(): void
    {
        // French-press/AeroPress brew guides use "steep" — the coffee
        // counter-signals (coffee/roast) must keep these in.
        $desc = 'Our house espresso. Brew guide: add coffee, pour water, steep for 4 minutes, press. '
            . 'Roasted for sweetness.';
        $this->assertTrue(Shared::looksLikeCoffee('Nightcap', '', [], $desc));
    }

    public function test_description_check_is_inert_when_no_description_is_passed(): void
    {
        // Back-compat: all existing call sites that pass no description keep
        // their exact previous behavior.
        $this->assertTrue(Shared::looksLikeCoffee('Chamomile', '', []));
    }

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

    // ── looksLikeCoffee: field-confirmed junk regression ─────────────────
    //
    // These EXACT titles were found imported-as-coffee in the production
    // database (they slipped in before the gear filter was tightened, e.g.
    // Ace's "Espro French Press" whose 18oz/32oz sizes parseGrams read as
    // 510g/907g "bags" at a $150-190 price that sat inside the per-gram sanity
    // band). They must stay rejected forever — even with a 'Coffee'
    // product_type, which is the worst case (a positive type signal present).

    /** @return array<string, array{0:string,1:string,2:array<int,string>}> */
    public static function fieldConfirmedJunkCases(): array
    {
        return [
            'espro french press (stored)' => ['Espro French Press', 'Coffee', ['coffee']],
            'espro french press (raw)'    => ['Espro P7 18oz French Press', 'Coffee', []],
            'mugs case'                   => ['Created Co 12oz White Mugs Case of 6', 'Coffee', []],
            'etched milk pitcher'         => ['Revolution Graduated & Etched Milk Pitcher', 'Coffee', []],
            'breville milk pitcher'       => ['Breville - the Temp Control Milk Pitcher', 'Coffee', []],
            'honey product'               => ['Drizzle Honey', 'Coffee', []],
            'internal test sku'           => ['DO NOT BUY [TEST]', 'Coffee', []],
            // Prototype's storage-vessel refill: $19 "100g tin refill for top
            // tier coffees … No jar purchase necessary" — a container refill,
            // not a bean. Empty product_type (Squarespace), no gear token in the
            // title, so it read as coffee until the vacuum-pack/refill reject.
            'vacuum pack / jar refill'    => ['100g Vacuum Pack/Jar Refill', '', []],
            'tin refill'                  => ['Coffee Tin Refill', 'Coffee', []],
            'refill for canister'         => ['Refill for Canister', '', []],
        ];
    }

    /**
     * The vacuum-pack/refill reject must be surgical: a bare "jar" in a real
     * coffee's name stays coffee. Rosso's "Jam Jar / Ethiopia" is the live case
     * that a blanket "jar" reject would wrongly purge.
     */
    public function test_looks_like_coffee_keeps_coffee_named_jam_jar(): void
    {
        $this->assertTrue(Shared::looksLikeCoffee('Jam Jar / Ethiopia', 'Coffee', []));
        $this->assertTrue(Shared::looksLikeCoffee('Jam Jar', '', ['coffee']));
    }

    /**
     * @param array<int, string> $tags
     */
    #[DataProvider('fieldConfirmedJunkCases')]
    public function test_looks_like_coffee_rejects_field_confirmed_junk(string $title, string $type, array $tags): void
    {
        $this->assertFalse(
            Shared::looksLikeCoffee($title, $type, $tags),
            "Field-confirmed junk must be rejected: \"{$title}\" [{$type}]"
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

    // ── looksLikeCoffee: roast-level + region tag taxonomies ──────────────
    //
    // Some roaster sites (Oso Negro is the canonical case) use a minimal
    // taxonomy where coffees are tagged only by roast level (Dark, Medium,
    // Light, Very Dark) and growing region (Africa, Americas, Indonesia)
    // — no explicit "Coffee" tag, no "coffee" in the title. The old
    // positive-marker list missed every one of these and rejected ~15 of
    // their 17 coffees, leaving the roaster with a single stale entry.
    // These tags are unambiguous in the context of a roaster's catalog
    // because gear/merch is already excluded upstream by product_type.

    public function test_looks_like_coffee_accepts_roast_level_tag(): void
    {
        $this->assertTrue(Shared::looksLikeCoffee('Campfire', '', ['Dark']));
        $this->assertTrue(Shared::looksLikeCoffee('Speckled Sky', '', ['Medium']));
        $this->assertTrue(Shared::looksLikeCoffee('The Mudshark', '', ['Very Dark']));
        $this->assertTrue(Shared::looksLikeCoffee('Some Bean', '', ['Light']));
    }

    public function test_looks_like_coffee_accepts_growing_region_tag(): void
    {
        $this->assertTrue(Shared::looksLikeCoffee('Selkirk', '', ['Africa', 'Americas', 'Indonesia']));
        $this->assertTrue(Shared::looksLikeCoffee('Full Original', '', ['Americas', 'Indonesia']));
    }

    public function test_looks_like_coffee_accepts_combined_oso_negro_style_tags(): void
    {
        // Real shape of Oso Negro's coffee categories — no "Coffee" tag,
        // no "coffee" in name, just region+roast.
        $this->assertTrue(Shared::looksLikeCoffee('Campfire', '', ['Africa', 'Americas', 'Dark', 'Indonesia', 'Medium']));
        $this->assertTrue(Shared::looksLikeCoffee('Messy Room', '', ['Africa', 'Americas', 'Dark', 'Indonesia']));
        $this->assertTrue(Shared::looksLikeCoffee('Meteor', '', ['Americas', 'Dark', 'Indonesia']));
    }

    public function test_roast_level_tags_do_not_override_negative_product_type(): void
    {
        // A hoodie tagged Africa or Dark must still be rejected — the
        // Merchandise product_type fires negative checks before the
        // positive-tag fallback can ever run.
        $this->assertFalse(Shared::looksLikeCoffee('Black Hoodie', 'Merchandise', ['Dark']));
        $this->assertFalse(Shared::looksLikeCoffee('Africa-Print Tee', 'Apparel', ['Africa']));
    }

    // ── looksLikeCoffee: compound coffee tags (c637b63 regression) ────────
    //
    // c637b63 tightened the positive coffee-tag match from substring to
    // exact in_array() to stop "filter" matching "smartrrfilter:brewers".
    // It over-corrected: a roaster that tags beans with a COMPOUND phrase
    // ("Whole Bean Coffee", "Single Origin Coffee", "Single-Origin Coffee
    // Beans") AND uses bare-origin product titles ("Ethiopia Yirgacheffe",
    // no "coffee"/"blend" in the name, no coffee product_type) suddenly
    // lost every product — the whole-tag string equals none of the bare
    // keywords. This is the single biggest driver of "bean counts look too
    // low". The fix restores a word-boundary noun match while keeping the
    // gear-tag exclusions that run first.

    /** @return array<string, array{0:string,1:string,2:array<int,string>}> */
    public static function compoundCoffeeTagCases(): array
    {
        return [
            'whole bean coffee tag'        => ['Ethiopia Yirgacheffe', '', ['Whole Bean Coffee']],
            'single origin coffee tag'     => ['Colombia Huila', '', ['Single Origin Coffee']],
            'single-origin coffee beans'   => ['Guatemala Antigua', '', ['Single-Origin Coffee Beans']],
            'roasted coffee tag'           => ['Kenya Nyeri AA', '', ['Roasted Coffee']],
            'roasted coffee beans tag'     => ['Peru Cajamarca', '', ['Roasted Coffee Beans']],
            'decaf coffee tag'             => ['Brazil Cerrado', '', ['Decaf Coffee']],
            'compound among region tags'   => ['Sumatra Mandheling', '', ['Indonesia', 'Whole Bean Coffee']],
            // Qualified "<kind> Blends" categories — Midnight Sun's house
            // coffees carry ONLY a "Signature Blends" category and a fanciful
            // name with no coffee word ("Klondike Gold").
            'signature blends category'    => ['Klondike Gold', 'Signature Blends', ['Signature Blends']],
            'house blends category'        => ['Midnight Oil', '', ['House Blends']],
            // Bare "Full City" roast-level category, fanciful-free title.
            'full city roast category'     => ['Colombian', 'Full City', ['Full City']],
        ];
    }

    /** @param array<int,string> $tags */
    #[DataProvider('compoundCoffeeTagCases')]
    public function test_looks_like_coffee_accepts_compound_coffee_tags(string $title, string $type, array $tags): void
    {
        $this->assertTrue(
            Shared::looksLikeCoffee($title, $type, $tags),
            "Expected compound coffee tag to be accepted: \"{$title}\" tags=" . implode(',', $tags)
        );
    }

    public function test_compound_coffee_noun_match_does_not_override_gear(): void
    {
        // Word-boundary noun match must NOT resurrect gear. These all have a
        // "coffee"-containing tag but are rejected earlier: a gear
        // product_type (Brewer) or the "coffee accessor" gear-tag pattern,
        // both of which run before the positive noun fallback.
        $this->assertFalse(Shared::looksLikeCoffee('Munieq Tetra Dripper', 'Brewer', ['Coffee Brewing Equipment']));
        $this->assertFalse(Shared::looksLikeCoffee('Grindz Cleaner', '', ['Coffee Accessories']));
        // "filter" inside "smartrrfilter:brewers" is not a word boundary, so
        // the noun fallback can't match it (and there's no coffee noun here).
        $this->assertFalse(Shared::looksLikeCoffee('Tetra Dripper Stainless', 'Brewer', ['SmartrrFilter:Brewers']));
    }

    public function test_qualified_blends_tag_does_not_admit_tea_blends(): void
    {
        // The "<kind> Blends" positive is deliberately qualified — a bare
        // `blends?` would have let tea sold under a "Tea Blends" / "Herbal
        // Blends" category through whenever the title carries no tea word.
        $this->assertFalse(Shared::looksLikeCoffee('Vanilla Dream', 'Tea Blends', ['Tea Blends']));
        $this->assertFalse(Shared::looksLikeCoffee('Evening Calm', '', ['Herbal Blends']));
    }

    // ── looksLikeCoffee: "chocolate" as flavor descriptor vs product ──────
    //
    // The bare 'chocolates?' title-level reject was too broad — it nuked
    // real coffees whose tasting-note vocabulary names them after the
    // flavour. Oso Negro's "Chocolate Cake" is the canonical case; other
    // roasters have "Chocolate Cherry Bomb", "Cookies & Chocolate", etc.
    // The replacement must:
    //   - Pass coffees with "chocolate" used as a flavor descriptor
    //   - Still reject obvious chocolate products (bars, truffles,
    //     drinking chocolate, hot cocoa)

    public function test_looks_like_coffee_accepts_chocolate_as_flavor_descriptor(): void
    {
        // Real Oso Negro coffee — fails today, must pass post-fix.
        $this->assertTrue(Shared::looksLikeCoffee('Chocolate Cake', '', ['Africa', 'Americas', 'Dark']));
        // Other flavor-descriptor patterns seen on roaster sites.
        $this->assertTrue(Shared::looksLikeCoffee('Chocolate Cherry Bomb', 'Coffee', []));
        $this->assertTrue(Shared::looksLikeCoffee('Cookies & Chocolate Blend', 'Coffee', []));
        $this->assertTrue(Shared::looksLikeCoffee('Chocolate Hazelnut Espresso', 'Coffee', []));
    }

    public function test_looks_like_coffee_rejects_honey_jar_products(): void
    {
        // Rosso Coffee sells "Drizzle Honey" — actual raw honey, not coffee.
        // The user spotted it in the directory. These patterns reject
        // honey-as-product without false-positiving on coffee names that
        // legitimately include "honey" as a flavor descriptor or process.
        $this->assertFalse(Shared::looksLikeCoffee('Drizzle Honey', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Raw Honey', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Wildflower Honey', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Pure Honey', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Liquid Honey', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Honey Jar', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Honey Sticks', '', []));
    }

    public function test_looks_like_coffee_still_keeps_honey_processed_coffees(): void
    {
        // "Honey" in the title as a PROCESS tag or part of a coffee name
        // must NOT be rejected. Rosso's "Honey, Hunny / Guatemala" is a
        // real coffee; "Red Honey" / "Black Honey" describe process.
        $this->assertTrue(Shared::looksLikeCoffee('Honey, Hunny / Guatemala', '', ['beans']));
        $this->assertTrue(Shared::looksLikeCoffee('Ponderosa Red Honey MS Geisha 100g', '', []));
        $this->assertTrue(Shared::looksLikeCoffee('Janson Honey Geisha Lot 623', '', []));
        $this->assertTrue(Shared::looksLikeCoffee('Lerida Honey Geisha Lot 5', '', []));
    }

    public function test_looks_like_coffee_still_rejects_actual_chocolate_products(): void
    {
        // Bars / truffles / discs — actual chocolate confectionery.
        $this->assertFalse(Shared::looksLikeCoffee('Oso Negro Coffee Chocolate Bar', 'Chocolate', []));
        $this->assertFalse(Shared::looksLikeCoffee('Dark Chocolate Truffles', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('70% Chocolate Squares', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Milk Chocolate Buttons', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Chocolate Medallions', '', []));
        // Drinking chocolate / hot cocoa — beverage powders.
        $this->assertFalse(Shared::looksLikeCoffee('Drinking Chocolate', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Hot Cocoa Mix', '', []));
    }

    // ── looksLikeCoffee: origin-named coffees (Prototype / Squarespace) ───
    //
    // Specialty roasters routinely title coffees purely by farm / region /
    // country with no "coffee" word and no coffee tag. When such a product
    // ALSO carries a non-coffee category (Squarespace quality tiers like
    // "Top Tier"), the no-tags default-accept is suppressed and the coffee
    // used to be silently dropped. An origin-country signal rescues them —
    // Prototype was showing ZERO coffees because of exactly this.

    public function test_looks_like_coffee_accepts_origin_named_coffee_with_noncoffee_category(): void
    {
        $this->assertTrue(Shared::looksLikeCoffee('Bohemia (Washed Gesha), Colombia', '', ['Top Tier']));
        $this->assertTrue(Shared::looksLikeCoffee('Gatugi, Kenya', '', ['Top Tier']));
        $this->assertTrue(Shared::looksLikeCoffee('La Colonia, Mexico', '', ['Single Origin']));
        // PNG newly added to the gazetteer — a frequent country-only title.
        $this->assertTrue(Shared::looksLikeCoffee('Kagamugu (Natural), Papua New Guinea', '', ['Top Tier']));
    }

    public function test_origin_signal_does_not_override_gear_or_merch_negatives(): void
    {
        // The origin signal runs AFTER every negative check, so a country word
        // in a gear / merch title can't resurrect it.
        $this->assertFalse(Shared::looksLikeCoffee('Colombia Coffee Mug', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Ethiopia Map Tote Bag', '', []));
        $this->assertFalse(Shared::looksLikeCoffee('Kenya Roaster Hoodie', 'Apparel', []));
        // No origin word + a non-coffee category → still false. The signal
        // adds recall; it does not blanket-accept anything with a category.
        $this->assertFalse(Shared::looksLikeCoffee('Mystery Item', '', ['Top Tier']));
    }

    // ── parseBodyGrams (shared description size fallback) ──────────────────

    public function test_parse_body_grams_accepts_standard_sizes(): void
    {
        $this->assertSame(100, Shared::parseBodyGrams('100g. Tasting Notes: Earl Grey, Pear.'));
        $this->assertSame(250, Shared::parseBodyGrams('250g. Tasting Notes: Blueberry.'));
        $this->assertSame(340, Shared::parseBodyGrams('Bag size: 340 g.'));
        $this->assertSame(1000, Shared::parseBodyGrams('Available in 1kg.'));
    }

    public function test_parse_body_grams_rejects_incidental_numbers(): void
    {
        // Altitude / brew ratio / steep time must not become a bag weight —
        // the standard-size whitelist is the guard.
        $this->assertNull(Shared::parseBodyGrams('Altitude: 1600 MASL. Brew 18g in, 36g out.'));
        $this->assertNull(Shared::parseBodyGrams('Grown at 1,800 m. Steep for 4 minutes.'));
        $this->assertNull(Shared::parseBodyGrams(''));
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
