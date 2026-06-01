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

    public function roaster(): BelongsTo
    {
        return $this->belongsTo(Roaster::class);
    }

    public function coffee(): BelongsTo
    {
        return $this->belongsTo(Coffee::class);
    }
}
