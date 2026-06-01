@php
    $reasonLabels = [
        'price_non_positive' => 'Zero / negative price',
        'cpg_out_of_band' => 'Price-per-gram out of band',
    ];
    $bucketLabels = [
        'unplaced' => 'No coordinates (invisible on map)',
        'centroid_only' => 'City-centroid only (never resolved)',
        'missing_street' => 'Missing street address',
        'missing_postal' => 'Missing postal code',
        'stale' => 'Verification missing or stale',
    ];
@endphp
@component('mail::message')
# Roastmap data-quality digest

Snapshot generated {{ $report['generated_at'] }}. Freshness window: {{ $report['window_days'] }} days.

## Imports
Across {{ $report['imports']['total'] }} active roaster(s):

- **{{ $report['imports']['success'] }}** last imported cleanly
- **{{ $report['imports']['empty'] }}** returned no products
- **{{ $report['imports']['error'] }}** errored
- **{{ $report['imports']['never'] }}** have never imported
- **{{ $report['imports']['stale'] }}** are stale (no successful import inside the window)

## Dropped variants (sanity gate)
@if($report['rejections']['total'] === 0)
No variants were dropped at the price / price-per-gram gate. ✓
@else
**{{ $report['rejections']['total'] }}** variant(s) currently dropped at the import sanity gate.

By reason:
@foreach($report['rejections']['by_reason'] as $reason => $count)
- {{ $reasonLabels[$reason] ?? $reason }} — **{{ $count }}**
@endforeach

@if(!empty($report['rejections']['top_roasters']))
Worst offenders:
@foreach($report['rejections']['top_roasters'] as $row)
- {{ $row['roaster'] }} — **{{ $row['count'] }}**
@endforeach
@endif
@endif

## Possible duplicates
@php($dups = $report['duplicates'])
@if($dups['host_groups'] === 0 && $dups['name_groups'] === 0 && $dups['similar_pairs'] === 0)
No likely-duplicate roasters detected. ✓
@else
- **{{ $dups['host_groups'] }}** shared-website group(s)
- **{{ $dups['name_groups'] }}** identical-name group(s)
- **{{ $dups['similar_pairs'] }}** similar-name pair(s)

Review with `php artisan roasters:find-duplicates`.
@endif

## Address quality
@php($addr = $report['addresses'])
@if($addr['flagged'] === 0)
All {{ $addr['ok'] }} physical roaster(s) have complete, current addresses. ✓
@else
**{{ $addr['flagged'] }}** physical roaster(s) flagged ({{ $addr['ok'] }} OK, {{ $addr['online_only'] }} online-only excluded):

@foreach($addr['buckets'] as $bucket => $count)
@if($count > 0)
- {{ $bucketLabels[$bucket] ?? $bucket }} — **{{ $count }}**
@endif
@endforeach

Review with `php artisan roasters:check-addresses`.
@endif

---

Automated weekly digest from the Roastmap importer. Read-only — nothing here was changed.
@endcomponent
