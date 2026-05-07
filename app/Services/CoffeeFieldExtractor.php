<?php

namespace App\Services;

/**
 * Heuristic extractors that pull structured coffee facts out of free-text
 * descriptions. Roasters wildly under-populate Shopify's structured fields
 * (varietal, origin, etc.) and just dump everything into the description
 * blob, so we recover what we can with regex and a small known-vocabulary
 * dictionary.
 *
 * Every method returns null when nothing reliable was found — never
 * fabricate. The importer only fills missing fields; it never overwrites
 * a value the catalog feed already provided.
 */
class CoffeeFieldExtractor
{
    /**
     * Elevation in metres above sea level. Handles:
     *   "1800m", "1,800 masl", "1,800 m above sea level", "Altitude 1750m",
     *   "Elevation: 1,800-2,100 m" (returns midpoint), "5,900 ft" (converts).
     *   Validates: 200m-3500m to filter out false positives (page IDs, etc.).
     */
    public static function extractElevation(?string $text): ?int
    {
        if (!$text) return null;

        // Range-in-metres first: "1,800-2,100m" or "1800 - 2100 masl"
        if (preg_match(
            '/(\d{1,4}(?:,\d{3})*)\s*[-–—to]+\s*(\d{1,4}(?:,\d{3})*)\s*(?:m(?:eters?|etres?)?|masl|m\.?a\.?s\.?l)\b/i',
            $text,
            $m
        )) {
            $a = self::parseInt($m[1]);
            $b = self::parseInt($m[2]);
            $mid = (int) round(($a + $b) / 2);
            if ($mid >= 200 && $mid <= 3500) return $mid;
        }

        // Range-in-feet: "5,900-6,500 ft"
        if (preg_match(
            '/(\d{1,5}(?:,\d{3})*)\s*[-–—to]+\s*(\d{1,5}(?:,\d{3})*)\s*(?:ft|feet|\')\b/i',
            $text,
            $m
        )) {
            $a = self::parseInt($m[1]);
            $b = self::parseInt($m[2]);
            $mid = (int) round((($a + $b) / 2) * 0.3048);
            if ($mid >= 200 && $mid <= 3500) return $mid;
        }

        // Single value in metres. Must have an "altitude" / "elevation" / "masl"
        // anchor nearby OR end with "masl" — bare "1800m" without context is too
        // risky (could be a generic measurement).
        if (preg_match(
            '/(?:altitude|elevation|grown\s+at|growing\s+altitude)[^0-9]{0,30}(\d{1,4}(?:,\d{3})*)\s*(?:m(?:eters?|etres?)?|masl)?\b/i',
            $text,
            $m
        )) {
            $v = self::parseInt($m[1]);
            if ($v >= 200 && $v <= 3500) return $v;
        }
        if (preg_match('/(\d{1,4}(?:,\d{3})*)\s*(?:masl|m\.?a\.?s\.?l)\b/i', $text, $m)) {
            $v = self::parseInt($m[1]);
            if ($v >= 200 && $v <= 3500) return $v;
        }

        // Single value in feet with anchor.
        if (preg_match(
            '/(?:altitude|elevation|grown\s+at)[^0-9]{0,30}(\d{1,5}(?:,\d{3})*)\s*(?:ft|feet|\')/i',
            $text,
            $m
        )) {
            $v = (int) round(self::parseInt($m[1]) * 0.3048);
            if ($v >= 200 && $v <= 3500) return $v;
        }

        return null;
    }

    /**
     * Varietal: matches against a curated list of well-known cultivars.
     * Returns the FIRST match in canonical capitalisation. Multiple varietals
     * (e.g. "Bourbon, Caturra") return the first one — the directory's
     * varietal field is single-valued.
     */
    public static function extractVarietal(?string $text): ?string
    {
        if (!$text) return null;
        $t = ' ' . $text . ' ';

        // Order matters: longer/more-specific entries first so "Yellow Bourbon"
        // wins over "Bourbon", "Pink Bourbon" wins over "Bourbon", etc.
        $varietals = [
            'Yellow Bourbon', 'Red Bourbon', 'Pink Bourbon',
            'Mundo Novo', 'Yellow Catuai', 'Red Catuai',
            'Pacamara', 'Maragogype', 'Maragogipe',
            'Castillo', 'Colombia', 'Tabi',
            'Geisha', 'Gesha',
            'SL28', 'SL34', 'SL-28', 'SL-34',
            'Kent', 'Java', 'Pache',
            'Catimor', 'Sarchimor',
            'Bourbon', 'Caturra', 'Catuai', 'Catuaí', 'Typica',
            'Heirloom', 'Ethiopian Heirloom', 'Landrace',
            'Pacas', 'Villa Sarchi', 'Villalobos', 'Mokka',
            'Sidra', 'Wush Wush', 'Laurina',
        ];

        foreach ($varietals as $v) {
            $pattern = '/(?<![\w\-])' . preg_quote($v, '/') . '(?![\w\-])/iu';
            if (preg_match($pattern, $t)) {
                // Canonicalize: SL-28 → SL28, Catuaí → Catuai, Gesha → Geisha
                return self::canonicalVarietal($v);
            }
        }
        return null;
    }

    private static function canonicalVarietal(string $v): string
    {
        $map = [
            'SL-28' => 'SL28', 'SL-34' => 'SL34',
            'Catuaí' => 'Catuai', 'Maragogipe' => 'Maragogype',
            'Gesha' => 'Geisha', 'Ethiopian Heirloom' => 'Heirloom',
        ];
        return $map[$v] ?? $v;
    }

    /**
     * Process: matches a known-vocabulary list. Long-form phrases first
     * ("Carbonic Maceration" beats bare "Carbonic"), then bare keywords.
     */
    public static function extractProcess(?string $text): ?string
    {
        if (!$text) return null;
        $t = ' ' . $text . ' ';

        $processes = [
            // Specific long-forms first
            ['Carbonic Maceration', 'Carbonic'],
            ['Anaerobic Natural', 'Anaerobic Natural'],
            ['Anaerobic Washed', 'Anaerobic Washed'],
            ['Anaerobic Honey', 'Anaerobic Honey'],
            ['Anaerobic', 'Anaerobic'],
            ['Pulped Natural', 'Pulped Natural'],
            ['Wet Hulled', 'Wet Hulled'],
            ['Giling Basah', 'Wet Hulled'],
            ['Semi-Washed', 'Semi-Washed'],
            ['Semi Washed', 'Semi-Washed'],
            ['White Honey', 'Honey'],
            ['Yellow Honey', 'Honey'],
            ['Red Honey', 'Honey'],
            ['Black Honey', 'Honey'],
            ['Honey Process', 'Honey'],
            ['Honey-Processed', 'Honey'],
            ['Fully Washed', 'Washed'],
            ['Double Washed', 'Washed'],
            ['Washed Process', 'Washed'],
            ['Natural Process', 'Natural'],
            ['Dry Process', 'Natural'],
            ['Sun Dried', 'Natural'],
            // Bare keywords (last priority)
            ['Washed', 'Washed'],
            ['Natural', 'Natural'],
            ['Honey', 'Honey'],
        ];

        foreach ($processes as [$pattern, $canonical]) {
            $regex = '/(?<![\w])' . preg_quote($pattern, '/') . '(?![\w])/iu';
            if (preg_match($regex, $t)) return $canonical;
        }
        return null;
    }

    /**
     * Roast level: light / medium / medium-dark / dark / omni. Strict
     * keyword match — most roaster sites either say "light roast" /
     * "medium roast" outright or use a labelled "Roast: ..." block.
     */
    public static function extractRoastLevel(?string $text): ?string
    {
        if (!$text) return null;
        $t = ' ' . $text . ' ';

        // Specific labelled forms first.
        if (preg_match('/\broast(?:\s+level)?\s*[:\-]\s*([a-z\-]+)/i', $t, $m)) {
            $hit = strtolower($m[1]);
            foreach (['medium-dark', 'medium dark', 'medium-light', 'medium light', 'light', 'medium', 'dark', 'omni'] as $level) {
                if (str_starts_with($hit, str_replace('-', '', $level))) return self::canonicalRoast($level);
            }
        }

        // Bare "X roast" phrases.
        $patterns = [
            ['medium-dark roast', 'medium-dark'],
            ['medium dark roast', 'medium-dark'],
            ['medium-light roast', 'light'],
            ['light roast', 'light'],
            ['medium roast', 'medium'],
            ['dark roast', 'dark'],
            ['city roast', 'medium'],
            ['full city roast', 'medium-dark'],
            ['vienna roast', 'dark'],
            ['french roast', 'dark'],
            ['italian roast', 'dark'],
            ['omni roast', 'omni'],
            ['filter roast', 'light'],
            ['espresso roast', 'medium-dark'],
        ];
        foreach ($patterns as [$needle, $canonical]) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $t)) return $canonical;
        }
        return null;
    }

    private static function canonicalRoast(string $v): string
    {
        $v = strtolower(trim(str_replace(' ', '-', $v)));
        // Collapse niche labels into the four user-facing tiers:
        //   medium-light  → light
        //   omni          → light (omni roasts are tuned brighter than
        //                   espresso medium; "light" matches user
        //                   expectations better than the unfamiliar term)
        if (in_array($v, ['light', 'medium', 'medium-dark', 'medium-light', 'dark', 'omni'], true)) {
            if ($v === 'medium-light' || $v === 'omni') return 'light';
            return $v;
        }
        return $v;
    }

    /**
     * Tasting notes — pulled from a labelled section in the description.
     * Looks for a "Notes:" / "Tasting Notes:" / "Flavour Notes:" header,
     * then captures the next ~120 chars up to a sentence break or pipe.
     * Returns the comma-separated list verbatim (cleaned).
     */
    public static function extractTastingNotes(?string $text): ?string
    {
        if (!$text) return null;
        // Order matters: longer/more-specific labels first so "Tasting
        // notes:" wins over the bare "Notes:" alternative.
        $patterns = [
            '/(?:tasting\s+notes?|flavou?r\s+notes?|cup\s+notes?|notes?\s+of)\s*[:\-—]\s*([^\n.|]{3,120})/i',
            '/(?:^|[\s.])notes?\s*[:\-—]\s*([^\n.|]{3,120})/i',
            '/we\s+(?:taste|love|notice)\s+([^\n.|]{3,80})/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $raw = trim($m[1]);
                $raw = preg_replace('/\b(?:and\s+(?:a|the)\s+|with\s+)/i', '', $raw);
                if (self::looksLikeTastingNoteList($raw)) {
                    return $raw;
                }
            }
        }
        return null;
    }

    /**
     * Sanity-check an extracted note list. A real tasting-note list looks
     * like "jasmine, bergamot, honey" — short comma-separated flavor terms,
     * no farming/processing language. Rejects:
     *   - Anything with farming/process keywords ("roast", "raised",
     *     "altitude", "process", "beds", "harvest", "grown", "ferment")
     *   - Anything with embedded colons / semicolons (suggests a label
     *     leaked through, like "raised african beds. roast: light")
     *   - Anything with too few or too many words
     *   - Anything where individual tokens average more than 3 words long
     */
    public static function looksLikeTastingNoteList(string $raw): bool
    {
        $r = trim($raw);
        if ($r === '') return false;

        $wordCount = str_word_count($r);
        if ($wordCount < 1 || $wordCount > 18) return false;

        // Embedded labels mean we straddled into a different field.
        if (preg_match('/[:;]/', $r)) return false;

        // Farming / processing / agronomy language — that is NOT a tasting note.
        if (preg_match('/\b(roast(?:ing)?|raised|altitude|elevation|process(?:ed|ing)?|fermented?|harvest(?:ed)?|grown|growing|farm|farmer|producer|crop|variety|varietal|region|country|origin|sourced?|cooperative|estate|washed|natural|honey\s+process|anaerobic|carbonic|beds?|patio|drying|dried|export(?:er)?|import(?:er)?|grade|score|sca)\b/i', $r)) {
            return false;
        }

        // Reject if average token (split by comma) is too wordy. Real notes
        // are 1-3 words each: "milk chocolate", "stone fruit", "candied lime".
        $tokens = preg_split('/\s*,\s*/', $r);
        if (count($tokens) > 0) {
            $avgWords = $wordCount / count($tokens);
            if ($avgWords > 3.5) return false;
        }

        return true;
    }

    private static function parseInt(string $raw): int
    {
        return (int) str_replace(',', '', $raw);
    }
}
