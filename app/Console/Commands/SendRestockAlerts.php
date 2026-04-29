<?php

namespace App\Console\Commands;

use App\Mail\RestockDigest;
use App\Models\CoffeeVariant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Q14: emails users one digest per day listing their wishlisted coffees
 * that just transitioned out-of-stock → in-stock since yesterday's run.
 *
 * Schedule: dailyAt('14:00') (≈ 07:00 PST), about 3h after the daily
 * import finishes so the deltas are settled.
 */
class SendRestockAlerts extends Command
{
    protected $signature = 'alerts:restock {--dry-run : List recipients without sending}';
    protected $description = 'Mail users a digest of their wishlisted beans that came back in stock today.';

    public function handle(): int
    {
        // Variants that flipped to in-stock in the last 24 hours.
        $recentlyRestocked = CoffeeVariant::query()
            ->where('in_stock', true)
            ->whereNotNull('in_stock_changed_at')
            ->where('in_stock_changed_at', '>=', now()->subHours(24))
            ->pluck('coffee_id')
            ->unique()
            ->values();

        if ($recentlyRestocked->isEmpty()) {
            $this->info('No restocks in the last 24h.');
            return self::SUCCESS;
        }

        // Verified users with a wishlist entry on any of these coffees.
        $users = User::query()
            ->whereNotNull('email_verified_at')
            ->whereHas('wishlist', fn ($q) => $q->whereIn('coffee_id', $recentlyRestocked))
            ->with(['wishlist.coffee.roaster' => fn ($q) => $q])
            ->get();

        $sent = 0;
        $frontendBase = rtrim(config('services.google.frontend_url', 'http://localhost:5174'), '/');

        foreach ($users as $user) {
            $hits = $user->wishlist
                ->filter(fn ($w) => $recentlyRestocked->contains($w->coffee_id) && $w->coffee && !$w->coffee->removed_at)
                ->map(fn ($w) => [
                    'id' => $w->coffee->id,
                    'name' => $w->coffee->name,
                    'image_url' => $w->coffee->image_url,
                    'roaster_name' => $w->coffee->roaster->name,
                    'frontend_url' => "{$frontendBase}/c/{$w->coffee->id}",
                ])
                ->values()
                ->all();

            if (empty($hits)) continue;

            $unsubscribe = "{$frontendBase}/me/email-preferences"; // placeholder until preferences page lands

            if ($this->option('dry-run')) {
                $this->line("  → {$user->email}  (" . count($hits) . " coffees)");
            } else {
                Mail::to($user->email)->send(new RestockDigest($hits, $unsubscribe));
                $sent++;
            }
        }

        $this->info($this->option('dry-run')
            ? "Dry run: would send {$users->count()} emails."
            : "Sent {$sent} restock digest(s).");

        return self::SUCCESS;
    }
}
