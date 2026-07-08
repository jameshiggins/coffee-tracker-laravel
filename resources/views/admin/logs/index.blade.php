@extends('layouts.app')

@section('title', 'Logs — Roastmap Admin')

@section('content')
<div class="admin-content">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
            <h2 style="color:#6F4E37;">Operational logs</h2>
            <p style="color:#888;font-size:13px;margin-top:4px;">
                Errors, warnings, and admin audit events are always recorded.
                Verbose adds debug detail (per-product import decisions, per-recipient sends).
                The raw server stream lives in <code>fly logs</code>.
            </p>
        </div>
        <form method="POST" action="{{ route('admin.settings.verbose') }}">
            @csrf
            <button type="submit" class="btn {{ $verbose ? 'btn-danger' : 'btn-primary' }}">
                Verbose logging: {{ $verbose ? 'ON — click to turn OFF' : 'OFF — click to turn ON' }}
            </button>
        </form>
    </div>

    <form method="GET" action="{{ route('admin.logs.index') }}"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
        <select name="level" style="padding:8px 12px;border:2px solid #ddd;border-radius:8px;">
            <option value="">All levels</option>
            @foreach (['error', 'warning', 'info', 'debug'] as $lvl)
                <option value="{{ $lvl }}" @selected($filters['level'] === $lvl)>
                    {{ ucfirst($lvl) }} ({{ $counts[$lvl] ?? 0 }})
                </option>
            @endforeach
        </select>
        <input type="text" name="event" value="{{ $filters['event'] }}" placeholder="Event prefix (e.g. import.)"
               style="padding:8px 12px;border:2px solid #ddd;border-radius:8px;min-width:180px;">
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search messages…"
               style="padding:8px 12px;border:2px solid #ddd;border-radius:8px;min-width:180px;">
        <button type="submit" class="btn btn-primary btn-small">Filter</button>
        @if ($filters['level'] || $filters['event'] || $filters['q'])
            <a href="{{ route('admin.logs.index') }}" class="btn btn-secondary btn-small">Clear</a>
        @endif
    </form>

    @if ($logs->isEmpty())
        <div class="empty-state">
            <h3>No log entries{{ $filters['level'] || $filters['event'] || $filters['q'] ? ' match these filters' : ' yet' }}</h3>
            <p>Events appear here as imports run and admin actions happen.</p>
        </div>
    @else
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:150px;">When</th>
                    <th style="width:80px;">Level</th>
                    <th style="width:220px;">Event</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td title="{{ $log->created_at->toIso8601String() }}" style="white-space:nowrap;">
                            {{ $log->created_at->format('M j, H:i:s') }}
                        </td>
                        <td>
                            @php
                                $badge = ['error' => '#dc3545', 'warning' => '#daa520', 'info' => '#17a2b8', 'debug' => '#6c757d'][$log->level] ?? '#6c757d';
                            @endphp
                            <span class="region-badge" style="background: {{ $badge }};">{{ $log->level }}</span>
                        </td>
                        <td><code style="font-size:12px;">{{ $log->event }}</code></td>
                        <td>
                            {{ $log->message }}
                            @if ($log->context)
                                <details style="margin-top:4px;">
                                    <summary style="cursor:pointer;color:#8B4513;font-size:12px;">context</summary>
                                    <pre style="background:#f8f9fa;border:1px solid #eee;border-radius:6px;padding:8px;font-size:11px;overflow-x:auto;">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 0;">
            <span style="color:#888;font-size:13px;">
                Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }} — {{ $logs->total() }} entries (14-day retention)
            </span>
            <div style="display:flex;gap:8px;">
                @if ($logs->previousPageUrl())
                    <a href="{{ $logs->previousPageUrl() }}" class="btn btn-secondary btn-small">← Newer</a>
                @endif
                @if ($logs->nextPageUrl())
                    <a href="{{ $logs->nextPageUrl() }}" class="btn btn-secondary btn-small">Older →</a>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
