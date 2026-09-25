<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trust#9: one row per variant the importer rejected at the sanity gate.
 *
 * Written by RoasterImporter::logRejection() when syncVariants() drops a
 * variant for a non-positive price, an out-of-band cents-per-gram, or a
 * per-gram price wildly inconsistent with the same coffee's other bag sizes.
 * Read by the ops emails and the admin "Dropped variants" page to spot feeds
 * whose parsing has drifted. `context` carries the offending numbers (price,
 * grams, cpg, size label) plus a `suspected` cause (see SUSPECT_*) as JSON.
 *
 * Snapshot with a memory: the importer replaces a roaster's rows on every
 * run, but first_seen_at and reviewed_at are carried forward for the same
 * (coffee, bag size, reason), so the emails can separate new drops from
 * ongoing ones and an operator can mark a row "reviewed" to retire it.
 */
class ScraperRejectionLog extends Model
{
    use HasFactory;

    public const REASON_PRICE_NON_POSITIVE = 'price_non_positive';
    public const REASON_CPG_OUT_OF_BAND = 'cpg_out_of_band';
    /** Per-gram price far below the same coffee's other sizes — a unit mis-parse, not a bargain. */
    public const REASON_CPG_INCONSISTENT = 'cpg_inconsistent';

    /** What the gate thinks it caught. Labels live in reasonLabels()/suspectLabels(). */
    public const SUSPECT_NON_COFFEE = 'non_coffee';
    public const SUSPECT_UNIT_ERROR = 'unit_error';
    public const SUSPECT_BULK_PRICING = 'bulk_pricing';
    public const SUSPECT_SAMPLE_OR_PORTION = 'sample_or_portion';
    public const SUSPECT_UNKNOWN = 'unknown';

    protected $fillable = [
        'roaster_id', 'coffee_id', 'coffee_name', 'reason', 'context', 'first_seen_at', 'reviewed_at',
    ];

    protected $casts = [
        'context' => 'array',
        'first_seen_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** Human labels for the emails and the admin page. */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_PRICE_NON_POSITIVE => 'Zero / negative price',
            self::REASON_CPG_OUT_OF_BAND => 'Price-per-gram out of band',
            self::REASON_CPG_INCONSISTENT => 'Price-per-gram inconsistent with other sizes',
        ];
    }

    public static function suspectLabels(): array
    {
        return [
            self::SUSPECT_NON_COFFEE => 'probably not coffee',
            self::SUSPECT_UNIT_ERROR => 'probably a bag-size mis-parse',
            self::SUSPECT_BULK_PRICING => 'plausible bulk pricing',
            self::SUSPECT_SAMPLE_OR_PORTION => 'sample or portion pack',
            self::SUSPECT_UNKNOWN => 'unclear',
        ];
    }

    /**
     * Identity of a drop across re-imports: the same coffee, at the same bag
     * size, rejected for the same reason. Used to carry first_seen_at and
     * reviewed_at forward when the importer replaces the snapshot.
     */
    public static function identityKey(?int $coffeeId, mixed $grams, string $reason): string
    {
        return ($coffeeId ?? 'none').'|'.($grams === null ? 'none' : (string) $grams).'|'.$reason;
    }

    public function identity(): string
    {
        return self::identityKey($this->coffee_id, $this->context['grams'] ?? null, $this->reason);
    }

    public function scopeUnreviewed(Builder $q): Builder
    {
        return $q->whereNull('reviewed_at');
    }

    public function scopeReviewed(Builder $q): Builder
    {
        return $q->whereNotNull('reviewed_at');
    }

    /**
     * The currently-outstanding dropped variants, flattened for the ops emails:
     * which bean, at which roaster, dropped for which reason, with the offending
     * numbers (price / grams / cpg / size label) pulled out of `context`, the
     * suspected cause, and when the drop was first seen. Reviewed rows are
     * excluded unless asked for. Oldest-first within a run is fine; callers
     * split on `first_seen_at`. Capped so a feed that suddenly rejects hundreds
     * of rows can't bloat the email — callers compare the returned count
     * against the total to show a "+N more" note.
     *
     * @return list<array{roaster:string,coffee:?string,reason:string,price:mixed,grams:mixed,cpg:mixed,size_label:mixed,suspected:string,first_seen_at:?string,reviewed:bool}>
     */
    public static function itemizedSnapshot(int $limit = 50, bool $includeReviewed = false): array
    {
        return static::query()
            ->when(! $includeReviewed, fn ($q) => $q->unreviewed())
            ->with('roaster:id,name')
            ->orderByDesc('first_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (self $row) => $row->toItem())
            ->all();
    }

    public function toItem(): array
    {
        return [
            'id' => $this->id,
            'roaster' => $this->roaster?->name ?? "#{$this->roaster_id}",
            'coffee' => $this->coffee_name,
            'reason' => $this->reason,
            'price' => $this->context['price'] ?? null,
            'grams' => $this->context['grams'] ?? null,
            'cpg' => $this->context['cpg'] ?? null,
            'size_label' => $this->context['source_size_label'] ?? null,
            'suspected' => $this->context['suspected'] ?? self::SUSPECT_UNKNOWN,
            'first_seen_at' => ($this->first_seen_at ?? $this->created_at)?->toIso8601String(),
            'reviewed' => $this->reviewed_at !== null,
        ];
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
