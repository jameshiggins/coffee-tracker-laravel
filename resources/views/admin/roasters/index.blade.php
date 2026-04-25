@extends('layouts.app')

@section('title', 'Admin – Roasters')

@section('content')
<div class="admin-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Manage Roasters</h2>
        <div style="display:flex; gap:10px;">
            <form method="POST" action="{{ route('admin.snapshot') }}"
                  onsubmit="return confirm('Record a price snapshot for every variant right now?')">
                @csrf
                <button type="submit" class="btn btn-secondary">📸 Snapshot prices now</button>
            </form>
            <a href="{{ route('admin.roasters.import.form') }}" class="btn btn-secondary">🌐 Import from URL</a>
            <a href="{{ route('admin.roasters.create') }}" class="btn btn-primary">+ Add Roaster</a>
        </div>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Roaster</th>
                <th>Region</th>
                <th>City</th>
                <th style="text-align:center">Offerings</th>
                <th style="text-align:center">Ships</th>
                <th style="text-align:center">Sub</th>
                <th style="text-align:center">Active</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($roasters as $roaster)
            <tr>
                <td><strong>{{ $roaster->name }}</strong></td>
                <td>{{ $roaster->region ?? '—' }}</td>
                <td>{{ $roaster->city }}</td>
                <td style="text-align:center">{{ $roaster->coffees_count }}</td>
                <td style="text-align:center">{{ $roaster->has_shipping ? '✓' : '–' }}</td>
                <td style="text-align:center">{{ $roaster->has_subscription ? '✓' : '–' }}</td>
                <td style="text-align:center">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $roaster->is_active ? '#28a745' : '#dc3545' }}"></span>
                </td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('admin.coffees.create', $roaster) }}" class="btn btn-small btn-primary">+ Coffee</a>
                        <a href="{{ route('admin.roasters.edit', $roaster) }}" class="btn btn-small btn-secondary">Edit</a>
                        <form method="POST" action="{{ route('admin.roasters.destroy', $roaster) }}"
                              onsubmit="return confirm('Delete {{ addslashes($roaster->name) }} and all its offerings?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>

            @if($roaster->coffees_count > 0)
            <tr>
                <td colspan="8" style="padding: 4px 15px 12px;">
                    <div class="inline-coffees">
                        @foreach($roaster->coffees as $coffee)
                        @php
                            $variants = $coffee->variants;
                            $sizes = $variants->map(fn ($v) => $v->bag_weight_grams . 'g')->implode(' / ');
                            $best = $coffee->best_price_per_gram;
                        @endphp
                        <div class="coffee-chip">
                            <strong>{{ $coffee->name }}</strong>
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
                <td colspan="8" class="empty-state">No roasters yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 20px; text-align: center;">{{ $roasters->links() }}</div>
</div>
@endsection
