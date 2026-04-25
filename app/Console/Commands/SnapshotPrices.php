<?php

namespace App\Console\Commands;

use App\Models\CoffeeVariant;
use App\Models\PriceHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SnapshotPrices extends Command
{
    protected $signature = 'prices:snapshot {--dry-run : Print summary without writing}';
    protected $description = 'Record current prices of all coffee variants into the price_history table';

    public function handle(): int
    {
        $variants = CoffeeVariant::with('coffee.roaster')->get();
        $now = Carbon::now();
        $rows = [];

        foreach ($variants as $variant) {
            $rows[] = [
                'coffee_variant_id' => $variant->id,
                'price' => $variant->price,
                'in_stock' => $variant->in_stock,
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->info("Will record {$variants->count()} price points at {$now->toIso8601String()}.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing written.');
            return self::SUCCESS;
        }

        if (!empty($rows)) {
            PriceHistory::insert($rows);
        }

        $this->info("Snapshot complete.");
        return self::SUCCESS;
    }
}
