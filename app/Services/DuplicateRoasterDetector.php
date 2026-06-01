<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Trust#7: surface likely-duplicate roaster rows for human review.
 *
 * Duplicates creep in three ways, each with its own confidence:
 *   1. Two rows pointing at the SAME website host — almost always a real dup
 *      (a rename that forked the row, or a double-add). High confidence.
 *   2. Two rows whose names canonicalize identically once industry filler
 *      ("Coffee", "Roasters", "& / and", punctuation, accents) is stripped —
 *      e.g. "Pilot Coffee Roasters" vs "Pilot Coffee". High confidence.
 *   3. Two rows whose canonical names are merely SIMILAR (one typo apart) —
 *      e.g. "Transcend" vs "Transend". Lower confidence; reported for review.
 *
 * This service only DETECTS and returns structured findings. It never merges
 * or deletes — that's a destructive, human-judgment decision left to the admin.
 */
class DuplicateRoasterDetector
{
    /** Industry filler dropped from names before comparison. */
    private const STOP_WORDS = [
        'coffee', 'coffees', 'roaster', 'roasters', 'roasting', 'roastery',
        'roasterie', 'roasted', 'co', 'company', 'inc', 'ltd', 'llc', 'the',
        'and', 'cafe', 'caffe', 'beans', 'bean', 'specialty',
    ];

    /**
     * @param  Collection  $roasters  rows exposing id, name, slug, website, is_active
     * @return array{
     *   host_groups: array<int, array<int, array>>,
     *   name_groups: array<int, array<int, array>>,
     *   similar_pairs: array<int, array{a: array, b: array, score: float}>
     * }
     */
    public function detect(Collection $roasters, float $threshold = 0.85): array
    {
        $rows = $roasters->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'website' => $r->website,
            'is_active' => (bool) $r->is_active,
            'host' => self::canonicalHost($r->website),
            'canon' => self::canonicalName($r->name),
        ])->values();

        // (1) Shared-host groups: ≥2 rows on one canonical host.
        $hostGroups = $rows
            ->filter(fn ($r) => $r['host'] !== null)
            ->groupBy('host')
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->values()->all())
            ->values()
            ->all();

        // (2) Identical-canonical-name groups.
        $nameGroups = $rows
            ->filter(fn ($r) => $r['canon'] !== '')
            ->groupBy('canon')
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->values()->all())
            ->values()
            ->all();

        // (3) Fuzzy pairs: similar but NOT identical (identical pairs already
        // surface in nameGroups). O(n²), fine for a maintenance command run
        // against a few hundred rows.
        $similar = [];
        $list = $rows->all();
        $n = count($list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $list[$i];
                $b = $list[$j];
                if ($a['canon'] === '' || $b['canon'] === '') continue;
                if ($a['canon'] === $b['canon']) continue;
                $score = self::similarity($a['canon'], $b['canon']);
                if ($score >= $threshold) {
                    $similar[] = ['a' => $a, 'b' => $b, 'score' => round($score, 3)];
                }
            }
        }
        usort($similar, fn ($x, $y) => $y['score'] <=> $x['score']);

        return [
            'host_groups' => $hostGroups,
            'name_groups' => $nameGroups,
            'similar_pairs' => $similar,
        ];
    }

    /** Lowercased registrable host with the common storefront sub-domains dropped. */
    public static function canonicalHost(?string $website): ?string
    {
        if (!$website) return null;
        $host = parse_url($website, PHP_URL_HOST) ?: $website;
        $host = strtolower(trim($host));
        $host = preg_replace('/^(www|shop|store)\./', '', $host);
        return $host !== '' ? $host : null;
    }

    /**
     * Reduce a display name to its distinguishing core: lowercase, de-accented,
     * "&"→"and", punctuation→spaces, then industry filler words removed. Returns
     * '' for names that are entirely filler (those never group with each other).
     */
    public static function canonicalName(?string $name): string
    {
        if (!$name) return '';
        $s = mb_strtolower($name, 'UTF-8');
        $s = self::stripAccents($s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $words = array_filter(
            explode(' ', $s),
            fn ($w) => $w !== '' && !in_array($w, self::STOP_WORDS, true)
        );
        return trim(implode(' ', $words));
    }

    /** Normalized Levenshtein similarity in [0,1]; 1.0 == identical. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) return 0.0;
        return 1.0 - (levenshtein($a, $b) / $maxLen);
    }

    private static function stripAccents(string $s): string
    {
        $from = ['á','à','â','ä','ã','å','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','ö','õ','ú','ù','û','ü','ñ','ç'];
        $to   = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','n','c'];
        return str_replace($from, $to, $s);
    }
}
