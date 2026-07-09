<?php

namespace App\Services;

use App\Models\Roaster;
use App\Models\ScraperRejectionLog;
use App\Models\SystemHeartbeat;
use Illuminate\Support\Carbon;

/**
 * Ops notifications: roll the last day's operational signals into one
 * structured array for the daily ops summary email. Where the weekly
 * DataQualityReport is a comprehensive audit (duplicates, address gaps), this
 * is the tight "what happened in the last 24h + is everything alive" pulse:
 *
 *   roasters_added — roasters created inside the window (directory growth;
 *                    previously surfaced nowhere).
 *   import_errors  — active roasters whose last import errored, with the error
 *                    message so the line is actionable. Current-state, matching
 *                    GET /up's imports check — a roaster that broke days ago and
 *                    hasn't recovered is still broken.
 *   rejections     — variants the importer dropped at the sanity gate (Trust#9),
 *                    the current outstanding snapshot, by reason + worst offenders.
 *   mail           — delivery confirmation from the mail.sent heartbeat: when the
 *                    transport last accepted a message, and whether that's recent.
 *
 * Pure read: builds a plain array, mutates nothing. The daily email's reliable
 * arrival is itself the "mail + scheduler are alive" signal; its absence is the
 * alarm, backstopped by the GET /up uptime monitor.
 */
class DailyOpsReport
{
    /**
     * Mail is considered healthy if the transport accepted a message within
     * this window. The daily email sends every day and bumps mail.sent itself,
     * so once running, a gap longer than this means mail is genuinely broken.
     * Slightly over 24h to avoid edge-of-window false positives.
     */
    public const MAIL_STALE_AFTER_HOURS = 26;

    /** Cap the itemized dropped-bean list so a runaway feed can't bloat the email. */
    public const MAX_REJECTION_ITEMS = 50;

    public function build(int $windowHours = 24): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subHours($windowHours);

        // --- Roasters added (directory growth in the window) -------------------
        $added = Roaster::where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get(['name', 'slug', 'city', 'region', 'is_active', 'created_at'])
            ->map(fn (Roaster $r) => [
                'name' => $r->name,
                'slug' => $r->slug,
                'city' => $r->city,
                'region' => $r->region,
                'is_active' => (bool) $r->is_active,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();

        // --- Import errors (current failing roasters, actionable) -------------
        $errored = Roaster::where('is_active', true)
            ->where('last_import_status', 'error')
            ->orderByDesc('last_imported_at')
            ->get(['name', 'slug', 'last_import_error', 'last_imported_at'])
            ->map(fn (Roaster $r) => [
                'name' => $r->name,
                'slug' => $r->slug,
                'error' => $this->truncate($r->last_import_error),
                'last_imported_at' => $r->last_imported_at?->toIso8601String(),
            ])
            ->all();

        // --- Variant rejections (current snapshot, mirrors the weekly digest) --
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

        // --- Mail delivery confirmation ---------------------------------------
        $lastSent = SystemHeartbeat::lastSeen('mail.sent');
        $mailHealthy = $lastSent !== null
            && $lastSent->gt($now->copy()->subHours(self::MAIL_STALE_AFTER_HOURS));

        return [
            'generated_at' => $now->toIso8601String(),
            'window_hours' => $windowHours,
            'since' => $since->toIso8601String(),
            'roasters_added' => [
                'count' => count($added),
                'list' => $added,
            ],
            'import_errors' => [
                'count' => count($errored),
                'list' => $errored,
            ],
            'rejections' => [
                'total' => $rejectionTotal,
                'by_reason' => $rejectionByReason,
                'top_roasters' => $rejectionTopRoasters,
                // The actual dropped beans (name + reason + offending numbers),
                // so the email says WHICH beans went and why — not just a count.
                'items' => ScraperRejectionLog::itemizedSnapshot(self::MAX_REJECTION_ITEMS),
            ],
            'mail' => [
                'last_sent' => $lastSent?->toIso8601String(),
                'healthy' => $mailHealthy,
                'age_hours' => $lastSent ? (int) round($lastSent->diffInHours($now)) : null,
            ],
        ];
    }

    /**
     * True when the report contains something an operator should look at:
     * a new roaster, a current import error, an outstanding variant rejection,
     * or mail that has gone quiet past the healthy window. Used by the command's
     * --only-when-notable mode to suppress all-clear days.
     */
    public function isNotable(array $report): bool
    {
        return $report['roasters_added']['count'] > 0
            || $report['import_errors']['count'] > 0
            || $report['rejections']['total'] > 0
            || $report['mail']['healthy'] === false;
    }

    /** Keep import-error messages to one readable line in the email. */
    private function truncate(?string $value, int $limit = 160): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
