<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\ScraperRejectionLog;
use Illuminate\Http\RedirectResponse;

/**
 * /admin/rejections — the variants the importer dropped at the sanity gate,
 * with the offending numbers and the gate's best guess at why, so an operator
 * can decide in one glance: a scraper bug to fix, a product the classifier
 * should have excluded, or a real 3 kg bag that is simply cheap per gram.
 *
 * "Mark reviewed" is that last verdict. The row keeps existing (it is still a
 * fact about the feed) but leaves the daily and weekly emails, and the flag
 * survives re-imports for as long as the same variant keeps tripping the gate
 * (RoasterImporter carries reviewed_at forward). If the variant changes or
 * disappears the row goes with it, so a genuinely new problem is never hidden
 * behind an old review.
 */
class RejectionController extends Controller
{
    public function index()
    {
        $open = ScraperRejectionLog::unreviewed()
            ->with('roaster:id,name,slug')
            ->orderBy('roaster_id')
            ->orderByDesc('first_seen_at')
            ->get()
            ->groupBy(fn (ScraperRejectionLog $r) => $r->roaster?->name ?? "#{$r->roaster_id}")
            ->sortKeys();

        $reviewed = ScraperRejectionLog::reviewed()
            ->with('roaster:id,name,slug')
            ->orderByDesc('reviewed_at')
            ->get();

        return view('admin.rejections.index', [
            'open' => $open,
            'openCount' => $open->flatten()->count(),
            'reviewed' => $reviewed,
            'reasonLabels' => ScraperRejectionLog::reasonLabels(),
            'suspectLabels' => ScraperRejectionLog::suspectLabels(),
        ]);
    }

    /** "I looked, this is fine" — retire the row from the ops emails. */
    public function review(ScraperRejectionLog $rejection): RedirectResponse
    {
        $rejection->update(['reviewed_at' => now()]);
        AdminLog::info('admin.rejection.reviewed', "Dropped variant marked reviewed: {$rejection->coffee_name} ({$rejection->roaster?->name})", [
            'rejection_id' => $rejection->id, 'roaster_id' => $rejection->roaster_id,
            'coffee_id' => $rejection->coffee_id, 'reason' => $rejection->reason, 'context' => $rejection->context,
        ]);

        return back()->with('success', "Marked reviewed: {$rejection->coffee_name}. It stays out of the ops emails while the feed keeps sending the same numbers.");
    }

    public function unreview(ScraperRejectionLog $rejection): RedirectResponse
    {
        $rejection->update(['reviewed_at' => null]);
        AdminLog::info('admin.rejection.unreviewed', "Dropped variant re-opened: {$rejection->coffee_name} ({$rejection->roaster?->name})", [
            'rejection_id' => $rejection->id, 'roaster_id' => $rejection->roaster_id, 'coffee_id' => $rejection->coffee_id,
        ]);

        return back()->with('success', "Re-opened: {$rejection->coffee_name}. It will appear in the ops emails again.");
    }
}
