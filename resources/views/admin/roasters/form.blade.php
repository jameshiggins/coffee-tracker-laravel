@extends('layouts.app')

@section('title', ($roaster->exists ? 'Edit' : 'Add') . ' Roaster – Admin')

@section('content')
<div class="admin-content">
    <a href="{{ route('admin.roasters.index') }}" class="back-link">← Back to Admin</a>

    <h2>{{ $roaster->exists ? 'Edit ' . $roaster->name : 'Add New Roaster' }}</h2>

    <form method="POST"
          action="{{ $roaster->exists ? route('admin.roasters.update', $roaster) : route('admin.roasters.store') }}"
          class="admin-form" style="margin-top: 20px;">
        @csrf
        @if($roaster->exists) @method('PUT') @endif

        @if($errors->any())
            <div class="error-list">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="form-row">
            <div class="form-group">
                <label>Roaster Name *</label>
                <input type="text" name="name" value="{{ old('name', $roaster->name) }}" required>
            </div>
            <div class="form-group">
                <label>Region *</label>
                <select name="region" required>
                    <option value="">Select Region</option>
                    @foreach(['Victoria', 'Vancouver', 'Interior', 'Kootenays', 'Okanagan'] as $r)
                        <option value="{{ $r }}" @selected(old('region', $roaster->region) === $r)>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>City *</label>
                <input type="text" name="city" value="{{ old('city', $roaster->city) }}" required>
            </div>
            <div class="form-group">
                <label>Website</label>
                <input type="url" name="website" value="{{ old('website', $roaster->website) }}" placeholder="https://...">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Instagram handle</label>
                <input type="text" name="instagram" value="{{ old('instagram', $roaster->instagram) }}" placeholder="@handle">
            </div>
        </div>

        <div class="form-section">
            <h3>Address & location</h3>
            <p style="color:#666; font-size:12px; margin-bottom:8px;">
                Used for nearest-to-me sort. Click <strong>Geocode</strong> after saving to fill latitude/longitude
                from the street address via OpenStreetMap.
            </p>
            <div class="form-group">
                <label>Street address</label>
                <input type="text" name="street_address" value="{{ old('street_address', $roaster->street_address) }}" placeholder="111 Main St">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Postal code</label>
                    <input type="text" name="postal_code" value="{{ old('postal_code', $roaster->postal_code) }}" placeholder="V6B 1A1">
                </div>
                <div class="form-group">
                    <label style="font-size:12px; color:#666">Coordinates (auto-filled by Geocode)</label>
                    <div style="font-size:12px; color:#666; padding:10px 0;">
                        @if($roaster->latitude && $roaster->longitude)
                            {{ number_format($roaster->latitude, 4) }}, {{ number_format($roaster->longitude, 4) }}
                        @else
                            <em>not set</em>
                        @endif
                    </div>
                </div>
            </div>
            @if($roaster->exists && $roaster->street_address)
                <form method="POST" action="{{ route('admin.roasters.geocode', $roaster) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="btn btn-small btn-secondary">📍 Geocode address</button>
                </form>
            @endif
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="3">{{ old('description', $roaster->description) }}</textarea>
        </div>

        <div class="form-section">
            <h3>Shipping</h3>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="has_shipping" value="1" @checked(old('has_shipping', $roaster->has_shipping))>
                    Offers online shipping
                </label>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Flat shipping cost ($)</label>
                    <input type="number" name="shipping_cost" value="{{ old('shipping_cost', $roaster->shipping_cost) }}" step="0.01" min="0" placeholder="e.g. 9.95">
                </div>
                <div class="form-group">
                    <label>Free shipping over ($)</label>
                    <input type="number" name="free_shipping_over" value="{{ old('free_shipping_over', $roaster->free_shipping_over) }}" step="0.01" min="0" placeholder="e.g. 75.00">
                </div>
            </div>
            <div class="form-group">
                <label>Shipping notes</label>
                <input type="text" name="shipping_notes" value="{{ old('shipping_notes', $roaster->shipping_notes) }}" placeholder="e.g. BC only, ships Tuesdays">
            </div>
        </div>

        <div class="form-section">
            <h3>Subscription</h3>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="has_subscription" value="1" @checked(old('has_subscription', $roaster->has_subscription))>
                    Offers a subscription service
                </label>
            </div>
            <div class="form-group">
                <label>Subscription details</label>
                <input type="text" name="subscription_notes" value="{{ old('subscription_notes', $roaster->subscription_notes) }}" placeholder="e.g. Monthly, bi-weekly, choose your bag size">
            </div>
        </div>

        <div class="form-section">
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $roaster->exists ? $roaster->is_active : true))>
                Active (visible in directory)
            </label>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button type="submit" class="btn btn-primary">{{ $roaster->exists ? 'Save Changes' : 'Add Roaster' }}</button>
            <a href="{{ route('admin.roasters.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
