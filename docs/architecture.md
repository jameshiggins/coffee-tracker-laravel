# Architecture

High-level decisions and the reasoning behind them.

## What this app is (and isn't)

It's a **directory of Canadian micro-roasters** that scrapes their public
websites for current beans and prices. The core value is "what's actually
in stock right now" rolled up across many roasters, with bean-centric
filters (origin, process, roast).

It's **not** a personal coffee tracker, a marketplace, an e-commerce site,
or a roaster review platform. The bean detail pages happen to host community
ratings and notes, but those are secondary signal — the primary product is
the directory.

## The five big design choices

### 1. Stable-ID upsert + soft-remove

Every imported coffee carries a `source_id` from the roaster's site
(Shopify product GID, WooCommerce product ID, JSON-LD URL). When the
daily import runs, we match by `(roaster_id, source_id)` and:

- **Update** existing rows in place — preserves the row's primary key,
  which is what user tastings point at
- **Soft-remove** rows that disappear from the source — sets `removed_at`,
  keeps the row alive, marks it "no longer sold" in the UI

The alternative (drop and re-insert) would orphan every existing tasting.
This pattern is the spine of the whole import pipeline.

### 2. Strategy pattern for scrapers

`RoasterScraper` is an interface; `ShopifyScraper`, `WooCommerceScraper`,
`SquarespaceScraper`, and `GenericHtmlScraper` are implementations.
`ScraperRegistry` probes them in order (most specific first, generic
catch-all last) on first import and caches the platform on the roaster row
so subsequent runs skip the probe.

The generic JSON-LD scraper is the fallback for sites that aren't on a
known platform but expose `<script type="application/ld+json">` Product
schema (most well-built specialty sites do).

### 3. Pure-token Sanctum

`config/sanctum.php` has `'guard' => []` (not `['web']`). This means tokens
are validated against the database row only — no session fallback. Revoking
a token actually revokes it, and there's no CSRF surface.

Trade-off: no SPA cookie flow. Every authed request from React carries
`Authorization: Bearer <token>`. We're fine with that — the React app is
a SPA, not server-rendered.

### 4. Soft-delete for moderation

`Tasting` uses Laravel's `SoftDeletes`. Hidden tastings disappear from
every public surface via the global scope, but stay in the DB for:
- Audit trail
- Undo
- Post-mortem on harassment patterns

The user-facing "delete my tasting" action is also a soft-delete. Users
who genuinely need GDPR-style erasure email us and we hard-delete by hand.

### 5. Minimal dependencies

- **One observability SaaS, and only for errors** — production exceptions
  go to Sentry (`app/Exceptions/Handler.php`), which is inert until a DSN is
  set so dev and CI stay silent. No product analytics, no user telemetry —
  server logs plus the daily ops email cover the rest for a directory this
  size. (See `system-overview.md` Flow 4 for the full observability picture.)
- **Tailwind via the standard PostCSS build** — the React app compiles
  Tailwind with Vite/PostCSS (`tailwind.config.js` maps semantic tokens to
  CSS variables in `src/styles/tokens.css`). An earlier draft used the CDN;
  the build was adopted for purging, tokens, and dark-mode variants
- **OpenStreetMap Nominatim for geocoding** — free, no API key. The address
  cascade prefers scraped JSON-LD / contact-page addresses, then falls back
  to Nominatim search. A Google Places fallback exists but is **off by
  default** (stubbed unless `GOOGLE_PLACES_API_KEY` is set), so a stock
  deploy needs no paid maps key.
- **SMTP2GO for email** — plain SMTP through Laravel's built-in mailer,
  no SDK or API key in the app (see `deploy.md` → SMTP2GO setup). A dormant
  Resend driver remains wired in `config/mail.php` but is unused

## Data model

```
roasters          1───∞      coffees       1───∞     coffee_variants
   │                            │                          │
   │                            │            rejected imports → scraper_rejection_log
   │
users             1───∞      tastings (soft-deletes)
   │                            ↑
   │                            │ flagged_by_user_id
   │
   1───∞       wishlist (UNIQUE user_id, coffee_id)

system_heartbeats   (standalone key→timestamp store behind /up)
```

A `coffee_variant` is a (coffee, bag_weight_grams) row with its own
price + stock state. One Yirgacheffe coffee might have variants for 250g,
340g, and 1kg.

Roasters also carry geocoding columns (`street_address`, `latitude`,
`longitude`, `address_source`, `is_online_only`) and import bookkeeping
(`last_import_status`, `last_import_error`, `last_imported_at`).
`scraper_rejection_log` records variants refused by the import sanity gate;
`system_heartbeats` stores liveness ticks (`scheduler.tick`, `mail.sent`).
See `system-overview.md` for the full table-by-table tour.

## Scheduled jobs

Eight commands run on the in-container scheduler (`app/Console/Kernel.php`):

| When (UTC) | Command | Purpose |
|---|---|---|
| daily 11:00 | `roasters:import-all` | nightly catalogue refresh |
| daily 11:30 | `reports:daily-ops` | daily ops summary email (incl. itemized dropped beans) |
| daily 14:00 | `alerts:restock` | wishlist back-in-stock emails |
| Sat 10:00 | `roasters:retry-inactive` | weekend retry of deactivated roasters; reactivate the recovered |
| Mon 13:00 | `reports:weekly-digest` | weekly data-quality audit email (incl. itemized dropped beans) |
| Mon 13:30 | `coffees:purge-non-coffee --apply` | weekly non-coffee catalogue sweep |
| 1st 12:00 | `roasters:scrape-addresses` | monthly address / geocode sweep |
| every 5 min | `scheduler-heartbeat` | bumps `scheduler.tick` for `/up` |

`withoutOverlapping` prevents a slow import from triggering a second
instance the next day; `onOneServer` matters once we're on more than one
Fly.io machine; import/report failures email `CRON_FAILURE_EMAIL`. The
scheduler itself is `php artisan schedule:work`, launched in a restart loop
by `docker/entrypoint.sh` and kept always-on by `fly.toml`
(`auto_stop_machines = 'off'`). Its 5-minute heartbeat is exactly what `/up`
reads to notice a silently dead scheduler — see `system-overview.md` Flow 4.

## What we'd change if we did it again

- **Queue the imports** — a single `roasters:import-all` synchronously
  walking 50+ sites takes ~10 minutes. A queued per-roaster job would
  parallelize and recover from individual-roaster failures cleanly.
- **Move scraping to a separate service** — keeps the API process lean
  and lets us run scrapers on a schedule without worrying about web
  request concurrency.
- **A purpose-built moderation tool** — the current Blade admin page is
  fine for low volume but doesn't scale.
