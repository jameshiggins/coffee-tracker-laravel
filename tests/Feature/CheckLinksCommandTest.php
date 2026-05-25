<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * roasters:check-links walks the directory and HEAD-probes every
 * outbound URL (roaster website, instagram, coffee product_url, and
 * each variant's purchase_link), reporting broken / redirected / OK
 * counts so we can spot link rot before users do.
 *
 * Tests use Http::fake so they exercise the classification logic + the
 * count summary without touching real networks.
 */
class CheckLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoaster(array $attrs): Roaster
    {
        return Roaster::create(array_merge([
            'name' => 'R', 'slug' => 'r', 'city' => 'V',
            'is_active' => true, 'has_shipping' => true,
        ], $attrs));
    }

    private function addCoffee(Roaster $r, array $attrs, array $variantUrls = []): Coffee
    {
        $coffee = $r->coffees()->create(array_merge([
            'name' => 'X', 'origin' => 'Ethiopia', 'is_blend' => false,
        ], $attrs));
        // bag_weight_grams is part of the unique key; bump per variant so
        // tests that pass multiple URLs don't trip the constraint.
        foreach ($variantUrls as $i => $url) {
            $coffee->variants()->create([
                'bag_weight_grams' => 250 + $i * 100,
                'price' => 20.00,
                'in_stock' => true,
                'purchase_link' => $url,
            ]);
        }
        return $coffee;
    }

    public function test_counts_ok_redirect_and_broken_responses(): void
    {
        $r = $this->makeRoaster([
            'website' => 'https://ok-site.example',
            'instagram' => 'https://instagram.com/handle',
        ]);
        $this->addCoffee($r, ['name' => 'Yirg', 'product_url' => 'https://shop.example/yirg'], [
            'https://shop.example/yirg?variant=1',  // OK
            'https://shop.example/dead',            // 404
        ]);

        Http::fake([
            'ok-site.example*'         => Http::response('', 200),
            'instagram.com/*'          => Http::response('', 200),
            'shop.example/yirg*'       => Http::response('', 200),
            'shop.example/dead*'       => Http::response('', 404),
        ]);

        // Expected: website + instagram + coffee.product_url + variant1 = 4 OK;
        // variant2 (the /dead URL) = 1 BROKEN.
        $this->artisan('roasters:check-links')
            ->expectsOutputToContain('OK: 4')
            ->expectsOutputToContain('BROKEN: 1')
            ->assertExitCode(0);
    }

    public function test_redirects_are_counted_separately_from_ok(): void
    {
        $r = $this->makeRoaster(['website' => 'https://old-domain.example']);

        Http::fake([
            'old-domain.example*' => Http::response('', 301, ['Location' => 'https://new-domain.example']),
        ]);

        $this->artisan('roasters:check-links')
            ->expectsOutputToContain('REDIRECT: 1')
            ->assertExitCode(0);
    }

    public function test_network_errors_count_as_broken(): void
    {
        $r = $this->makeRoaster(['website' => 'https://unreachable.example']);

        // A connection-refused exception thrown by Http::fake mimics DNS
        // failure / SSL error / connection-refused — the cases that took
        // down Smoke & Mirrors in the last import-all run.
        Http::fake([
            'unreachable.example*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('DNS resolution failed'),
        ]);

        $this->artisan('roasters:check-links')
            ->expectsOutputToContain('BROKEN: 1')
            ->assertExitCode(0);
    }

    public function test_soft_removed_coffees_are_skipped(): void
    {
        $r = $this->makeRoaster(['website' => 'https://ok.example']);
        // Soft-removed coffee — its purchase_link should NOT be probed.
        $coffee = $this->addCoffee($r, [
            'name' => 'Gone', 'product_url' => 'https://shop.example/gone',
            'removed_at' => now(),
        ], ['https://shop.example/gone?variant=1']);

        Http::fake([
            'ok.example*' => Http::response('', 200),
            // Any hit to shop.example would be a regression — assert it
            // by NOT registering a fake; an unmatched fake throws.
        ]);

        $this->artisan('roasters:check-links')
            ->expectsOutputToContain('OK: 1')
            ->assertExitCode(0);

        $hitShop = [];
        Http::recorded(function ($req) use (&$hitShop) {
            if (str_contains($req->url(), 'shop.example')) $hitShop[] = $req->url();
        });
        if (!empty($hitShop)) fwrite(STDERR, "\nSHOP HITS: " . json_encode($hitShop) . " | coffee removed_at: " . (string) $coffee->fresh()->removed_at . "\n");
        $this->assertSame([], $hitShop, 'soft-removed coffee variants must not be probed');
    }

    public function test_inactive_roasters_are_skipped(): void
    {
        $this->makeRoaster([
            'website' => 'https://inactive.example',
            'is_active' => false,
        ]);

        Http::fake();

        $this->artisan('roasters:check-links')
            ->expectsOutputToContain('OK: 0')
            ->expectsOutputToContain('BROKEN: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_shopify_product_403_falls_back_to_json_endpoint_and_counts_ok(): void
    {
        // Shopify storefronts behind Cloudflare reject many requests
        // from data-center IPs (Fly) with 403 regardless of UA. The
        // /products/{handle}.json endpoint is rate-limited differently
        // and almost always answers. When we get 403 on a Shopify
        // product URL the probe should retry the .json variant and
        // count 200 there as OK — link rot it isn't.
        $r = $this->makeRoaster([
            'website' => 'https://shop.example/products/yirg',
        ]);
        $this->addCoffee($r, [
            'name' => 'Yirg',
            'product_url' => 'https://shop.example/products/yirg',
        ], ['https://shop.example/products/yirg?variant=42']);

        Http::fake([
            'shop.example/products/yirg.json*'    => Http::response(['product' => ['id' => 1]], 200),
            'shop.example/products/yirg'          => Http::response('forbidden', 403),
            'shop.example/products/yirg?variant=*' => Http::response('forbidden', 403),
        ]);

        $this->artisan('roasters:check-links')
            ->expectsOutputToContain('OK: 3')
            ->expectsOutputToContain('BROKEN: 0')
            ->assertExitCode(0);
    }

    public function test_only_flag_scopes_to_a_single_roaster(): void
    {
        $a = $this->makeRoaster(['name' => 'A', 'slug' => 'roaster-a', 'website' => 'https://a.example']);
        $b = $this->makeRoaster(['name' => 'B', 'slug' => 'roaster-b', 'website' => 'https://b.example']);

        Http::fake([
            'a.example*' => Http::response('', 200),
            'b.example*' => Http::response('', 200),
        ]);

        $this->artisan('roasters:check-links', ['--only' => 'roaster-a'])
            ->expectsOutputToContain('OK: 1')
            ->assertExitCode(0);

        $hitB = [];
        Http::recorded(function ($req) use (&$hitB) {
            if (str_contains($req->url(), 'b.example')) $hitB[] = $req->url();
        });
        if (!empty($hitB)) fwrite(STDERR, "\nUNEXPECTED HITS TO B: " . json_encode($hitB) . "\n");
        $this->assertSame([], $hitB, '--only=roaster-a must not probe roaster B');
    }
}
