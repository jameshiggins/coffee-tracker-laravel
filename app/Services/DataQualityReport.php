<?php

namespace App\Services;

use App\Models\Roaster;
use App\Models\ScraperRejectionLog;
use Illuminate\Support\Carbon;

/**
 * Trust#2: roll the directory's data-quality signals into one structured
 * report for the weekly ops digest. Composes the per-concern tools built for
 * Trust#6/#7/#9 plus the import-status columns (Trust#1):
 *
 *   imports     — last-import outcome across active roasters (success / empty /
 *                 error / never) and how many have gone stale.
 *   rejections  — variants the importer dropped at the sanity gate (Trust#9),
 *                 totalled, broken down by reason, with the worst offenders.
 *   duplicates  — likely-duplicate roaster counts (Trust#7).
 *   addresses   — address-quality bucket counts (Trust#6).
 *
 * Pure read: builds a plain array, mutates nothing.
 */
class DataQualityReport
{
    public function __construct(
        private DuplicateRoasterDetector $duplicates = new DuplicateRoasterDetector(),
        private AddressQualityChecker $addresses = new AddressQualityChecker(),
    ) {
    }

    public function build(int $staleDays = 7): array
    {
        $staleCutoff = Carbon::now()->subDays($staleDays);

        // --- Imports (Trust#1) -------------------------------------------------
        $total = Roaster::where('is_active', true)->count();
        $success = Roaster::where('is_active', true)->where('last_import_status', 'success')->count();
        $empty = Roaster::where('is_active', true)->where('last_import_status', 'empty')->count();
        $error = Roaster::where('is_active', true)->where('last_import_status', 'error')->count();
        $never = Roaster::where('is_active', true)->whereNull('last_imported_at')->count();
        $stale = Roaster::where('is_active', true)
            ->whereNotNull('last_imported_at')
            ->where('last_imported_at', '<', $staleCutoff)
            ->count();

        // --- Rejections (Trust#9) ---------------------------------------------
        // The table is a snapshot of the latest import per roaster, so these
        // are "currently outstanding" drops rather than a historical tally.
        $rejectionTotal = ScraperRejectionLog::count();
        $rejectionByReason = ScraperRejectionLog::query()
            ->selectRaw('reason, COUNT(*) as cnt')
            ->groupBy('reason')
            ->pluck('cnt', 'reason')
            ->map(fn ($c) => (int) $c)
            ->all();
        $rejectionTopRoasters = ScraperRejectionLog::query()
            ->selectRaw('roaster_id, COUNT(*) as cnt')
            ->groupBy('roaster_id')
            ->orderByDesc('cnt')
            ->limit(5)
            ->with('roaster:id,name')
            ->get()
            ->map(fn ($row) => [
                'roaster' => $row->roaster?->name ?? "#{$row->roaster_id}",
                'count' => (int) $row->cnt,
            ])
            ->all();

        // --- Duplicates (Trust#7) ---------------------------------------------
        $dup = $this->duplicates->detect(
            Roaster::where('is_active', true)->get(['id', 'name', 'slug', 'website', 'is_active'])
        );

        // --- Addresses (Trust#6) ----------------------------------------------
        $addr = $this->addresses->check(
            Roaster::where('is_active', true)->get([
                'id', 'name', 'slug', 'city', 'region', 'latitude', 'longitude',
                'address_source', 'street_address', 'postal_code', 'address_verified_at', 'is_online_only',
            ])
        );

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'window_days' => $staleDays,
            'imports' => [
                'total' => $total,
                'success' => $success,
                'empty' => $empty,
                'error' => $error,
                'never' => $never,
                'stale' => $stale,
            ],
            'rejections' => [
                'total' => $rejectionTotal,
                'by_reason' => $rejectionByReason,
                'top_roasters' => $rejectionTopRoasters,
            ],
            'duplicates' => [
                'host_groups' => count($dup['host_groups']),
                'name_groups' => count($dup['name_groups']),
                'similar_pairs' => count($dup['similar_pairs']),
            ],
            'addresses' => [
                'flagged' => $addr['flagged'],
                'buckets' => array_map('count', $addr['buckets']),
                'online_only' => $addr['online_only'],
                'ok' => $addr['ok'],
            ],
        ];
    }

    /** True when the report surfaces anything an operator should act on. */
    public function hasIssues(array $report): bool
    {
        return $report['imports']['error'] > 0
            || $report['imports']['empty'] > 0
            || $report['imports']['stale'] > 0
            || $report['imports']['never'] > 0
            || $report['rejections']['total'] > 0
            || $report['duplicates']['host_groups'] > 0
            || $report['duplicates']['name_groups'] > 0
            || $report['duplicates']['similar_pairs'] > 0
            || $report['addresses']['flagged'] > 0;
    }
}
