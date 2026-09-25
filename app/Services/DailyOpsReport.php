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
 *   roasters_added — roasters created inside the window (directory growth).
 *   import_errors  — active roasters whose last import errored, split into
 *                    NEW (started failing inside the window), ONGOING (older;
 *                    listed with how long) and WATCHING (a first slow night,
 *                    or a stale rate-limit verdict — real, but not yet worth
 *                    an operator's morning).
 *   rejections     — variants the importer dropped at the sanity gate, the
 *                    current outstanding snapshot, split NEW vs ONGOING by
 *                    first_seen_at, with reviewed rows kept out of the email.
 *   mail           — delivery confirmation from the mail.sent heartbeat.
 *
 * Only the NEW halves (plus a roaster added or mail going quiet) make a day
 * notable. The first version flagged any nonzero count, and because both the
 * error list and the rejection snapshot are standing state it said "action
 * needed" fifty mornings in a row — a subject line that never changes is one
 * nobody reads.
 *
 * Pure read: builds a plain array, mutates nothing.
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

    /** A timeout only counts once it has repeated: hosts have slow nights. */
    public const TIMEOUT_GRACE_HOURS = 36;

    /** Error kinds that describe our run rather than the roaster; never notable. */
    public const WATCH_ONLY_KINDS = ['rate_limited'];

    public const ERROR_KIND_LABELS = [
        'dead_domain' => 'dead domain',
        'blocked' => 'blocked (401/403)',
        'rate_limited' => 'rate limited (429)',
        'timeout' => 'timed out',
        'error' => 'error',
    ];

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

        // --- Import errors (current failing roasters, bucketed) ---------------
        $errored = Roaster::where('is_active', true)
            ->where('last_import_status', 'error')
            ->orderByDesc('last_imported_at')
            ->get(['name', 'slug', 'last_import_status', 'last_import_error', 'last_imported_at', 'import_failing_since'])
            ->map(function (Roaster $r) use ($since, $now) {
                $kind = $r->importErrorKind() ?? 'error';
                $failingSince = $r->import_failing_since ?? $r->last_imported_at;
                $ageHours = $failingSince ? (int) $failingSince->diffInHours($now) : null;

                if (in_array($kind, self::WATCH_ONLY_KINDS, true)) {
                    $bucket = 'watching';
                } elseif ($kind === 'timeout' && $ageHours !== null && $ageHours < self::TIMEOUT_GRACE_HOURS) {
                    $bucket = 'watching';
                } elseif ($failingSince === null || $failingSince->gte($since)) {
                    $bucket = 'new';
                } else {
                    $bucket = 'ongoing';
                }

                return [
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'kind' => $kind,
                    'kind_label' => self::ERROR_KIND_LABELS[$kind] ?? $kind,
                    'error' => $this->truncate($r->last_import_error),
                    'last_imported_at' => $r->last_imported_at?->toIso8601String(),
                    'failing_since' => $failingSince?->toIso8601String(),
                    'failing_since_label' => $failingSince?->format('M j'),
                    'age_days' => $ageHours === null ? null : intdiv($ageHours, 24),
                    'bucket' => $bucket,
                ];
            });
        $errorGroups = [
            'new' => $errored->where('bucket', 'new')->values()->all(),
            'ongoing' => $errored->where('bucket', 'ongoing')->sortBy('failing_since')->values()->all(),
            'watching' => $errored->where('bucket', 'watching')->values()->all(),
        ];

        // --- Variant rejections (current snapshot, reviewed rows hidden) -------
        $newSeen = fn ($q) => $q->where(fn ($w) => $w->whereNull('first_seen_at')->orWhere('first_seen_at', '>=', $since));
        $rejectionTotal = ScraperRejectionLog::unreviewed()->count();
        $rejectionNew = ScraperRejectionLog::unreviewed()->tap($newSeen)->count();
        $rejectionReviewed = ScraperRejectionLog::reviewed()->count();
        $rejectionByReason = ScraperRejectionLog::unreviewed()
            ->selectRaw('reason, COUNT(*) as cnt')
            ->groupBy('reason')
            ->pluck('cnt', 'reason')
            ->map(fn ($c) => (int) $c)
            ->all();
        $rejectionTopRoasters = ScraperRejectionLog::unreviewed()
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
        $items = collect(ScraperRejectionLog::itemizedSnapshot(self::MAX_REJECTION_ITEMS))
            ->map(function (array $it) use ($since) {
                $firstSeen = $it['first_seen_at'] ? Carbon::parse($it['first_seen_at']) : null;
                $it['is_new'] = $firstSeen === null || $firstSeen->gte($since);
                $it['first_seen_label'] = $firstSeen?->format('M j');
                $it['suspected_label'] = ScraperRejectionLog::suspectLabels()[$it['suspected']] ?? $it['suspected'];

                return $it;
            });

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
                'count' => $errored->count(),
                'new' => count($errorGroups['new']),
                'ongoing' => count($errorGroups['ongoing']),
                'watching' => count($errorGroups['watching']),
                'list' => $errored->values()->all(),
                'groups' => $errorGroups,
            ],
            'rejections' => [
                'total' => $rejectionTotal,
                'new' => $rejectionNew,
                'ongoing' => $rejectionTotal - $rejectionNew,
                'reviewed' => $rejectionReviewed,
                'by_reason' => $rejectionByReason,
                'top_roasters' => $rejectionTopRoasters,
                // The actual dropped beans (name + reason + offending numbers +
                // suspected cause), so the email says WHICH beans went and why.
                'items' => $items->all(),
                'new_items' => $items->where('is_new', true)->values()->all(),
                'ongoing_items' => $items->where('is_new', false)->values()->all(),
            ],
            'mail' => [
                'last_sent' => $lastSent?->toIso8601String(),
                'healthy' => $mailHealthy,
                'age_hours' => $lastSent ? (int) round($lastSent->diffInHours($now)) : null,
            ],
        ];
    }

    /**
     * True when something CHANGED that an operator should look at today: a
     * roaster added, a roaster that started failing, a variant newly dropped,
     * or mail going quiet. Ongoing state is listed but does not re-flag.
     */
    public function isNotable(array $report): bool
    {
        return $report['roasters_added']['count'] > 0
            || $report['import_errors']['new'] > 0
            || $report['rejections']['new'] > 0
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
