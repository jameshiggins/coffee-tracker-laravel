<?php

namespace App\Services\Scraping\Address;

/**
 * Step 2 of the cascade. When a roaster's site has no JSON-LD LocalBusiness
 * block we fall back to the rendered HTML of a contact / visit / locations
 * page. Strategy:
 *
 *   1. Prefer a <address> semantic element (most reliable signal).
 *   2. Otherwise scan the document for a Canadian postal code regex and
 *      build a candidate from the surrounding text.
 *
 * Sanity checks: street must contain a digit (street number) and a postal
 * code must be present. Without those, "Vancouver, BC V6B 1A1" — a mailing
 * footer with no actual address — would otherwise pass.
 *
 * Pure HTML-string parser, no HTTP. The cascade orchestrator decides WHICH
 * pages to fetch.
 */
class ContactPageAddressExtractor
{
    /**
     * Canadian postal code: A1A 1A1 (space optional). Per Canada Post spec:
     *
     *   - Position 1 EXCLUDES D F I O Q U (look-alike confusion with digits)
     *     AND W Z (unassigned territories).
     *   - Positions 3 and 5 EXCLUDE D F I O Q U.
     *
     * Tightening from the lax `[A-Z]` matters: hex color codes like #F3F3F3
     * read as "F3F 3F3" when split by whitespace and used to wrongly match
     * the previous "any letter" regex. Real Canadian postals never start
     * with F (or D, I, O, Q, U, W, Z), so a postal-shape token beginning
     * with those letters is almost always a hex code or other false alarm.
     */
    private const POSTAL_REGEX =
        '/\b([ABCEGHJKLMNPRSTVXY]\d[ABCEGHJKLMNPRSTVWXYZ])\s?(\d[ABCEGHJKLMNPRSTVWXYZ]\d)\b/i';

    /**
     * Patterns that a real street address NEVER contains but that scraped
     * CSS / JSON / minified markup routinely does. Used to reject
     * extractions like "--color-badge-border: 18, 18" that pass the
     * "must contain a digit" street sanity check.
     */
    private const CSS_OR_JSON_MARKERS = '/[{}:;]|--[\w-]+|var\(|\["|"\]/';

    /** Province / territory codes recognized inside a "City, ON" blob. */
    private const PROVINCES = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];

    public function extract(string $html): ?ScrapedAddress
    {
        // 1. Semantic <address> elements first — when present they're the
        // strongest signal a site author intended this as a postal address.
        if (preg_match_all('/<address\b[^>]*>(.*?)<\/address>/is', $html, $matches)) {
            foreach ($matches[1] as $inner) {
                $text = $this->htmlToText($inner);
                $address = $this->parseTextBlob($text);
                if ($address) return $address;
            }
        }

        // 2. Anchor on a postal code anywhere in the rendered text. There may
        // be multiple matches; walk each and pick the first whose surrounding
        // context produces a complete (street + postal) address.
        $text = $this->htmlToText($html);
        if (preg_match_all(self::POSTAL_REGEX, $text, $postalMatches, PREG_OFFSET_CAPTURE)) {
            $offsets = array_map(fn ($m) => $m[1], $postalMatches[0]);
            foreach ($postalMatches[0] as $idx => [$match, $offset]) {
                // Only look at text BETWEEN the previous postal match (or
                // start of text) and THIS one — otherwise a later, real
                // address's street would be glommed onto an earlier postal
                // lookalike, and vice versa.
                $prevEnd = $idx > 0
                    ? $offsets[$idx - 1] + strlen($postalMatches[0][$idx - 1][0])
                    : 0;
                $windowStart = max($prevEnd, $offset - 160);
                $blob = substr($text, $windowStart, ($offset - $windowStart) + strlen($match));
                $address = $this->parseAnchoredBlob($blob, $match);
                if ($address) return $address;
            }
        }

        return null;
    }

    /**
     * Strip an HTML fragment to plain text, treating <br> and block tags as
     * line breaks so "123 Main St<br>Vancouver" doesn't collapse into one
     * mashed-together word.
     */
    private function htmlToText(string $html): string
    {
        $s = preg_replace('/<br\s*\/?\s*>/i', ', ', $html);
        $s = preg_replace('/<\/(p|div|li|address|footer|section|article)>/i', ', ', $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace; commas already serve as separators.
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * Parse "123 Main St, Vancouver, BC V6B 1A1" style strings into the DTO,
     * given a blob of text that ends at (and includes) a known postal-code
     * match. Used by the postal-anchored scan in extract().
     */
    private function parseAnchoredBlob(string $blob, string $postalMatch): ?ScrapedAddress
    {
        if (!preg_match(self::POSTAL_REGEX, $postalMatch, $pm)) return null;

        return $this->buildAddressFromParts($blob, $pm);
    }

    /**
     * Parse a blob that may contain "123 Main St, City, REGION POSTAL" using
     * the first postal-code regex match. Public-shape: <address> elements
     * use this path because their contents are self-contained.
     */
    private function parseTextBlob(string $blob): ?ScrapedAddress
    {
        if (!preg_match(self::POSTAL_REGEX, $blob, $pm)) return null;
        return $this->buildAddressFromParts($blob, $pm);
    }

    /**
     * Shared logic to turn the comma-split text-before-postal into the DTO.
     * Requires a postal code AND a street segment containing a digit.
     */
    private function buildAddressFromParts(string $blob, array $pm): ?ScrapedAddress
    {
        $postal = strtoupper($pm[1] . ' ' . $pm[2]);

        // Everything BEFORE the postal code, comma-split. Walk RIGHT to LEFT:
        //   [-1] usually = "BC" (region, 2-letter)
        //   [-2] usually = "City"
        //   join earlier segments = street address
        $beforePostal = trim(substr($blob, 0, strpos($blob, $pm[0])));
        // Strip trailing comma and whitespace.
        $beforePostal = rtrim($beforePostal, " ,\t\n\r\0\x0B");
        $parts = array_map('trim', preg_split('/\s*,\s*/', $beforePostal));
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if (count($parts) === 0) return null;

        $region = null;
        $city = null;
        $street = null;

        // Pop region if the last segment is a province code (with optional
        // trailing "Canada" earlier on, which we ignore).
        $last = end($parts);
        if ($this->isProvince($last)) {
            $region = strtoupper($last);
            array_pop($parts);
        }
        // Pop city.
        if (!empty($parts)) {
            $city = end($parts);
            array_pop($parts);
        }
        // Remaining segments = street address.
        if (!empty($parts)) {
            $street = implode(', ', $parts);
        }

        // Sanity: street must contain a digit. Otherwise we're looking at a
        // mailing-list footer or other content that happens to have a postal
        // code but no real street number.
        if ($street === null || !preg_match('/\d/', $street)) return null;

        // Sanity: reject streets that smell like CSS / JSON / minified
        // markup rather than prose. Real addresses are "123 Main Street",
        // never "--color-badge-border: 18, 18". This catches cases where
        // a regex-valid-shape postal (e.g. B0B 1A1) appears inside an
        // embedded style block and the surrounding "text" is CSS.
        if (preg_match(self::CSS_OR_JSON_MARKERS, $street)) return null;

        return new ScrapedAddress(
            source: 'website',
            street_address: $street,
            postal_code: $postal,
            city: $city,
            region: $region,
        );
    }

    private function isProvince(string $token): bool
    {
        return in_array(strtoupper($token), self::PROVINCES, true);
    }
}
