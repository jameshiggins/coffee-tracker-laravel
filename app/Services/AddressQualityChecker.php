<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Trust#6: read-only audit of how good each roaster's map placement is.
 *
 * Online-only roasters are excluded — they intentionally have no address. Every
 * other (physical) roaster is sorted into exactly ONE bucket via a most-severe-
 * wins cascade:
 *
 *   unplaced       no lat/lng at all → invisible on the map (worst).
 *   centroid_only  has coordinates but address_source IS NULL → the lat/lng is
 *                  the seeder's CITY centroid, never resolved to a real street.
 *                  (The cascade stamps a source — jsonld/website/osm/google —
 *                  whenever it finds a precise address, so a null source on a
 *                  placed roaster means "centroid placeholder".)
 *   missing_street resolved to a point but no street_address to display.
 *   missing_postal has a street but no postal code.
 *   stale          complete, but address_verified_at is missing or older than
 *                  the staleness window → re-verify candidate.
 *
 * Anything that passes all five is "ok". Pure detection: never mutates a row.
 */
class AddressQualityChecker
{
    /** Reporting order — most severe first. */
    public const BUCKETS = ['unplaced', 'centroid_only', 'missing_street', 'missing_postal', 'stale'];

    /**
     * @param  Collection  $roasters  rows exposing the address columns + is_online_only
     * @return array{
     *   buckets: array<string, array<int, array>>,
     *   ok: int, online_only: int, flagged: int, stale_months: int
     * }
     */
    public function check(Collection $roasters, int $staleMonths = 12): array
    {
        $cutoff = Carbon::now()->subMonths($staleMonths);
        $buckets = array_fill_keys(self::BUCKETS, []);
        $ok = 0;
        $onlineOnly = 0;

        foreach ($roasters as $r) {
            if ($r->is_online_only) {
                $onlineOnly++;
                continue;
            }
            $cat = $this->classify($r, $cutoff);
            if ($cat === 'ok') {
                $ok++;
                continue;
            }
            $buckets[$cat][] = [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'city' => $r->city,
                'region' => $r->region,
                'address_source' => $r->address_source,
                'address_verified_at' => $r->address_verified_at?->toIso8601String(),
            ];
        }

        return [
            'buckets' => $buckets,
            'ok' => $ok,
            'online_only' => $onlineOnly,
            'flagged' => array_sum(array_map('count', $buckets)),
            'stale_months' => $staleMonths,
        ];
    }

    private function classify($r, Carbon $cutoff): string
    {
        $hasCoords = $r->latitude !== null && $r->longitude !== null;
        if (!$hasCoords) return 'unplaced';
        if ($r->address_source === null) return 'centroid_only';
        if (blank($r->street_address)) return 'missing_street';
        if (blank($r->postal_code)) return 'missing_postal';
        if ($r->address_verified_at === null || $r->address_verified_at->lt($cutoff)) return 'stale';
        return 'ok';
    }
}
