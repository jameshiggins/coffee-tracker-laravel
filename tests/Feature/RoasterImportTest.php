<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\RoasterImporter;
use App\Services\ShopifyScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoasterImportTest extends TestCase
{
    use RefreshDatabase;

    private function fakeShopifyResponse(): array
    {
        return [
            'products' => [
                [
                    'id' => 1, 'title' => 'Ethiopia Yirgacheffe', 'product_type' => 'Coffee',
                    'tags' => ['Single Origin', 'Ethiopia'], 'body_html' => '<p>Floral notes.</p>',
                    'variants' => [
                        ['id' => 11, 'title' => '250g', 'price' => '24.00', 'available' => true],
                        ['id' => 12, 'title' => '1lb', 'price' => '38.00', 'available' => true],
                    ],
                ],
                [
                    'id' => 2, 'title' => 'House Blend', 'product_type' => 'Coffee',
                    'tags' => ['Blend'], 'body_html' => '',
                    'variants' => [['id' => 21, 'title' => '340g', 'price' => '20.00', 'available' => true]],
                ],
            ],
        ];
    }

    public function test_import_creates_roaster_from_url_with_scraped_coffees_and_variants(): void
    {
        Http::fake([
            'roasterexample.com/products.json*' => Http::response($this->fakeShopifyResponse(), 200),
        ]);

        $roaster = (new RoasterImporter())->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $this->assertInstanceOf(Roaster::class, $roaster);
        $this->assertSame('roaster-example', $roaster->slug);
        $this->assertSame(2, $roaster->coffees()->count(), 'imports single-origin + blend');

        $coffee = $roaster->coffees()->where('name', 'Ethiopia Yirgacheffe')->with('variants')->first();
        $this->assertNotNull($coffee);
        $this->assertSame(2, $coffee->variants()->count());
    }

    public function test_import_persists_is_blend_flag_per_coffee(): void
    {
        Http::fake(['*' => Http::response($this->fakeShopifyResponse(), 200)]);

        $roaster = (new RoasterImporter())->import('https://roasterexample.com', name: 'X', city: 'Vancouver');

        $yirg = $roaster->coffees()->where('name', 'Ethiopia Yirgacheffe')->first();
        $blend = $roaster->coffees()->where('name', 'House Blend')->first();

        $this->assertFalse($yirg->is_blend, 'single-origin coffee should not be marked as blend');
        $this->assertTrue($blend->is_blend, 'blend coffee should be marked as such');
    }

    public function test_import_marks_a_default_variant_per_coffee(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeShopifyResponse(), 200),
        ]);

        $roaster = (new RoasterImporter())->import('https://roasterexample.com', name: 'X', city: 'Vancouver');
        $coffee = $roaster->coffees()->with('variants')->first();
        $defaults = $coffee->variants()->where('is_default', true)->count();
        $this->assertSame(1, $defaults);
    }

    public function test_reimport_updates_existing_roaster_in_place(): void
    {
        Http::fake(['*' => Http::response($this->fakeShopifyResponse(), 200)]);

        $importer = new RoasterImporter();
        $first = $importer->import('https://roasterexample.com', name: 'X', city: 'Vancouver');
        $second = $importer->import('https://roasterexample.com', name: 'X', city: 'Vancouver');

        $this->assertSame($first->id, $second->id, 'should update, not duplicate');
        $this->assertSame(1, Roaster::count());
    }

    public function test_import_throws_when_shopify_endpoint_is_unreachable(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->expectException(\RuntimeException::class);
        (new RoasterImporter())->import('https://roasterexample.com', name: 'X', city: 'Vancouver');
    }
}
