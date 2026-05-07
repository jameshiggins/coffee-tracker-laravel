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
`GenericHtmlScraper` are implementations. `ScraperRegistry` probes them
in order on first import and caches the platform on the roaster row so
subsequent runs skip the probe.

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

### 5. Boring dependencies

- **No analytics, no telemetry, no error reporting SaaS** — server logs
  are enough for a directory of this size
- **No JS bundler ceremony for Tailwind** — using the Tailwind CDN keeps
  the React app's `npm install` time under 30 seconds
- **OpenStreetMap Nominatim**, not Google Maps — free, no API key, good
  enough for street-address-to-lat/lng on Canadian addresses
- **Resend for email** — chosen because it's the cheapest credible option
  with good deliverability and a sane API

## Data model

```
roasters          1───∞      coffees       1───∞     coffee_variants
                                │
                                │
users             1───∞      tastings (soft-deletes)
   │                            ↑
   │                            │ flagged_by_user_id
   │
   1───∞       wishlist (UNIQUE user_id, coffee_id)
```

A `coffee_variant` is a (coffee, bag_weight_grams) row with its own
price + stock state. One Yirgacheffe coffee might have variants for 250g,
340g, and 1kg.

## Scheduled jobs

```
$schedule->command('roasters:import-all')
  ->dailyAt('11:00')
  ->withoutOverlapping()
  ->onOneServer()
  ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL'));

$schedule->command('alerts:restock')
  ->dailyAt('14:00')
  ->withoutOverlapping()
  ->onOneServer();
```

`withoutOverlapping` prevents a slow import from triggering a second
instance the next day. `onOneServer` matters once we're on more than one
Fly.io machine.

## What we'd change if we did it again

- **Queue the imports** — a single `roasters:import-all` synchronously
  walking 50+ sites takes ~10 minutes. A queued per-roaster job would
  parallelize and recover from individual-roaster failures cleanly.
- **Move scraping to a separate service** — keeps the API process lean
  and lets us run scrapers on a schedule without worrying about web
  request concurrency.
- **A purpose-built moderation tool** — the current Blade admin page is
  fine for low volume but doesn't scale.
