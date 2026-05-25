<?php

namespace Tests\Unit\Scraping\Address;

use App\Services\Scraping\Address\ContactPageAddressExtractor;
use App\Services\Scraping\Address\ScrapedAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 2 of the cascade: read free-form contact pages and pull out a
 * Canadian postal-coded address. Anchor on the postal code (the most
 * unambiguous token in a Canadian street address) and grab the surrounding
 * street + city.
 */
class ContactPageAddressExtractorTest extends TestCase
{
    public function test_extracts_from_semantic_address_element(): void
    {
        $html = '<html><body>'
            . '<address>123 Main St<br>Vancouver, BC V6B 1A1</address>'
            . '</body></html>';

        $result = (new ContactPageAddressExtractor())->extract($html);

        $this->assertInstanceOf(ScrapedAddress::class, $result);
        $this->assertSame('website', $result->source);
        $this->assertSame('123 Main St', $result->street_address);
        $this->assertSame('Vancouver', $result->city);
        $this->assertSame('BC', $result->region);
        $this->assertSame('V6B 1A1', $result->postal_code);
    }

    public function test_extracts_from_footer_blob_with_postal_code(): void
    {
        $html = '<html><body>'
            . '<footer>Visit us at 456 King St W, Toronto, ON M5V 1L4 — open daily.</footer>'
            . '</body></html>';

        $result = (new ContactPageAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('M5V 1L4', $result->postal_code);
        $this->assertStringContainsString('456 King St', $result->street_address);
        $this->assertSame('Toronto', $result->city);
        $this->assertSame('ON', $result->region);
    }

    public function test_handles_postal_code_without_space(): void
    {
        // Some sites write the postal code as "V6B1A1" without the conventional
        // single space — accept either form.
        $html = '<html><body><p>789 Pine Rd, Calgary, AB T2P1J9</p></body></html>';

        $result = (new ContactPageAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('T2P 1J9', $result->postal_code);
        $this->assertSame('Calgary', $result->city);
    }

    public function test_returns_null_when_no_canadian_postal_code(): void
    {
        $html = '<html><body><p>123 Main St, Vancouver, BC. Email us!</p></body></html>';
        $this->assertNull((new ContactPageAddressExtractor())->extract($html));
    }

    public function test_returns_null_when_street_lacks_digit(): void
    {
        // A blob that happens to contain a postal code but no actual street
        // number doesn't count as a verified address. (Examples like a
        // mailing-list footer that only carries "Vancouver, BC V6B 1A1".)
        $html = '<html><body><p>Vancouver, BC V6B 1A1</p></body></html>';
        $this->assertNull((new ContactPageAddressExtractor())->extract($html));
    }

    public function test_prefers_address_element_over_footer_blob(): void
    {
        $html = '<html><body>'
            . '<address>10 First Ave<br>Halifax, NS B3J 1A1</address>'
            . '<footer>Mail-only: PO Box 5000, Saint John, NB E2L 4L5</footer>'
            . '</body></html>';

        $result = (new ContactPageAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('10 First Ave', $result->street_address);
        $this->assertSame('Halifax', $result->city);
    }

    public function test_handles_lowercase_postal_code(): void
    {
        $html = '<html><body><p>500 Granville St, Vancouver, BC v6c 1w6</p></body></html>';

        $result = (new ContactPageAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        // Canonical form: uppercased with one space.
        $this->assertSame('V6C 1W6', $result->postal_code);
    }

    public function test_skips_postal_code_lookalikes_in_unrelated_text(): void
    {
        // "A1B 2C3" patterns can appear in inventory codes, etc. We accept
        // anything that matches the regex; this test just confirms we do find
        // the real postal code rather than an earlier false-positive when
        // BOTH a real address blob and a code-looking SKU exist.
        $html = '<html><body>'
            . '<p>SKU N1N 1N1 for stock</p>'
            . '<p>1000 Oak St, Winnipeg, MB R3C 0L7</p>'
            . '</body></html>';

        $result = (new ContactPageAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        // The first regex hit IS the SKU pattern. The implementation should
        // anchor on a postal code that lives in a blob with surrounding
        // street/city context, not just take the literal first match.
        $this->assertSame('R3C 0L7', $result->postal_code);
        $this->assertSame('Winnipeg', $result->city);
    }

    // ── Regression: real-world false positives from prod ──────────────────
    //
    // When the cascade ran against 10 Vancouver roasters' contact pages, the
    // extractor returned garbage "addresses" with address_source=website set
    // (so the cascade thought it succeeded). Two root causes:
    //
    //  (1) The postal regex /[A-Z]\d[A-Z] \d[A-Z]\d/i matched hex color
    //      codes embedded in CSS (#F3F3F3 → "F3F 3F3" when split by
    //      whitespace). Real Canada Post codes per spec EXCLUDE D F I O Q U
    //      from positions 1, 3, 5 — and W Z from position 1 as well — so
    //      F-starting (or D/W/etc.) codes are never valid postals.
    //
    //  (2) The street sanity check ("must contain a digit") accepted CSS
    //      variable strings like "--color-badge-border: 18, 18, 18" — has
    //      a digit, but obviously not an address.
    //
    // These inputs are minified-but-faithful snippets of what the prod
    // pages actually served. Each must yield null after the fix.

    /** @return array<string, array{0:string}> */
    public static function realWorldFalsePositiveCases(): array
    {
        return [
            // F-starting hex codes — never valid Canadian postals per spec.
            'css hex F3F3F3 from CSS variable' =>
                ['<style>--color-badge-border: 18, 18, 18; F3F 3F3</style>'],
            'css hex F6F6F6 in color rule' =>
                ['<style>.bg { color: F6F 6F6; }</style>'],
            'css hex F8F8F8 in CSS variable' =>
                ['<style>--body-color-transparent00: F8F 8F8;</style>'],
            'css hex F5F5F5 with opacity rule' =>
                ['<style>opacity: 75%; bg: F5F 5F5;</style>'],
            // D/W also excluded from position 1.
            'css hex D5D9D8 (D not valid pos1)' =>
                ['<style>--ring: D5D 9D8;</style>'],
            'minified token W3W3W3 (W not valid pos1)' =>
                ['<div>W3W 3W3 minified</div>'],
            // C-starting hex IS pos-1-valid per spec, but the surrounding
            // text is unambiguously JSON, not prose. Existing "no digit in
            // street" guard already rejects this — defensive coverage.
            'shopify section JSON with C7C7C7 token' =>
                ['<script>{"ton_align":"fullBtn","button_cl":"primary","var":"C7C 7C7"}</script>'],
            // Hardest case: B IS valid pos1, surrounding text is CSS that
            // DOES contain digits. Requires both fixes (or the CSS-marker
            // street reject) to catch.
            'css text near regex-valid postal-shape (B0B 1A1)' =>
                ['<style>--color-badge-border: 18, 18, 18; B0B 1A1</style>'],
        ];
    }

    #[DataProvider('realWorldFalsePositiveCases')]
    public function test_rejects_real_world_false_positives_from_prod(string $html): void
    {
        $this->assertNull(
            (new ContactPageAddressExtractor())->extract($html),
            'Expected extractor to reject CSS/JSON masquerading as an address'
        );
    }
}
