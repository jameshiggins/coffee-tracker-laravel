# Admin Guide

You're the operator. This is the reference for the Blade admin console at
`https://api.roastmap.ca/admin`, the scheduled jobs that keep the catalogue
fresh, and the artisan commands you can run by hand. Visitor-facing behaviour
(the React app at roastmap.ca) is in the [user guide](user-guide.md).

## Contents

1. [Signing in](#signing-in)
2. [The admin screens](#the-admin-screens)
   - [Roasters (home)](#roasters-home)
   - [Needs Attention](#needs-attention)
   - [Import from URL](#import-from-url)
   - [Roaster form](#roaster-form)
   - [Coffee & bag-size editor](#coffee--bag-size-editor)
   - [Moderation](#moderation)
   - [Logs](#logs)
3. [What runs on its own](#what-runs-on-its-own)
4. [Monitoring — how you know it's working](#monitoring--how-you-know-its-working)
5. [Common tasks](#common-tasks)
6. [Command reference](#command-reference)
7. [How the import works](#how-the-import-works)
8. [Data safety rules](#data-safety-rules)
9. [Gotchas](#gotchas)

---

## Signing in

`/admin/login`. One credential pair, `ADMIN_USER` / `ADMIN_PASS`, set as Fly
secrets (`config/admin.php`). There are no user accounts — everyone who signs
in is "the operator", and every action is stamped into the [Logs](#logs) with
the client IP.

Things worth knowing:

- **Fails closed.** If either secret is unset, the entire `/admin/*` tree —
  login page included — returns **503**. A locked-out operator beats a
  world-writable admin. If you see a 503 on `/admin/login`, check
  `fly secrets list`.
- **Brute-force throttle.** Ten failed attempts from one IP → a 5-minute
  cooldown. Successful sign-ins don't count toward it, so normal use can't
  trip it. Lockouts and failures are logged as `admin.auth.lockout` /
  `admin.auth.login_failed`.
- **Sign out** is in the header. It invalidates the session server-side.
- Visitor auth for the React app is a separate system (Sanctum bearer tokens)
  and never grants admin access.

## The admin screens

The header links to Roasters, Needs Attention, Logs and the live site. The
roaster list also links to Moderation, Import from URL and Add Roaster.

### Roasters (home)

`/admin/roasters` — every roaster, active or not, 50 per page.

**Sort order is deliberate:** problem roasters float to the top —
`error` first, then never-imported, then `empty`, then `success` — and
alphabetical within each. The row background is tinted by status
(green = imported, yellow = empty, red = error); errors show the stored
`last_import_error` text so you can usually diagnose from the list.

Per row: coffee count, last import time, status, and:

| Button | What it does |
|---|---|
| **Refresh** | Queues a re-import of that roaster's catalogue (runs on the queue worker; refresh the page in a minute). |
| **Edit** | The [roaster form](#roaster-form). |
| **Delete** | *Deactivates* — sets `is_active=false`, hiding the roaster and all its beans from the site. Nothing is deleted; re-tick **Active** in the form to bring it back. |

### Needs Attention

`/admin/attention` — the triage view. Active roasters whose last import
wasn't a clean success, bucketed by cause so each bucket has an obvious next
step:

| Bucket | Meaning | Do |
|---|---|---|
| **Dead domains** | DNS won't resolve — closed, rebranded, or the domain lapsed. | Auto-deactivated after 7 consecutive days by `roasters:auto-deactivate-dead`, or click **Deactivate all** now. If they rebranded, fix the website URL in Edit instead. |
| **Blocked (401 / 403)** | Site is up but its WAF refused the scraper. | **Retry** — usually transient. If it persists, that site needs a scraper tweak. |
| **Other import errors** | Anything else threw. | **Retry**, then read the exception in [Logs](#logs). |
| **Empty catalog** | Site responded, zero coffees found. | **Retry**. If it stays empty, the storefront is on a platform the scrapers don't read, or the shop genuinely has nothing in stock. Check whether the real store lives on a different host (e.g. `shop.example.com`) and update the website URL. |
| **Never imported** | Added by hand and never scraped. | **Retry** to pull the catalogue. |

Roasters that are healthy don't appear. Deactivated roasters don't either —
`roasters:retry-inactive` gives those a weekly second chance automatically.

### Import from URL

`/admin/import` — the fastest way to add a roaster. Paste the storefront
homepage; name, city and region are optional (name is inferred from the
domain if blank). The import is queued, not run inline, because a full
scrape can take minutes.

The importer probes the URL against each scraper in order — Shopify,
WooCommerce, Squarespace, then a generic JSON-LD reader — and remembers which
one matched so the nightly run doesn't re-probe. The roaster row plus its
first batch of coffees appear once the job finishes. If nothing appears,
look for it under **Needs Attention → Empty catalog**.

Tip: paste the *shop* host, not the marketing site. Several roasters run a
brochure site on the bare domain and the actual Shopify store on
`shop.` — the brochure site imports as empty.

### Roaster form

`/admin/roasters/create` and `/admin/roasters/{slug}/edit`. The slug is
derived from the name on save.

- **Name, Province/Territory, City** — required. Use the full province name
  ("British Columbia", not "BC") to match the existing data.
- **Website** — the import source. Change this to repoint a moved storefront.
- **Instagram**, **Logo URL** — the logo field overrides the favicon the
  scraper picked up (useful when a site's favicon is a generic platform icon).
- **Address & location** — street address and postal code you enter here are
  what **Geocode** turns into a map pin. Coordinates themselves are read-only
  on the form.
- **Description**, **Shipping** (flag, flat cost, free-over threshold, notes),
  **Subscription** (flag, notes) — displayed on the roaster card.
- **Active** — untick to hide the roaster from the directory without losing
  anything.

Two buttons live on the edit page rather than the list:

- **Geocode** — sends the street address to OpenStreetMap Nominatim and
  stores the returned coordinates with `address_source=manual`, which tells
  the monthly address sweep to leave this pin alone forever. If it fails, the
  message tells you *why*: `no match for that address` means fix the address
  text; `Nominatim returned HTTP 403` or `429` means OSM refused or
  rate-limited the request — wait and retry, and make sure
  `NOMINATIM_CONTACT_EMAIL` (or `OPS_EMAIL`) is set on Fly so the request
  identifies you per OSM's usage policy.
- **Refresh** — same as on the list.

### Coffee & bag-size editor

From a roaster's edit page you can add or edit individual offerings
(`/admin/roasters/{slug}/coffees/…`). Coffee fields: name, origin (required),
process, roast level, varietal, tasting notes. Below the coffee, **Bag Sizes
& Pricing** lists its variants — each one is a weight in grams, a price, an
in-stock flag and an optional purchase link. Add, edit or remove them
individually.

**Removing a coffee** soft-removes it (`removed_at`): it drops out of the
directory but stays in the database so any user tastings or wishlist entries
pointing at it survive. **Restore** brings it back. Removing a *variant* is a
real delete — variants carry no user data.

**Your edits are not permanent for imported roasters.** The next nightly
import treats the roaster's website as the source of truth and overwrites
coffee and variant fields. Manual edits stick only for roasters with no
website (nothing to import from), or if you deactivate the roaster's import
by clearing its website. See [Data safety rules](#data-safety-rules).

### Moderation

`/admin/moderation`. Readers can flag any public tasting; nothing is hidden
automatically — every decision is yours.

- **Flagged** tab (default): newest first, with the tasting text, the author
  and the coffee. **Dismiss flag** clears it and leaves the tasting public.
  **Hide** soft-deletes it: gone from every public surface (profile, coffee
  page, permalink, aggregate rating) but kept for audit and undo.
- **Hidden** tab: everything soft-deleted, with **Restore**.

Each screen shows the count for the other tab so you can see the backlog.
Hidden tastings are never hard-deleted by the system.

### Logs

`/admin/logs` — the operational log, newest first, 100 per page. Every admin
action (`admin.*`), every import decision, every mail send and every auth
event lands here, mirrored to Fly's stderr stream so nothing is lost if the
table is pruned.

Filters: **level** (debug / info / warning / error), **event** prefix (e.g.
`admin.roaster.` or `import.`), and free-text search of the message. Each
row expands to show its JSON context (roaster id, changed fields, IP, …).

**Verbose logging toggle** (button on this page): adds debug-level detail —
per-product import decisions, why a variant was rejected, per-recipient mail
sends — with no deploy or restart. Turn it on to diagnose a specific roaster's
import, then off again; verbose nights add thousands of rows. The toggle
itself is logged. Web requests and scheduled jobs pick up the change
immediately; the long-running queue worker picks it up on its next hourly
restart.

Rows older than 14 days are pruned nightly by `logs:prune`.

## What runs on its own

All times UTC. `schedule:work` runs inside the Fly machine; the queue worker
runs imports off the web request path. See `app/Console/Kernel.php`.

| When | Job | What it does |
|---|---|---|
| Daily 11:00 | `roasters:import-all` | Re-scrapes every active roaster with a website. The main catalogue refresh. |
| Daily 11:30 | `reports:daily-ops` | Emails the ops summary (see Monitoring). |
| Daily 11:50 | `roasters:auto-deactivate-dead` | Deactivates roasters whose domain has failed DNS for 7+ days. |
| Daily 12:10 | `logs:prune` | Drops admin-log rows older than 14 days. |
| Daily 14:00 | `alerts:restock` | Emails users whose wishlisted beans came back in stock in the last 24 h. |
| Mon 13:00 | `reports:weekly-digest` | Deeper data-quality audit email. |
| Mon 13:30 | `coffees:purge-non-coffee --apply` | Soft-removes catalogue rows the coffee/gear filter now rejects (mugs, gift cards, tea that slipped in earlier). |
| Sat 10:00 | `roasters:retry-inactive` | Re-tries every *deactivated* roaster and reactivates any whose site is back. |
| 1st of month 12:00 | `roasters:scrape-addresses` | Resolves street addresses + pins for roasters that don't have one yet. |
| Every 5 min | scheduler heartbeat | Bumps the liveness signal `/up` reads. |

If a scheduled job throws, its console output is emailed to
`CRON_FAILURE_EMAIL` (falling back to `OPS_EMAIL`, then `MAIL_FROM_ADDRESS`).

## Monitoring — how you know it's working

Four signals, each catching a different failure. Full setup in
[deploy.md](deploy.md).

1. **`GET https://api.roastmap.ca/up`** — 200 when the database is reachable
   *and* the scheduler heartbeat is fresh; 503 otherwise. Point an external
   uptime monitor at it. This is the only check that catches a silently dead
   scheduler — the worst failure, because the site looks fine while the
   catalogue quietly goes stale.
2. **Daily ops email** (11:30 UTC) — roasters added in the last 24 h, active
   roasters failing import with the error text, variant rejections, and a
   mail-delivery confirmation. It sends *every day*; if it stops arriving,
   that is itself the alarm. Preview: `php artisan reports:daily-ops
   --dry-run`; quieter version: `--only-when-notable`.
3. **Weekly digest** (Mon 13:00 UTC) — import health over the week, dropped
   variants, likely duplicate roasters, address gaps.
4. **Sentry** — uncaught exceptions, once `SENTRY_LARAVEL_DSN` is set.

Both emails go to `OPS_EMAIL` (falling back to `MAIL_FROM_ADDRESS`); override
for a one-off with `--email=you@example.com`.

## Common tasks

Run artisan commands on production with
`fly ssh console -a coffee-tracker-laravel -C "php artisan <command>"`.

**Add a roaster** — `/admin/import`, paste the shop URL. Done. If it lands in
Needs Attention as empty, check for a `shop.` host and edit the website.

**Add a roaster that doesn't sell online** — Add Roaster form, leave website
blank, fill in the address, save, then **Geocode**. Its coffees have to be
entered by hand via the coffee editor, and they'll stay as you entered them.

**Add a roaster permanently, in code** — for a roaster that should exist on
every fresh database (and survive a DB rebuild), add it to `REQUIRED_ROASTERS`
in `app/Console/Commands/ApplyRoasterCorrections.php`, optionally with a
verified street address + coordinates in `ADDRESS_FIXES`, then run
`roasters:apply-corrections` on prod. The command is idempotent and skips
anything that already exists by name or slug.

**Fix a roaster whose storefront moved** — Edit → change Website → Save →
Refresh. If it's a permanent, well-known move, also add it to `URL_FIXES` in
`ApplyRoasterCorrections` so a rebuilt database gets it right.

**Put a roaster on the map** — Edit → enter street address + postal code →
Save → **Geocode**. If Geocode says "no match", simplify the address
(drop suite numbers, use the street name OSM knows). The monthly address
sweep tries to do this automatically for unplaced roasters, but a hand-placed
pin always wins.

**A roaster's beans have no tasting notes / wrong roast level** — click
**Refresh**; the extractors improve over time and a re-import re-runs them.
If the storefront simply doesn't publish that information, edit the coffee by
hand — but remember the next import overwrites it.

**A reader reported a tasting** — `/admin/moderation`, decide, done.

**Something looks wrong and you don't know why** — `/admin/logs`, filter by
the roaster's name or the event prefix. If the entry isn't detailed enough,
turn on verbose logging, click Refresh on the roaster, and read the
per-product decisions.

**Hide a roaster without losing anything** — Delete on the list (or untick
Active in the form). Reverse it by ticking Active again.

## Command reference

Every command is safe to run repeatedly. Read-only ones are marked.

| Command | What it does |
|---|---|
| `roasters:import-all` | Re-import every active roaster with a website. `--only=<slug>` for one. |
| `roasters:apply-corrections [--dry-run]` | Idempotent data fixes: repoint known-stale website URLs, create the roasters in `REQUIRED_ROASTERS`, apply verified addresses in `ADDRESS_FIXES`. Run after deploying a change to that file. |
| `roasters:scrape-addresses [--force] [--limit=N] [--only=<slug>]` | Address-resolution cascade (site JSON-LD → contact page → Nominatim). Skips roasters already resolved unless `--force`. Never overwrites `manual` pins. |
| `roasters:auto-deactivate-dead [--days=7] [--dry-run]` | Deactivate roasters whose DNS has failed for N days. |
| `roasters:retry-inactive [--dry-run]` | Re-try deactivated roasters; reactivate the ones that import successfully. |
| `roasters:check-links [--only=<slug>] [--max-broken=20]` | *Read-only.* HEAD-probe every roaster, coffee and variant URL and report dead links. |
| `roasters:check-addresses` | *Read-only.* Audit of unplaced pins, centroid-only coordinates, missing street/postal, stale verifications. |
| `roasters:find-duplicates` | *Read-only.* Likely duplicates by shared website host or similar name. Never merges. |
| `coffees:purge-non-coffee [--apply]` | Sweep the catalogue for rows the coffee filter now rejects. Dry run by default. |
| `alerts:restock [--dry-run]` | Send (or list) restock-alert emails. |
| `reports:daily-ops [--dry-run] [--only-when-notable] [--email=]` | The daily ops email. |
| `reports:weekly-digest [--dry-run] [--email=]` | The weekly digest. |
| `logs:prune [--days=14]` | Delete old admin-log rows. |
| `ops:heartbeat <key>` | Record a liveness ping (the container does this at boot so `/up` doesn't false-alarm). |

## How the import works

Knowing this explains most of what you'll see in Needs Attention and Logs.

1. **Detect the platform.** The roaster's cached platform is tried first;
   otherwise each scraper probes in order — Shopify (`/products.json`),
   WooCommerce (`/wp-json/wc/store`), Squarespace, then a generic reader that
   looks for schema.org `Product` JSON-LD on the homepage and shop pages.
   Requests go out with a browser-like User-Agent because storefront WAFs
   score Fly's datacenter IP as high-risk otherwise — that's the origin of most
   "Blocked" entries.
2. **Filter to coffee.** Merch, gift cards, subscriptions, equipment, sample
   packs and tea are dropped by title, product type, tags and description.
   Wholesale SKUs and bundles are dropped too.
3. **Extract fields** from the product title, description and tags: origin
   (via a country/region gazetteer), process, roast level, varietal,
   elevation and tasting notes. Notes are recognised under many labels
   ("Tasting notes:", "Flavour:", "In the cup:", "Notes of …"), from
   heading-styled product pages with no punctuation, from the product title
   ("Ethiopia Guji – Blueberry, Jasmine"), and as a last resort from flavour
   tags. French copy is translated first. Anything the extractor can't
   confidently identify is left null rather than guessed.
4. **Parse bag sizes** from variant titles ("12oz" → 340 g). Sizes the shop
   lists twice (12 oz and 340 g) collapse to one; the in-stock one wins.
   Variants with no parseable weight, a zero price, or an implausible price
   per gram are rejected and logged.
5. **Upsert** coffees and variants by the platform's stable product id, so a
   renamed product keeps its history. Coffees that vanished from the source
   are soft-removed, not deleted, so user tastings survive.
6. **Stamp** `last_imported_at` and `last_import_status`
   (`success` / `empty` / `error`, with the error text) on the roaster, and
   `in_stock_changed_at` on variants that flipped back in stock — that's what
   drives restock alerts.

## Data safety rules

These are enforced in code; knowing them tells you what's reversible.

- **Deleting a roaster deactivates it.** `is_active=false`. Nothing is
  removed. A real delete would cascade through every coffee, tasting and
  wishlist entry.
- **Deleting a coffee soft-removes it.** `removed_at` is set; user tastings
  and wishlists keep pointing at it. Restore is one click.
- **Deleting a variant is real.** Variants hold no user data.
- **Hiding a tasting soft-deletes it.** Restore from the Hidden tab.
- **Imports never overwrite a hand-placed pin** (`address_source=manual`) and
  never delete anything a user created.
- **Imports do overwrite** coffee and variant fields for any roaster with a
  website. Don't hand-edit imported catalogue data and expect it to stick.

## Gotchas

- **Empty catalogue on a live site** is almost always the wrong host
  (brochure site vs. `shop.` store) or a platform the scrapers don't read.
  Check the store's URL bar in a browser.
- **Geocode "HTTP 403/429"** is OpenStreetMap refusing the request, not a bad
  address. The geocoder identifies itself with `APP_URL` plus
  `NOMINATIM_CONTACT_EMAIL` → `OPS_EMAIL` → `MAIL_FROM_ADDRESS` — make sure
  one of those is set on Fly and is a mailbox you read. Nominatim's limit is
  one request per second; the app enforces that itself.
- **Map tiles with an "API KEY REQUIRED" watermark** are a *frontend* issue:
  CARTO requires a key since August 2026. Set `VITE_CARTO_API_KEY` in the
  Vercel project (free key from carto.com/basemaps/apikey) and redeploy.
  Without it the map falls back to OpenStreetMap tiles.
- **Verbose logging left on** fills `admin_logs` fast. Turn it off when
  you're done; `logs:prune` keeps the table bounded regardless.
- **Espresso products** are treated as blends unless tagged "Single Origin".
- **Windows + cURL** locally: PHP on Windows has no CA bundle; the app ships
  one at `storage/cacert.pem`.

## Documentation

This file plus the others in `docs/` are the source for the public docs site.
Plain Markdown — Docusaurus, MkDocs, or anything else can render them.
