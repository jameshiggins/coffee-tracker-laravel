<?php

namespace App\Console\Commands;

use App\Models\CoffeeVariant;
use App\Models\PriceHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SnapshotPrices extends Command
{
    protected $signature = 'prices:snapshot {--dry-run : Print summary without writing}';
    protected $description = 'Record current prices of all coffee variants into the price_history table.';

    public function handle(): int
    {
        $variants = CoffeeVariant::all();
        $now = Carbon::now();
        $today = $now->toDateString();

        $this->info("Recording {$variants->count()} variant prices for {$today}.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing written.');
            return self::SUCCESS;
        }

        // Idempotent within a single day: if a row already exists for today,
        // overwrite it with the current price/in_stock; otherwise insert.
        // Cron retries on the same day are safe and don't grow the table.
        DB::transaction(function () use ($variants, $now, $today) {
            foreach ($variants as $variant) {
                PriceHistory::updateOrCreate(
                    [
                        'coffee_variant_id' => $variant->id,
                        // SQLite-compatible day match: store recorded_at at midnight UTC.
                        'recorded_at' => Carbon::parse($today),
                    ],
                    [
                        'price' => $variant->price,
                        'in_stock' => $variant->in_stock,
                    ]
                );
            }
        });

        $this->info('Snapshot complete.');
        return self::SUCCESS;
    }
}
