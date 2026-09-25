@extends('layouts.app')

@section('title', 'Dropped variants — Roastmap Admin')

@php
    $suspectBadge = [
        'unit_error' => '#dc3545',
        'non_coffee' => '#6f42c1',
        'bulk_pricing' => '#17a2b8',
        'sample_or_portion' => '#6c757d',
        'unknown' => '#daa520',
    ];
    $numbers = function ($row) {
        $c = $row->context ?? [];
        $bits = [];
        if (($c['price'] ?? null) !== null) { $bits[] = '$'.$c['price']; }
        if (($c['grams'] ?? null) !== null) { $bits[] = $c['grams'].' g'; }
        $s = implode(' / ', $bits);
        if (($c['cpg'] ?? null) !== null) { $s .= ' = '.$c['cpg'].'¢/g'; }
        if (($c['floor'] ?? null) !== null) { $s .= ' (floor '.$c['floor'].')'; }
        if (($c['sibling_grams'] ?? null) !== null) { $s .= ' vs '.$c['sibling_grams'].' g at $'.$c['sibling_price']; }
        return $s;
    };
@endphp

@section('content')
<div class="admin-content">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:8px;">
        <h2 style="color:#6F4E37;">Dropped variants</h2>
        <span style="color:#888;font-size:13px;">{{ $openCount }} open · {{ $reviewed->count() }} reviewed</span>
    </div>
    <p style="color:#888;font-size:13px;margin-bottom:20px;">
        Bag sizes the importer refused at the price sanity gate, with its best guess at why.
        <strong>Bag-size mis-parse</strong> → the scraper needs a fix. <strong>Not coffee</strong> → the classifier
        needs a rule. <strong>Plausible bulk pricing</strong> → probably a real 3 kg bag: mark it reviewed and it
        leaves the daily email for as long as the feed keeps sending the same numbers.
    </p>

    @if ($openCount === 0)
        <div class="empty-state">
            <h3>🎉 Nothing waiting</h3>
            <p>No unreviewed drops from the latest imports.</p>
        </div>
    @endif

    @foreach ($open as $roasterName => $rows)
        <div class="form-section" style="margin-top:24px;">
            <h3 style="color:#8B4513;">
                <span class="region-badge" style="background:#6c757d;">{{ $rows->count() }}</span>
                {{ $roasterName }}
            </h3>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Bean</th>
                        <th>Size label</th>
                        <th>Numbers</th>
                        <th>Reason</th>
                        <th>Suspected</th>
                        <th style="width:110px;">First seen</th>
                        <th style="width:150px;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $suspect = $row->context['suspected'] ?? 'unknown'; @endphp
                        <tr>
                            <td><strong>{{ $row->coffee_name ?: 'Unnamed variant' }}</strong></td>
                            <td style="white-space:nowrap;color:#666;">{{ $row->context['source_size_label'] ?? '—' }}</td>
                            <td style="font-size:12px;color:#666;white-space:nowrap;">{{ $numbers($row) ?: '—' }}</td>
                            <td style="font-size:12px;">{{ $reasonLabels[$row->reason] ?? $row->reason }}</td>
                            <td>
                                <span class="region-badge" style="background: {{ $suspectBadge[$suspect] ?? '#daa520' }};">
                                    {{ $suspectLabels[$suspect] ?? $suspect }}
                                </span>
                            </td>
                            <td style="white-space:nowrap;color:#888;">{{ ($row->first_seen_at ?? $row->created_at)?->format('M j') }}</td>
                            <td style="text-align:right;">
                                <div class="action-btns">
                                    <form method="POST" action="{{ route('admin.rejections.review', $row) }}" style="display:inline">
                                        @csrf
                                        <button type="submit" class="btn btn-small btn-primary">Mark reviewed</button>
                                    </form>
                                    @if ($row->roaster)
                                        <a href="{{ route('admin.roasters.edit', $row->roaster) }}" class="btn btn-small btn-secondary">Roaster</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    @if ($reviewed->isNotEmpty())
        <div class="form-section" style="margin-top:32px;">
            <h3 style="color:#8B4513;">
                <span class="region-badge" style="background:#28a745;">{{ $reviewed->count() }}</span>
                Reviewed
            </h3>
            <p style="color:#888;font-size:12px;margin:4px 0 12px;">Hidden from the ops emails. Re-open one if it turns out to be a real problem after all.</p>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Roaster</th>
                        <th>Bean</th>
                        <th>Numbers</th>
                        <th>Reason</th>
                        <th style="width:110px;">Reviewed</th>
                        <th style="width:120px;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reviewed as $row)
                        <tr>
                            <td>{{ $row->roaster?->name ?? '#'.$row->roaster_id }}</td>
                            <td><strong>{{ $row->coffee_name ?: 'Unnamed variant' }}</strong></td>
                            <td style="font-size:12px;color:#666;white-space:nowrap;">{{ $numbers($row) ?: '—' }}</td>
                            <td style="font-size:12px;">{{ $reasonLabels[$row->reason] ?? $row->reason }}</td>
                            <td style="white-space:nowrap;color:#888;">{{ $row->reviewed_at?->format('M j') }}</td>
                            <td style="text-align:right;">
                                <form method="POST" action="{{ route('admin.rejections.unreview', $row) }}" style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-small btn-secondary">Re-open</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
