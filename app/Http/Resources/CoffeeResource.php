<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical JSON shape for a coffee in the bean-centric /api/coffees listing.
 * Defined once here so list and (future) detail responses can't drift.
 *
 * `rating_summary` is attached to the model by the controller from one grouped
 * query so this resource never triggers an N+1.
 */
class CoffeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'origin' => $this->origin,
            'process' => $this->process,
            'roast_level' => $this->roast_level,
            'varietal' => $this->varietal,
            'tasting_notes' => $this->tasting_notes,
            'image_url' => $this->image_url,
            'product_url' => $this->product_url,
            'is_blend' => (bool) $this->is_blend,
            'is_removed' => $this->removed_at !== null,
            'best_price_per_gram' => $this->best_price_per_gram,
            'best_cents_per_gram' => $this->best_cents_per_gram,
            'in_stock' => $this->whenLoaded('variants', fn () => $this->variants->contains('in_stock', true)),
            'roaster' => $this->whenLoaded('roaster', fn () => [
                'id' => $this->roaster->id,
                'slug' => $this->roaster->slug,
                'name' => $this->roaster->name,
                'city' => $this->roaster->city,
                'region' => $this->roaster->region,
                'favicon_url' => $this->roaster->favicon_url,
            ]),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'rating' => $this->rating_summary ?? ['count' => 0, 'average' => null, 'average_stars' => null],
        ];
    }
}
