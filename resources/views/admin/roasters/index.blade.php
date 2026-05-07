@extends('layouts.app')

@section('title', 'Admin – Roasters')

@section('content')
<div class="admin-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Manage Roasters</h2>
        <div style="display:flex; gap:10px;">
            <a href="{{ route('admin.moderation.index') }}" class="btn btn-secondary">🚩 Moderation</a>
            <a href="{{ route('admin.roasters.import.form') }}" class="btn btn-secondary">🌐 Import from URL</a>
            <a href="{{ route('admin.roasters.create') }}" class="btn btn-primary">+ Add Roaster</a>
        </div>
    </div>

    @php
        // Per-status background tints so admin can see at a glance which
        // roasters need attention.
        $statusBg = [
            'success'     => '#f1f9f1',
            'empty'       => '#fdfbe7',
            'error'       => '#fbe9e7',
            'unsupported' => '#f0eef9',
        ];
        $statusLabel = [
            'success'     => '✓ Imported',
            'empty'       => '∅ Empty catalog',
            'error'       => '✗ Error',
            'unsupported' => '? Unsupported platform',
        ];
    @endphp

    <table class="admin-table">
        <thead>
            <tr>
                <th>Roaster</th>
                <th>City</th>
                <th>Platform</th>
                <th style="text-align:center">Beans</th>
                <th>Last import</th>
                <th>Status</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($roasters as $roaster)
            @php $bg = $statusBg[$roaster->last_import_status] ?? '#fff'; @endphp
            <tr style="background: {{ $bg }}">
                <td>
                    <strong>{{ $roaster->name }}</strong>
                    <div style="color:#999; font-size:11px">{{ $roaster->region ?? '' }}</div>
                </td>
                <td>{{ $roaster->city }}</td>
                <td>{{ $roaster->platform ?? '—' }}</td>
                <td style="text-align:center">{{ $roaster->coffees_count }}</td>
                <td style="font-size:12px; color:#666">
                    {{ $roaster->last_imported_at ? $roaster->last_imported_at->diffForHumans() : 'never' }}
                </td>
                <td style="font-size:12px">
                    @if($roaster->last_import_status)
                        {{ $statusLabel[$roaster->last_import_status] ?? $roaster->last_import_status }}
                        @if($roaster->last_import_error)
                            <div style="color:#c00; font-size:11px; margin-top:2px; max-width:300px;
                                        white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                 title="{{ $roaster->last_import_error }}">
                                {{ $roaster->last_import_error }}
                            </div>
                        @endif
                    @else
                        <span style="color:#999">never imported</span>
                    @endif
                </td>
                <td>
                    <div class="action-btns">
                        @if($roaster->website)
                            <form method="POST" action="{{ route('admin.roasters.refresh', $roaster) }}" style="display:inline">
                                @csrf
                                <button type="submit" class="btn btn-small btn-secondary"
                                        title="Re-fetch this roaster's products from {{ $roaster->website }}">↻ Refresh</button>
                            </form>
                        @endif
                        <a href="{{ route('admin.coffees.create', $roaster) }}" class="btn btn-small btn-primary">+ Coffee</a>
                        <a href="{{ route('admin.roasters.edit', $roaster) }}" class="btn btn-small btn-secondary">Edit</a>
                        <form method="POST" action="{{ route('admin.roasters.destroy', $roaster) }}"
                              onsubmit="return confirm('Delete {{ addslashes($roaster->name) }} and all its offerings?')"
                              style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>

            @if($roaster->coffees_count > 0)
            <tr style="background: {{ $bg }}">
                <td colspan="7" style="padding: 4px 15px 12px;">
                    <div class="inline-coffees">
                        @foreach($roaster->coffees as $coffee)
                        @php
                            $variants = $coffee->variants;
                            $sizes = $variants->map(fn ($v) => $v->bag_weight_grams . 'g')->implode(' / ');
                            $best = $coffee->best_price_per_gram;
                        @endphp
                        <div class="coffee-chip" style="{{ $coffee->removed_at ? 'opacity:0.5' : '' }}">
                            <strong>{{ $coffee->name }}</strong>
                            @if($coffee->removed_at)
                                <span style="color:#c00; font-size:10px">SOLD OUT</span>
                            @endif
                            <span style="color:#999">{{ $coffee->origin }}</span>
                            @if($variants->isNotEmpty())
                                <span style="color:#666; font-size: 11px;">{{ $sizes }}</span>
                                <span style="color:#8B4513">best ${{ number_format($best, 3) }}/g</span>
                            @else
                                <span style="color:#dc3545">no variants</span>
                            @endif
                            <a href="{{ route('admin.coffees.edit', [$roaster, $coffee]) }}">edit</a>
                            <form method="POST" action="{{ route('admin.coffees.destroy', [$roaster, $coffee]) }}"
                                  onsubmit="return confirm('Delete this offering?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="delete-x">×</button>
                            </form>
                        </div>
                        @endforeach
                    </div>
                </td>
            </tr>
            @endif
            @empty
            <tr>
                <td colspan="7" class="empty-state">No roasters yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 20px; text-align: center;">{{ $roasters->links() }}</div>
</div>
@endsection
