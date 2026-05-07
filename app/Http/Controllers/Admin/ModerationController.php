<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tasting;
use Illuminate\Http\Request;

/**
 * Q17: admin moderation queue.
 *
 * Reader-flagged tastings show up here. Admin reviews each one and
 * either soft-deletes (hide from public surfaces, retain audit trail)
 * or dismisses the flag. No auto-hide on flag — every decision is
 * human-driven.
 */
class ModerationController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab', 'flagged');

        if ($tab === 'hidden') {
            $tastings = Tasting::onlyTrashed()
                ->with(['user:id,display_name,email', 'coffee:id,name,roaster_id', 'coffee.roaster:id,name,slug'])
                ->orderByDesc('deleted_at')
                ->limit(200)
                ->get();
        } else {
            $tastings = Tasting::whereNotNull('flagged_at')
                ->with(['user:id,display_name,email', 'coffee:id,name,roaster_id', 'coffee.roaster:id,name,slug'])
                ->orderByDesc('flagged_at')
                ->limit(200)
                ->get();
        }

        $counts = [
            'flagged' => Tasting::whereNotNull('flagged_at')->count(),
            'hidden'  => Tasting::onlyTrashed()->count(),
        ];

        return view('admin.moderation.index', compact('tastings', 'tab', 'counts'));
    }

    /**
     * Soft-delete the tasting. Stays in DB (audit trail / undo) but
     * disappears from every public surface (feeds, profile, permalink,
     * coffee aggregate rating).
     */
    public function hide(Tasting $tasting)
    {
        $tasting->delete();
        return back()->with('success', "Hid tasting #{$tasting->id}.");
    }

    /**
     * Restore a soft-deleted tasting.
     */
    public function restore(int $id)
    {
        $tasting = Tasting::onlyTrashed()->findOrFail($id);
        $tasting->restore();
        return back()->with('success', "Restored tasting #{$id}.");
    }

    /**
     * Clear the flag without hiding — flag was bogus.
     */
    public function dismiss(Tasting $tasting)
    {
        $tasting->forceFill([
            'flagged_at' => null,
            'flagged_by_user_id' => null,
        ])->save();
        return back()->with('success', "Dismissed flag on tasting #{$tasting->id}.");
    }
}
