@extends('layouts.app')

@section('title', 'BC Coffee Roasters Price Tracker')

@section('content')
@php
    $regionClasses = [
        'Victoria' => 'region-victoria',
        'Vancouver' => 'region-vancouver',
        'Interior' => 'region-interior',
        'Kootenays' => 'region-kootenays',
        'Okanagan' => 'region-okanagan',
    ];

    function sortUrl($field, $currentSort, $currentDir) {
        $dir = ($currentSort === $field && $currentDir === 'asc') ? 'desc' : 'asc';
        return request()->fullUrlWithQuery(['sort' => $field, 'dir' => $dir]);
    }

    function sortArrow($field, $currentSort, $currentDir) {
        if ($currentSort !== $field) return '↕';
        return $currentDir === 'asc' ? '↑' : '↓';
    }
@endphp

<form method="GET" action="{{ route('roasters.index') }}">
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">
    <div class="controls">
        <div class="search-box">
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search roasters, regions, or coffee names...">
        </div>
        <div class="filter-controls">
            <select name="region" onchange="this.form.submit()">
                <option value="">All Regions</option>
                @foreach($regions as $region)
                    <option value="{{ $region }}" @selected(request('region') === $region)>{{ $region }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary">Search</button>
            @if(request()->hasAny(['search', 'region']))
                <a href="{{ route('roasters.index') }}" class="btn btn-secondary">Clear</a>
            @endif
        </div>
    </div>
</form>

<div class="stats">
    <div class="stat-card">
        <div class="stat-number">{{ $totalRoasters }}</div>
        <div class="stat-label">Total Roasters</div>
    </div>
    <div class="stat-card">
        <div class="stat-number">{{ $totalCoffees }}</div>
        <div class="stat-label">Coffee Offerings</div>
    </div>
    <div class="stat-card">
        <div class="stat-number">${{ number_format($avgPrice, 2) }}</div>
        <div class="stat-label">Average Price</div>
    </div>
    <div class="stat-card">
        <div class="stat-number">{{ number_format($avgCentsPerGram, 1) }}¢</div>
        <div class="stat-label">Average ¢/g</div>
    </div>
</div>

<div class="table-container">
    @if($coffees->isEmpty())
        <div class="empty-state">
            <h3>No coffee data found</h3>
            <p>Try adjusting your search or filters.</p>
        </div>
    @else
        <table class="coffee-table">
            <thead>
                <tr>
                    <th class="sort-header">
                        <a href="{{ sortUrl('roaster', $sort, $dir) }}">
                            Roaster <span class="sort-arrow">{{ sortArrow('roaster', $sort, $dir) }}</span>
                        </a>
                    </th>
                    <th class="sort-header">
                        <a href="{{ sortUrl('region', $sort, $dir) }}">
                            Region <span class="sort-arrow">{{ sortArrow('region', $sort, $dir) }}</span>
                        </a>
                    </th>
                    <th class="sort-header">
                        <a href="{{ sortUrl('name', $sort, $dir) }}">
                            Coffee Name <span class="sort-arrow">{{ sortArrow('name', $sort, $dir) }}</span>
                        </a>
                    </th>
                    <th class="sort-header">
                        <a href="{{ sortUrl('bag_weight_grams', $sort, $dir) }}">
                            Bag Size (g) <span class="sort-arrow">{{ sortArrow('bag_weight_grams', $sort, $dir) }}</span>
                        </a>
                    </th>
                    <th class="sort-header">
                        <a href="{{ sortUrl('price', $sort, $dir) }}">
                            Price <span class="sort-arrow">{{ sortArrow('price', $sort, $dir) }}</span>
                        </a>
                    </th>
                    <th class="sort-header">
                        <a href="{{ sortUrl('cents_per_gram', $sort, $dir) }}">
                            ¢/g <span class="sort-arrow">{{ sortArrow('cents_per_gram', $sort, $dir) }}</span>
                        </a>
                    </th>
                    <th>Purchase Link</th>
                </tr>
            </thead>
            <tbody>
                @foreach($coffees as $variant)
                @php
                    $coffee = $variant->coffee;
                    $roaster = $coffee?->roaster;
                    if (!$coffee || !$roaster) continue;
                    $cpg = $variant->cents_per_gram;
                    $priceClass = $cpg < 6.5 ? 'price-good' : ($cpg < 7.5 ? 'price-average' : 'price-expensive');
                    $regionKey = $roaster->region ?? 'Vancouver';
                    $badgeClass = $regionClasses[$regionKey] ?? 'region-vancouver';
                @endphp
                <tr>
                    <td><strong>{{ $roaster->name }}</strong></td>
                    <td><span class="region-badge {{ $badgeClass }}">{{ $roaster->region ?? '—' }}</span></td>
                    <td>{{ $coffee->name }}</td>
                    <td>{{ $variant->bag_weight_grams }}g</td>
                    <td class="price-cell">${{ number_format($variant->price, 2) }}</td>
                    <td class="price-cell {{ $priceClass }}">{{ number_format($cpg, 1) }}¢</td>
                    <td>
                        @if($variant->purchase_link)
                            <a href="{{ $variant->purchase_link }}" target="_blank" rel="noopener" class="btn btn-small btn-primary">Buy Now</a>
                        @elseif($roaster->website)
                            <a href="{{ $roaster->website }}" target="_blank" rel="noopener" class="btn btn-small btn-secondary">Visit Site</a>
                        @else
                            <span style="color: #999;">No link</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
