@extends('layouts.app')

@section('title', ($coffee->exists ? 'Edit' : 'Add') . ' Coffee – ' . $roaster->name)

@section('content')
<div class="admin-content">
    <a href="{{ route('admin.roasters.index') }}" class="back-link">← Back to Admin</a>

    <h2>{{ $coffee->exists ? 'Edit Offering' : 'Add Coffee Offering' }}</h2>
    <p style="color: #666; margin-bottom: 20px;">{{ $roaster->name }}</p>

    <form method="POST"
          action="{{ $coffee->exists ? route('admin.coffees.update', [$roaster, $coffee]) : route('admin.coffees.store', $roaster) }}"
          class="admin-form">
        @csrf
        @if($coffee->exists) @method('PUT') @endif

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
                <label>Coffee Name *</label>
                <input type="text" name="name" value="{{ old('name', $coffee->name) }}" required placeholder="e.g. Yirgacheffe Natural">
            </div>
            <div class="form-group">
                <label>Origin *</label>
                <input type="text" name="origin" value="{{ old('origin', $coffee->origin) }}" required placeholder="e.g. Ethiopia, Yirgacheffe">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Process</label>
                <select name="process">
                    <option value="">— Unknown —</option>
                    @foreach(['Washed', 'Natural', 'Honey', 'Anaerobic', 'Wet-hulled', 'Other'] as $p)
                        <option value="{{ strtolower($p) }}" @selected(old('process', $coffee->process) === strtolower($p))>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Roast Level</label>
                <select name="roast_level">
                    <option value="">— Unknown —</option>
                    @foreach(['Light', 'Medium-Light', 'Medium', 'Medium-Dark', 'Dark'] as $r)
                        <option value="{{ strtolower($r) }}" @selected(old('roast_level', $coffee->roast_level) === strtolower($r))>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Varietal</label>
            <input type="text" name="varietal" value="{{ old('varietal', $coffee->varietal) }}" placeholder="e.g. Heirloom, Bourbon, Gesha">
        </div>

        <div class="form-group">
            <label>Tasting Notes</label>
            <input type="text" name="tasting_notes" value="{{ old('tasting_notes', $coffee->tasting_notes) }}" placeholder="e.g. Blueberry, jasmine, dark chocolate">
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button type="submit" class="btn btn-primary">{{ $coffee->exists ? 'Save Changes' : 'Add Offering' }}</button>
            <a href="{{ route('admin.roasters.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

    @if($coffee->exists)
        <div class="form-section" style="margin-top: 30px;">
            <h3>Bag Sizes & Pricing</h3>
            <p style="color: #666; font-size: 13px; margin-bottom: 12px;">Each bag size is a separate variant with its own price and stock state. Daily price snapshots are tracked per variant.</p>

            <table class="admin-table" style="margin-bottom: 16px;">
                <thead>
                    <tr>
                        <th>Weight (g)</th>
                        <th>Price</th>
                        <th>$/g</th>
                        <th>In stock</th>
                        <th>Purchase link</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($coffee->variants as $variant)
                        <tr>
                            <form method="POST" action="{{ route('admin.variants.update', $variant) }}">
                                @csrf @method('PUT')
                                <td><input type="number" name="bag_weight_grams" value="{{ $variant->bag_weight_grams }}" min="1" required style="width: 100px;"></td>
                                <td><input type="number" name="price" value="{{ $variant->price }}" step="0.01" min="0" required style="width: 100px;"></td>
                                <td>${{ number_format($variant->price_per_gram, 3) }}</td>
                                <td><input type="checkbox" name="in_stock" value="1" @checked($variant->in_stock)></td>
                                <td><input type="url" name="purchase_link" value="{{ $variant->purchase_link }}" placeholder="https://…" style="width: 220px;"></td>
                                <td>
                                    <button type="submit" class="btn btn-small btn-primary">Save</button>
                            </form>
                            <form method="POST" action="{{ route('admin.variants.destroy', $variant) }}" style="display:inline" onsubmit="return confirm('Remove this variant?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-small btn-danger">Remove</button>
                            </form>
                                </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <form method="POST" action="{{ route('admin.variants.store', $coffee) }}" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                @csrf
                <div class="form-group" style="margin: 0;">
                    <label>Weight (g)</label>
                    <input type="number" name="bag_weight_grams" min="1" placeholder="340" required style="width: 100px;">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>Price</label>
                    <input type="number" name="price" step="0.01" min="0" placeholder="22.00" required style="width: 100px;">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>Purchase link</label>
                    <input type="url" name="purchase_link" placeholder="https://…" style="width: 240px;">
                </div>
                <label class="checkbox-label" style="margin-bottom: 6px;">
                    <input type="checkbox" name="in_stock" value="1" checked> In stock
                </label>
                <button type="submit" class="btn btn-primary">+ Add variant</button>
            </form>
        </div>
    @endif
</div>
@endsection
