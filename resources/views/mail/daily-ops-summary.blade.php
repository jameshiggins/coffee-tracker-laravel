@php
    $reasonLabels = \App\Models\ScraperRejectionLog::reasonLabels();
    $added = $report['roasters_added'];
    $errors = $report['import_errors'];
    $rejections = $report['rejections'];
    $mail = $report['mail'];
    $window = $report['window_hours'];
    // Bean and roaster names are scraped from storefronts, so every dynamic
    // part is HTML-escaped before the markdown line is assembled.
    $renderDrop = function (array $it) use ($reasonLabels): string {
        $bits = [];
        if (($it['price'] ?? null) !== null) { $bits[] = '$'.e($it['price']); }
        if (($it['grams'] ?? null) !== null) { $bits[] = e($it['grams']).'g'; }
        $detail = implode(' / ', $bits);
        if (($it['cpg'] ?? null) !== null) { $detail .= ' = '.e($it['cpg']).'¢/g'; }
        if (!empty($it['size_label'])) { $detail .= ' — “'.e($it['size_label']).'”'; }
        $line = '**'.e($it['coffee'] ?: 'Unnamed variant').'** ('.e($it['roaster']).') — '.e($reasonLabels[$it['reason']] ?? $it['reason']);
        if ($detail !== '') { $line .= ': '.$detail; }
        $line .= ' — _'.e($it['suspected_label']).'_';
        if (!($it['is_new'] ?? true) && !empty($it['first_seen_label'])) { $line .= ' — since '.e($it['first_seen_label']); }
        return $line;
    };
@endphp
@component('mail::message')
# Roastmap daily ops

@if($notable)
Something changed in the last {{ $window }}h worth a look.
@else
Quiet last {{ $window }}h — nothing new needs attention. ✓ (You're getting this because the daily pulse arriving at all confirms the scheduler and mail are alive.)
@endif

Snapshot generated {{ $report['generated_at'] }}.

## New roasters
@if($added['count'] === 0)
No roasters added in the last {{ $window }}h.
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
**{{ $errors['count'] }}** active roaster(s) failing their import — **{{ $errors['new'] }}** new since yesterday, {{ $errors['ongoing'] }} ongoing{{ $errors['watching'] > 0 ? ', '.$errors['watching'].' watching' : '' }}.

@if(!empty($errors['groups']['new']))
**New** — started failing in the last {{ $window }}h:

@foreach($errors['groups']['new'] as $r)
- **{{ $r['name'] }}** — {{ $r['error'] ?? 'no error message recorded' }} _({{ $r['kind_label'] }})_
@endforeach

@endif
@if(!empty($errors['groups']['ongoing']))
**Ongoing** — still failing, nothing changed:

@foreach($errors['groups']['ongoing'] as $r)
- **{{ $r['name'] }}** — since {{ $r['failing_since_label'] ?? '?' }}{{ $r['age_days'] !== null ? ' ('.$r['age_days'].'d)' : '' }} — {{ $r['error'] ?? 'no error message recorded' }} _({{ $r['kind_label'] }})_
@endforeach

Blocked storefronts are auto-hidden after 30 days, dead domains after 7. Fix a URL in the admin if the shop moved.

@endif
@if(!empty($errors['groups']['watching']))
**Watching** — not counted yet (a first slow night, or the platform throttling our IP):

@foreach($errors['groups']['watching'] as $r)
- **{{ $r['name'] }}** — {{ $r['error'] ?? 'no error message recorded' }} _({{ $r['kind_label'] }})_
@endforeach

@endif
Re-run a single roaster from the admin (Refresh) or check the source feed.
@endif

## Dropped variants (sanity gate)
@if($rejections['total'] === 0)
No variants currently dropped at the price / price-per-gram gate. ✓{{ $rejections['reviewed'] > 0 ? ' ('.$rejections['reviewed'].' reviewed row(s) hidden.)' : '' }}
@else
**{{ $rejections['total'] }}** variant(s) currently dropped at the import sanity gate — **{{ $rejections['new'] }}** new since yesterday, {{ $rejections['ongoing'] }} ongoing.{{ $rejections['reviewed'] > 0 ? ' '.$rejections['reviewed'].' reviewed row(s) hidden.' : '' }}

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

@if(!empty($rejections['new_items']))
Which beans — new since yesterday:
@foreach($rejections['new_items'] as $it)
- {!! $renderDrop($it) !!}
@endforeach
@endif

@if(!empty($rejections['ongoing_items']))
Which beans — ongoing:
@foreach($rejections['ongoing_items'] as $it)
- {!! $renderDrop($it) !!}
@endforeach
@endif
@if($rejections['total'] > count($rejections['items']))
_…and {{ $rejections['total'] - count($rejections['items']) }} more not shown._
@endif

Real bulk pricing or a bean you've checked? Mark it reviewed and it leaves this list: {{ url('/admin/rejections') }}
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
