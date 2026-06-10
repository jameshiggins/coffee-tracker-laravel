# Developer Guide

## Stack

- **Backend**: Laravel 10 (PHP 8.2+), SQLite in **both** dev and prod (prod
  DB on a Fly persistent volume at `/data/database.sqlite` — not Postgres)
- **Frontend**: React 18 + Vite + Tailwind CSS (CDN, no build step)
- **Auth**: Sanctum bearer tokens (pure-token, `'guard' => []`)
- **Mail**: Resend transactional API
- **Scraping**: strategy pattern over Shopify products.json, WooCommerce
  Store API, Squarespace `?format=json`, and a generic HTML JSON-LD fallback
- **Geocoding**: OpenStreetMap Nominatim (free); optional Google Places
  address fallback, off unless `GOOGLE_PLACES_API_KEY` is set
- **Hosting**: Fly.io (API), Vercel (React + docs), Cloudflare DNS
- **Observability**: `GET /up` health probe, daily + weekly ops emails, and
  Sentry for uncaught exceptions (inert without a DSN) — see `deploy.md`

## Layout

```
coffee-tracker-laravel/
  app/
    Console/Commands/        # ImportAllRoasters, SendRestockAlerts,
                             # SendDailyOpsSummary, SendWeeklyDigest,
                             # ScrapeRoasterAddresses, OpsHeartbeat, + data-quality CLIs
    Console/Kernel.php       # the schedule (6 jobs)
    Exceptions/Handler.php   # reports uncaught exceptions to Sentry (no-op without DSN)
    Http/Controllers/
      Api/                   # JSON endpoints (auth + tastings + wishlist + …)
      Admin/                 # admin UI (Blade), behind HTTP Basic
      HealthController.php    # GET /up health probe
    Listeners/               # RecordMailSent (bumps the mail.sent heartbeat)
    Mail/                    # RestockDigest, DailyOpsSummary, WeeklyDataQualityDigest
    Models/                  # Roaster, Coffee, CoffeeVariant, Tasting, User, Wishlist,
                             # ScraperRejectionLog, SystemHeartbeat
    Services/
      Scraping/              # RoasterScraper interface + Shopify/Woo/Squarespace/Generic
      Scraping/Address/      # address cascade (JSON-LD, contact page, Nominatim, Google Places)
      RoasterImporter.php    # orchestrates a single import run
      DailyOpsReport.php     # builds the daily ops email payload
      DataQualityReport.php  # weekly digest data
      DuplicateRoasterDetector.php, AddressQualityChecker.php
      OriginGazetteer.php    # ~70 region/estate aliases → country
      NominatimGeocoder.php  # OSM geocoding
  database/
    migrations/              # one per feature
    seeders/                 # BC roasters + coffees (dev seed only)
  routes/
    api.php                  # JSON API
    web.php                  # public + /up + admin
  tests/
    Feature/ + Unit/         # 417 tests, 1062 assertions

coffee-tracker-react/
  src/
    pages/                   # one per route
    components/              # reusable UI atoms
    hooks/                   # useAuth, useWishlist, useUserLocation
    utils/                   # distance, similarity, rating, units, countries…
    api.js                   # thin fetch wrapper
    auth.jsx                 # AuthProvider + authFetch
  src/__tests__/             # Vitest unit + component tests
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
npm run dev   # http://localhost:5174
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
    // Exact keys RoasterImporter::syncVariants() reads. NOTE: it's `grams`
    // (not bag_weight_grams), `available` (not in_stock), and `source_id`
    // (not source_variant_id). Currency is not per-variant — it defaults to
    // CAD on the column. `source_size_label` is the friendly bag label.
    ['grams' => 340, 'price' => 24.50, 'available' => true,
     'source_id' => '…', 'purchase_link' => '…', 'source_size_label' => '340 g'],
  ],
]
```

Register the scraper by adding it to the default list in
`App\Services\Scraping\ScraperRegistry::__construct()` (there is no `all()`
method). Order matters: most specific / cheapest-to-detect first, the
generic `GenericHtmlScraper` catch-all last.

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
- `RestockAlertsTest` for the restock digest
- `DailyOpsSummaryTest` for the daily ops email
- `SentryErrorTrackingTest` for the production error-tracking wiring

`Http::fake()` and `Http::fakeSequence()` mock outbound HTTP. Sanctum tests
use `Sanctum::actingAs()`.

## Contributing

1. Open an issue describing the change
2. Write tests first when the requirement is clear
3. Match the existing code style (4-space PHP, 2-space JS)
4. Run the full test suite before submitting

## Watching for regressions

- Always run both test suites before deploying. The PHP suite runs in well
  under a minute; Vitest is near-instant.
- After deploy, hit the smoke-test URLs in `docs/deploy.md` and confirm
  `GET /up` returns 200 before considering the release done.
