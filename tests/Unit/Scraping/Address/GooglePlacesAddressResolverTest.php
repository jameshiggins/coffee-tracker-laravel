<?php

namespace Tests\Unit\Scraping\Address;

use App\Services\Scraping\Address\GooglePlacesAddressResolver;
use Tests\TestCase;

/**
 * Step 4 of the address cascade. INTENTIONALLY STUBBED — implementing the
 * real Find-Place-from-Text call needs a Google Cloud project, billing, and
 * a key bound to the Places API. See config('services.google_places.key').
 *
 * The contract here is small but exercised by the cascade:
 *   - hasKey() reports whether config is wired
 *   - resolve() throws if invoked with no key (a misconfiguration signal,
 *     never silently fails)
 */
class GooglePlacesAddressResolverTest extends TestCase
{
    public function test_has_key_is_false_when_config_unset(): void
    {
        config(['services.google_places.key' => null]);
        $this->assertFalse((new GooglePlacesAddressResolver())->hasKey());
    }

    public function test_has_key_is_true_when_config_set(): void
    {
        config(['services.google_places.key' => 'fake-key']);
        $this->assertTrue((new GooglePlacesAddressResolver())->hasKey());
    }

    public function test_resolve_throws_runtime_exception_when_key_missing(): void
    {
        config(['services.google_places.key' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GOOGLE_PLACES_API_KEY/');

        (new GooglePlacesAddressResolver())->resolve('Acme', 'Vancouver');
    }
}
