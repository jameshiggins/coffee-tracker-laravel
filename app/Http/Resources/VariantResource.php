<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical JSON shape for a coffee variant (bag size). Used by the
 * bean-centric /api/coffees endpoints so the shape is defined once.
 */
class VariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bag_weight_grams' => $this->bag_weight_grams,
            'source_size_label' => $this->source_size_label,
            'price' => (float) $this->price,
            'currency_code' => $this->currency_code ?? 'CAD',
            'in_stock' => (bool) $this->in_stock,
            'purchase_link' => $this->purchase_link,
            'price_per_gram' => $this->price_per_gram,
            'cents_per_gram' => $this->cents_per_gram,
        ];
    }
}
