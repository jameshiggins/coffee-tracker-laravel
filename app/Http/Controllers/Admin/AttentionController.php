<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportRoasterJob;
use App\Models\AdminLog;
use App\Models\Roaster;
use Illuminate\Http\RedirectResponse;

/**
 * /admin/attention — triage view for roasters that need operator attention,
 * grouped by cause so each bucket has an obvious action:
 *
 *   dead_domain    → domain unresolvable; auto-deactivated after 7 days, or
 *                    bulk-deactivate them here now
 *   blocked        → 401/403 bot-block, often transient → Retry
 *   error          → other import failure → Retry / investigate
 *   empty          → site alive but zero coffees (scraper coverage or genuinely
 *                    sold out) → Retry
 *   never_imported → added manually, never scraped → Retry
 */
class AttentionController extends Controller
{
    public function index()
    {
        $active = Roaster::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $groups = [
            'dead_domain' => collect(),
            'blocked' => collect(),
            'error' => collect(),
            'empty' => collect(),
            'never_imported' => collect(),
        ];

        foreach ($active as $roaster) {
            $kind = $roaster->importErrorKind();
            if ($kind !== null) {
                $groups[$kind]->push($roaster);
            } elseif ($roaster->last_imported_at === null) {
                $groups['never_imported']->push($roaster);
            } elseif ($roaster->last_import_status === 'empty') {
                $groups['empty']->push($roaster);
            }
        }

        $healthy = $active->count() - array_sum(array_map(fn ($g) => $g->count(), $groups));

        return view('admin.attention.index', [
            'groups' => $groups,
            'healthy' => $healthy,
            'total' => $active->count(),
        ]);
    }

    /** Deactivate every dead-domain roaster in one click. */
    public function deactivateDead(): RedirectResponse
    {
        $dead = Roaster::query()
            ->where('is_active', true)
            ->where('last_import_status', 'error')
            ->get()
            ->filter(fn (Roaster $r) => $r->importErrorKind() === 'dead_domain');

        foreach ($dead as $roaster) {
            $roaster->update(['is_active' => false]);
            AdminLog::warning('admin.roaster.deactivated', "Roaster deactivated (dead domain, bulk): {$roaster->name}", [
                'roaster_id' => $roaster->id, 'website' => $roaster->website,
            ]);
        }

        return redirect()->route('admin.attention.index')
            ->with('success', "Deactivated {$dead->count()} dead-domain roaster(s). Data preserved — reactivate any by editing it.");
    }

    /** Retry importing a roaster right now (queues the scrape). */
    public function retry(Roaster $roaster): RedirectResponse
    {
        if (! $roaster->website) {
            return back()->withErrors(['website' => "{$roaster->name} has no website to import from."]);
        }

        ImportRoasterJob::dispatch($roaster->website, $roaster->name, $roaster->city, $roaster->region);
        AdminLog::info('admin.import.refresh_queued', "Retry queued from Needs Attention: {$roaster->name}", [
            'roaster_id' => $roaster->id, 'website' => $roaster->website,
        ]);

        return back()->with('success', "Retry queued for {$roaster->name} — refresh in a moment.");
    }
}
