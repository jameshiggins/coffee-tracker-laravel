<?php

namespace Tests\Feature\Admin;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin variant CRUD — the manual path for bag sizes and prices. Prices and
 * weights entered here feed price-per-gram sorting on the public directory,
 * so the validation floor (weight >= 1g, price >= 0, sane purchase_link)
 * matters more than usual.
 */
class AdminVariantCrudTest extends TestCase
{
    use RefreshDatabase;

    private function coffee(): Coffee
    {
        return Coffee::factory()->for(Roaster::factory())->create();
    }

    public function test_store_creates_variant_defaulting_to_in_stock(): void
    {
        $coffee = $this->coffee();

        // No in_stock checkbox in the payload → store() defaults it to true.
        $this->actingAsAdmin()
            ->post("/admin/coffees/{$coffee->id}/variants", [
                'bag_weight_grams' => 340,
                'price' => 21.50,
            ])
            ->assertRedirect(route('admin.coffees.edit', [$coffee->roaster, $coffee]));

        $variant = $coffee->variants()->firstOrFail();
        $this->assertSame(340, $variant->bag_weight_grams);
        $this->assertSame('21.50', (string) $variant->price);
        $this->assertTrue($variant->in_stock);

        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.variant.created']);
    }

    public function test_store_validates_weight_price_and_link(): void
    {
        $coffee = $this->coffee();

        $this->actingAsAdmin()
            ->post("/admin/coffees/{$coffee->id}/variants", [
                'bag_weight_grams' => 0,          // min:1
                'price' => -5,                    // min:0
                'purchase_link' => 'not-a-url',   // url
            ])
            ->assertSessionHasErrors(['bag_weight_grams', 'price', 'purchase_link']);

        $this->assertDatabaseCount('coffee_variants', 0);
    }

    public function test_update_changes_fields_and_unchecked_box_marks_out_of_stock(): void
    {
        $coffee = $this->coffee();
        $variant = CoffeeVariant::factory()->for($coffee)->create([
            'bag_weight_grams' => 250, 'price' => 18.00, 'in_stock' => true,
        ]);

        // Checkbox semantics: an absent in_stock on UPDATE means unchecked → false.
        $this->actingAsAdmin()
            ->put("/admin/variants/{$variant->id}", [
                'bag_weight_grams' => 454,
                'price' => 25.00,
                'purchase_link' => 'https://example.test/buy',
            ])
            ->assertRedirect(route('admin.coffees.edit', [$coffee->roaster, $coffee]));

        $variant->refresh();
        $this->assertSame(454, $variant->bag_weight_grams);
        $this->assertSame('25.00', (string) $variant->price);
        $this->assertSame('https://example.test/buy', $variant->purchase_link);
        $this->assertFalse($variant->in_stock);

        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.variant.updated']);
    }

    public function test_update_validation_leaves_row_untouched(): void
    {
        $variant = CoffeeVariant::factory()->for($this->coffee())->create([
            'bag_weight_grams' => 250, 'price' => 18.00,
        ]);

        $this->actingAsAdmin()
            ->put("/admin/variants/{$variant->id}", [
                'bag_weight_grams' => 250,
                'price' => 'free', // numeric
            ])
            ->assertSessionHasErrors(['price']);

        $this->assertSame('18.00', (string) $variant->fresh()->price);
    }

    public function test_destroy_deletes_variant_and_logs(): void
    {
        $coffee = $this->coffee();
        $variant = CoffeeVariant::factory()->for($coffee)->create();

        $this->actingAsAdmin()
            ->delete("/admin/variants/{$variant->id}")
            ->assertRedirect(route('admin.coffees.edit', [$coffee->roaster, $coffee]));

        $this->assertDatabaseMissing('coffee_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.variant.deleted']);
    }

    public function test_variant_routes_require_admin_authentication(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
        $coffee = $this->coffee();
        $variant = CoffeeVariant::factory()->for($coffee)->create();

        $this->post("/admin/coffees/{$coffee->id}/variants", [
            'bag_weight_grams' => 340, 'price' => 20,
        ])->assertRedirect(route('admin.login'));

        $this->delete("/admin/variants/{$variant->id}")
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('coffee_variants', ['id' => $variant->id]);
        $this->assertDatabaseCount('coffee_variants', 1);
    }
}
