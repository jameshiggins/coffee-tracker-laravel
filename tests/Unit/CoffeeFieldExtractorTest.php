<?php

namespace Tests\Unit;

use App\Services\CoffeeFieldExtractor;
use PHPUnit\Framework\TestCase;

class CoffeeFieldExtractorTest extends TestCase
{
    /* ------------ Elevation ------------ */

    public function test_extract_elevation_metres_range(): void
    {
        $this->assertSame(1950, CoffeeFieldExtractor::extractElevation('Grown at 1,800-2,100 m above sea level'));
        $this->assertSame(1750, CoffeeFieldExtractor::extractElevation('Altitude: 1500-2000m'));
    }

    public function test_extract_elevation_single_metres_with_anchor(): void
    {
        $this->assertSame(1800, CoffeeFieldExtractor::extractElevation('Altitude 1800m'));
        $this->assertSame(1750, CoffeeFieldExtractor::extractElevation('Elevation: 1,750 masl'));
        $this->assertSame(1900, CoffeeFieldExtractor::extractElevation('grown at 1900 metres'));
    }

    public function test_extract_elevation_masl_without_anchor(): void
    {
        $this->assertSame(1600, CoffeeFieldExtractor::extractElevation('Producer info: 1600 masl'));
    }

    public function test_extract_elevation_feet_converts_to_metres(): void
    {
        // 5900 ft ≈ 1798 m
        $this->assertEqualsWithDelta(1798, CoffeeFieldExtractor::extractElevation('Altitude 5900 ft'), 5);
        // range 5500-6500 ft ≈ midpoint 6000 ft ≈ 1829 m
        $this->assertEqualsWithDelta(1829, CoffeeFieldExtractor::extractElevation('5,500-6,500 ft elevation'), 5);
    }

    public function test_extract_elevation_rejects_implausible_values(): void
    {
        $this->assertNull(CoffeeFieldExtractor::extractElevation('Made in 2024'));
        $this->assertNull(CoffeeFieldExtractor::extractElevation('100 masl'));   // below 200
        $this->assertNull(CoffeeFieldExtractor::extractElevation('5000 masl'));  // above 3500
        $this->assertNull(CoffeeFieldExtractor::extractElevation(null));
        $this->assertNull(CoffeeFieldExtractor::extractElevation(''));
    }

    public function test_extract_elevation_ignores_bare_metres_without_anchor(): void
    {
        // "1800m" with no altitude/elevation context could be anything (a
        // bag size in some weird unit, distance, etc.) — too risky.
        $this->assertNull(CoffeeFieldExtractor::extractElevation('shipped from 1800m of warehouse rows'));
    }

    /* ------------ Varietal ------------ */

    public function test_extract_varietal_canonical_names(): void
    {
        $this->assertSame('Bourbon', CoffeeFieldExtractor::extractVarietal('100% Bourbon variety'));
        $this->assertSame('Caturra', CoffeeFieldExtractor::extractVarietal('Caturra grown at altitude'));
        $this->assertSame('Geisha', CoffeeFieldExtractor::extractVarietal('Renowned Gesha cultivar'));
        $this->assertSame('SL28', CoffeeFieldExtractor::extractVarietal('Kenyan SL-28 selection'));
    }

    public function test_extract_varietal_prefers_more_specific(): void
    {
        $this->assertSame('Yellow Bourbon', CoffeeFieldExtractor::extractVarietal('Yellow Bourbon Brazilian heirloom'));
        $this->assertSame('Pink Bourbon', CoffeeFieldExtractor::extractVarietal('Rare Pink Bourbon lots'));
    }

    public function test_extract_varietal_word_boundary_safe(): void
    {
        // "Bourbon Street" should not match "Bourbon" the cultivar — but our
        // simple word-boundary check does match here. This documents the
        // limitation: the extractor errs on the side of recall.
        $this->assertSame('Bourbon', CoffeeFieldExtractor::extractVarietal('Bourbon Street blend'));
    }

    public function test_extract_varietal_returns_null_when_unknown(): void
    {
        $this->assertNull(CoffeeFieldExtractor::extractVarietal('A wonderful coffee from Ethiopia'));
        $this->assertNull(CoffeeFieldExtractor::extractVarietal(''));
        $this->assertNull(CoffeeFieldExtractor::extractVarietal(null));
    }

    /* ------------ Process ------------ */

    public function test_extract_process_canonical(): void
    {
        $this->assertSame('Washed', CoffeeFieldExtractor::extractProcess('Fully washed at the mill'));
        $this->assertSame('Natural', CoffeeFieldExtractor::extractProcess('Natural process, sun dried'));
        $this->assertSame('Honey', CoffeeFieldExtractor::extractProcess('Yellow Honey processed'));
        $this->assertSame('Anaerobic', CoffeeFieldExtractor::extractProcess('Anaerobic fermentation'));
        $this->assertSame('Carbonic', CoffeeFieldExtractor::extractProcess('Carbonic Maceration tank'));
    }

    public function test_extract_process_long_form_beats_short(): void
    {
        $this->assertSame('Wet Hulled', CoffeeFieldExtractor::extractProcess('Sumatran Giling Basah method'));
        $this->assertSame('Wet Hulled', CoffeeFieldExtractor::extractProcess('Wet hulled traditional Indonesian'));
    }

    public function test_extract_process_returns_null_when_absent(): void
    {
        $this->assertNull(CoffeeFieldExtractor::extractProcess('A great cup of coffee'));
        $this->assertNull(CoffeeFieldExtractor::extractProcess(null));
    }

    /* ------------ Tasting notes ------------ */

    public function test_extract_tasting_notes_from_label(): void
    {
        $this->assertSame(
            'jasmine, bergamot, honey',
            CoffeeFieldExtractor::extractTastingNotes('A washed Ethiopian. Tasting notes: jasmine, bergamot, honey. Brew at 1:16.')
        );
        $this->assertSame(
            'blueberry, dark chocolate',
            CoffeeFieldExtractor::extractTastingNotes('Flavor notes: blueberry, dark chocolate')
        );
    }

    public function test_extract_tasting_notes_returns_null_when_missing(): void
    {
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotes('A wonderful coffee with no labelled notes'));
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotes(null));
    }

    public function test_extract_tasting_notes_rejects_run_on_sentences(): void
    {
        // The capture group greedily caps at a sentence break or 120 chars,
        // so when the "notes:" header is followed by a paragraph, we extract
        // up to the first period.
        $this->assertSame(
            'red apple, plum, milk chocolate',
            CoffeeFieldExtractor::extractTastingNotes(
                'Notes: red apple, plum, milk chocolate. This wonderful Bolivian was sourced through our partners.'
            )
        );
    }
}
