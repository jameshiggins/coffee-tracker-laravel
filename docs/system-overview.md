# System Overview — How the Whole Thing Works

A tour of the moving parts and how data flows between them. For the plain-English
version see `backend-eli5.md`; for *why* each choice was made see
`architecture.md`.

## Two apps, one product

Roastmap is a directory of Canadian specialty-coffee roasters. It's two
codebases that deploy independently:

| | Repo | Runs on | Public URL |
|---|---|---|---|
| **API + admin** | `coffee-tracker-laravel` (this repo) | Fly.io | `api.roastmap.ca` |
| **Web app** | `coffee-tracker-react` | Vercel | `roastmap.ca` |

The React app is what visitors see. It holds no data of its own — it asks the
Laravel API for everything over HTTP (JSON). The Laravel app owns the database,
the nightly scraping, all email, and the operator's admin pages.

DNS is Cloudflare; transactional email is Resend. Deploy details live in
`deploy.md`.

## The data model

```
roasters ─1──∞─ coffees ─1──∞─ coffee_variants
   │                │
   │                └──────────── scraper_rejection_log   (variants we refused to import)
   │
users ─1──∞─ tastings (soft-deletes)   tastings.flagged_by_user_id → users
   │
   └─1──∞─ wishlist (unique user_id + coffee_id)

system_heartbeats   (standalone key→timestamp store the health probe reads)
```

- A **coffee_variant** is a (coffee, bag-weight) pair with its own price and
  in-stock flag — e.g. a Yirgacheffe in 250g / 1kg.
- **Roasters** also carry location/quality columns: `street_address`,
  `latitude`/`longitude`, `address_source` (`jsonld` / `website` / `osm` /
  `google` / `manual`), `is_online_only`, and import bookkeeping
  (`last_import_status`, `last_import_error`, `last_imported_at`). Address
  resolution is a cascade (scraped JSON-LD → contact page → Nominatim search →
  an optional Google Places fallback that's off unless `GOOGLE_PLACES_API_KEY`
  is set).
- In **dev** the database is `database/database.sqlite` (seeded with a handful of
  BC roasters). In **prod** it's the same SQLite engine on a Fly persistent
  volume at `/data/database.sqlite`.

## Flow 1 — a visitor loads the site

```
Browser → roastmap.ca (React on Vercel)
        → GET api.roastmap.ca/api/... (JSON)
        → Laravel route → Controller → Eloquent query → SQLite
        ← JSON response ← React renders
```

Anything personal (your wishlist, posting a tasting) carries an
`Authorization: Bearer <token>` header. Tokens are pure Sanctum tokens
(`config/sanctum.php` → `'guard' => []`): validated against the DB row only, no
session/cookie, no CSRF surface. Public reads (the directory, a coffee page) need
no token.

The admin pages (`/admin/*`, server-rendered Blade) are a separate world, gated
by a session login page at `/admin/login` (`admin.auth` middleware; env-pair
credential, failure-throttled) — independent of the React app's token auth.

## Flow 2 — the nightly import (the heart of the system)

```
schedule (11:00 UTC)
  → php artisan roasters:import-all
    → for each active roaster with a website:
        RoasterImporter
          → ScraperRegistry picks a scraper (cached per roaster, most
            specific first): Shopify / WooCommerce / Squarespace /
            GenericHtml (JSON-LD)
          → fetch catalogue
          → syncCoffees():   upsert by (roaster_id, source_id),
                             soft-remove (removed_at) coffees that vanished
          → syncVariants():  upsert by (coffee_id, bag_weight_grams),
                             stamp in_stock_changed_at on stock flips,
                             reject impossible prices → scraper_rejection_log
          → stamp last_import_status / last_import_error / last_imported_at
```

**Why upsert-not-replace:** user tastings point at a coffee's primary key. Drop
and re-insert would orphan every tasting. So existing rows are updated in place,
and disappeared rows are *soft-removed* (kept, marked "no longer sold") rather
than deleted. This is the single most important invariant in the codebase.

**The sanity gate:** `syncVariants()` drops variants with a non-positive price or
an out-of-band price-per-gram, recording each in `scraper_rejection_log`. That
table is a snapshot of the *latest* import per roaster, so it answers "what's
currently being dropped" — a tripwire for a roaster site whose layout drifted.

## Flow 3 — scheduled work (app/Console/Kernel.php)

| When (UTC) | Command | What it does |
|---|---|---|
| daily 11:00 | `roasters:import-all` | the nightly catalogue refresh above |
| daily 11:30 | `reports:daily-ops` | emails the daily ops summary (see below) |
| daily 14:00 | `alerts:restock` | emails users whose wishlisted beans returned |
| Mon 13:00 | `reports:weekly-digest` | deeper weekly data-quality audit email |
| Mon 13:30 | `coffees:purge-non-coffee --apply` | weekly backstop soft-removing gear rows the filter now rejects |
| 1st 12:00 | `roasters:scrape-addresses` | monthly address / geocode sweep |
| every 5 min | `scheduler-heartbeat` | bumps `scheduler.tick` so `/up` can tell the scheduler is alive |

The scheduler runs *inside* the Fly container: `docker/entrypoint.sh` launches
`php artisan schedule:work` in a restart loop, and `fly.toml` keeps one machine
always on (`auto_stop_machines = 'off'`). If `schedule:work` died, none of the
above would fire — which is exactly why the heartbeat + `/up` exist.

## Flow 4 — observability (knowing when nothing's working)

Three independent layers, each catching a different failure class:

1. **Infrastructure — `GET /up`** (`HealthController`). Returns **200 healthy**
   when the database is reachable *and* `scheduler.tick` is fresh (< 15 min old);
   **503 degraded** otherwise. An external uptime monitor (UptimeRobot / Better
   Stack) polls it and pages you on a non-200. This is the only signal that
   catches a *silently dead scheduler* — the worst failure, because the web app
   still looks fine while the catalogue quietly goes stale. The body also reports
   informational-only checks (`mail.last_sent`, `imports.errors`) that never flip
   the status.
2. **Data/work — the daily ops email** (`reports:daily-ops`). Roasters added in
   the last 24h, active roasters failing import (with the error), outstanding
   variant rejections, and mail-delivery confirmation. It sends every day, so its
   reliable arrival is itself a "mail + scheduler are alive" signal.
3. **Code — Sentry** (`app/Exceptions/Handler.php`). Uncaught exceptions are
   reported to Sentry. Inert until `SENTRY_LARAVEL_DSN` is set (a Fly secret), so
   it's silent in dev. `/up` is excluded from tracing so monitor polling doesn't
   flood it.

Liveness signals are stored in `system_heartbeats` (a key→timestamp table):
`scheduler.tick` (bumped every 5 min by the scheduler, seeded at boot by the
entrypoint) and `mail.sent` (bumped by the `RecordMailSent` listener whenever the
mail transport accepts a message).

## Where things live (quick map)

```
app/
  Console/Commands/   ImportAllRoasters, SendRestockAlerts, SendWeeklyDigest,
                      SendDailyOpsSummary, ScrapeAddresses, OpsHeartbeat, ...
  Console/Kernel.php  the schedule above
  Http/Controllers/   Api/ (JSON), Admin/ (Blade), HealthController (/up)
  Services/           RoasterImporter, Scraping/ (scrapers + registry),
                      DataQualityReport, DailyOpsReport, DuplicateRoasterDetector,
                      AddressQualityChecker, NominatimGeocoder, OriginGazetteer
  Mail/               RestockDigest, WeeklyDataQualityDigest, DailyOpsSummary
  Listeners/          RecordMailSent (mail.sent heartbeat)
  Models/             Roaster, Coffee, CoffeeVariant, Tasting, User, Wishlist,
                      ScraperRejectionLog, SystemHeartbeat
docker/entrypoint.sh  prod boot: migrate, cache, seed heartbeat, run scheduler
routes/web.php        public redirects + /up + admin; api.php  the JSON API
```

See `developer-guide.md` to run it locally and `deploy.md` to ship it.
