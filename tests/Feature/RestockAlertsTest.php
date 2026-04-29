<?php

namespace Tests\Feature;

use App\Mail\RestockDigest;
use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RestockAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function setupRoasterCoffeeAndVariant(): array
    {
        $r = Roaster::create(['name' => 'R', 'slug' => 'r', 'city' => 'V']);
        $c = $r->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);
        $v = $c->variants()->create([
            'bag_weight_grams' => 250,
            'price' => 24,
            'in_stock' => true,
            'in_stock_changed_at' => now(),
        ]);
        return ['roaster' => $r, 'coffee' => $c, 'variant' => $v];
    }

    private function makeUser(string $email, bool $verified = true): User
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return User::create([
            'name' => 'A', 'email' => $email,
            'display_name' => 'taster_' . $suffix,
            'password' => bcrypt('x'),
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    public function test_emails_users_with_wishlisted_recently_restocked_coffee(): void
    {
        Mail::fake();
        ['coffee' => $coffee] = $this->setupRoasterCoffeeAndVariant();
        $user = $this->makeUser('a@example.com');
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $coffee->id]);

        $this->artisan('alerts:restock')->assertExitCode(0);

        Mail::assertSent(RestockDigest::class, function ($m) use ($user) {
            return $m->hasTo($user->email);
        });
    }

    public function test_skips_unverified_users(): void
    {
        Mail::fake();
        ['coffee' => $coffee] = $this->setupRoasterCoffeeAndVariant();
        $user = $this->makeUser('unverified@example.com', verified: false);
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $coffee->id]);

        $this->artisan('alerts:restock')->assertExitCode(0);

        Mail::assertNotSent(RestockDigest::class);
    }

    public function test_skips_users_whose_wishlist_does_not_intersect_recent_restocks(): void
    {
        Mail::fake();
        $this->setupRoasterCoffeeAndVariant(); // coffee 1, restocked
        $r = Roaster::create(['name' => 'R2', 'slug' => 'r2', 'city' => 'V']);
        $other = $r->coffees()->create(['name' => 'Other', 'origin' => 'Brazil']);

        $user = $this->makeUser('a@example.com');
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $other->id]);

        $this->artisan('alerts:restock')->assertExitCode(0);
        Mail::assertNotSent(RestockDigest::class);
    }

    public function test_dry_run_writes_no_email(): void
    {
        Mail::fake();
        ['coffee' => $coffee] = $this->setupRoasterCoffeeAndVariant();
        $user = $this->makeUser('a@example.com');
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $coffee->id]);

        $this->artisan('alerts:restock', ['--dry-run' => true])->assertExitCode(0);
        Mail::assertNotSent(RestockDigest::class);
    }

    public function test_skips_soft_removed_coffees(): void
    {
        Mail::fake();
        ['coffee' => $coffee] = $this->setupRoasterCoffeeAndVariant();
        $coffee->update(['removed_at' => now()]);
        $user = $this->makeUser('a@example.com');
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $coffee->id]);

        $this->artisan('alerts:restock')->assertExitCode(0);
        Mail::assertNotSent(RestockDigest::class);
    }

    public function test_no_recent_restocks_short_circuits(): void
    {
        Mail::fake();
        ['variant' => $v] = $this->setupRoasterCoffeeAndVariant();
        $v->update(['in_stock_changed_at' => now()->subDays(7)]);
        $user = $this->makeUser('a@example.com');
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $v->coffee_id]);

        $this->artisan('alerts:restock')->assertExitCode(0);
        Mail::assertNotSent(RestockDigest::class);
    }
}
