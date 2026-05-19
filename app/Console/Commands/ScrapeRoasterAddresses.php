<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use App\Services\Scraping\Address\AddressScraper;
use App\Services\Scraping\Address\ScrapedAddress;
use Illuminate\Console\Command;

/**
 * Q-AR: walk every active roaster and resolve a precise street address.
 *
 *   php artisan roasters:scrape-addresses
 *     - process every active roaster where address_source IS NULL and
 *       is_online_only IS false (i.e., not yet resolved)
 *   --force         re-process EVERY active roaster (refresh)
 *   --limit=N       cap the number processed (smoke-test runs)
 *   --only=X        target a single roaster by slug or exact name
 *
 * Politely rate-limits between roasters (≥0.5s) and inside the cascade
 * (≥1s between requests to nominatim.openstreetmap.org).
 *
 * Idempotent: re-running without --force is a no-op for already-resolved
 * rows, so the scheduler can run this monthly without churn.
 */
class ScrapeRoasterAddresses extends Command
{
    protected $signature = 'roasters:scrape-addresses
                            {--force : Re-process every roaster even when already resolved}
                            {--limit= : Maximum number of roasters to process this run}
                            {--only= : Only process a single roaster by slug or exact name}';

    protected $description = 'Resolve precise street addresses for roasters via JSON-LD / contact-page / Nominatim cascade.';

    /** Seconds to pause between roasters to be neighborly to Nominatim. */
    private const SLEEP_BETWEEN_ROASTERS = 0.5;

    public function handle(AddressScraper $scraper): int
    {
        $query = Roaster::query()
            ->where('is_active', true)
            ->whereNotNull('website');

        $force = (bool) $this->option('force');
        if (!$force) {
            $query->whereNull('address_source')
                  ->where(function ($q) {
                      $q->where('is_online_only', false)->orWhereNull('is_online_only');
                  });
        }

        if ($only = $this->option('only')) {
            $query->where(function ($q) use ($only) {
                $q->where('slug', $only)->orWhere('name', $only);
            });
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $roasters = $query->orderBy('name')->get();
        if ($roasters->isEmpty()) {
            $this->warn('No roasters to process.');
            return self::SUCCESS;
        }

        $this->info("Will attempt {$roasters->count()} roaster(s).");

        $resolved = 0;
        $onlineOnly = 0;
        foreach ($roasters as $r) {
            try {
                $result = $scraper->scrape($r, force: $force);
            } catch (\Throwable $e) {
                $this->line(sprintf('  ! %-40s cascade error: %s', $r->name, $this->shortReason($e->getMessage())));
                $this->maybeSleep();
                continue;
            }

            if ($result instanceof ScrapedAddress) {
                $this->persistResult($r, $result);
                $this->line(sprintf('  + %-40s [%s] %s', $r->name, $result->source, $result->street_address));
                $resolved++;
            } else {
                $this->persistOnlineOnly($r);
                $this->line(sprintf('  - %-40s online_only', $r->name));
                $onlineOnly++;
            }

            $this->maybeSleep();
        }

        $this->newLine();
        $this->info("Done: {$resolved} resolved, {$onlineOnly} marked online-only.");
        return self::SUCCESS;
    }

    private function persistResult(Roaster $r, ScrapedAddress $a): void
    {
        $r->fill([
            'street_address' => $a->street_address ?? $r->street_address,
            'postal_code' => $a->postal_code ?? $r->postal_code,
            // Only overwrite the seeder's city centroid when the new lat/lng
            // is actually available — otherwise leave the existing centroid
            // in place so the map has SOMETHING to render.
            'latitude' => $a->latitude ?? $r->latitude,
            'longitude' => $a->longitude ?? $r->longitude,
            'address_source' => $a->source,
            'address_verified_at' => now(),
            'is_online_only' => false,
        ])->save();
    }

    private function persistOnlineOnly(Roaster $r): void
    {
        $r->fill([
            'is_online_only' => true,
            'address_verified_at' => now(),
            // Leave address_source NULL — we didn't actually resolve anything,
            // and a NULL source is the cue for the next run to re-attempt
            // when --force is used.
        ])->save();
    }

    private function shortReason(string $msg): string
    {
        $first = strtok($msg, "\n");
        return strlen($first) > 100 ? substr($first, 0, 97) . '...' : $first;
    }

    /** Pace between roasters; skip the wait under test (Http::fake → no real network). */
    private function maybeSleep(): void
    {
        if (app()->runningUnitTests()) return;
        usleep((int) (self::SLEEP_BETWEEN_ROASTERS * 1_000_000));
    }
}
