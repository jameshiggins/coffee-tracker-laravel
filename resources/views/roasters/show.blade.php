@extends('layouts.app')

@section('title', $roaster->name . ' – BC Roaster Directory')

@section('content')
<div class="mb-6">
    <a href="{{ route('roasters.index') }}" class="text-espresso-500 hover:text-espresso-700 text-sm">← Back to Directory</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-espresso-100 p-6 mb-8">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-espresso-900">{{ $roaster->name }}</h1>
            <div class="text-espresso-500 mt-1">📍 {{ $roaster->city }}, British Columbia</div>
        </div>
        <div class="flex gap-2 flex-shrink-0">
            @if($roaster->website)
                <a href="{{ $roaster->website }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 bg-espresso-800 hover:bg-espresso-700 text-white text-sm px-4 py-2 rounded-lg transition-colors">
                    🌐 Website
                </a>
            @endif
            @if($roaster->instagram)
                <a href="https://instagram.com/{{ ltrim($roaster->instagram, '@') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 bg-pink-600 hover:bg-pink-500 text-white text-sm px-4 py-2 rounded-lg transition-colors">
                    📸 Instagram
                </a>
            @endif
        </div>
    </div>

    @if($roaster->description)
        <p class="mt-4 text-espresso-700 leading-relaxed">{{ $roaster->description }}</p>
    @endif

    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-espresso-50 rounded-lg p-4 border border-espresso-100">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-lg">🚚</span>
                <span class="font-semibold text-espresso-800">Online Shipping</span>
                @if($roaster->has_shipping)
                    <span class="ml-auto text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full border border-green-200">Available</span>
                @else
                    <span class="ml-auto text-xs bg-espresso-100 text-espresso-500 px-2 py-0.5 rounded-full border border-espresso-200">Not available</span>
                @endif
            </div>
            @if($roaster->has_shipping)
                @if($roaster->shipping_cost !== null)
                    <div class="text-sm text-espresso-600">Flat rate: <span class="font-medium">${{ number_format($roaster->shipping_cost, 2) }}</span></div>
                @endif
                @if($roaster->free_shipping_over !== null)
                    <div class="text-sm text-espresso-600">Free shipping over: <span class="font-medium">${{ number_format($roaster->free_shipping_over, 2) }}</span></div>
                @endif
                @if($roaster->shipping_notes)
                    <div class="text-sm text-espresso-500 mt-1">{{ $roaster->shipping_notes }}</div>
                @endif
            @endif
        </div>

        <div class="bg-espresso-50 rounded-lg p-4 border border-espresso-100">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-lg">🔁</span>
                <span class="font-semibold text-espresso-800">Subscription</span>
                @if($roaster->has_subscription)
                    <span class="ml-auto text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full border border-purple-200">Available</span>
                @else
                    <span class="ml-auto text-xs bg-espresso-100 text-espresso-500 px-2 py-0.5 rounded-full border border-espresso-200">Not available</span>
                @endif
            </div>
            @if($roaster->has_subscription && $roaster->subscription_notes)
                <div class="text-sm text-espresso-500">{{ $roaster->subscription_notes }}</div>
            @endif
        </div>
    </div>
</div>

<div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-bold text-espresso-900">Current Offerings</h2>
    <span class="text-espresso-400 text-sm">{{ $coffees->count() }} {{ Str::plural('item', $coffees->count()) }}</span>
</div>

@if($coffees->isEmpty())
    <div class="bg-white rounded-xl border border-espresso-100 p-10 text-center text-espresso-400">
        No offerings listed yet.
    </div>
@else
    <div class="overflow-x-auto rounded-xl shadow-sm border border-espresso-100">
        <table class="w-full bg-white text-sm">
            <thead>
                <tr class="bg-espresso-50 border-b border-espresso-100 text-espresso-600 text-xs uppercase tracking-wide">
                    <th class="text-left px-4 py-3">Coffee</th>
                    <th class="text-left px-4 py-3">Origin</th>
                    <th class="text-left px-4 py-3">Process</th>
                    <th class="text-left px-4 py-3">Roast</th>
                    <th class="text-right px-4 py-3">Weight</th>
                    <th class="text-right px-4 py-3">Price</th>
                    <th class="text-right px-4 py-3">$/g</th>
                    <th class="text-center px-4 py-3">Stock</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-espresso-50">
                @foreach($coffees as $coffee)
                @php
                    $roastColors = [
                        'light'  => 'bg-yellow-50 text-yellow-700 border-yellow-100',
                        'medium' => 'bg-orange-50 text-orange-700 border-orange-100',
                        'dark'   => 'bg-red-50 text-red-700 border-red-100',
                    ];
                    $cl = $roastColors[strtolower($coffee->roast_level ?? '')] ?? 'bg-espresso-50 text-espresso-600 border-espresso-100';
                @endphp
                <tr class="hover:bg-espresso-50 transition-colors {{ !$coffee->in_stock ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3">
                        <div class="font-medium text-espresso-900">{{ $coffee->name }}</div>
                        @if($coffee->varietal)
                            <div class="text-espresso-400 text-xs">{{ $coffee->varietal }}</div>
                        @endif
                        @if($coffee->tasting_notes)
                            <div class="text-espresso-500 text-xs italic mt-0.5">{{ $coffee->tasting_notes }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-espresso-700">{{ $coffee->origin }}</td>
                    <td class="px-4 py-3">
                        @if($coffee->process)
                            <span class="bg-amber-50 text-amber-700 border border-amber-100 text-xs px-2 py-0.5 rounded-full capitalize">
                                {{ $coffee->process }}
                            </span>
                        @else
                            <span class="text-espresso-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($coffee->roast_level)
                            <span class="text-xs px-2 py-0.5 rounded-full border capitalize {{ $cl }}">{{ $coffee->roast_level }}</span>
                        @else
                            <span class="text-espresso-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-espresso-700">{{ $coffee->bag_weight_grams }}g</td>
                    <td class="px-4 py-3 text-right font-medium text-espresso-900">${{ number_format($coffee->price, 2) }}</td>
                    <td class="px-4 py-3 text-right text-espresso-600 font-mono text-xs">${{ number_format($coffee->price_per_gram, 3) }}/g</td>
                    <td class="px-4 py-3 text-center">
                        @if($coffee->in_stock)
                            <span class="inline-block w-2 h-2 bg-green-400 rounded-full" title="In stock"></span>
                        @else
                            <span class="inline-block w-2 h-2 bg-red-300 rounded-full" title="Out of stock"></span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
