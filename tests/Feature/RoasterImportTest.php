<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\RoasterImporter;
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
                    'handle' => 'ethiopia-yirgacheffe',
                    'images' => [['src' => 'https://cdn.example.com/yirg.jpg']],
                    'variants' => [
                        ['id' => 11, 'title' => '250g', 'price' => '24.00', 'available' => true],
                        ['id' => 12, 'title' => '1lb', 'price' => '38.00', 'available' => true],
                    ],
                ],
                [
                    'id' => 2, 'title' => 'House Blend', 'product_type' => 'Coffee',
                    'tags' => ['Blend'], 'body_html' => '',
                    'handle' => 'house-blend',
                    'variants' => [['id' => 21, 'title' => '340g', 'price' => '20.00', 'available' => true]],
                ],
            ],
        ];
    }

    public function test_import_creates_roaster_with_scraped_coffees_and_variants(): void
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

        $this->assertFalse($yirg->is_blend);
        $this->assertTrue($blend->is_blend);
    }

    public function test_reimport_updates_existing_roaster_in_place(): void
    {
        Http::fake(['*' => Http::response($this->fakeShopifyResponse(), 200)]);

        $importer = new RoasterImporter();
        $first = $importer->import('https://roasterexample.com', name: 'X', city: 'Vancouver');
        $second = $importer->import('https://roasterexample.com', name: 'X', city: 'Vancouver');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Roaster::count());
    }

    public function test_successful_import_caches_platform_on_roaster(): void
    {
        Http::fake(['*' => Http::response($this->fakeShopifyResponse(), 200)]);

        $roaster = (new RoasterImporter())->import('https://roasterexample.com', name: 'X', city: 'Vancouver');

        $this->assertSame('shopify', $roaster->platform);
        $this->assertSame('success', $roaster->last_import_status);
        $this->assertNotNull($roaster->last_imported_at);
        $this->assertNull($roaster->last_import_error);
    }

    public function test_empty_product_list_records_empty_status(): void
    {
        Http::fake(['*' => Http::response(['products' => []], 200)]);

        $roaster = (new RoasterImporter())->import('https://example.com', name: 'X', city: 'Vancouver');

        $this->assertSame('empty', $roaster->last_import_status);
        $this->assertSame(0, $roaster->coffees()->count());
    }

    public function test_failed_import_records_error_status_and_rethrows(): void
    {
        // All scrapers fail to handle a network error — Generic-HTML's homepage
        // fetch also gets the 500. Importer records the error and rethrows so
        // the caller (artisan command) can log it.
        Http::fake(['*' => Http::response('boom', 500)]);

        try {
            (new RoasterImporter())->import('https://example.com', name: 'X', city: 'Vancouver');
            $this->fail('expected import to throw');
        } catch (\RuntimeException) {
            // expected
        }

        $roaster = Roaster::where('slug', 'x')->first();
        $this->assertNotNull($roaster);
        $this->assertSame('error', $roaster->last_import_status);
        $this->assertNotEmpty($roaster->last_import_error);
    }
}
