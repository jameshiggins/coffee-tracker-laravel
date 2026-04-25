<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use App\Services\RoasterImporter;
use Illuminate\Console\Command;

class ImportAllRoasters extends Command
{
    protected $signature = 'roasters:import-all
                            {--only= : Slug of a single roaster to (re-)import}
                            {--dry-run : List what would be attempted without making HTTP calls}';

    protected $description = 'Re-import current inventory for every active roaster with a website (Shopify storefronts).';

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
        foreach ($roasters as $r) {
            try {
                $imported = $importer->import($r->website, name: $r->name, city: $r->city, region: $r->region);
                $count = $imported->coffees()->count();
                $this->line(sprintf("  ✓ %-40s %d beans imported", $r->name, $count));
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = ['roaster' => $r->name, 'reason' => $e->getMessage()];
                $this->line(sprintf("  ✗ %-40s %s", $r->name, $this->shortReason($e->getMessage())));
            }
        }

        $this->newLine();
        $this->info("Done: {$ok} imported, " . count($failed) . " failed.");
        return self::SUCCESS;
    }

    private function shortReason(string $msg): string
    {
        // Trim noisy multi-line exception messages to a single readable summary.
        $first = strtok($msg, "\n");
        return strlen($first) > 100 ? substr($first, 0, 97) . '…' : $first;
    }
}
