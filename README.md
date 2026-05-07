# coffee-tracker-laravel

API + admin for a directory of Canadian specialty coffee roasters. Pairs with the user-facing React app at [`coffee-tracker-react`](https://github.com/jameshiggins/coffee-tracker-react).

## What it does

- **Scrapes** coffee inventory from each roaster's storefront — Shopify, WooCommerce, and Squarespace are first-class; generic JSON-LD is a fallback.
- **Extracts** structured fields from product copy: process, varietal, elevation, origin sub-region, roast level, tasting notes. Translates Quebec roasters' product copy from French to English along the way.
- **Snapshots** coffee details into tasting records so seasonal product rotation doesn't break old user tastings.
- **Serves** a JSON API at `/api/*` (consumed by the React app) and a Blade admin at `/admin/*` for moderation, geocoding, and per-roaster import inspection.

## Stack

- Laravel 10, PHP 8.1+
- SQLite for dev (no external DB service needed)
- Sanctum for API auth
- Resend for transactional email (verification, password reset, restock alerts)
- Nominatim for geocoding (no API key)

## Run locally

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve   # API + admin on :8000
```

The root URL `localhost:8000/` redirects to `FRONTEND_URL` (default `http://localhost:5174` — the React dev server). The user-facing app is React only; do not assume Blade views under `resources/views/roasters/` are what users see.

## Daily import

```bash
php artisan roasters:import-all
```

Refreshes coffee/variant data from each active roaster's storefront. Stable-ID upsert means user tastings don't break when products rotate; missing products are soft-removed (`removed_at` timestamp) rather than deleted.

## Tests

```bash
php artisan test       # 200 PHPUnit tests
```

CI runs on every push to `main`.

## Documentation

See [`docs/`](docs/) for:
- [Architecture](docs/architecture.md)
- [Developer guide](docs/developer-guide.md)
- [Admin guide](docs/admin-guide.md)
- [Deploy](docs/deploy.md)
- [User guide](docs/user-guide.md)

## License

MIT
