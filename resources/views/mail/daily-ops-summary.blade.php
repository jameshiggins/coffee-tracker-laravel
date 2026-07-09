@php
    $reasonLabels = [
        'price_non_positive' => 'Zero / negative price',
        'cpg_out_of_band' => 'Price-per-gram out of band',
    ];
    $added = $report['roasters_added'];
    $errors = $report['import_errors'];
    $rejections = $report['rejections'];
    $mail = $report['mail'];
@endphp
@component('mail::message')
# Roastmap daily ops

@if($notable)
Something happened in the last {{ $report['window_hours'] }}h worth a look.
@else
Quiet last {{ $report['window_hours'] }}h — nothing needs attention. ✓ (You're getting this because the daily pulse arriving at all confirms the scheduler and mail are alive.)
@endif

Snapshot generated {{ $report['generated_at'] }}.

## New roasters
@if($added['count'] === 0)
No roasters added in the last {{ $report['window_hours'] }}h.
@else
**{{ $added['count'] }}** roaster(s) added:

@foreach($added['list'] as $r)
@php
    $loc = $r['city'] ? ' — '.$r['city'].($r['region'] ? ', '.$r['region'] : '') : '';
    $tag = $r['is_active'] ? '' : ' _(inactive)_';
@endphp
- **{{ $r['name'] }}**{{ $loc }}{{ $tag }}
@endforeach
@endif

## Import errors
@if($errors['count'] === 0)
No active roasters are in an import-error state. ✓
@else
**{{ $errors['count'] }}** active roaster(s) failing their import:

@foreach($errors['list'] as $r)
- **{{ $r['name'] }}** — {{ $r['error'] ?? 'no error message recorded' }}
@endforeach

Re-run a single roaster from the admin (Refresh) or check the source feed.
@endif

## Dropped variants (sanity gate)
@if($rejections['total'] === 0)
No variants currently dropped at the price / price-per-gram gate. ✓
@else
**{{ $rejections['total'] }}** variant(s) currently dropped at the import sanity gate.

By reason:
@foreach($rejections['by_reason'] as $reason => $count)
- {{ $reasonLabels[$reason] ?? $reason }} — **{{ $count }}**
@endforeach

@if(!empty($rejections['top_roasters']))
Worst offenders:
@foreach($rejections['top_roasters'] as $row)
- {{ $row['roaster'] }} — **{{ $row['count'] }}**
@endforeach
@endif

@if(!empty($rejections['items']))
Which beans:
@foreach($rejections['items'] as $it)
@php
    $bits = [];
    if (($it['price'] ?? null) !== null) { $bits[] = '$'.$it['price']; }
    if (($it['grams'] ?? null) !== null) { $bits[] = $it['grams'].'g'; }
    $detail = implode(' / ', $bits);
    if (($it['cpg'] ?? null) !== null) { $detail .= ' = '.$it['cpg'].'¢/g'; }
    if (!empty($it['size_label'])) { $detail .= ' — “'.$it['size_label'].'”'; }
@endphp
- **{{ $it['coffee'] ?: 'Unnamed variant' }}** ({{ $it['roaster'] }}) — {{ $reasonLabels[$it['reason']] ?? $it['reason'] }}{{ $detail !== '' ? ': '.$detail : '' }}
@endforeach
@if($rejections['total'] > count($rejections['items']))
_…and {{ $rejections['total'] - count($rejections['items']) }} more not shown._
@endif
@endif
@endif

## Mail delivery
@if($mail['healthy'])
Working ✓ — the transport last accepted a message {{ $mail['age_hours'] === 0 ? 'less than an hour' : $mail['age_hours'].'h' }} ago.
@elseif($mail['last_sent'] === null)
**No mail has ever been recorded as sent.** If users should be getting verification or restock emails, the transport may be misconfigured — check the Resend credentials on Fly.
@else
**Mail may be broken.** Last confirmed send was {{ $mail['age_hours'] }}h ago (over {{ \App\Services\DailyOpsReport::MAIL_STALE_AFTER_HOURS }}h). Since this digest itself sends daily, that gap suggests the transport is failing — check the Resend credentials on Fly.
@endif

---

Automated daily ops summary from Roastmap. Read-only — nothing here was changed. Infra liveness (database, scheduler) is on the GET /up uptime monitor; this email covers the data signals.
@endcomponent
