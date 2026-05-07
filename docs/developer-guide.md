# Developer Guide

## Stack

- **Backend**: Laravel 10 (PHP 8.2+), SQLite in dev, Postgres in prod
- **Frontend**: React 18 + Vite + Tailwind CSS (CDN, no build step)
- **Auth**: Sanctum bearer tokens (pure-token, `'guard' => []`)
- **Mail**: Resend transactional API
- **Scraping**: strategy pattern over Shopify products.json, WooCommerce
  Store API, and a generic HTML JSON-LD fallback
- **Geocoding**: OpenStreetMap Nominatim
- **Hosting**: Fly.io (API), Vercel (React + docs), Cloudflare DNS

## Layout

```
coffee-tracker-laravel/
  app/
    Console/Commands/        # ImportAllRoasters, SendRestockAlerts
    Http/Controllers/
      Api/                   # JSON endpoints (auth + tastings + wishlist + …)
      Admin/                 # admin UI (Blade)
    Mail/                    # RestockDigest mailable
    Models/                  # Roaster, Coffee, CoffeeVariant, Tasting, User, Wishlist
    Services/
      Scraping/              # RoasterScraper interface + implementations
      RoasterImporter.php    # orchestrates a single import run
      OriginGazetteer.php    # ~70 region/estate aliases → country
      NominatimGeocoder.php  # OSM geocoding
  database/
    migrations/              # one per feature
    seeders/                 # 8 BC roasters, 22 coffees (dev seed only)
  routes/
    api.php                  # JSON API
    web.php                  # public + admin
  tests/
    Feature/                 # 14 test files, 174 assertions
    Unit/

coffee-tracker-react/
  src/
    pages/                   # one per route
    components/              # reusable UI atoms
    hooks/                   # useAuth, useWishlist, useUserLocation
    utils/                   # distance, similarity, rating, units, countries…
    api.js                   # thin fetch wrapper
    auth.jsx                 # AuthProvider + authFetch
  src/__tests__/             # Vitest unit tests, 54 assertions
```

## Running locally

### Backend
```
cd coffee-tracker-laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve   # http://localhost:8000
```

### Frontend
```
cd coffee-tracker-react
npm install
npm run dev   # http://localhost:5173
```

### Tests
```
# Backend
php artisan test

# Frontend
npx vitest run
```

## Adding a scraper

Implement `App\Services\Scraping\RoasterScraper`:

```php
interface RoasterScraper
{
    public function canHandle(string $url): bool;   // probe whether this site type matches
    public function fetch(string $url): array;      // return list of normalized coffee arrays
    public function platformKey(): string;          // unique identifier ("shopify", "woocommerce", …)
}
```

Each `fetch()` returns coffees in this shape:

```php
[
  'name' => 'Yirgacheffe Konga',
  'source_id' => 'gid://shopify/Product/12345',
  'description' => '…',
  'image_url' => '…',
  'product_url' => '…',
  'is_blend' => false,
  'origin' => 'Ethiopia',
  'process' => 'Washed',
  'roast_level' => 'light',
  'varietal' => 'Heirloom',
  'tasting_notes' => 'jasmine, bergamot',
  'variants' => [
    ['bag_weight_grams' => 340, 'price' => 24.50, 'currency' => 'CAD',
     'in_stock' => true, 'purchase_link' => '…', 'source_variant_id' => '…'],
  ],
]
```

Register the scraper in `App\Services\Scraping\ScraperRegistry::all()`.

## Stable-ID upsert + soft-remove

`RoasterImporter::syncCoffees()` matches incoming products against existing
DB rows by `(roaster_id, source_id)`. Existing rows are updated; missing rows
are soft-removed (`removed_at`) so user tastings keep their target intact.
Variants are upserted by `(coffee_id, bag_weight_grams)` and stamp
`in_stock_changed_at` when stock state flips.

## Testing approach

We use TDD where the requirement is clear. Look at:
- `RoasterImportTest` for the import pipeline
- `ImportSoftRemoveTest` for soft-remove of disappeared coffees
- `ModerationTest` for the Q17 moderation flow
- `EmailVerificationTest` + `PasswordResetTest` for the auth pipeline
- `RestockAlertsTest` for the daily digest

`Http::fake()` and `Http::fakeSequence()` mock outbound HTTP. Sanctum tests
use `Sanctum::actingAs()`.

## Contributing

1. Open an issue describing the change
2. Write tests first when the requirement is clear
3. Match the existing code style (4-space PHP, 2-space JS)
4. Run the full test suite before submitting

## Watching for regressions

- Always run both test suites before deploying — they take under 10 seconds
  combined.
- After deploy, hit the smoke-test URLs in `docs/deploy.md` (TBD) before
  considering the release done.
