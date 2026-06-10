<?php

namespace App\Services;

use App\Services\Scraping\Shared;

/**
 * Pure text-cleaning + field-inference helpers used by the import pipeline.
 *
 * Extracted from RoasterImporter (which had grown to mix orchestration with
 * ~150 lines of regex-heavy normalization). These are stateless string
 * transforms with no dependency on importer state — only the other pure
 * helpers (Shared, CoffeeFieldExtractor, OriginGazetteer) — so they live here
 * as static methods, independently testable.
 */
final class CoffeeTextNormalizer
{
    /** Best-effort roaster name from a bare website host ("shop.foo.com" → "Foo"). */
    public static function inferNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^(www|shop)\./', '', $host);
        $base = explode('.', $host)[0];
        return ucwords(str_replace(['-', '_'], ' ', $base));
    }

    /**
     * Normalize a free-text field for storage:
     *   - decode HTML entities (&amp;, &#039;, &ntilde; → ñ)
     *   - swap typographic curly quotes / dashes for ASCII equivalents
     *   - drop U+FFFD replacement characters and stray control chars
     *   - collapse internal whitespace runs to single spaces
     *   - trim leading/trailing whitespace + leftover bullet markers
     *
     * Shared by every user-visible string the importer touches —
     * coffee name, tasting_notes, origin, process, roast_level,
     * varietal. Without it the audit on the live API showed 43 fields
     * with leading/trailing whitespace, 31 with multi-spaces, 15 with
     * literal "&amp;" still encoded, and 9 with curly apostrophes from
     * sites that copy-paste Word smart quotes into product descriptions.
     */
    public static function sanitizeText(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
            "\u{FFFD}" => '',  // strip U+FFFD replacement chars from upstream UTF-8 damage
        ]);
        // Drop ASCII control characters except \n and \t (which collapse below).
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s);
        // Collapse all whitespace runs to a single space, then trim. Also
        // trim leading/trailing punctuation cruft commonly left behind by
        // bullet markers and separator artifacts.
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s, " \t\n\r\0\x0B|·•-—–,.");
    }

    /**
     * Strip trailing bag-weight annotations and process/roast tags from a
     * product title — those are shown as separate chips on the card, so
     * "Brazil Santos (454 g) | Washed" reads cleaner as "Brazil Santos".
     * Conservative: only strips from the END so mid-title sizes/processes
     * (real product names like "12oz Lined Bag") stay intact.
     */
    public static function cleanCoffeeName(string $name): string
    {
        $cleaned = self::sanitizeText($name);

        // Trailing bag-weight annotations.
        $patterns = [
            '/\s*[\(\[]\s*\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\.?\s*[\)\]]\s*$/i',
            '/\s*[-–—|·]\s*\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\.?\s*$/i',
            '/\s+\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\s*$/i',
        ];
        foreach ($patterns as $p) {
            $cleaned = preg_replace($p, '', $cleaned) ?? $cleaned;
        }

        // Trailing process-method tags after a separator. Strips
        // "| Washed", "- Natural Process", " (Anaerobic)", etc. We keep
        // process names that aren't preceded by a separator (so "Washed
        // Coffee" the standalone product name stays intact), and only
        // strip from the END so "Anaerobic Lot 12" mid-title is safe.
        $processWords = 'fully\s+washed|double\s+washed|washed\s+process|natural\s+process|dry\s+process|sun\s+dried|pulped\s+natural|wet\s+hulled|giling\s+basah|semi[\s-]washed|carbonic\s+maceration|anaerobic\s+natural|anaerobic\s+washed|anaerobic\s+honey|honey\s+process|honey-?processed|white\s+honey|yellow\s+honey|red\s+honey|black\s+honey|washed|natural|honey|anaerobic|carbonic';
        // Roast levels get their own chip, so strip them too — same rules.
        $roastWords = 'medium[\s-]dark\s+roast|medium[\s-]light\s+roast|extra\s+dark\s+roast|light\s+roast|medium\s+roast|dark\s+roast|city\s+roast|full\s+city\s+roast|french\s+roast|italian\s+roast|vienna\s+roast|filter\s+roast|espresso\s+roast|omni\s+roast|light|medium|dark';
        $processPatterns = [
            // "Peru Marshell | Washed", "Brazil - Natural", "Foundry - Light Roast"
            '/\s*[|·\-–—,]+\s*(?:' . $processWords . ')\s*$/i',
            '/\s*[|·\-–—,]+\s*(?:' . $roastWords . ')\s*$/i',
            // "(Washed)" / "[Light Roast]" trailing
            '/\s*[\(\[]\s*(?:' . $processWords . ')\s*[\)\]]\s*$/i',
            '/\s*[\(\[]\s*(?:' . $roastWords . ')\s*[\)\]]\s*$/i',
        ];
        // Run twice — sometimes a title has both ("- Light Roast - Washed")
        for ($i = 0; $i < 2; $i++) {
            foreach ($processPatterns as $p) {
                $cleaned = preg_replace($p, '', $cleaned) ?? $cleaned;
            }
        }

        $cleaned = trim($cleaned, " \t-–—|·,");
        return $cleaned !== '' ? $cleaned : $name;  // never return empty
    }

    /**
     * Turn a scraped product body into a short, card-friendly description:
     * strips brew recipes, ratios/temps, ALL-CAPS headers, leftover section
     * labels, labelled spec blocks, URLs/emails and list markers; caps at
     * ~3 sentences / 320 chars; sentence-cases shouty intros. Returns null
     * when nothing usable remains.
     */
    public static function cleanDescription(string $raw): ?string
    {
        // Some roaster sites serve copy-pasted Word/Mac smart quotes that
        // arrive as truncated multi-byte sequences. Strip non-UTF-8 bytes
        // first — leaving them in the DB is fine until the API tries to
        // json_encode the row, then it 500s the whole endpoint.
        $s = Shared::sanitizeUtf8($raw);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize quote/dash typography so split-sentence regex behaves.
        $s = strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
        ]);
        $s = preg_replace("/\r\n?/", "\n", $s);
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = trim($s);
        if ($s === '') return null;

        // Strip everything from the first "brewing recipe / instructions /
        // recommendations" header onwards. Roasters love appending a brew
        // recipe table — irrelevant on a directory card, and it bloats text.
        $cutPattern = '/\b(brewing\s+(?:recipe|recommendations?|instructions?|guide|tips|notes?|method)|brew(?:ing)?\s+(?:guide|method|technique|temperature|temp|time)|how\s+to\s+brew|brew\s+ratio|brew\s+method|recipe[s]?:|grind\s+(?:size|setting|level)|water\s+temperature|water\s+to\s+coffee|coffee\s+to\s+water|extraction\s+time|bloom\s+time|pour[\s-]?over\s+recipe|espresso\s+recipe|aeropress\s+recipe|french\s+press\s+recipe|shipping\s+(?:info|details?)|free\s+shipping|please\s+note|click\s+here|buy\s+now|add\s+to\s+cart|enjoy[\s!]+|cheers!?\s*$)\b/i';
        if (preg_match($cutPattern, $s, $m, PREG_OFFSET_CAPTURE)) {
            $s = trim(substr($s, 0, $m[0][1]));
        }

        // Strip explicit ratios/temps that bleed in mid-sentence:
        // "1:16 ratio", "94°C", "60g/L", "20s bloom"
        $s = preg_replace('/\b\d+\s*:\s*\d+\s*(?:ratio|brew)?\b/i', '', $s);
        $s = preg_replace('/\b\d+\s*°\s*[CF]\b/i', '', $s);
        $s = preg_replace('/\b\d+\s*g\s*\/\s*\d+\s*(?:ml|g|l)\b/i', '', $s);

        // Strip ALL-CAPS section headers ("ABOUT THIS COFFEE", "FROM THE
        // ROASTER:") that some sites prepend to every product.
        $s = preg_replace('/\b[A-Z][A-Z\s]{6,}[A-Z](?:\s*[:\-]|\s*\n)/', '', $s);

        // Strip leading section labels like "Description", "About", "Story",
        // "Overview" — these are leftover <h2> text from strip_tags() runs.
        // Match at the very start, with optional trailing colon/dash/newline.
        $s = preg_replace(
            '/^\s*(?:description|about(?:\s+this\s+(?:coffee|bean|blend))?|overview|story|the\s+story|background|details?|tasting|notes?\s+from\s+(?:the\s+)?roaster|from\s+(?:the\s+)?roaster|product\s+description|product\s+details)\s*[:\-—]?\s+/i',
            '',
            $s
        );

        // Strip explicit "Tasting Notes:" / "Origin:" / "Process:" / etc.
        // labelled blocks because we display those structured fields
        // separately on the card. Keep just the marketing prose.
        $labels = 'tasting\s+notes?|flavou?r\s+notes?|cup\s+notes?|notes?|origin|region|country|process(?:ing)?|varietal|variety|altitude|elevation|producer|farm|roast(?:\s+level)?|harvest|crop\s+year|importer|exporter|grade|score|sca\s+score|cupping\s+score';
        $s = preg_replace(
            '/(?:^|\n|\.\s+|—\s+)\s*(?:' . $labels . ')\s*[:\-]\s*[^\n.]{0,200}(?=\n|\.\s+|$)/iu',
            ' ',
            $s
        );

        // Strip URL fragments and bare email addresses.
        $s = preg_replace('/https?:\/\/\S+/', '', $s);
        $s = preg_replace('/\S+@\S+\.\S+/', '', $s);

        // Drop bullet/list markers — descriptions read better as prose.
        $s = preg_replace('/^[\s•·\-*]+/m', '', $s);

        // Collapse whitespace again after stripping.
        $s = preg_replace('/\n{2,}/', ' ', $s);
        $s = preg_replace('/\s{2,}/', ' ', $s);
        $s = preg_replace('/\s+([,.;:!?])/', '$1', $s);  // no space before punctuation
        $s = trim($s, " \t\n.,;:-");
        if ($s === '') return null;

        // Cap at ~3 sentences or 320 chars, whichever comes first. Capping
        // at the sentence boundary keeps the cut clean (no mid-word ellipsis).
        if (mb_strlen($s) > 320) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $s, 6);
            $kept = '';
            foreach ($sentences as $sent) {
                $candidate = trim($kept . ' ' . $sent);
                if (mb_strlen($candidate) > 320 && $kept !== '') break;
                $kept = $candidate;
            }
            $s = $kept !== '' ? $kept : mb_substr($s, 0, 300) . '…';
        }

        // Sentence-case the first character so "OUR special blend…" → "Our
        // special blend…" without disturbing later capitalization.
        if (mb_strlen($s) > 0 && ctype_upper(mb_substr($s, 0, 1)) && ctype_upper(mb_substr($s, 0, 6))) {
            $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_strtolower(mb_substr($s, 1));
            // Re-capitalize after periods to restore proper sentences.
            $s = preg_replace_callback('/([.!?]\s+)(.)/u', fn ($m) => $m[1] . mb_strtoupper($m[2]), $s);
            $s = preg_replace_callback('/^(.)/', fn ($m) => mb_strtoupper($m[1]), $s);
        }

        // Ensure a sentence-ending punctuation (helps when we cut mid-blurb).
        if (!preg_match('/[.!?…]$/', $s)) $s .= '.';

        return $s !== '' ? $s : null;
    }

    /** Pull a "Tasting Notes: …" inline list out of a description, normalized. */
    public static function extractTastingNotes(?string $description): ?string
    {
        if (!$description) return null;
        if (preg_match('/(?:tasting\s+notes?|flavou?r\s+notes?|notes?)\s*[:\-—]\s*([^\n.]{3,120})/i', $description, $m)) {
            // Belt-and-braces: cleanDescription already sanitized $description,
            // but the regex slice could still produce a partial multi-byte
            // sequence at the boundaries.
            $raw = trim(Shared::sanitizeUtf8($m[1]));
            // Roasters delimit notes with bullets/pipes/slashes ("Golden
            // berry • Jasmine • Pear"); normalize to the comma form the rest
            // of the system expects.
            return CoffeeFieldExtractor::normalizeNoteSeparators($raw);
        }
        return null;
    }

    /** Infer a country of origin from a coffee title via the gazetteer. */
    public static function inferOrigin(string $title): string
    {
        return OriginGazetteer::inferCountry($title);
    }
}
