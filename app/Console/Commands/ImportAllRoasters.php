<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use App\Services\RoasterImporter;
use App\Services\Scraping\RateLimitedException;
use Illuminate\Console\Command;

class ImportAllRoasters extends Command
{
    protected $signature = 'roasters:import-all
                            {--only= : Slug of a single roaster to (re-)import}
                            {--dry-run : List what would be attempted without making HTTP calls}';

    protected $description = 'Re-import current inventory for every active roaster with a website (Shopify storefronts).';

    /**
     * How long to stand back the first time the storefront platform answers
     * 429 after the scraper's own retries. Shopify's per-IP window is longer
     * than the ≤30 s Retry-After the scraper honours, so without this the
     * rest of the alphabet failed on bad nights.
     */
    public const RATE_LIMIT_PAUSE_SECONDS = 90;

    public function handle(RoasterImporter $importer): int
    {
        $query = Roaster::query()->where('is_active', true)->whereNotNull('website');
        if ($slug = $this->option('only')) {
            $query->where('slug', $slug);
        }
        $roasters = $query->orderBy('name')->get();

        if ($roasters->isEmpty()) {
            $this->warn('No matching roasters with a website.');
            return self::SUCCESS;
        }

        $this->info("Will attempt {$roasters->count()} roaster(s).");
        if ($this->option('dry-run')) {
            foreach ($roasters as $r) {
                $this->line("  • {$r->name} — {$r->website}");
            }
            $this->warn('Dry run — nothing fetched.');
            return self::SUCCESS;
        }

        $ok = 0;
        $failed = [];
        $deferred = [];
        $paused = false;
        foreach ($roasters as $i => $r) {
            // Pace the run. ~90 of these stores are Shopify, which rate-limits
            // products.json PER CLIENT IP platform-wide — a zero-gap burst from
            // one Fly egress IP tripped their limiter and 429-failed every
            // remaining Shopify roaster on bad nights. A couple of seconds
            // between roasters keeps the request rate under the radar and costs
            // ~4 minutes at 11:00 UTC. Skipped for a single --only re-import
            // and under the test suite (mirrors the cacheDirectory precedent).
            if ($i > 0 && ! $slug && ! app()->runningUnitTests()) {
                \Illuminate\Support\Sleep::for(2)->seconds();
            }
            try {
                $imported = $importer->import($r->website, name: $r->name, city: $r->city, region: $r->region);
                $count = $imported->coffees()->count();
                $this->line(sprintf("  ✓ %-40s %d beans imported", $r->name, $count));
                $ok++;
            } catch (RateLimitedException $e) {
                // The platform is throttling our IP. One tripped limiter used to
                // fail every remaining Shopify roaster in the run (16–20 "errors"
                // on a bad night, all 429). Park this roaster for a second pass,
                // and the first time it happens, stand back long enough for the
                // limiter window to close before carrying on.
                $deferred[] = $r;
                $this->line(sprintf("  ⏸ %-40s rate limited — deferred", $r->name));
                if (! $paused) {
                    $paused = true;
                    $this->warn(sprintf('  Storefront platform is rate limiting this IP; pausing %ds before continuing.', self::RATE_LIMIT_PAUSE_SECONDS));
                    \Illuminate\Support\Sleep::for(self::RATE_LIMIT_PAUSE_SECONDS)->seconds();
                }
            } catch (\Throwable $e) {
                $failed[] = ['roaster' => $r->name, 'reason' => $e->getMessage()];
                $this->line(sprintf("  ✗ %-40s %s", $r->name, $this->shortReason($e->getMessage())));
            }
        }

        // Second pass for the rate-limited roasters. Still throttled = skipped,
        // not failed: their last status stays whatever the previous night said.
        $skipped = [];
        if ($deferred !== []) {
            $this->newLine();
            $this->info('Retrying ' . count($deferred) . ' rate-limited roaster(s).');
            foreach ($deferred as $r) {
                if (! $slug && ! app()->runningUnitTests()) {
                    \Illuminate\Support\Sleep::for(2)->seconds();
                }
                try {
                    $imported = $importer->import($r->website, name: $r->name, city: $r->city, region: $r->region);
                    $this->line(sprintf("  ✓ %-40s %d beans imported", $r->name, $imported->coffees()->count()));
                    $ok++;
                } catch (RateLimitedException $e) {
                    $skipped[] = $r->name;
                    $this->line(sprintf("  ⏸ %-40s still rate limited — skipped", $r->name));
                } catch (\Throwable $e) {
                    $failed[] = ['roaster' => $r->name, 'reason' => $e->getMessage()];
                    $this->line(sprintf("  ✗ %-40s %s", $r->name, $this->shortReason($e->getMessage())));
                }
            }
        }

        $this->newLine();
        $this->info("Done: {$ok} imported, " . count($failed) . ' failed, ' . count($skipped) . ' skipped (rate limited).');

        // Systemic-failure signal. A handful of dead roasters is normal (sites
        // go down) and stays SUCCESS — the daily ops email itemizes them. But
        // if EVERY attempted roaster failed, something is broadly wrong (network
        // down, a bad deploy, a dependency break), so exit non-zero: the
        // scheduler's emailOutputOnFailure then pages instead of the failure
        // hiding behind a green exit code.
        if ($ok === 0 && $roasters->isNotEmpty()) {
            $this->error('Every roaster failed to import — treating as a systemic failure.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function shortReason(string $msg): string
    {
        // Trim noisy multi-line exception messages to a single readable summary.
        $first = strtok($msg, "\n");
        return strlen($first) > 100 ? substr($first, 0, 97) . '…' : $first;
    }
}
