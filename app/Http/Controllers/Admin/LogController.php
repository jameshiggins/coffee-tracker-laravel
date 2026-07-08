<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /admin/logs — the operator's window into the admin_logs stream, plus the
 * runtime verbose-logging toggle. Errors/warnings/audit events are always
 * recorded; the toggle adds debug-level detail (per-product import
 * decisions, per-recipient mail sends, …) without a deploy or restart.
 */
class LogController extends Controller
{
    public function index(Request $request)
    {
        $level = $request->query('level');
        $event = trim((string) $request->query('event'));
        $q = trim((string) $request->query('q'));

        $logs = AdminLog::query()
            ->when(in_array($level, ['debug', 'info', 'warning', 'error'], true),
                fn ($query) => $query->where('level', $level))
            ->when($event !== '', fn ($query) => $query->where('event', 'like', $event . '%'))
            ->when($q !== '', fn ($query) => $query->where('message', 'like', '%' . $q . '%'))
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        $counts = AdminLog::query()
            ->selectRaw('level, COUNT(*) as c')
            ->groupBy('level')
            ->pluck('c', 'level');

        return view('admin.logs.index', [
            'logs' => $logs,
            'counts' => $counts,
            'verbose' => Setting::verboseLogging(),
            'filters' => ['level' => $level, 'event' => $event, 'q' => $q],
        ]);
    }

    public function toggleVerbose(Request $request): RedirectResponse
    {
        $on = ! Setting::verboseLogging();
        Setting::put('verbose_logging', $on ? '1' : '0');

        // The toggle itself is an audit event — always recorded.
        AdminLog::info('admin.settings.verbose_logging', 'Verbose logging turned ' . ($on ? 'ON' : 'OFF'), [
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.logs.index')
            ->with('success', 'Verbose logging is now ' . ($on ? 'ON' : 'OFF') . '.');
    }
}
