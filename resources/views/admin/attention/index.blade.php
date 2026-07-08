@extends('layouts.app')

@section('title', 'Needs Attention — Roastmap Admin')

@php
    $meta = [
        'dead_domain' => ['label' => 'Dead domains', 'badge' => '#dc3545',
            'blurb' => "Website won't resolve — closed, rebranded, or the domain lapsed. Auto-deactivated after 7 days of failures."],
        'blocked' => ['label' => 'Blocked (401 / 403)', 'badge' => '#daa520',
            'blurb' => 'Reachable but refusing our scraper (bot-block). Often transient — Retry, and if it persists it needs a scraper tweak.'],
        'error' => ['label' => 'Other import errors', 'badge' => '#dc3545',
            'blurb' => 'Something else failed during import. Retry, then check the logs for the exception.'],
        'empty' => ['label' => 'Empty catalog', 'badge' => '#6c757d',
            'blurb' => 'Site responded but no coffees were found — usually a scraper the generic parser can’t read, sometimes genuinely sold out.'],
        'never_imported' => ['label' => 'Never imported', 'badge' => '#17a2b8',
            'blurb' => 'Added manually and never scraped. Retry to pull its catalogue.'],
    ];
@endphp

@section('content')
<div class="admin-content">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:8px;">
        <h2 style="color:#6F4E37;">Needs attention</h2>
        <span style="color:#888;font-size:13px;">
            {{ $healthy }} of {{ $total }} active roasters healthy
        </span>
    </div>
    <p style="color:#888;font-size:13px;margin-bottom:20px;">
        Roasters grouped by what went wrong on their last import. Deactivating is a soft hide — every
        coffee, tasting, and wishlist is kept, and a successful re-import brings the roaster right back.
    </p>

    @php $allClear = collect($groups)->every(fn ($g) => $g->isEmpty()); @endphp

    @if ($allClear)
        <div class="empty-state">
            <h3>🎉 Everything's healthy</h3>
            <p>No roasters are erroring or empty right now.</p>
        </div>
    @endif

    @foreach ($groups as $kind => $roasters)
        @continue($roasters->isEmpty())
        <div class="form-section" style="margin-top:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <h3 style="color:#8B4513;">
                    <span class="region-badge" style="background: {{ $meta[$kind]['badge'] }};">{{ $roasters->count() }}</span>
                    {{ $meta[$kind]['label'] }}
                </h3>
                @if ($kind === 'dead_domain')
                    <form method="POST" action="{{ route('admin.attention.deactivate_dead') }}"
                          onsubmit="return confirm('Deactivate all {{ $roasters->count() }} dead-domain roasters? Data is preserved and they can be reactivated.');">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-small">Deactivate all {{ $roasters->count() }}</button>
                    </form>
                @endif
            </div>
            <p style="color:#888;font-size:12px;margin:4px 0 12px;">{{ $meta[$kind]['blurb'] }}</p>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Roaster</th>
                        <th>City</th>
                        <th style="width:120px;">Last import</th>
                        @if ($kind === 'dead_domain')<th style="width:120px;">Failing since</th>@endif
                        <th>Detail</th>
                        <th style="width:200px;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roasters as $roaster)
                        <tr>
                            <td><strong>{{ $roaster->name }}</strong></td>
                            <td style="white-space:nowrap;">{{ $roaster->city }}</td>
                            <td style="white-space:nowrap;color:#888;">
                                {{ $roaster->last_imported_at?->diffForHumans() ?? 'never' }}
                            </td>
                            @if ($kind === 'dead_domain')
                                <td style="white-space:nowrap;color:#dc3545;">
                                    {{ $roaster->import_failing_since?->format('M j') ?? '—' }}
                                </td>
                            @endif
                            <td style="font-size:12px;color:#666;">
                                @if ($roaster->last_import_error)
                                    {{ \Illuminate\Support\Str::limit($roaster->last_import_error, 90) }}
                                @elseif ($kind === 'empty')
                                    Platform: <code>{{ $roaster->platform ?? 'undetected' }}</code>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <div class="action-btns">
                                    <form method="POST" action="{{ route('admin.attention.retry', $roaster) }}" style="display:inline">
                                        @csrf
                                        <button type="submit" class="btn btn-small btn-primary">Retry</button>
                                    </form>
                                    <a href="{{ route('admin.roasters.edit', $roaster) }}" class="btn btn-small btn-secondary">Edit</a>
                                    <form method="POST" action="{{ route('admin.roasters.destroy', $roaster) }}" style="display:inline"
                                          onsubmit="return confirm('Deactivate {{ $roaster->name }}? Data is preserved.');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-small btn-danger">Deactivate</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
@endsection
