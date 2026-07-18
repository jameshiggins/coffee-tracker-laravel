<?php

namespace Tests\Unit;

use App\Services\FrenchToEnglish;
use PHPUnit\Framework\TestCase;

/**
 * The Quebec-roaster dictionary translator. Word-boundary matching and
 * longest-phrase-first ordering are the two behaviors that keep it from
 * mangling text, so both get locked in here.
 */
class FrenchToEnglishTest extends TestCase
{
    public function test_null_and_empty_pass_through(): void
    {
        $this->assertNull(FrenchToEnglish::translate(null));
        $this->assertSame('', FrenchToEnglish::translate(''));
    }

    public function test_english_text_is_untouched(): void
    {
        $this->assertSame(
            'Ethiopia Yirgacheffe, washed process',
            FrenchToEnglish::translate('Ethiopia Yirgacheffe, washed process')
        );
    }

    public function test_longer_phrases_win_over_single_words(): void
    {
        // "café filtre" must translate as one unit, not "coffee filtre".
        $this->assertSame('filter coffee', FrenchToEnglish::translate('café filtre'));
        $this->assertSame('house blend', FrenchToEnglish::translate('mélange maison'));
    }

    public function test_initial_capital_is_preserved(): void
    {
        $this->assertSame('Filter coffee', FrenchToEnglish::translate('Café filtre'));
        $this->assertSame('Decaf', FrenchToEnglish::translate('Décaféiné'));
    }

    public function test_word_boundaries_protect_substrings(): void
    {
        // "café" must not fire inside "cafétéria".
        $this->assertSame('cafétéria', FrenchToEnglish::translate('cafétéria'));
    }

    public function test_tasting_notes_translate_word_by_word(): void
    {
        $this->assertSame(
            'Dark chocolate, cherry, raspberry',
            FrenchToEnglish::translate('Chocolat noir, cerise, framboise')
        );
    }

    public function test_process_vocabulary_translates(): void
    {
        $this->assertSame('washed', FrenchToEnglish::translate('lavé'));
        $this->assertSame('Natural process', FrenchToEnglish::translate('Séchage naturel'));
    }

    public function test_unknown_words_pass_through_inside_translated_text(): void
    {
        // Additive, not destructive: words missing from the dictionary stay.
        $this->assertSame(
            'Coffee du terroir',
            FrenchToEnglish::translate('Café du terroir')
        );
    }
}
