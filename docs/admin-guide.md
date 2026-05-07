# Admin Guide

You're the operator. The admin UI lives at `/admin` and has no auth — restrict
it at the network layer (Cloudflare Access, IP allow-list) before going live.

## Daily flow

The scheduled job `roasters:import-all` runs every day at 11:00 server time
(see `app/Console/Kernel.php`). It:

1. Iterates every active roaster with a website
2. Probes the cached scraper platform (Shopify / WooCommerce / Generic HTML)
3. Fetches the catalog and upserts coffees + variants by stable `source_id`
4. Soft-removes coffees that have disappeared from the source (preserves
   user tasting history; UI marks them "no longer sold")
5. Stamps `last_imported_at`, `last_import_status` (`success` / `empty` /
   `error` / `unsupported`), and `last_import_error`
6. Stamps `in_stock_changed_at` on variants that flipped from out → in

Failures email `CRON_FAILURE_EMAIL` (or `MAIL_FROM_ADDRESS` if unset).

The restock alert job `alerts:restock` runs at 14:00 — it digests every
wishlisted coffee that came back in stock in the last 24h and emails the
wishlist owner.

## Adding a roaster

### From a URL (fastest)
1. Go to `/admin/import`
2. Paste the roaster's homepage URL
3. Optionally fill in name / city / region (we'll auto-detect what we can)
4. Click "Import"

The importer probes the URL against each scraper in order. If we can find a
catalog endpoint we'll create the roaster and its first batch of coffees.

### Manually
1. `/admin/roasters` → "Add Roaster"
2. Fill in name, slug, city, region, country code, website
3. After saving, click "Refresh" to pull the catalog
4. If the roaster has a street address, click "Geocode" to populate
   latitude/longitude (uses OpenStreetMap Nominatim — free, ~1 req/sec rate
   limit, runs synchronously)

## Per-roaster controls

On `/admin/roasters` each row shows:
- **Last import status** — color-coded background (green=success, yellow=empty,
  red=error, gray=unsupported)
- **Last imported at** — relative time
- **Refresh** — re-runs the importer for that roaster
- **Edit** — change name/city/region/website etc.

## Moderation queue

`/admin/moderation` (or "🚩 Moderation" button on the roaster index) shows
flagged tastings, sorted newest first.

For each flagged tasting:
- **Dismiss flag** — the report was bogus, clear `flagged_at` and let the
  tasting stay public
- **Hide** — soft-delete the tasting. It disappears from every public
  surface (profile, coffee page, permalink, aggregate rating) but stays
  in the DB for audit / undo

The "Hidden" tab shows soft-deleted tastings; click "Restore" to undo.

Soft-deleted tastings older than 90 days should be hard-deleted by a future
purge job (not yet implemented).

## Catalog edits

You can manually edit any coffee or variant. Be aware that the next import
will overwrite your edits — this is intentional, the source roaster is the
source of truth. If you need a permanent edit, deactivate the auto-import for
that roaster (or open an issue).

## Operational gotchas

- **Windows + cURL**: PHP on Windows ships without a CA bundle. The Mozilla
  bundle is at `storage/cacert.pem` and Guzzle is configured in
  `App\Services\Scraping\Shared::clientOptions()`.
- **Bag weight from Shopify variants**: we parse the variant title string
  (e.g. "12oz" → 340g) instead of trusting the Shopify `grams` field, which
  is unreliable.
- **Espresso products**: the scraper marks Espresso products as blends
  unless they also have a "Single Origin" tag.
- **Duplicate variants**: if the same gram weight appears twice (e.g. "12oz"
  and "340g"), the in-stock one wins.

## Documentation

This file plus the others in `docs/` are the source for the public docs site.
Plain Markdown — Docusaurus, MkDocs, or anything else can render them.
