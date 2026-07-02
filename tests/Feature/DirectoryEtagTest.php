<?php

namespace Tests\Feature;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP revalidation for the directory firehose (H6 interim, 2026-07 review
 * P2): GET /api/roasters carries an ETag derived from the same content
 * version that keys the server-side cache. A matching If-None-Match gets an
 * empty 304 instead of the full multi-hundred-KB payload; any content
 * change — including deletions that only move a COUNT, not max(updated_at)
 * (P3: admin variant delete) — must change the ETag.
 */
class DirectoryEtagTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoaster(): Roaster
    {
        $r = Roaster::create([
            'name' => 'Etag Roaster', 'slug' => 'etag-roaster', 'city' => 'Vancouver',
            'is_active' => true, 'has_shipping' => true, 'website' => 'https://etag.example',
        ]);
        $coffee = $r->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia', 'is_blend' => false]);
        $coffee->variants()->create(['bag_weight_grams' => 250, 'price' => 20, 'in_stock' => true]);
        $coffee->variants()->create(['bag_weight_grams' => 1000, 'price' => 60, 'in_stock' => true]);

        return $r;
    }

    public function test_index_sends_an_etag_and_cache_control(): void
    {
        $this->seedRoaster();

        $res = $this->getJson('/api/roasters');

        $res->assertOk();
        $this->assertNotEmpty($res->headers->get('ETag'));
        $this->assertStringContainsString('max-age=300', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));
    }

    public function test_matching_if_none_match_returns_an_empty_304(): void
    {
        $this->seedRoaster();
        $etag = $this->getJson('/api/roasters')->headers->get('ETag');

        $res = $this->call('GET', '/api/roasters', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag]);

        $res->assertStatus(304);
        $this->assertSame('', (string) $res->getContent(), '304 must carry no body');
        $this->assertSame($etag, $res->headers->get('ETag'));
    }

    public function test_deleting_a_variant_changes_the_etag_and_defeats_a_stale_match(): void
    {
        $r = $this->seedRoaster();
        $etag1 = $this->getJson('/api/roasters')->headers->get('ETag');

        // Deleting the NON-newest variant leaves every max(updated_at)
        // untouched — only the variant COUNT moves. This was the P3 cache
        // hole: without CoffeeVariant::count() in the version hash, the
        // stale payload survived for the full TTL.
        $r->coffees()->first()->variants()->orderBy('id')->first()->delete();

        $etag2 = $this->getJson('/api/roasters')->headers->get('ETag');
        $this->assertNotSame($etag1, $etag2, 'variant deletion must move the content version');

        // A client holding the stale ETag gets fresh content, not a 304.
        $this->call('GET', '/api/roasters', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag1])
            ->assertStatus(200);
    }
}
