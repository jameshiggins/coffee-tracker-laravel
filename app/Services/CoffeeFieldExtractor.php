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
        // SPECIFIC VARIETALS — checked first. These are unambiguously
        // varietal names with no country/location collision risk.
        $specificVarietals = [
            'Yellow Bourbon', 'Red Bourbon', 'Pink Bourbon',
            'Mundo Novo', 'Yellow Catuai', 'Red Catuai',
            'Pacamara', 'Maragogype', 'Maragogipe',
            'Castillo', 'Tabi',
            'Geisha', 'Gesha',
            'SL28', 'SL34', 'SL-28', 'SL-34',
            'Pache', 'Catimor', 'Sarchimor',
            'Bourbon', 'Caturra', 'Catuai', 'Catuaí', 'Typica',
            'Heirloom', 'Ethiopian Heirloom', 'Landrace',
            'Pacas', 'Villa Sarchi', 'Villalobos', 'Mokka',
            'Sidra', 'Wush Wush', 'Laurina',
        ];
        // AMBIGUOUS VARIETALS — these names are ALSO commonly used as
        // country/region indicators (Colombia is a country first, Java is
        // an island first, Kent is a UK county). Only accept these when
        // there's no country-context marker like ", Colombia" / "-
        // Colombia" / "from Colombia" / "| Colombia" / "(Colombia)" right
        // around them. Without this guard, Continuum's "El Obraje Geisha -
        // Colombia *Special Release*" returns varietal=Colombia instead of
        // Geisha because Colombia matches before Geisha gets a chance.
        $ambiguousVarietals = ['Colombia', 'Java', 'Kent'];

        foreach ($specificVarietals as $v) {
            $pattern = '/(?<![\w\-])' . preg_quote($v, '/') . '(?![\w\-])/iu';
            if (preg_match($pattern, $t)) {
                return self::canonicalVarietal($v);
            }
        }
        foreach ($ambiguousVarietals as $v) {
            // Must match the bare word AND NOT appear with a country
            // context separator immediately before. "El Obraje | Colombia"
            // / "Geisha - Colombia" / "Coffee, Colombia" / "(Colombia)" /
            // "from Colombia" all disqualify it as a varietal.
            $countryCtx = '/(?:[-|,]\s*|from\s+|origin[:\s]+|\()' . preg_quote($v, '/') . '(?![\w\-])/iu';
            if (preg_match($countryCtx, $t)) continue;
            $pattern = '/(?<![\w\-])' . preg_quote($v, '/') . '(?![\w\-])/iu';
            if (preg_match($pattern, $t)) {
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
     * Labels roasters put in front of a flavour list. Longest / most
     * specific first so "Tasting notes:" wins over the bare "Notes:".
     * Bare "Profile" is deliberately absent — "Roast profile: medium" is
     * the common collision; it only counts with a flavour qualifier.
     */
    private const NOTE_LABELS = 'tasting\s+notes?|flavou?r\s+notes?|cup(?:ping)?\s+notes?|aroma\s+(?:and|&)\s+flavou?r|'
        . 'flavou?r\s+profile|taste\s+profile|tasting\s+profile|cup\s+profile|cup\s+character|'
        . 'in\s+the\s+cup|notes\s+of|hints\s+of|flavou?rs?\s+of|tastes?\s+like|'
        . 'flavou?rs?|taste|notes?';

    /**
     * The spec-sheet labels that typically follow a notes block on a
     * heading-styled product page ("Tasting Notes Cherry, Cola Roast Light
     * Process Washed"). A capture is cut at the first one so the flavour
     * list survives instead of being rejected wholesale for containing
     * "roast".
     */
    private const NEXT_LABEL = '\b(?:roast(?:\s+(?:level|profile|degree))?|process(?:ing)?(?:\s+method)?|origin|region|country|'
        . 'varietal|variety|varieties|cultivar|altitude|elevation|producer|farm|harvest|crop|importer|'
        . 'weight|size|brew(?:ing)?|grind|price|ingredients?|net\s+wt|packaging|shipping)\b';

    /**
     * Tasting notes — pulled from a labelled section in the description.
     * Handles the shapes roaster copy actually takes:
     *   - "Tasting Notes: cherry, cola, brown sugar"
     *   - "Flavour: cherry / cola"  ·  "Notes of cherry and cola"
     *   - heading-styled rows with no punctuation once block tags are
     *     flattened: "Tasting Notes Cherry, Cola, Brown Sugar Roast Light"
     *   - "We taste cherry, cola and brown sugar"
     * The list is validated by looksLikeTastingNoteList() and normalized to
     * a comma-separated string. Returns null rather than guessing.
     */
    public static function extractTastingNotes(?string $text): ?string
    {
        if (!$text) return null;

        $labels = self::NOTE_LABELS;
        $patterns = [
            // Explicit separator after the label: "Notes: …", "Flavour — …".
            // The label must sit at a word boundary so "footnotes:" and
            // "keynotes:" don't fire.
            '/(?<![\p{L}])(?:' . $labels . ')\s*[:\-—–]\s*([^\n.|]{3,140})/iu',
            // Heading with no punctuation. Only trusted when what follows
            // already reads as a list (a separator or "and" inside it), so a
            // prose sentence that merely starts with "Taste" is skipped.
            '/(?<![\p{L}])(?:' . $labels . ')\s+((?=[^\n.|]{0,60}(?:[,•·\/]|\s(?:and|&)\s))[^\n.|]{3,140})/iu',
            '/\bwe\s+(?:taste|get|find|love|notice)\s+([^\n.|]{3,100})/iu',
            '/\b(?:expect|look\s+for)\s+(?:notes\s+of\s+|flavou?rs\s+of\s+|hints\s+of\s+)?([^\n.|]{3,100})/iu',
        ];

        foreach ($patterns as $p) {
            if (!preg_match_all($p, $text, $all, PREG_SET_ORDER)) continue;
            foreach ($all as $m) {
                $notes = self::cleanNoteCandidate($m[1]);
                if ($notes !== null) return $notes;
            }
        }

        return null;
    }

    /**
     * Notes embedded in the product title, which is where a lot of roasters
     * put them: "Ethiopia Guji – Blueberry, Jasmine, Honey" or
     * "Colombia Huila | Caramel · Red Apple". Only the segment after the
     * LAST separator is considered, it must read as a list, and at least one
     * term must be a known flavour word — so "House Blend - Brazil, Colombia"
     * (origins) and "Kenya AA - 250g, 1kg" (sizes) stay out.
     */
    public static function extractTastingNotesFromTitle(?string $title): ?string
    {
        if (!$title) return null;

        $parts = preg_split('/\s+[\-–—|:]\s+|\s*\|\s*/u', $title);
        if ($parts === false || count($parts) < 2) return null;

        $segment = trim((string) end($parts));
        if ($segment === '' || !preg_match('/[,•·\/]|\s(?:and|&)\s/u', $segment)) return null;

        $notes = self::cleanNoteCandidate($segment);
        if ($notes === null || !self::hasKnownFlavour($notes)) return null;

        return $notes;
    }

    /**
     * Notes from platform tags. Some shops tag every bean with its flavour
     * words ("Chocolate", "Stone Fruit", "Floral") and nothing else. Tags
     * are unordered and shared with taxonomy ("Single Origin", "Ethiopia",
     * "Light Roast"), so only known flavour vocabulary is kept, and at least
     * two matches are required — a lone "Sweet" tag says nothing.
     *
     * @param  array<int, string>  $tags
     */
    public static function extractTastingNotesFromTags(array $tags): ?string
    {
        $found = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            // "notes:chocolate" / "Flavour - Berry" style prefixed tags.
            $tag = preg_replace('/^(?:tasting\s+notes?|flavou?r\s+notes?|flavou?rs?|notes?|taste)\s*[:\-_]\s*/iu', '', $tag);
            if ($tag === '' || str_word_count($tag) > 3) continue;
            if (self::hasKnownFlavour($tag)) {
                $found[mb_strtolower($tag)] ??= $tag;
            }
        }

        if (count($found) < 2) return null;

        return implode(', ', array_values($found));
    }

    /**
     * Shared clean-up for a raw captured note list: cut at the next spec
     * label, drop connective words, split "and"/"&" into list separators,
     * then apply the list sanity gate and separator normalization.
     */
    private static function cleanNoteCandidate(string $raw): ?string
    {
        $raw = trim($raw);
        $raw = preg_replace('/\s*' . self::NEXT_LABEL . '.*$/iu', '', $raw) ?? $raw;
        // Sentence-form captures ("we get cherry and cola in this one") run
        // on past the list; drop everything from a trailing preposition /
        // pronoun clause onward.
        $raw = preg_replace('/\s+(?:in|on|from|for|that|which|this|these|when|as|to|throughout|across)\s+.*$/iu', '', $raw) ?? $raw;
        $raw = preg_replace('/\b(?:and\s+(?:a|the)\s+|with\s+(?:a|an|the)?\s*|a\s+(?:hint|touch|note)\s+of\s+)/iu', '', $raw) ?? $raw;
        // "cherry, cola and brown sugar" → three chips, not two.
        $raw = preg_replace('/\s*,?\s+(?:and|&|\+)\s+/iu', ', ', $raw) ?? $raw;
        $raw = trim($raw, " \t,.;:-–—");

        if ($raw === '' || !self::looksLikeTastingNoteList($raw)) return null;

        return self::normalizeNoteSeparators($raw);
    }

    /** True when any term in the list is (or ends with) a known flavour word. */
    public static function hasKnownFlavour(string $list): bool
    {
        foreach (preg_split('/\s*[,•·|\/]\s*/u', mb_strtolower($list)) ?: [] as $term) {
            $term = trim($term);
            if ($term === '') continue;
            if (isset(self::FLAVOUR_LEXICON[$term])) return true;
            // "milk chocolate", "candied orange", "black tea" — match on the
            // head noun so the lexicon doesn't need every adjective combo.
            $words = preg_split('/\s+/', $term) ?: [];
            $last = end($words);
            if ($last !== false && isset(self::FLAVOUR_LEXICON[$last])) return true;
        }

        return false;
    }

    /**
     * Flavour vocabulary (SCA flavour wheel + the descriptors Canadian
     * roasters actually print). Keys only; values are unused. Kept to nouns
     * and well-known descriptors — no origins, processes or roast words, so
     * a lexicon hit is a strong "this is a flavour list" signal.
     */
    private const FLAVOUR_LEXICON = [
        // fruit
        'berry' => 1, 'berries' => 1, 'blueberry' => 1, 'strawberry' => 1, 'raspberry' => 1, 'blackberry' => 1,
        'cranberry' => 1, 'gooseberry' => 1, 'currant' => 1, 'blackcurrant' => 1, 'cherry' => 1, 'cherries' => 1,
        'plum' => 1, 'peach' => 1, 'apricot' => 1, 'nectarine' => 1, 'apple' => 1, 'pear' => 1, 'grape' => 1,
        'grapes' => 1, 'raisin' => 1, 'prune' => 1, 'fig' => 1, 'date' => 1, 'dates' => 1, 'citrus' => 1,
        'lemon' => 1, 'lime' => 1, 'orange' => 1, 'grapefruit' => 1, 'bergamot' => 1, 'mandarin' => 1,
        'tangerine' => 1, 'clementine' => 1, 'yuzu' => 1, 'mango' => 1, 'pineapple' => 1, 'papaya' => 1,
        'passionfruit' => 1, 'passion fruit' => 1, 'lychee' => 1, 'guava' => 1, 'melon' => 1, 'watermelon' => 1,
        'cantaloupe' => 1, 'banana' => 1, 'kiwi' => 1, 'pomegranate' => 1, 'tamarind' => 1, 'jackfruit' => 1,
        'coconut' => 1, 'tropical' => 1, 'stone fruit' => 1, 'fruit' => 1, 'fruity' => 1, 'rhubarb' => 1,
        'tomato' => 1, 'jam' => 1, 'jammy' => 1, 'marmalade' => 1, 'compote' => 1, 'lemonade' => 1, 'juicy' => 1,
        // sweet
        'caramel' => 1, 'toffee' => 1, 'butterscotch' => 1, 'honey' => 1, 'molasses' => 1, 'sugar' => 1,
        'maple' => 1, 'vanilla' => 1, 'marshmallow' => 1, 'nougat' => 1, 'praline' => 1, 'fudge' => 1,
        'candy' => 1, 'candied' => 1, 'syrup' => 1, 'syrupy' => 1, 'treacle' => 1, 'sweet' => 1, 'sweetness' => 1,
        'brownie' => 1, 'cake' => 1, 'pastry' => 1, 'pie' => 1, 'cookie' => 1, 'biscuit' => 1, 'graham' => 1,
        'shortbread' => 1, 'crumble' => 1, 'custard' => 1, 'sherbet' => 1, 'cola' => 1, 'gummy' => 1,
        // chocolate / nut
        'chocolate' => 1, 'chocolatey' => 1, 'cocoa' => 1, 'cacao' => 1, 'nib' => 1, 'nibs' => 1,
        'hazelnut' => 1, 'almond' => 1, 'walnut' => 1, 'peanut' => 1, 'pecan' => 1, 'cashew' => 1,
        'pistachio' => 1, 'macadamia' => 1, 'nut' => 1, 'nuts' => 1, 'nutty' => 1, 'malt' => 1, 'malty' => 1,
        'marzipan' => 1, 'mocha' => 1,
        // floral / herbal / tea
        'floral' => 1, 'flower' => 1, 'flowers' => 1, 'jasmine' => 1, 'rose' => 1, 'hibiscus' => 1,
        'lavender' => 1, 'chamomile' => 1, 'elderflower' => 1, 'blossom' => 1, 'honeysuckle' => 1,
        'tea' => 1, 'herbal' => 1, 'mint' => 1, 'eucalyptus' => 1, 'sage' => 1, 'thyme' => 1, 'lemongrass' => 1,
        // spice / roasted / other
        'cinnamon' => 1, 'clove' => 1, 'nutmeg' => 1, 'cardamom' => 1, 'ginger' => 1, 'anise' => 1,
        'licorice' => 1, 'liquorice' => 1, 'pepper' => 1, 'spice' => 1, 'spices' => 1, 'spicy' => 1,
        'tobacco' => 1, 'cedar' => 1, 'sandalwood' => 1, 'wood' => 1, 'woody' => 1, 'smoky' => 1, 'smoke' => 1,
        'toast' => 1, 'toasty' => 1, 'cereal' => 1, 'grain' => 1, 'oat' => 1, 'oats' => 1, 'granola' => 1,
        'bread' => 1, 'wine' => 1, 'winey' => 1, 'boozy' => 1, 'rum' => 1, 'whisky' => 1, 'whiskey' => 1,
        'brandy' => 1, 'port' => 1, 'cream' => 1, 'creamy' => 1, 'butter' => 1, 'buttery' => 1, 'milk' => 1,
        'yogurt' => 1, 'umami' => 1, 'savoury' => 1, 'savory' => 1, 'earthy' => 1, 'bright' => 1, 'tart' => 1,
        'crisp' => 1, 'clean' => 1, 'smooth' => 1, 'rich' => 1, 'bold' => 1, 'balanced' => 1, 'silky' => 1,
        'velvety' => 1, 'round' => 1, 'mellow' => 1, 'zesty' => 1, 'tangy' => 1, 'lively' => 1, 'delicate' => 1,
        'complex' => 1, 'full-bodied' => 1, 'full bodied' => 1,
    ];

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

        // Reject if average token is too wordy. Real notes are 1-3 words
        // each: "milk chocolate", "stone fruit", "candied lime". Split on the
        // full separator set roasters actually use — commas, bullets (• ·),
        // pipes, and slashes — not commas alone, or a bullet-delimited list
        // like "Golden berry • Jasmine • Pear" counts as one 4-word token and
        // is wrongly rejected.
        $tokens = preg_split('/\s*[,•·|\/]\s*/u', $r);
        $tokens = array_values(array_filter($tokens, fn ($t) => trim($t) !== ''));
        if (count($tokens) > 0) {
            $avgWords = $wordCount / count($tokens);
            if ($avgWords > 3.5) return false;
        }

        return true;
    }

    /**
     * Normalize a tasting-note list to a clean comma-separated string.
     * Roasters delimit notes with bullets ("Golden berry • Jasmine • Pear"),
     * pipes, or slashes; the rest of the system (and the DB) expects commas.
     * Collapses any run of separator characters into a single ", ".
     */
    public static function normalizeNoteSeparators(string $raw): string
    {
        $s = preg_replace('/\s*[•·|\/]+\s*/u', ', ', $raw);
        $s = preg_replace('/\s*,\s*/', ', ', $s);
        $s = preg_replace('/(?:,\s*)+/', ', ', $s);
        return trim($s, " ,\t\n\r");
    }

    private static function parseInt(string $raw): int
    {
        return (int) str_replace(',', '', $raw);
    }
}
