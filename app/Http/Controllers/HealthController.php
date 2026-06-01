<?php

namespace App\Http\Controllers;

use App\Models\Roaster;
use App\Models\SystemHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /up — liveness + readiness probe for external uptime monitors
 * (UptimeRobot, Better Stack) and a quick human glance.
 *
 * Returns 200 when the infrastructure is healthy and 503 when something an
 * uptime monitor should page on is wrong: the database is unreachable, or the
 * scheduler has stopped ticking. Data-quality concerns (import errors, mail
 * gone quiet) are reported in the body but never flip the status to 503 —
 * those belong in the daily/weekly digest, not a 3 a.m. uptime page.
 *
 * The body carries per-check status only — no secrets, no PII — so it's safe
 * to expose unauthenticated.
 */
class HealthController extends Controller
{
    /** schedule:work bumps scheduler.tick every 5 min; allow ~3 misses. */
    private const SCHEDULER_STALE_AFTER_MINUTES = 15;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'scheduler' => $this->checkScheduler(),
            'mail' => $this->checkMail(),
            'imports' => $this->checkImports(),
        ];

        // Only infrastructure failures (database, scheduler) page the monitor.
        $healthy = ($checks['database']['ok'] ?? false) && ($checks['scheduler']['ok'] ?? false);

        return response()->json([
            'ok' => $healthy,
            'status' => $healthy ? 'healthy' : 'degraded',
            'time' => Carbon::now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }

    private function checkScheduler(): array
    {
        $last = SystemHeartbeat::lastSeen('scheduler.tick');

        // Never ticked yet (e.g. the very first boot before the seed ran).
        // Treat as pending rather than paging on a brand-new deploy.
        if ($last === null) {
            return ['ok' => true, 'detail' => 'pending', 'last_tick' => null];
        }

        $stale = $last->lt(Carbon::now()->subMinutes(self::SCHEDULER_STALE_AFTER_MINUTES));

        return [
            'ok' => ! $stale,
            'detail' => $stale ? 'stale' : 'ticking',
            'last_tick' => $last->toIso8601String(),
        ];
    }

    private function checkMail(): array
    {
        // Informational only: mail is bursty and may be legitimately quiet
        // for days, so this never fails the probe — it just surfaces the last
        // time the transport accepted a message.
        $last = SystemHeartbeat::lastSeen('mail.sent');

        return [
            'ok' => true,
            'last_sent' => $last?->toIso8601String(),
        ];
    }

    private function checkImports(): array
    {
        // Informational: active roasters whose last import errored. Surfaced
        // for a quick glance; the weekly digest is the system of record.
        try {
            $errors = Roaster::where('is_active', true)
                ->where('last_import_status', 'error')
                ->count();

            return ['ok' => $errors === 0, 'errors' => $errors];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'unknown'];
        }
    }
}
