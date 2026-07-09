<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trust#9: one row per variant the importer rejected at the sanity gate.
 *
 * Written by RoasterImporter::logRejection() when syncVariants() drops a
 * variant for a non-positive price or an out-of-band cents-per-gram. Read by
 * the weekly data-quality digest and the admin surface to spot feeds whose
 * parsing has drifted. `context` carries the offending numbers (price, grams,
 * cpg, size label) as JSON.
 */
class ScraperRejectionLog extends Model
{
    use HasFactory;

    public const REASON_PRICE_NON_POSITIVE = 'price_non_positive';
    public const REASON_CPG_OUT_OF_BAND = 'cpg_out_of_band';

    protected $fillable = [
        'roaster_id', 'coffee_id', 'coffee_name', 'reason', 'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    /**
     * The currently-outstanding dropped variants, flattened for the ops emails:
     * which bean, at which roaster, dropped for which reason, with the offending
     * numbers (price / grams / cpg / size label) pulled out of `context`. Newest
     * first, capped so a feed that suddenly rejects hundreds of rows can't bloat
     * the email — callers compare the returned count against the total to show a
     * "+N more" note.
     *
     * @return list<array{roaster:string,coffee:?string,reason:string,price:mixed,grams:mixed,cpg:mixed,size_label:mixed}>
     */
    public static function itemizedSnapshot(int $limit = 50): array
    {
        return static::query()
            ->with('roaster:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (self $row) => [
                'roaster' => $row->roaster?->name ?? "#{$row->roaster_id}",
                'coffee' => $row->coffee_name,
                'reason' => $row->reason,
                'price' => $row->context['price'] ?? null,
                'grams' => $row->context['grams'] ?? null,
                'cpg' => $row->context['cpg'] ?? null,
                'size_label' => $row->context['source_size_label'] ?? null,
            ])
            ->all();
    }

    public function roaster(): BelongsTo
    {
        return $this->belongsTo(Roaster::class);
    }

    public function coffee(): BelongsTo
    {
        return $this->belongsTo(Coffee::class);
    }
}
