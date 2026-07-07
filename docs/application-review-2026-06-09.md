# Coffee Tracker — Application & Design Review

*Laravel 10 Canadian micro-roaster directory · pure-token Sanctum API + out-of-repo React SPA · Fly.io / SQLite deploy*

*Review date: 2026-06-09 · Method: 10-dimension multi-agent review (101 raw findings → 4 refuted → 97 verified) with adversarial verification of every finding.*

---

## 1. Executive Summary

Coffee Tracker is a **genuinely well-built core with a thin, under-hardened edge**. The heart of the product — the stable-ID upsert + soft-remove import spine, the strategy-pattern scrapers behind a registry, the empty-fetch catalog-protection guard, and the self-healing platform re-detection — is thoughtfully designed, well-commented, and (unusually for a project this size) backed by a strong ~342-method test suite that asserts the real invariants. The "directory, not a tracker" product intent is cleanly reflected in the schema and API. This is not a rescue job; it is a solid system with a handful of sharp edges.

The edges, however, are real. The highest-impact problems cluster around two themes: **(1) no SSRF defenses anywhere** on a server whose entire job is fetching arbitrary third-party URLs, and **(2) destructive admin paths that violate the project's own central invariant** by hard-deleting coffees/roasters and cascade-wiping user tastings — the exact data loss the soft-remove design exists to prevent.

**The single most important thing to fix:** the admin **hard-delete cascade** (`Admin\CoffeeController::destroy` / `Admin\RoasterController::destroy`). A single routine admin click silently and irreversibly destroys all user tastings (and their moderation audit trail) for a coffee or an entire roaster — DB-level `cascadeOnDelete` bypasses even Tasting's SoftDeletes. It is one line of code away from the invariant the rest of the system was carefully built to honor, and it requires no attacker — just an operator doing their job. Pair it immediately with an SSRF guard in the shared HTTP client, since the daily import cron follows redirects from untrusted roaster sites with zero IP/scheme restrictions.

### Severity tally

| Severity | Count |
|---|---|
| High | 8 |
| Medium | 20 |
| Low | 61 |
| Info | 8 |
| *Refuted during verification* | 4 |

### Per-dimension scorecard

| Dimension | Verdict |
|---|---|
| **Security** | Auth/authz fundamentals are solid (pure-bearer tokens, bcrypt, enumeration-safe flows, constant-time admin gate, correct ownership policies, no SQLi/XSS). Two sharp gaps dominate: zero SSRF defense on the importer/scrapers, and the admin hard-delete data-loss path. Secondary: token-in-URL on OAuth, weak login throttling, wildcard CORS, `APP_DEBUG=true`. |
| **Correctness & business logic** | Import/upsert/soft-remove pipeline is well-constructed and self-healing. Real defects: spurious "restock" emails on newly-listed variants, an empty-string `source_id` unique-index crash, and stock-blind `best_price_per_gram` that contradicts the in-stock premise. |
| **Data layer & schema** | Sensible keys and indexing for the import path; money/currency modeled correctly. Defects: the admin cascade data loss, Google OAuth writing a non-unique/unformatted `display_name` into a UNIQUE column, and index gaps vs. the actual hot directory query. |
| **Scraping subsystem** | Well-engineered strategy/registry with real observability (rejection logs, CPG/price sanity gates) and battle-tested heuristics. Defects: Nominatim rate-limit promised in docs but never implemented, multipack rejection only in Shopify, Shopify capped at 250 products (no pagination), substring origin matching ("Mocha"→Yemen). |
| **API design** | Auth/validation hygiene mostly sound; soft-deleted/private content correctly excluded from feeds. Defects: deactivated roasters/coffees still served on detail endpoints; the firehose `/api/roasters` is unpaginated/unfilterable; hand-built ad-hoc shapes drift; inconsistent error envelopes; no versioning. |
| **Performance & efficiency** | Import match key and rating aggregation are correctly indexed/batched. Risk concentrated in the uncached full-directory read path and synchronous mail/cron on a `sync` queue. Tolerable at ~35 roasters; the firehose and sync mail hurt first. |
| **Testing quality & coverage** | Unusually strong suite with meaningful assertions; the import spine is the best-covered area. Gaps by component: the entire admin panel + Basic-auth gate (zero tests), the web.php closures, GenericHtmlScraper internals, and a near-total absence of factories. |
| **UI/UX & visual design** | Operator-only admin + email templates only (end-user UI is the out-of-repo React app). Minor: non-responsive variant table with invalid table-nested per-row forms; dead/unwired CSS/JS assets. |
| **DevOps, deploy & config** | More thoughtful than typical: scheduler genuinely runs, `/up` probe checks DB + heartbeat, Sentry PII off. Weak spots are resilience: `sync` queue with no worker, single SQLite-on-volume machine (deploy downtime, no data rollback, `migrate --force` on every boot), unconfigured TrustProxies, minimal CI. |
| **Architecture & maintainability** | Core pipeline genuinely well-architected and intent-aligned. Weaknesses: business logic in route closures, orphaned legacy controller/views wired to a deleted route, doc drift on the scraper contract, and a ~700-line `RoasterImporter` god-class. None threaten the running product. |

---

## 2. Critical & High Findings

### H1 — Admin "delete coffee/roaster" hard-deletes and cascade-wipes all user tastings & wishlists
**Severity: High** · *data-loss / breaks core invariant*
**Files:** `app/Http/Controllers/Admin/CoffeeController.php:57`, `app/Http/Controllers/Admin/RoasterController.php:101`, `app/Models/Coffee.php:10`, `database/migrations/2026_04_24_150100_create_tastings_table.php:14`, `database/migrations/2026_04_28_160000_create_wishlists_table.php:22`, `database/migrations/2026_03_11_205208_create_coffees_table.php:16`

**Problem.** `Coffee` does **not** use `SoftDeletes` (it tracks availability with a manual `removed_at`), so `Admin\CoffeeController::destroy()` calling `$coffee->delete()` is a real `DELETE`. `tastings.coffee_id` and `wishlists.coffee_id` are declared `constrained()->cascadeOnDelete()`, and `coffees.roaster_id` is `cascadeOnDelete`. Therefore deleting one coffee permanently destroys every user tasting and wishlist row that references it; deleting a roaster transitively destroys all its coffees and all their tastings/wishlists. The DB-level cascade physically removes the rows — it **bypasses Tasting's Eloquent `SoftDeletes` entirely**, so `deleted_at` and the moderation audit trail are destroyed too, and the `coffee_snapshot` that was designed to make tastings survive coffee changes is moot because the whole row is gone. This directly contradicts the documented invariant ("never hard-deletes, to preserve user tastings that FK to coffees") that `RoasterImporter::syncCoffees` (`RoasterImporter.php:295-300`) is so careful to uphold. There is no confirmation prompt, no audit trail, no recovery. Severity stays High (not critical) only because the routes are HTTP-Basic-gated, not externally exploitable.

**Fix.** Make the admin destroy actions **soft-remove** (set `removed_at` / `is_active=false`) instead of issuing a `DELETE`, matching the importer's contract. Alternatively, add `SoftDeletes` to `Coffee`/`Roaster` and change the `tastings`/`wishlists` FKs to `nullOnDelete` / `restrictOnDelete` so user content can never be cascade-destroyed. At absolute minimum, guard `destroy()` to refuse when tastings exist. This is the single highest-priority change in the report.

---

### H2 — SSRF: importer and scrapers fetch arbitrary URLs with no host/scheme/redirect restrictions
**Severity: High** · *SSRF*
**Files:** `app/Services/RoasterImporter.php:45,49`, `app/Services/Scraping/Shared.php:80,92`, `app/Services/Scraping/ShopifyScraper.php:38`, `app/Services/Scraping/GenericHtmlScraper.php:67`, `routes/web.php:57`

**Problem.** `Shared::clientOptions()` (`Shared.php:80-89`) sets only a CA bundle and User-Agent — **no `allow_redirects` override (Guzzle follows up to 5 by default), no scheme allowlist, no DNS/IP filtering, no response-size cap.** `Shared::origin()` (`Shared.php:92`) only rejects an empty host. The operator-triggered path (`POST /admin/import`) is gated by the fail-closed Basic-auth middleware, so it requires admin credentials. The **genuinely low-barrier vector is redirect-following on the daily cron**: every roaster's third-party site (homepage, `/pages/about`, favicon, `/policies/shipping-policy`, product pages) is auto-fetched, and any malicious or compromised roaster site can issue a 30x redirect to `http://169.254.169.254/latest/meta-data/` or an internal Fly 6PN host, which all scrapers will follow. Bodies and error messages surface back to the admin, making it a usable blind/semi-blind probe.

**Fix.** Centralize a guarded HTTP client (in `Shared::clientOptions()` or a dedicated `SafeHttpClient`) that, before any outbound fetch: resolves the host and rejects private/loopback/link-local/reserved ranges (incl. IPv6 `::1`/`fc00::`/`fe80::` and `169.254.169.254`); enforces an `http`/`https` scheme allowlist; disables or tightly caps redirects with an `on_redirect` callback that re-validates each hop's resolved IP; and caps response size (stream + byte cap). Every scraper and the geocoder inherit it.

---

### H3 — Google OAuth writes `display_name` into a UNIQUE column with no uniqueness/format handling
**Severity: High** · *data-integrity*
**Files:** `app/Http/Controllers/Auth/GoogleAuthController.php:42` (create branch), `:35` (update branch), `database/migrations/2026_04_28_150000_add_display_name_unique_constraint.php:21`, `app/Http/Controllers/Api/AuthController.php:81`

**Problem.** `users.display_name` carries a UNIQUE index. The email/password register path de-dups via `fallbackDisplayName()` (looping on `exists()`) and slugifies to `/^[A-Za-z0-9_-]+$/`. The Google **create** branch does neither: `'display_name' => $googleUser->getNickname() ?? $googleUser->getName()` is written raw, inside a `User::create` that the surrounding try/catch (lines 21-26) does **not** cover (it only wraps the Socialite fetch). Two Google users with a colliding nickname/name (e.g. two "John Smith") throw an **uncaught `QueryException` → 500** on the second signup. Even when unique, spaces/diacritics produce handles that diverge from the registration charset and yield ugly/inconsistent `/u/<display_name>` URLs. (The update branch at line 35 preserves an existing handle and cannot self-collide; its only flaw is writing a raw unslugified name when `display_name` was previously null.)

**Fix.** Run the Google name through the same slugify + collision-resolution logic as `AuthController::fallbackDisplayName()` before assigning, and wrap the user create/save in error handling so a collision degrades gracefully instead of 500-ing the OAuth callback.

---

### H4 — `ShopifyScraper` fetches only the first 250 products (no pagination)
**Severity: High** · *data-completeness*
**Files:** `app/Services/Scraping/ShopifyScraper.php:49-58`

**Problem.** `fetch()` requests `/products.json?limit=250` once and never paginates. Shopify caps that endpoint at 250 items per page **of raw products** (coffee + gear interleaved, since `looksLikeCoffee()` filtering runs in `normalize()` *after* the single fetch, `ShopifyScraper.php:210-215`). Any roaster with >250 published products deterministically loses the tail — and because the importer then **soft-removes previously-imported coffees that fell off the truncated page**, in-stock beans get flipped to `removed`. The class's own comment cites Rogue Wave (~730 total products); for a gear-heavy store, coffee loss begins well before 250 actual coffees. Contrast `WooCommerceScraper::fetch` (`:39-49`), which correctly loops `for ($page = 1; $page <= self::MAX_PAGES; $page++)`.

**Fix.** Paginate Shopify with `?limit=250&page=N` (or `since_id` cursor) until a short page returns, bounded by a `MAX_PAGES` cap as Woo does.

---

### H5 — `GET /api/roasters/{slug}` leaks deactivated roasters (`is_active` filtered on index but not on show)
**Severity: High** · *authz / moderation bypass*
**Files:** `app/Http/Controllers/Api/RoasterApiController.php:38`, `app/Models/Roaster.php:41`

**Problem.** `index()` filters `->where('is_active', true)` (`:19`), but `show(Roaster $roaster)` relies on plain slug route-model binding with no active check and no global scope on the model. A roaster an admin deactivated to hide it is still fully served at `/api/roasters/{slug}` — full catalogue, address, import status. Deactivation is therefore not a real hide. The same gap exists on `CoffeeApiController::show` (see M-group). The leaked fields are the same public directory data shown for active roasters, so this is a **failure of the moderation control to actually hide a record**, not disclosure of secret data — which is what holds it at High rather than escalating it.

**Fix.** Scope the route binding to active roasters: override `resolveRouteBinding` to add `->where('is_active', true)` (404 for inactive), use `->scopeBindings()` with a where, or add a global `active` scope (using `withoutGlobalScope` only in admin). Add a regression test: `GET /api/roasters/{inactive-slug}` → 404.

---

### H6 — `GET /api/roasters` loads & serializes the entire directory, uncached, on every request
**Severity: High** · *unbounded query / no caching*
**Files:** `app/Http/Controllers/Api/RoasterApiController.php:15-36,142-226`, `routes/api.php:25`

**Problem.** `index()` eager-loads every active roaster with all non-removed coffees and all their variants in one shot, then `transformRoaster()` walks every coffee and variant in PHP. There is **no pagination, no field projection, and no caching anywhere in the app (zero `Cache::` usage)**, despite the payload changing at most once per day (the 04:00 import). The per-request PHP work is heavier than it looks: `best_price_per_gram` is a variant-iterating accessor invoked per coffee *and* re-derived at the roaster level, and `variants_count` re-iterates, so variants are walked 3+ times per request (`:210,220-225`). This backs the SPA's main directory/map view. (The rating aggregation is correctly batched into one grouped query — that part is fine.) At ~35 roasters this is tolerable; it is a scalability/cost issue that grows linearly.

**Fix.** Wrap the assembled payload in `Cache::remember()` keyed on `Roaster::max('last_imported_at')`, invalidated at the end of `ImportAllRoasters`. At minimum, emit an `ETag`/`Last-Modified` derived from `last_imported_at` so clients skip the transfer. Longer term, split into a lightweight list endpoint + per-roaster detail.

---

### H7 — `QUEUE_CONNECTION=sync` with no worker: transactional mail and admin imports block the web request
**Severity: High** · *availability / performance*
**Files:** `config/queue.php:16`, `fly.toml:12`, `app/Http/Controllers/Api/AuthController.php:37`, `app/Http/Controllers/Api/EmailVerificationController.php:43`, `routes/web.php:57,75`, `docker/entrypoint.sh:45-51`

**Problem.** The default queue is `sync` with no `QUEUE_CONNECTION` override in `fly.toml`, and the entrypoint starts `schedule:work` but **never a `queue:work`** — there is no worker process. Every dispatched unit runs inline. `register()` calls `sendEmailVerificationNotification()` inline (one synchronous Resend HTTP call on the request thread), so a Resend slowdown/outage inflates or 5xx's signup; resend and password-reset are the same. More clearly defective: `POST /admin/import` and `POST /admin/roasters/{roaster}/refresh` run `RoasterImporter::import()` **inline on the web request** (`web.php:57,75`), performing a multi-fetch scrape (catalog + about + shipping + favicon + up to ~40 product fetches) that can time out the admin HTTP request.

**Fix.** Move admin import/refresh **off the request thread** (dispatch a queued job or `artisan call`) — this is the highest-value half. For mail, implement `ShouldQueue` on the mailables/notifications and switch `QUEUE_CONNECTION` to `database`/`redis`, **adding a supervised `queue:work --tries=3` process to `docker/entrypoint.sh`** alongside `schedule:work` (none exists today). Document the connection in `fly.toml [env]`.

---

### H8 — Single Fly machine + SQLite on its volume: deploy downtime, no data rollback, `migrate --force` every boot
**Severity: High → effectively Medium per verifier** · *availability / data-safety*
**Files:** `fly.toml:30,37`, `docker/entrypoint.sh:7,22`

**Problem.** The deploy is intentionally one machine (`min_machines_running=1`, `auto_stop_machines='off'`) with the only DB being `database.sqlite` on its mounted volume. Consequences: (1) every deploy replaces the one machine → a hard downtime window per release (true blue/green is structurally impossible with one machine bound to one volume); (2) `php artisan migrate --force` runs on **every boot** (`entrypoint.sh:22`) under `set -euo pipefail` (`:7`), so a destructive or failing migration mutates/crash-loops the live volume. An **image** rollback is documented (`docs/deploy.md:142-147`), but it does **not** undo a schema migration already applied to the volume, and there is no pre-migrate backup. The downtime itself is documented and intentional for a once-daily-refresh directory whose roaster data is re-scrapable; the only irreplaceable data (user tastings) is already protected by soft-remove — which is why the practical severity is closer to Medium.

**Fix.** Add an automated SQLite backup of `/data/database.sqlite` (timestamped `cp` or `fly volume snapshot`) **before** `migrate --force` — this single mitigation addresses the highest-value risk. Consider a `[deploy] release_command` and a healthcheck grace before cutover.

---

## 3. Medium Findings

### Security & abuse
- **OAuth token in URL query string** (`GoogleAuthController.php:48,50`). The Sanctum plaintext token is delivered via `redirect(FRONTEND_URL/auth/callback?token=…)`; tokens in URLs leak via history, proxy/CDN logs, and `Referer`. Worse, Sanctum tokens never expire (`config/sanctum.php:50`), so one leaked URL grants indefinite access. **Fix:** deliver via a short-lived one-time exchange code or the URL fragment (`#token=`); set a token expiration.
- **No brute-force protection on login/register** (`routes/api.php:15-16`, `RouteServiceProvider.php:27`). Both inherit only the global 60/min-per-IP limiter — unlike forgot-password/reset/report which use `throttle:6,1`. That's a viable online-guessing rate with no lockout. **Fix:** add `throttle:5,1` keyed on `email+IP` to login and a separate limiter to register.

### Correctness & business logic
- **New in-stock variants trigger spurious "restock" wishlist emails** (`RoasterImporter.php:445`, `SendRestockAlerts.php:26-32`). First-seen variants are stamped `in_stock_changed_at = now`, and the alert query treats any variant flipped in-stock within 24h as a restock. **Caveat (verifier):** the create-path stamp is partly *load-bearing* — variants that vanish are hard-deleted and re-created on a genuine return, and the stamp is the only mechanism that detects that real "fully-OOS coffee came back" restock. The actual false-positive surface is narrower: **net-new bag sizes added to coffees that stayed in stock** (first-import coffees can't email anyone — no one has wishlisted them yet). **Fix:** soft-flag vanished variants `in_stock=false` instead of hard-deleting so a later flip is a true transition, or only stamp on create when no sibling variant of the same coffee is already in stock. (A naive "set null on create" would *break* real restock detection.)
- **Empty-string `source_id` crashes the import on the unique index** (`ShopifyScraper.php:282`, `WooCommerceScraper.php:150`, `SquarespaceScraper.php:163`, `RoasterImporter.php:268-289,328`, migration `2026_04_28_120000:29`). Scrapers emit `source_id => ''` when a platform id is missing; `''` is stored (not NULL), and a second such product on one roaster violates `coffees_roaster_source_unique`, aborting that roaster's sync mid-stream and recurring every run. Latent (platforms almost always return an id) but damaging when triggered. **Fix:** normalize empty source ids to NULL at the boundary (in `upsertCoffee`, or have scrapers emit `null`) so they route through the NULL/name-fallback path and stay outside the unique index.
- **`best_price_per_gram` includes out-of-stock variants** (`Coffee.php:53-65`, `RoasterApiController.php:210,220-223`). The headline per-gram price (coffee- and roaster-level) is computed over *all* variants with no `in_stock` filter, so users sort/compare on a price they can't pay — directly against the "in stock right now" premise. (`getCheapestVariantAttribute` is also stock-blind but is currently dead in this API path; the controller builds its own stock-aware `default_variant` at `:194`.) **Fix:** restrict the best-price accessor to in-stock variants (fallback to all only when none are in stock), or expose an explicit `best_in_stock_price_per_gram` for sort/compare.

### Scraping
- **Nominatim rate-limit documented but never implemented** (`Address/AddressScraper.php:30-31`, `NominatimGeocoder.php`, `ScrapeRoasterAddresses.php:142-146`). Two docstrings promise ≥1 req/s pacing; the only real pause is 0.5s *between roasters*, allowing ~2 req/s across consecutive geocoded roasters — over Nominatim's 1 req/s policy (IP-ban risk on a `--force` sweep). The scheduled monthly job is low-volume and only touches unresolved rows, hence Medium. **Fix:** enforce ≥1s before any `NominatimGeocoder` call (static last-call timestamp or `RateLimiter`), test-aware like `maybeSleep()`; raise `SLEEP_BETWEEN_ROASTERS` to ≥1s or fix the misleading docstrings.
- **Multipack/bundle variant rejection runs only in `ShopifyScraper`** (`Shared.php:193-212`, called only at `ShopifyScraper.php:243`). Woo/Squarespace/Generic never call `isBadVariantTitle()`, so a variant-level "12 x 250g"/"Case of 12" imports as a single-bag price and corrupts per-gram comparison. **Partial mitigation:** `looksLikeCoffee()` (`Shared.php:437`, run in all four) already drops multipacks expressed in the *product title*; the gap is bundle signatures at the *variant* level under a clean title. **Fix:** call `isBadVariantTitle()` on the assembled variant label in all scrapers — better, centralize variant building so the gate can't be omitted.
- **`OriginGazetteer` substring matching gives confident wrong origins** (`OriginGazetteer.php:22-45,134`, `RoasterImporter.php:690-693`, `Shared.php:605`). Case-insensitive `str_contains` with no word boundaries: "Caramel Mocha"→Yemen, "Java Script Roast"→Indonesia, "Embudo"→Kenya, "Harare"→Ethiopia. Writes to `coffees.origin` (a key filter field) and can flip `looksLikeCoffee`'s origin-named positive. **Fix:** match needles on word boundaries (`\b`), and guard/drop the collision-prone short aliases (`mocha`, `java`, `embu`, `volcan`, `harar`) unless adjacent to process/region context.

### API & data exposure
- **`GET /api/coffees/{id}` serves coffees of inactive roasters** (`CoffeeApiController.php:16`, `Coffee.php`). `show(Coffee $coffee)` has no `roaster.is_active` check; coffees of a deactivated roaster remain individually fetchable (name/website/address). Public directory data, not secrets, hence Medium. **Fix:** 404 when `$coffee->roaster` is null or inactive; the same pattern applies to `RoasterApiController::show` (H5).
- **`GET /api/roasters` has no pagination, filtering, or sorting** (`RoasterApiController.php:15`, `routes/api.php:25`). A full directory dump with no server-side support for the bean-centric filters (origin/process/in-stock/price-per-gram) the product promises. Functions at ~50 roasters; a latent scalability + functional gap. **Fix:** add a paginated, filterable `GET /api/coffees` (origin/process/roast/in-stock/price filters, sort, cursor/page); slim `/api/roasters` to lightweight summaries.

### Performance
- **Mail sent synchronously; cron blocks on the provider** (`config/queue.php:16`, `app/Mail/*.php`, `AuthController.php:37`, `SendRestockAlerts.php:69`). No mailable implements `ShouldQueue`; `SendRestockAlerts` sends per-user inline so one provider hiccup aborts the batch with no retry. (Transport is Resend's HTTP API, not SMTP; cron is off-peak with `withoutOverlapping` and bounded recipients, so the registration-latency path is the real concern.) **Fix:** `ShouldQueue` on mailables + a real queue connection + worker (see H7).
- **Daily import is fully sequential external HTTP** (`ImportAllRoasters.php:41-51`, `RoasterImporter.php:85-170`, `ShopifyScraper.php:49-58,76-108`). One roaster at a time; each `import()` chains platform fetch + about + favicon (homepage GET + per-candidate reachability GETs) + shipping (up to 8 paths × 8s) + up to 40×12s metafield fetches. The repo's own `Kernel.php:18` "~2-min" estimate is unrealistic; `docs/architecture.md` already lists queueing as planned. Side-fetches mostly fire only on first-import/backfill. **Fix:** `Http::pool()` for independent metafield fetches; dispatch one queued job per roaster; add a global time budget.

### Data layer & schema
- **Missing composite index `(roaster_id, removed_at)`** for the hot directory query (`RoasterApiController.php:17,41`, migration `2026_04_28_120000:28`). On SQLite (prod) `foreignId(...)->constrained()` does **not** auto-index `roaster_id`; the `roaster_id IN (...)` lookup rides the UNIQUE `(roaster_id, source_id)` prefix, and the standalone `removed_at` index is low-selectivity. **Fix:** replace the standalone `removed_at` index with a composite `(roaster_id, removed_at)`. (Verifier downgrades real-world impact to low at current scale.)
- **No indexes on `roasters.is_active`, `last_import_status`, `last_imported_at`** used by every `/stats`, `DataQualityReport`, and `DailyOpsReport` filter (migrations `…205205:14-30`, `…120000:19-26`). Full scans; irrelevant at ~35 rows but on a public endpoint + daily crons. **Fix:** add `is_active` and composites `(is_active, last_import_status)` / `(is_active, last_imported_at)`.
- *(Several other data/schema items — MySQL-vs-SQLite parity, one-off data migration, rating CHECK, free-text enums — are quality concerns the verifier downgraded to Low; see §4.)*

### DevOps & config
- **`TrustProxies` trusts no proxies behind the Fly edge** (`TrustProxies.php:15`, `fly.toml:27`). `$proxies = null` means `X-Forwarded-For`/`-Proto` are ignored, so `$request->ip()` is the internal Fly proxy address (degrading the IP-keyed rate limiter, Sentry context, logs) and the scheme is seen as `http`. The signed email-verification "broken link" sub-claim is **not** a real break (scheme is consistently `http` on both sign and verify, so signatures match) — it's cosmetic. **Fix:** set `$proxies = '*'` (or Fly's edge range); optionally `URL::forceScheme('https')` in production.
- **Sentry `sample_rate` 1.0 and no event scrubber** (`config/sentry.php:30,73,11`, `fly.toml:12`, `.env.example:64`). `send_default_pii=false` and `sql_bindings=false` are good, but with `sql_queries=true` and no `before_send`, **auth-endpoint request bodies (`password`, `password_confirmation`, `token`) can ride along** in error events shipped to a third party. Conditional on an actual captured exception during an auth POST with a DSN configured. **Fix:** add a `before_send` that strips `password`/`password_confirmation`/`token` (or set `max_request_body_size='never'`); set `SENTRY_ENVIRONMENT=production` and document it.

### Architecture & maintainability
- **Scraper-contract documentation has drifted from the code** (`docs/developer-guide.md:106-128`, `RoasterScraper.php:11-31`, `RoasterImporter.php:384-448`). The guide documents variant keys `bag_weight_grams`/`in_stock`/`source_variant_id`, but the importer reads `grams`/`available`/`source_id` (`:391,420`); it says "register in `ScraperRegistry::all()`" (no such method — registration is a constructor array); and the interface PHPDoc omits `purchase_link`/`source_size_label` that Shopify emits. A scraper written to the doc shape would throw a loud `QueryException` (NOT NULL `bag_weight_grams`), not silently drop variants. Doc-only drift → onboarding friction. **Fix:** sync the guide and interface PHPDoc to the real keys and the real registration mechanism.
- **Admin geocode route sets lat/lng but never `address_source`** (`routes/web.php:96-108`, `ScrapeRoasterAddresses.php:44-50`, `AddressScraper.php:75`). The whole address subsystem treats `address_source != null` as "resolved"; a manually-geocoded roaster will be re-swept and its pin can be overwritten (or NULLed by `persistOnlineOnly`). The `'manual'` convention is *already realized* in `ApplyRoasterCorrections.php:509` (stamps `address_source='manual'` + `address_verified_at=now()`), so this route is simply inconsistent with an established pattern. **Fix:** set `address_source='manual'` + `address_verified_at=now()` in the geocode route.

---

## 4. Low / Info Findings

Terse list, grouped. All are evidence-backed quality/hardening items, not active defects unless noted.

**Security (Low)**
- Wildcard CORS for `api/*` (`config/cors.php:18,22`) — hardening only; bearer-token auth + `supports_credentials=false` means it's not an active vuln. Pin `allowed_origins` to `FRONTEND_URL`. *(Reported in both security and devops dimensions — same fix.)*
- Unauthenticated tasting-report enables flag-spam/moderation-queue flooding (`routes/api.php:38`, `TastingController.php:111`). Require auth+verified to report; dedupe per actor.
- Google OAuth auto-links by email with no `email_verified` check; unconditionally overwrites `avatar_url` on every login (`GoogleAuthController.php:28,34`). *(`display_name` is NOT overwritten — `:35` preserves it.)* Gate linking on a verified Google email; don't clobber a set avatar.
- `Tasting` `$fillable` exposes `flagged_at`/`flagged_by_user_id`/`coffee_snapshot` (`Tasting.php:14`). Bounded today (narrow validated update), latent under any future `->update($request->all())`. Remove them from `$fillable`.

**Correctness (Low/Info)**
- Restock dry-run reports `$users->count()` instead of users-with-hits (`SendRestockAlerts.php:62,74-76`). Track a `$wouldSend` counter.
- `hasCoffeeChanged()` strict compare false-positives on importer re-derivation drift of `process`/`roast_level`/`varietal` (`TastingController.php:165-176`, `Tasting.php:32-48`). *(The null-vs-'' sub-claim is largely mitigated by `RoasterImporter.php:350-352`.)* Key "changed" off identity fields (`name`+`is_blend`).
- Squarespace defaults absent `qtyInStock` to out-of-stock vs in-stock on Shopify/Woo (`SquarespaceScraper.php:143-145`). Largely theoretical (payload co-emits the field) and partly *correct* (Squarespace gives an authoritative integer). Document the contract.
- Variant delete-and-recreate loses `in_stock_changed_at` history and can manufacture a spurious restock (`RoasterImporter.php:386,439-453`) — same root cause as the Medium restock finding; the cleanest fix (only stamp on a true OOS→in-stock flip of a surviving row) resolves both. *(Reported twice across correctness/data.)*
- **Info:** re-detect heal / soft-removed-coffee rating aggregation edge cases — confirmed *non-defects*, informational only (`RoasterApiController.php:122-140`, `RoasterImporter.php:114-119,197-221`).

**Data / schema (Low)**
- MySQL config default vs SQLite prod/CI parity (`config/database.php:19`) — config hygiene; `postal_code` is validated, `currency_code`/`country_code` have no free-form write path, so the "silent over-length" risk has no realistic trigger. Make SQLite the documented default.
- One-off Rogue Wave address baked into a migration with no-op `down()` (`2026_06_01_120000:28,42`) — hygiene; prefer the existing `ApplyRoasterCorrections` command as single source.
- Legacy public `RoasterController` queries omit `removed_at` (`RoasterController.php:13,71`) — dead code (routes only redirect); delete it.
- `removed_at` hand-rolled soft-delete vs Tasting's `SoftDeletes` (`Coffee.php:27`) — consistency footgun; all currently-routed paths apply the filter.
- `platform`/`last_import_status` free-text enums; `'unsupported'` is read/documented but never written (`RoasterController.php:17-22`, `RoasterImporter.php:99-176`, `admin/roasters/index.blade.php:23,29`, `admin-guide.md:21,78`) — dead bucket across multiple surfaces; either emit it or remove it everywhere. *(Reported in both data and architecture.)*
- `rating` 1-10 half-star encoding lives only in comments; plain `unsignedTinyInteger`, no CHECK (`tastings` migration `:16`). No current bypassing write path; add a CHECK / model guard.

**Scraping (Low/Info)**
- Cached-platform dispatch can throw unhandled `RuntimeException` on an unknown platform key (`ScraperRegistry.php:34-53`) — latent (no code writes a bad value). Treat unknown cached platform as a cache miss.
- Squarespace shop discovery latches onto the first non-empty `items[]` (`SquarespaceScraper.php:52-83`) — primarily a false-*negative* (real shop never tried); verify `collection.type` is a store or aggregate across paths.
- Woo `productType = categories[0]` is lossy (`WooCommerceScraper.php:101-106`) — narrow false-negative only when `categories[0]` is itself an exclusion term; scan all categories.
- Generic offer parsing drops availability on the `lowPrice`/AggregateOffer branch and mishandles string/symbol prices (`GenericHtmlScraper.php:115-138`) — mostly false-*negative* drops via the CPG gate; normalize price strings, derive availability in the lowPrice branch, substring-match `OutOfStock`/`SoldOut`.
- `FaviconScraper` treats missing `Content-Length` as reachable and validates icons with full GETs, no `Content-Type` check (`FaviconScraper.php:85-96`) — gated to roasters with no favicon; use HEAD + require `image/`.
- Shopify metafield enrichment gates on tasting-notes only, skipping roast/process/varietal recovery (`ShopifyScraper.php:80-108`). Gate on *any* missing field.
- **Info:** Woo price divisor hardcoded `/100` ignoring `currency_minor_unit` (`WooCommerceScraper.php:118,129`) — correct for CAD today; fix both sites to `/(10 ** ($v['prices']['currency_minor_unit'] ?? 2))`.

**API (Low)**
- Per-coffee public tasting feed and "my tastings" unpaginated (`TastingController.php:13,23`) — the unauthenticated `publicForCoffee()` is the stronger half. Apply `->limit(100)` like `PublicProfileController:26`; cursor-paginate the public feed.
- Coffee resource shape diverges between `RoasterApiController` and `CoffeeApiController` (`:196` vs `:29`) — bidirectional drift; introduce API Resources.
- Inconsistent error envelopes ({message,errors} / {error} / {ok,error} / RMB {message}) across at least four shapes (`TastingController.php:56`, `PasswordResetController.php:54`, `AuthController.php:57`, `Handler.php`) — centralize in `Handler.php`.
- No API versioning (`routes/api.php:1`) — add `/api/v1` now while there's one client.
- `tasted_on` accepts future dates → feed-pinning (`TastingController.php:67,91`) — add `before_or_equal:today`.
- `default_variant` can be null and is undocumented (`RoasterApiController.php:194`); `/me` vs `userPayload()` shapes differ (`/me` drops `email_verified`) (`routes/api.php:49`, `AuthController.php:94`); `report()`/`showTasting()` hand-build a `{error:'Not found'}` 404 that diverges from the RMB `{message}` 404 (`TastingController.php:114`, `PublicProfileController.php:43`). Standardize.
- **Info:** public roaster payload exposes coarse `last_import_status` incl. `'error'` (`RoasterApiController.php:181`) — map `error`/`empty` to a neutral `stale`.

**Performance (Low)**
- `/api/stats` issues **8** sequential aggregates uncached (`RoasterApiController.php:55-110`) — `Cache::remember` keyed on `last_imported_at`.
- Importer writes coffees/variants row-by-row with no transaction or bulk upsert (`RoasterImporter.php:242-301,384-454`) — wrap `syncCoffees` in `DB::transaction`; batch variant writes. (Coffee read is *not* N+1; only the per-coffee variant read is. Wall-clock is dominated by network I/O.)
- Per-gram pricing computed in PHP accessors, not stored/indexable (`Coffee.php:58-65`, `CoffeeVariant.php:29-41`) — persist `cents_per_gram`/`best_cents_per_gram` at import for DB-side sort/filter.
- `ImportAllRoasters` fires an extra `COUNT` after `fresh()` already loaded the relation (`ImportAllRoasters.php:43-45`, `RoasterImporter.php:178`); same in the import route (`web.php`, two sites). Use `$imported->coffees->count()`.
- `SendRestockAlerts` loads each matched user's *entire* wishlist into memory (`SendRestockAlerts.php:40-62`) — constrain the eager load to restocked coffee ids; cursor/chunk. Daily job, bounded scope.
- **Info:** dead legacy `RoasterController` loads-all-then-sorts-in-PHP (`RoasterController.php:11-67`) — the anti-pattern the new API must not copy; delete it.

**Testing (Low/Info)**
- Entire admin panel (4 controllers) + `BasicAdminAuth` gate have **zero tests** (`BasicAdminAuth.php:24`, `Admin/*`, `web.php:32`); the gate is correctly fail-closed today, so this is regression-protection on the only data-mutation surface. Add a `BasicAdminAuth` test (503/401/pass) + per-action authz tests.
- web.php import/refresh/geocode closures untested (`web.php:57,75,96`); underlying services *are* tested — only the glue (validation, branch, flash, persistence) is uncovered.
- `GenericHtmlScraper` internals have no direct test (`GenericHtmlScraper.php:37`) — *narrower than claimed*: no `og:product`/price-heuristic code exists (docblock-only); test the JSON-LD/`@graph` paths.
- Only `UserFactory` exists; Roaster/Coffee/Tasting/Variant/Wishlist hand-built across **21** test files — add factories. Maintainability only.
- Woo `normalize` layer thin coverage (`WooCommerceScraperTest`) — add a direct unit test; a `currency_minor_unit` test would surface the latent `/100` bug.
- Geocoder/address rate-limit pacing disabled under tests (`maybeSleep()` early-returns) and the promised per-request pacing **doesn't exist in code at all** — inject a Sleeper/Clock; fix the misleading docstrings.
- Weak `ImportAllCommandTest` "imported" assertion (passes on total failure) (`:38`); no end-to-end command-level soft-remove test; `FrenchToEnglish` (applied to every coffee) untested; `ImportSoftRemoveTest` leans on fragile `Http::sequence` counts; `GoogleAuthTest` over-mocks Socialite and lacks a null-email path (which would currently 500). Address per item.
- **Info:** SSRF protection has no tests because no SSRF protection exists (`Shared.php:80`, `web.php:57`) — fix H2 first, then add loopback/link-local/RFC1918 rejection tests.

**DevOps (Low/Info)**
- CI runs only `php artisan test` — no Pint `--test`, no Larastan, no coverage gate (`.github/workflows/ci.yml:46`, `composer.json:20`). Preventive tooling, cheap to add.
- `fly-deploy.yml` triggers on push to main without `needs:` CI → can ship a red build (`.github/workflows/fly-deploy.yml:4`). Gate deploy on CI (`workflow_run` or merged job).
- Prod caches rebuilt at boot under `set -e` (`docker/entrypoint.sh:24`); a Blade/config error crash-loops the deploy. Move `config:cache`/`route:cache`/`view:cache` to build steps. (The `env()`-after-`config:cache` note is prospective — no `.env` ships in prod.)
- Container runs Apache master + entrypoint as root (`Dockerfile:48`, `entrypoint.sh:53`) — request-time PHP already drops to `www-data`; root is confined to the master + boot steps; port 8080 is non-privileged so non-root is feasible. Defense-in-depth.
- **Info:** scheduler + web share one process tree with no external supervisor (`entrypoint.sh:45`) — recommendations already satisfied (external `/up` monitoring documented; scrapers have hard timeouts; import runs `runInBackground`); only shared-VM OOM coupling remains, accepted on a single SQLite machine.
- **Info:** `/up` is read-only (`select 1`) and passive on mail (`HealthController.php:49,79`) — a read-only/full volume eventually flips `/up` via the stale-heartbeat path (~15min); mail breakage has ~24h detection latency. Optionally add a writable-volume check.

**Architecture (Low)**
- Import/refresh/geocode logic in route closures, not controllers (`web.php:57-73,75-88,96-108`) — move into `AdminRoasterController` methods for testability/consistency. (CLI reuse is *not* blocked; container injection here is cosmetic.)
- Orphaned legacy `RoasterController` + `roasters/index|show.blade.php` reference deleted `route('roasters.index')` → would `RouteNotFoundException` if re-wired (`web.php:3` unused import). Delete them.
- `RoasterImporter` ~700-line god-class mixing orchestration with text-cleaning/extraction (~25-30% of the file) (`RoasterImporter.php:1-694`) — extract a `CoffeeTextNormalizer`; consolidate tasting-note extraction into `CoffeeFieldExtractor`.
- `GenericHtmlScraper` variant shape diverges (missing `purchase_link`/`source_size_label`) — but per the interface PHPDoc, **Shopify is the outlier**; document its extra keys as optional. Coffee-level `purchase_link` already covers most UI need.
- Duplicate `sanitizeUtf8` in `RoasterImporter:663` and `Shared.php:648` — route all calls through `Shared::sanitizeUtf8`.
- `import()` PHPDoc says "doesn't throw" but does (`RoasterImporter.php:42-44` vs `:103`) — correct the comment (it *does* record status *and* re-throw).
- Coffee→API transform duplicated across three controllers (`RoasterApiController:182`, `CoffeeApiController:22`, `TastingController:126`) — introduce `CoffeeResource`/`VariantResource`.
- Doc PHP version mismatch: guide says 8.2+, composer says `^8.1`, README says 8.1+ — CI/prod both pin 8.2; tighten composer to `^8.2` as the single source of truth.
- Coffee scrapers lack a shared `AbstractHttpScraper` base for the repeated transport/probe boilerplate (the Address subsystem has a clean orchestrator) — extract a `Shared::fetchJson/fetchHtml`. (`normalize()` bodies are genuinely platform-specific and shouldn't be merged.)

**UI/UX (Low)**
- Admin variant table not responsive; per-row save is inefficient *and* the `<form>` straddles multiple `<td>` cells (invalid table nesting) (`admin/coffees/form.blade.php:81,95,97`). Wrap in `.table-container`; consolidate into one form / AJAX save. Operator-only; prices come from automated scraping, so this is an occasional-correction surface.
- Dead/unwired assets: empty `app.css`, unused `app.js`/`bootstrap.js` Echo block, `@vite` never emitted (layout uses inline `<style>`), ineffective sticky table headers on a dead view (`resources/css/app.css:1`, `resources/js/bootstrap.js:18`, `layouts/app.blade.php:118`). Delete or wire via `@vite`.

---

## 5. Design Review

### (a) Software & architecture design — verdict: **strong core, soft edges**

The import spine is the best thing in this codebase and it is genuinely good. The combination of **(roaster_id, source_id) stable-ID upsert + `removed_at` soft-remove + an empty-fetch guard + self-healing platform re-detection** is a mature answer to the hardest problem in a scraping directory: how to reconcile a volatile external catalog against durable user-generated content without either losing data or letting stale rows accumulate. The `coffee_snapshot` on tastings is exactly the right denormalization for a system where the upstream entity can vanish. The strategy/registry scraper pattern (specific platforms first, generic last, detected platform cached on the row) is clean and extensible, and the rejection-log + CPG/price sanity gates give the system real observability into bad upstream data. The "directory, not a tracker" product intent is faithfully encoded: bean-centric directory endpoints are primary, tastings are a soft-deletable secondary signal. The test suite reinforces all of this — the invariants that matter most are the ones most heavily asserted.

The disappointment is that the **edge of the system doesn't live up to the center.** Three patterns recur:

1. **Logic leaks out of the service layer into route closures.** Import/refresh/geocode — three non-trivial operations — live as fat closures in `web.php`, inconsistent with the resourceful admin controllers right next to them, and untestable except through HTTP. The service they wrap (`RoasterImporter`) is excellent and reusable; the glue around it is the weak link.
2. **A god-class is forming.** `RoasterImporter` has accreted ~700 lines that mix orchestration with regex-heavy text cleaning and field extraction, overlapping `CoffeeFieldExtractor`'s remit and duplicating `Shared::sanitizeUtf8`. The ownership boundary for "where does coffee text get cleaned" is unclear. This is the highest-leverage refactor target.
3. **Dead code and doc drift erode trust in the map.** An orphaned legacy `RoasterController` + Blade views point at a deleted route (a loaded gun for the next person), the developer guide documents a scraper contract that no longer matches the code (a new contributor would get a loud failure), and a phantom `'unsupported'` status is sorted/styled/documented but never written. None break the running product; collectively they make safe change harder than it should be.

The legacy-Blade/React split is handled honestly — old public pages redirect to the SPA, and the layout even documents the removed route — but the cleanup wasn't finished. The biggest *structural* gap is that the API was built for ~35 roasters, not for the "50+ and growing, bean-centric filters" product on the box: hand-built ad-hoc response arrays (no Resources, divergent shapes, no versioning), an unpaginated/unfilterable firehose as the primary endpoint, and zero caching despite once-a-day data. These are the investments that decide whether the directory scales gracefully or gets rewritten.

### (b) UI/UX & visual design — admin panel + email templates only

**The end-user experience is the out-of-repo React SPA and is therefore not assessable here.** What's in this repo is the operator-facing surface: the HTTP-Basic-gated admin panel and the transactional email templates. Within that narrow scope, the verdict is **functional and low-stakes, with no design ambition and a couple of correctness nits.** The admin is a handful of operators on desktop maintaining scraped data, so polish rightly isn't the priority — but the variant editor has an actual HTML defect (a `<form>` nested across `<td>` cells, `admin/coffees/form.blade.php:95`) that argues for consolidating per-row saves into one form regardless of responsiveness, and the front-end asset story is half-built (empty `app.css`, an unused axios/Echo bundle, `@vite` never wired because the layout inlines its theme). These are cleanups, not redesigns. The crucial framing for any reader: **do not infer end-user UX quality from this repo** — the product's actual interface lives elsewhere.

---

## 6. Quick Wins

Highest value-to-effort, mostly one-to-a-few-line changes:

- **Normalize empty `source_id` → NULL** at the import boundary (`upsertCoffee`) — removes a per-cron import-abort crash class. (M)
- **404 inactive roasters/coffees** by scoping route binding / adding an `is_active` global scope — closes H5 + the coffee-detail leak in one change.
- **Filter `best_price_per_gram` to in-stock variants** (`Coffee.php:53-65`) — re-aligns the headline price with the product premise. (M)
- **Add `throttle:5,1` keyed on `email+IP` to `/auth/login`** and a limiter to `/auth/register` — closes the brute-force gap. (M)
- **Cache `/api/roasters` and `/api/stats`** with `Cache::remember` keyed on `Roaster::max('last_imported_at')`, invalidated by the import — biggest read-path win for the least code. (H6, perf)
- **Reuse `fallbackDisplayName()` + try/catch in the Google create branch** — stops a 500 on common-name collisions. (H3)
- **Add a `before_send` Sentry scrubber** for `password`/`password_confirmation`/`token` — stops credential leakage on auth-endpoint errors. (M)
- **Replace the standalone `removed_at` index with `(roaster_id, removed_at)`** and add `is_active` (+ composites) — cheap index hygiene that future-proofs the hot query and crons.
- **Add `before_or_equal:today` to `tasted_on`** — blocks feed-pinning. (Low)
- **Set `address_source='manual'` + `address_verified_at=now()` in the geocode route** — reuses an existing convention so the monthly sweep stops clobbering hand-placed pins. (M)
- **Delete the orphaned legacy `RoasterController` + its two views + the unused `web.php:3` import.**
- **Set `$proxies = '*'` in `TrustProxies`** — restores correct client IP for rate limiting, Sentry, and logs. (M)
- **Use `$imported->coffees->count()`** (no parentheses) — drops a redundant query per roaster per run.

---

## 7. Strategic Recommendations

The 3–6 structural investments that most reduce future risk:

1. **Make the admin destroy paths honor the soft-remove invariant — and prove it.** Convert `Admin\CoffeeController::destroy`/`RoasterController::destroy` to soft-remove (or change the `tastings`/`wishlists` FKs to `nullOnDelete`), and add the missing tests for the admin panel + `BasicAdminAuth` gate. This closes the single most dangerous defect (H1) *and* the largest test-coverage gap (the only data-mutation surface) in one initiative.

2. **Build one guarded outbound HTTP client and route every scraper + the geocoder through it.** Private-IP/loopback/link-local rejection, scheme allowlist, redirect re-validation, response-size cap, consistent timeouts. This closes the SSRF exposure (H2), naturally centralizes the transport boilerplate the scrapers duplicate today (the `AbstractHttpScraper` idea), and gives you one place to add per-request Nominatim pacing — folding several findings into a single defensible component.

3. **Move work off the web request: introduce a real queue + worker, and dispatch imports as jobs.** Switch `QUEUE_CONNECTION` to `database`/`redis`, add a supervised `queue:work` to the entrypoint, make mailables `ShouldQueue`, and dispatch one import job per roaster. This fixes registration latency, batch-mail fragility, the admin-import timeout (H7), and the sequential-import scaling wall in one structural change — and it's the prerequisite for `Http::pool()`-style concurrency later.

4. **Build the API the product actually needs: a paginated, filterable, cached bean-centric layer with proper Resources and versioning.** A `GET /api/coffees` with origin/process/roast/in-stock/price-per-gram filters + cursor pagination; `CoffeeResource`/`VariantResource`/`RoasterResource` so the shape is defined once; `/api/v1` prefix; per-gram price persisted as a stored column for DB-side sort. This converts the directory from "works at 35 roasters" to "scales as designed," eliminates the firehose (H6), the contract drift, and the no-filter functional gap together.

5. **Add a data-safety + delivery-gate layer to the deploy.** A pre-`migrate --force` SQLite backup/snapshot of `/data` (the one mitigation that addresses the irreversible-migration risk, H8), plus gating `fly-deploy` on CI success and adding Pint `--test` + Larastan to the pipeline. Cheap, and it directly protects the only irreplaceable data (user tastings) while stopping red builds and whole classes of null/type bugs from reaching the unattended nightly import.

6. **Finish the cleanup that's already half-done.** Extract `RoasterImporter`'s text-cleaning into a dedicated normalizer, move the three route closures into `AdminRoasterController`, delete the dead legacy controller/views/`'unsupported'` status, and reconcile the developer-guide scraper contract with the code. Individually low-severity; collectively they're what makes recommendations 1–5 safe to execute quickly.
