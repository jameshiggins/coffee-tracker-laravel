<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use App\Services\DuplicateRoasterDetector;
use Illuminate\Console\Command;

class FindDuplicateRoasters extends Command
{
    protected $signature = 'roasters:find-duplicates
                            {--threshold=0.85 : Name-similarity cutoff (0–1) for the fuzzy pass}
                            {--include-inactive : Also consider is_active=false roasters}';

    protected $description = 'Report likely-duplicate roasters by shared website host and canonicalized-name similarity. Read-only — never merges or deletes.';

    public function handle(DuplicateRoasterDetector $detector): int
    {
        $threshold = (float) $this->option('threshold');

        $query = Roaster::query();
        if (!$this->option('include-inactive')) {
            $query->where('is_active', true);
        }
        $roasters = $query->orderBy('name')->get(['id', 'name', 'slug', 'website', 'is_active']);

        $result = $detector->detect($roasters, $threshold);
        $hostGroups = $result['host_groups'];
        $nameGroups = $result['name_groups'];
        $pairs = $result['similar_pairs'];

        $this->info(sprintf(
            'Scanned %d roaster(s). Found %d shared-host group(s), %d identical-name group(s), %d similar-name pair(s).',
            $roasters->count(), count($hostGroups), count($nameGroups), count($pairs)
        ));

        if (!$hostGroups && !$nameGroups && !$pairs) {
            $this->line('No likely duplicates. ✓');
            return self::SUCCESS;
        }

        if ($hostGroups) {
            $this->newLine();
            $this->warn('▸ Same website host (high confidence):');
            foreach ($hostGroups as $group) {
                $this->line("  • {$group[0]['host']}");
                foreach ($group as $r) {
                    $this->line(sprintf('      #%-5d %-40s %s', $r['id'], $r['name'], $this->stateTag($r)));
                }
            }
        }

        if ($nameGroups) {
            $this->newLine();
            $this->warn('▸ Identical canonical name:');
            foreach ($nameGroups as $group) {
                $this->line("  • \"{$group[0]['canon']}\"");
                foreach ($group as $r) {
                    $this->line(sprintf('      #%-5d %-40s %s %s', $r['id'], $r['name'], $r['website'] ?? '', $this->stateTag($r)));
                }
            }
        }

        if ($pairs) {
            $this->newLine();
            $this->warn('▸ Similar names (review):');
            foreach ($pairs as $p) {
                $this->line(sprintf(
                    '  • %3.0f%%  #%d %s  ⇄  #%d %s',
                    $p['score'] * 100, $p['a']['id'], $p['a']['name'], $p['b']['id'], $p['b']['name']
                ));
            }
        }

        $this->newLine();
        $this->comment('Read-only report — resolve any real merges manually in the admin.');
        return self::SUCCESS;
    }

    private function stateTag(array $r): string
    {
        return $r['is_active'] ? '' : '(inactive)';
    }
}
