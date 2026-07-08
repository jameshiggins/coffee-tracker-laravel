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

    public function test_dns_failure_stamps_import_failing_since_and_preserves_it_across_failures(): void
    {
        // Throwing fake = a real connection failure ("could not resolve host").
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: deadco.test')]);

        $import = fn () => rescue(fn () => (new RoasterImporter())->import('https://deadco.test', name: 'Dead Co'), null, false);

        $import();
        $roaster = Roaster::where('slug', 'dead-co')->firstOrFail();
        $this->assertSame('error', $roaster->last_import_status);
        $this->assertNotNull($roaster->import_failing_since, 'failure streak stamped');
        $this->assertSame('dead_domain', $roaster->importErrorKind());
        $firstStamp = $roaster->import_failing_since;

        // A second failure (same fake still throws) must NOT move the streak
        // start — the 7-day window measures from the first failure.
        $this->travel(1)->days();
        $import();
        $this->assertEquals(
            $firstStamp->toDateTimeString(),
            $roaster->fresh()->import_failing_since->toDateTimeString(),
            'streak start preserved across failures'
        );
        $this->travelBack();
    }

    public function test_a_successful_import_clears_the_failing_streak(): void
    {
        // A roaster mid-failure-streak; matched by website on re-import.
        $roaster = Roaster::factory()->create([
            'name' => 'Dead Co', 'website' => 'https://deadco.test',
            'last_import_status' => 'error', 'import_failing_since' => now()->subDays(3),
        ]);

        Http::fake(['*' => Http::response($this->fakeShopifyResponse(), 200)]);
        (new RoasterImporter())->import('https://deadco.test', name: 'Dead Co');

        $roaster->refresh();
        $this->assertNull($roaster->import_failing_since, 'a response ends the streak');
        $this->assertSame('success', $roaster->last_import_status);
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

    public function test_import_recovers_metafield_notes_roast_process_from_product_page(): void
    {
        // A3a (Agro Roasters): /products.json carries only a thin body_html
        // ("seasonal lineup…") — roast/notes/process live in metafields, which
        // the API omits but the product page renders as bolded label rows. The
        // importer should fetch the page, recover those fields, and persist them.
        $productsJson = ['products' => [[
            'id' => 555, 'title' => 'Peru Geisha, Inabel Abad Jimenez', 'product_type' => 'Coffee',
            'tags' => ['Single Origin'], 'handle' => 'peru-geisha',
            'body_html' => '<p>This coffee is part of our seasonal lineup and available in small batches.</p>',
            'variants' => [['id' => 5551, 'title' => '340g', 'price' => '25.00', 'available' => true]],
        ]]];

        $pageHtml = <<<'HTML'
        <html><head><meta name="description" content="Golden berry • Jasmine • Pear"></head>
        <body><div class="product__info">
          <p><strong>Roast: </strong><span class="metafield-single_line_text_field">Light</span></p>
          <p><strong>Notes: </strong><span class="metafield-multi_line_text_field">Golden berry • Jasmine • Pear</span></p>
          <p><strong>Process: </strong><span class="metafield-single_line_text_field">Washed</span></p>
        </div></body></html>
        HTML;

        Http::fake([
            'agro.test/products.json*' => Http::response($productsJson, 200),
            'agro.test/products/peru-geisha*' => Http::response($pageHtml, 200),
            '*' => Http::response('', 200), // about/favicon/shipping best-effort
        ]);

        $roaster = (new RoasterImporter())->import('https://agro.test', name: 'Agro', city: 'Calgary');

        $coffee = $roaster->coffees()->where('name', 'like', 'Peru Geisha%')->first();
        $this->assertNotNull($coffee);
        $this->assertSame('Golden berry, Jasmine, Pear', $coffee->tasting_notes, 'bullet notes recovered + normalized');
        $this->assertSame('light', $coffee->roast_level);
        $this->assertSame('Washed', $coffee->process);
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
