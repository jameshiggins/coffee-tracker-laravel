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

    public function test_extract_varietal_prefers_specific_over_country_ambiguous(): void
    {
        // Continuum's "El Obraje Geisha - Colombia *Special Release*" was
        // returning varietal=Colombia because Colombia (a real but rare
        // varietal) appeared in the array before Geisha. The new specific-
        // vs-ambiguous split makes Geisha win.
        $this->assertSame('Geisha', CoffeeFieldExtractor::extractVarietal('El Obraje Geisha - Colombia *Special Release*'));
        // A string with only a country marker for Colombia and no other
        // varietal info must NOT return Colombia as the varietal.
        $this->assertNull(CoffeeFieldExtractor::extractVarietal('Single Origin - Colombia'));
        $this->assertNull(CoffeeFieldExtractor::extractVarietal('Sourced from Colombia'));
    }

    public function test_extract_varietal_still_accepts_colombia_when_unambiguous(): void
    {
        // "Colombia" as a bare varietal name with no country-context
        // separator nearby — accept. This preserves the rare-but-real case.
        $this->assertSame('Colombia', CoffeeFieldExtractor::extractVarietal('Colombia variety hybrid blend'));
    }

    public function test_extract_varietal_rejects_java_as_origin(): void
    {
        // Java is the canonical origin name; only accept as varietal when
        // it appears without country/region context.
        $this->assertNull(CoffeeFieldExtractor::extractVarietal('Premium - Java'));
        $this->assertNull(CoffeeFieldExtractor::extractVarietal('From Java, Indonesia'));
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

    public function test_extract_tasting_notes_normalizes_bullet_separators(): void
    {
        // Agro-style: roasters write notes bullet-delimited in the body
        // ("Notes: Golden berry • Jasmine • Pear"). These were previously
        // rejected (one 4-word token, avgWords > 3.5) and never stored.
        $this->assertSame(
            'Golden berry, Jasmine, Pear',
            CoffeeFieldExtractor::extractTastingNotes('Notes: Golden berry • Jasmine • Pear')
        );
        $this->assertSame(
            'Golden berry, Jasmine, Pear',
            CoffeeFieldExtractor::extractTastingNotes(
                'This coffee is part of our seasonal lineup. Notes: Golden berry • Jasmine • Pear'
            )
        );
    }

    public function test_extract_tasting_notes_handles_slash_separators(): void
    {
        // Slashes are allowed inside the capture (pipes are not — those mark a
        // field boundary), so a slash-delimited list normalizes to commas.
        $this->assertSame(
            'cherry, cocoa, almond',
            CoffeeFieldExtractor::extractTastingNotes('Tasting notes: cherry / cocoa / almond')
        );
    }

    public function test_looks_like_tasting_note_list_accepts_bullet_and_slash_lists(): void
    {
        $this->assertTrue(CoffeeFieldExtractor::looksLikeTastingNoteList('Golden berry • Jasmine • Pear'));
        $this->assertTrue(CoffeeFieldExtractor::looksLikeTastingNoteList('cherry / cocoa / almond'));
        // A genuine run-on sentence (one long token) is still rejected.
        $this->assertFalse(CoffeeFieldExtractor::looksLikeTastingNoteList('a smooth balanced cup for every morning of the week'));
    }

    /* ------------ Tasting notes: wider label + heading coverage ------------ */

    /** @dataProvider labelledNoteProvider */
    public function test_extract_tasting_notes_recognizes_more_labels(string $text, string $expected): void
    {
        $this->assertSame($expected, CoffeeFieldExtractor::extractTastingNotes($text));
    }

    public static function labelledNoteProvider(): array
    {
        return [
            'bare flavour label' => ['Flavour: milk chocolate, hazelnut, orange', 'milk chocolate, hazelnut, orange'],
            'flavor (US) label' => ['Flavor - cherry, cola', 'cherry, cola'],
            'taste label' => ['Taste: red apple / caramel / black tea', 'red apple, caramel, black tea'],
            'flavour profile' => ['Flavour Profile: strawberry, cream, honey', 'strawberry, cream, honey'],
            'cupping notes' => ['Cupping notes: plum, brown sugar', 'plum, brown sugar'],
            'in the cup' => ['In the cup: blueberry, jasmine and honey', 'blueberry, jasmine, honey'],
            'hints of' => ['A juicy lot with hints of peach, apricot and vanilla.', 'peach, apricot, vanilla'],
            'tastes like' => ['Tastes like: cherry pie, cola', 'cherry pie, cola'],
            'we get' => ['We get raspberry, dark chocolate and cedar in this one.', 'raspberry, dark chocolate, cedar'],
            'expect notes of' => ['Expect notes of lime, honey and black tea.', 'lime, honey, black tea'],
            'and split' => ['Notes: chocolate, caramel and citrus. Origin: Ethiopia', 'chocolate, caramel, citrus'],
            'ampersand split' => ['Tasting notes: cherry & cola', 'cherry, cola'],
            'en dash separator' => ['Tasting Notes – Blueberry, Jasmine, Honey', 'Blueberry, Jasmine, Honey'],
        ];
    }

    public function test_extract_tasting_notes_reads_headings_flattened_without_punctuation(): void
    {
        // <h4>Tasting Notes</h4><p>Cherry, Cola, Brown Sugar</p><h4>Roast</h4><p>Light</p>
        // becomes this once block tags are replaced with spaces.
        $this->assertSame(
            'Cherry, Cola, Brown Sugar',
            CoffeeFieldExtractor::extractTastingNotes('Tasting Notes Cherry, Cola, Brown Sugar Roast Light Process Washed Origin Ethiopia')
        );
    }

    public function test_extract_tasting_notes_cuts_at_the_next_spec_label(): void
    {
        // Previously the whole capture ("… Roast Level Medium") tripped the
        // farming-language gate and the notes were lost.
        $this->assertSame(
            'Milk chocolate, Hazelnut',
            CoffeeFieldExtractor::extractTastingNotes('Notes: Milk chocolate, Hazelnut Roast Level: Medium Process: Washed')
        );
    }

    public function test_extract_tasting_notes_heading_without_list_is_not_trusted(): void
    {
        // A prose sentence that happens to start with a label word must not
        // become tasting notes just because the heading regex has no colon.
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotes('Taste this coffee with an open mind on a quiet morning'));
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotes('Notes This lot was grown by the Kebede family at 2100 masl'));
    }

    public function test_extract_tasting_notes_ignores_footnotes_and_keynotes(): void
    {
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotes('Footnotes: see our shipping policy'));
    }

    public function test_extract_tasting_notes_skips_a_rejected_first_match_for_a_later_good_one(): void
    {
        // "Notes:" prose first, then a real list further down the body.
        $this->assertSame(
            'plum, cocoa',
            CoffeeFieldExtractor::extractTastingNotes('Notes: grown on the family farm at high altitude. Tasting notes: plum, cocoa')
        );
    }

    /* ------------ Tasting notes from the title ------------ */

    /** @dataProvider titleNoteProvider */
    public function test_extract_tasting_notes_from_title(string $title, ?string $expected): void
    {
        $this->assertSame($expected, CoffeeFieldExtractor::extractTastingNotesFromTitle($title));
    }

    public static function titleNoteProvider(): array
    {
        return [
            'en dash list' => ['Ethiopia Guji – Blueberry, Jasmine, Honey', 'Blueberry, Jasmine, Honey'],
            'pipe + bullets' => ['Colombia Huila | Caramel · Red Apple · Cola', 'Caramel, Red Apple, Cola'],
            'hyphen with and' => ['Kenya Kiambu - Blackcurrant and Grapefruit', 'Blackcurrant, Grapefruit'],
            'last segment wins' => ['Brazil - Natural - Milk Chocolate, Peanut', 'Milk Chocolate, Peanut'],
            'origins are not flavours' => ['House Blend - Brazil, Colombia', null],
            'sizes are not flavours' => ['Kenya AA - 250g, 1kg', null],
            'no separator in segment' => ['Ethiopia Guji - Washed', null],
            'no title separator' => ['Blueberry Jasmine Honey', null],
            'process words rejected by gate' => ['Peru - Washed, Natural', null],
            'null' => ['', null],
        ];
    }

    /* ------------ Tasting notes from tags ------------ */

    public function test_extract_tasting_notes_from_tags_keeps_only_flavour_words(): void
    {
        $this->assertSame(
            'Chocolate, Stone Fruit, Floral',
            CoffeeFieldExtractor::extractTastingNotesFromTags(['Single Origin', 'Ethiopia', 'Chocolate', 'Light Roast', 'Stone Fruit', 'Floral', 'coffee'])
        );
    }

    public function test_extract_tasting_notes_from_tags_strips_label_prefixes_and_dedupes(): void
    {
        $this->assertSame(
            'Cherry, Cola',
            CoffeeFieldExtractor::extractTastingNotesFromTags(['notes:Cherry', 'Flavour - Cola', 'cherry'])
        );
    }

    public function test_extract_tasting_notes_from_tags_requires_two_flavour_words(): void
    {
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotesFromTags(['Single Origin', 'Sweet']));
        $this->assertNull(CoffeeFieldExtractor::extractTastingNotesFromTags([]));
    }

    public function test_has_known_flavour_matches_on_head_noun(): void
    {
        $this->assertTrue(CoffeeFieldExtractor::hasKnownFlavour('candied orange, something'));
        $this->assertTrue(CoffeeFieldExtractor::hasKnownFlavour('Black Tea'));
        $this->assertFalse(CoffeeFieldExtractor::hasKnownFlavour('Brazil, Colombia'));
        $this->assertFalse(CoffeeFieldExtractor::hasKnownFlavour(''));
    }

    public function test_normalize_note_separators(): void
    {
        $this->assertSame('Golden berry, Jasmine, Pear', CoffeeFieldExtractor::normalizeNoteSeparators('Golden berry • Jasmine • Pear'));
        $this->assertSame('cherry, cocoa, almond', CoffeeFieldExtractor::normalizeNoteSeparators('cherry | cocoa | almond'));
        $this->assertSame('cherry, cocoa, almond', CoffeeFieldExtractor::normalizeNoteSeparators('cherry / cocoa / almond'));
        // Already comma-separated passes through unchanged.
        $this->assertSame('jasmine, bergamot, honey', CoffeeFieldExtractor::normalizeNoteSeparators('jasmine, bergamot, honey'));
        // Mixed / duplicated separators collapse to a single ", ".
        $this->assertSame('a, b, c', CoffeeFieldExtractor::normalizeNoteSeparators('a • b, c'));
    }
}
