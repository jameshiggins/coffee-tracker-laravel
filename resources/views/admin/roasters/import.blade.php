@extends('layouts.app')

@section('title', 'Import a Roaster')

@section('content')
<div class="admin-content">
    <a href="{{ route('admin.roasters.index') }}" class="back-link">← Back to Admin</a>

    <h2>Import a Roaster from URL</h2>
    <p style="color: #666; margin-bottom: 20px;">
        Paste any URL on a Shopify-hosted roaster's site. We'll fetch <code>/products.json</code>,
        filter to single-origin coffees, and create the roaster + variants in one shot. Re-importing
        the same URL refreshes inventory in place.
    </p>

    @if($errors->any())
        <div class="error-list">
            <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.roasters.import') }}" class="admin-form">
        @csrf

        <div class="form-group">
            <label>Roaster URL *</label>
            <input type="url" name="url" value="{{ old('url') }}" required
                   placeholder="https://example-coffee.com or https://shop.example-coffee.com">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Display Name (optional — inferred from URL)</label>
                <input type="text" name="name" value="{{ old('name') }}" placeholder="Example Coffee">
            </div>
            <div class="form-group">
                <label>City (optional)</label>
                <input type="text" name="city" value="{{ old('city') }}" placeholder="Vancouver">
            </div>
        </div>

        <div class="form-group">
            <label>Region (optional)</label>
            <input type="text" name="region" value="{{ old('region') }}" placeholder="Vancouver, Victoria, Pacific Northwest, …">
        </div>

        <button type="submit" class="btn btn-primary">Import</button>
    </form>

    <div style="margin-top: 30px; padding: 16px; background: #fffbf3; border: 1px solid #f4e3c4; border-radius: 8px; font-size: 13px; color: #6f4732;">
        <strong>What gets imported:</strong>
        <ul style="margin: 8px 0 0 22px; line-height: 1.7;">
            <li>Coffee name + variant sizes (250g, 12oz, 1lb, 1kg…) parsed from variant titles</li>
            <li>Price + in-stock state per variant</li>
            <li>First available variant is marked as the roaster's default</li>
            <li>Blends, decaf, gear, gift cards, subscriptions are skipped</li>
        </ul>
        <strong style="display:block; margin-top: 12px;">Not yet imported:</strong>
        <ul style="margin: 8px 0 0 22px; line-height: 1.7;">
            <li>Address, ships-to countries, full process/varietal/roast level (those need separate enrichment)</li>
        </ul>
    </div>
</div>
@endsection
