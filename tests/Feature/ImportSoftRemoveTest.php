<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use App\Services\RoasterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole point of Q1+Q2: re-importing a roaster must NOT cascade-delete
 * user tastings. Coffees with stable source ids upsert in place; coffees
 * that disappear from the source get soft-removed (removed_at set) but the
 * row stays so foreign keys hold.
 */
class ImportSoftRemoveTest extends TestCase
{
    use RefreshDatabase;

    private function shopifyResponse(array $products): array
    {
        return ['products' => $products];
    }

    private function product(int $id, string $title, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => $title,
            'product_type' => 'Coffee',
            'tags' => [],
            'body_html' => '',
            'handle' => strtolower(str_replace(' ', '-', $title)),
            'variants' => [['id' => $id * 10, 'title' => '340g', 'price' => '24.00', 'available' => true]],
        ], $extra);
    }

    public function test_reimport_preserves_existing_coffee_id_when_source_id_matches(): void
    {
        Http::fake(['*' => Http::response($this->shopifyResponse([
            $this->product(101, 'Ethiopia Yirg'),
        ]), 200)]);

        $importer = new RoasterImporter();
        $roaster = $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $firstCoffeeId = $roaster->coffees()->first()->id;

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $secondCoffeeId = $roaster->fresh()->coffees()->first()->id;

        $this->assertSame($firstCoffeeId, $secondCoffeeId, 'same source_id must keep the same row id');
    }

    public function test_reimport_preserves_user_tastings_across_re_imports(): void
    {
        // The data-integrity invariant Q1+Q2 exists to guarantee.
        Http::fake(['*' => Http::response($this->shopifyResponse([
            $this->product(101, 'Ethiopia Yirg'),
        ]), 200)]);

        $importer = new RoasterImporter();
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $coffee = Coffee::first();

        $user = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x')]);
        $tasting = Tasting::create([
            'user_id' => $user->id, 'coffee_id' => $coffee->id,
            'rating' => 8, 'notes' => 'Loved it', 'tasted_on' => '2026-04-01',
        ]);

        // Re-import — new behaviour must NOT cascade-delete this tasting.
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');

        $this->assertDatabaseHas('tastings', ['id' => $tasting->id, 'coffee_id' => $coffee->id]);
        $this->assertSame(1, Tasting::count());
    }

    public function test_coffee_missing_from_fresh_import_gets_soft_removed(): void
    {
        $importer = new RoasterImporter();

        // Use Http::fakeSequence so each call gets a fresh response.
        // The first import's products.json probe + fetch consume the same
        // response (probe = limit=1, fetch = limit=250); we set per-call.
        Http::fakeSequence()
            // probe + fetch for first import (both hit /products.json*)
            ->push($this->shopifyResponse([$this->product(101, 'Yirg'), $this->product(102, 'House Blend')]), 200)
            ->push($this->shopifyResponse([$this->product(101, 'Yirg'), $this->product(102, 'House Blend')]), 200)
            // the second import already has cached platform=shopify, so only one fetch call
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200);

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');

        $yirg = Coffee::where('source_id', '101')->first();
        $blend = Coffee::where('source_id', '102')->first();
        $this->assertNotNull($blend, 'soft-removed coffee row must still exist');
        $this->assertNotNull($blend->removed_at, 'absent coffee should have removed_at set');
        $this->assertNull($yirg->removed_at, 'still-present coffee should not be soft-removed');
    }

    public function test_soft_removed_coffee_un_removes_when_it_reappears(): void
    {
        $importer = new RoasterImporter();

        Http::fakeSequence()
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // probe
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // fetch
            ->push($this->shopifyResponse([]), 200)                             // disappears
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200); // comes back

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $this->assertNotNull(Coffee::where('source_id', '101')->first()->removed_at);

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $this->assertNull(Coffee::where('source_id', '101')->first()->removed_at,
            'restored coffee should have removed_at cleared');
    }

    public function test_available_scope_excludes_soft_removed(): void
    {
        $roaster = Roaster::create(['name' => 'R', 'slug' => 'r', 'city' => 'V']);
        $live = $roaster->coffees()->create(['name' => 'A', 'origin' => 'Ethiopia']);
        $gone = $roaster->coffees()->create(['name' => 'B', 'origin' => 'Brazil', 'removed_at' => now()]);

        $available = Coffee::available()->pluck('id')->all();
        $this->assertContains($live->id, $available);
        $this->assertNotContains($gone->id, $available);
    }
}
