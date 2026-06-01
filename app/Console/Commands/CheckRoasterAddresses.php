<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use App\Services\AddressQualityChecker;
use Illuminate\Console\Command;

class CheckRoasterAddresses extends Command
{
    protected $signature = 'roasters:check-addresses
                            {--stale-months=12 : Flag verifications missing or older than this many months}
                            {--include-inactive : Also check is_active=false roasters}';

    protected $description = 'Read-only audit of roaster address quality: unplaced pins, city-centroid-only coordinates, missing street/postal, and stale verifications.';

    public function handle(AddressQualityChecker $checker): int
    {
        $query = Roaster::query();
        if (!$this->option('include-inactive')) {
            $query->where('is_active', true);
        }
        $roasters = $query->orderBy('name')->get([
            'id', 'name', 'slug', 'city', 'region', 'latitude', 'longitude',
            'address_source', 'street_address', 'postal_code', 'address_verified_at', 'is_online_only',
        ]);

        $months = (int) $this->option('stale-months');
        $report = $checker->check($roasters, $months);

        $this->info(sprintf(
            'Checked %d physical roaster(s) (%d online-only excluded). %d OK, %d flagged.',
            $roasters->count() - $report['online_only'],
            $report['online_only'],
            $report['ok'],
            $report['flagged']
        ));

        $labels = [
            'unplaced' => 'No coordinates — invisible on the map',
            'centroid_only' => 'City-centroid only — never resolved to a real address',
            'missing_street' => 'Missing street address',
            'missing_postal' => 'Missing postal code',
            'stale' => "Verification missing or older than {$months} months",
        ];

        foreach (AddressQualityChecker::BUCKETS as $bucket) {
            $rows = $report['buckets'][$bucket];
            if (!$rows) continue;
            $this->newLine();
            $this->warn(sprintf('▸ %s (%d):', $labels[$bucket], count($rows)));
            foreach ($rows as $r) {
                $loc = trim(($r['city'] ?? '') . ($r['region'] ? ', ' . $r['region'] : ''), ', ');
                $this->line(sprintf('    #%-5d %-40s %s', $r['id'], $r['name'], $loc));
            }
        }

        if ($report['flagged'] === 0) {
            $this->line('All physical roasters have complete, current addresses. ✓');
        } else {
            $this->newLine();
            $this->comment('Read-only audit. Refresh one row with: roasters:scrape-addresses --force --only=<slug>');
        }

        return self::SUCCESS;
    }
}
