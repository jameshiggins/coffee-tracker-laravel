<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportAllCommandTest extends TestCase
{
    use RefreshDatabase;

    private function shopifyResponse(string $beanName): array
    {
        return [
            'products' => [[
                'id' => 1, 'title' => $beanName, 'product_type' => 'Coffee', 'tags' => [],
                'body_html' => '',
                'variants' => [['id' => 11, 'title' => '250g', 'price' => '20.00', 'available' => true]],
            ]],
        ];
    }

    public function test_command_imports_each_active_roaster_with_a_website(): void
    {
        Roaster::create(['name' => 'Alpha', 'slug' => 'alpha', 'city' => 'X',
            'website' => 'https://alpha.example.com', 'is_active' => true, 'has_shipping' => true]);
        Roaster::create(['name' => 'Beta', 'slug' => 'beta', 'city' => 'Y',
            'website' => 'https://beta.example.com', 'is_active' => true, 'has_shipping' => true]);

        Http::fake([
            'alpha.example.com/*' => Http::response($this->shopifyResponse('Alpha Bean'), 200),
            'beta.example.com/*' => Http::response($this->shopifyResponse('Beta Bean'), 200),
        ]);

        $this->artisan('roasters:import-all')
            ->expectsOutputToContain('imported')
            ->assertExitCode(0);

        $this->assertSame(2, Coffee::count());
    }

    public function test_command_skips_roasters_without_a_website(): void
    {
        Roaster::create(['name' => 'NoSite', 'slug' => 'nosite', 'city' => 'X', 'website' => null,
            'is_active' => true]);

        Http::fake();

        $this->artisan('roasters:import-all')->assertExitCode(0);
        $this->assertSame(0, Coffee::count());
    }

    public function test_command_skips_inactive_roasters(): void
    {
        Roaster::create(['name' => 'Inactive', 'slug' => 'inactive', 'city' => 'X',
            'website' => 'https://inactive.example.com', 'is_active' => false]);

        Http::fake();
        $this->artisan('roasters:import-all')->assertExitCode(0);
        $this->assertSame(0, Coffee::count());
    }

    public function test_command_continues_when_a_single_roaster_fails(): void
    {
        Roaster::create(['name' => 'OK', 'slug' => 'ok', 'city' => 'X',
            'website' => 'https://ok.example.com', 'is_active' => true]);
        Roaster::create(['name' => 'Bad', 'slug' => 'bad', 'city' => 'Y',
            'website' => 'https://bad.example.com', 'is_active' => true]);

        Http::fake([
            'ok.example.com/*' => Http::response($this->shopifyResponse('OK Bean'), 200),
            'bad.example.com/*' => Http::response('not found', 404),
        ]);

        $this->artisan('roasters:import-all')->assertExitCode(0);

        // OK roaster should have its bean; Bad should still be present but with no coffees.
        $this->assertSame(1, Coffee::count());
        $this->assertSame(1, Roaster::find(Roaster::where('slug', 'ok')->value('id'))->coffees()->count());
        $this->assertSame(0, Roaster::find(Roaster::where('slug', 'bad')->value('id'))->coffees()->count());
    }

    public function test_only_flag_filters_to_a_single_roaster_by_slug(): void
    {
        Roaster::create(['name' => 'Wanted', 'slug' => 'wanted', 'city' => 'X',
            'website' => 'https://wanted.example.com', 'is_active' => true]);
        Roaster::create(['name' => 'Other', 'slug' => 'other', 'city' => 'Y',
            'website' => 'https://other.example.com', 'is_active' => true]);

        Http::fake([
            'wanted.example.com/*' => Http::response($this->shopifyResponse('Wanted Bean'), 200),
            'other.example.com/*' => Http::response($this->shopifyResponse('Other Bean'), 200),
        ]);

        $this->artisan('roasters:import-all', ['--only' => 'wanted'])->assertExitCode(0);

        $this->assertSame(1, Coffee::count());
        $this->assertSame(1, Roaster::where('slug', 'wanted')->value('id') !== null
            ? Roaster::where('slug', 'wanted')->first()->coffees()->count() : 0);
    }
}
