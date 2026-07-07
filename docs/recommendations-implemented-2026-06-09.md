# Review Recommendations — Implementation Log (2026-06-09)

Implements the strategic recommendations + High/Medium findings from
`application-review-2026-06-09.md`. Branch: `review/implement-recommendations`.
Test suite: **493 passing** (baseline 451; +42 new tests), zero regressions.

## Done

### Rec 1 — Admin destroy now soft-removes (H1, data-loss)
- `Admin\CoffeeController::destroy` → sets `removed_at` (+ a `restore` action/route);
  `Admin\RoasterController::destroy` → sets `is_active=false`. No path hard-deletes,
  so the FK cascade that wiped user tastings/wishlists can no longer fire.
- New: `AdminAccessControlTest` (BasicAdminAuth 503/401/pass) + `AdminDestroyPreservesDataTest`
  (delete preserves tastings/wishlists). Added model factories (Roaster/Coffee/CoffeeVariant/
  Tasting/Wishlist) — previously only UserFactory existed.

### Rec 2 — SSRF-safe HTTP client (H2)
- `App\Services\Http\SsrfGuard` + `SafeHttp` + `BlockedUrlException`. Rejects private/
  loopback/link-local/reserved/CGNAT/IPv6-ULA addresses + non-http(s) schemes, on the
  initial request (request middleware) **and every redirect hop** (`on_redirect`). Every
  scraper + the geocoder routed through `SafeHttp::client()`.
- Folded in: Nominatim ≥1 req/s pacing; ShopifyScraper pagination (H4); multipack-variant
  reject in Woo/Squarespace (was Shopify-only); OriginGazetteer word-boundary matching.
- New: `SsrfProtectionTest`, `ShopifyPaginationTest`.

### Rec 3 — Real queue + worker (H7)
- `database` queue + `jobs`/`job_batches` migration; `ImportRoasterJob` (ShouldQueue);
  admin import/refresh dispatch it instead of running inline. The 3 ops mailables are
  `ShouldQueue`. `queue:work` added to `docker/entrypoint.sh`; `QUEUE_CONNECTION=database`
  in `fly.toml`. New: `AdminImportDispatchTest`.

### Rec 4 — Bean-centric API (H5/H6) — see "Deferred" for the contract-bound parts
- H5: `GET /api/roasters/{slug}` and `/api/coffees/{id}` now 404 for inactive roasters.
- H6: `/api/roasters` + `/api/stats` cached on a content-version key (auto-busts on any
  write; bypassed in tests).
- New `GET /api/coffees`: paginated, filterable (origin/process/roast/in-stock/price),
  sortable, via `CoffeeResource`/`VariantResource`. Persisted `coffees.best_cents_per_gram`
  (indexed, maintained by the importer + backfilled) for DB-side price sort/filter.
  Public tasting feed capped at 100. New: `CoffeeDirectoryApiTest`.

### Rec 5 — Deploy data-safety + CI gate (H8)
- `entrypoint.sh` snapshots `/data/database.sqlite` to `/data/backups/` (keep 7) BEFORE
  `migrate --force`. `fly-deploy.yml` now gated on the `CI` workflow succeeding. Deploy
  doc updated (backups, restore steps, stale CI section fixed).

### Rec 6 — Cleanup
- Import/refresh/geocode route closures → `AdminRoasterController` methods (+ geocode now
  stamps `address_source='manual'`). Deleted dead legacy `RoasterController` + its 2 views.
  Removed phantom `'unsupported'` status everywhere. Deduped `sanitizeUtf8` →
  `Shared::sanitizeUtf8`. Fixed `import()` PHPDoc. Reconciled the developer-guide scraper
  contract (real keys: `grams`/`available`/`source_id`; registration via the registry
  constructor). Extracted `RoasterImporter`'s ~150 lines of text-cleaning into a new
  `CoffeeTextNormalizer`.

### Quick wins / Medium (security/correctness/data)
- Empty `source_id` → NULL at import (prevents unique-index crash); stock-aware
  `best_price_per_gram`; login/register throttling (`throttle:login`/`register`); Sentry
  request-body scrubbing (`max_request_body_size=none` + `before_send`); `TrustProxies='*'`;
  `before_or_equal:today` on `tasted_on`; Google OAuth `display_name` slugify+collision +
  try/catch (H3) + verified-email link gate + avatar non-clobber; performance indexes
  migration; CORS pinned to `FRONTEND_URL`; Sanctum token 90-day expiration.

## Deferred (with rationale)

These were intentionally **not** implemented because they would break the out-of-repo
React SPA's API contract (which this repo cannot update) or require offline-unavailable
tooling. Each is a safe, separate follow-up:

- **`/api/v1` prefix + unified error envelopes + refactoring existing endpoints onto
  Resources.** Moving routes or changing response/error shapes breaks the live React
  client. The new `/api/coffees` endpoint already uses Resources; migrate the client, then
  version + unify.
- **Pint `--test` + Larastan in CI.** The codebase uses a deliberate house style that
  differs from Pint defaults (a `pint --test` gate would force a repo-wide reformat first),
  and Larastan can't be added without network access to update `composer.lock`.
- **OAuth token delivery channel (URL query → fragment/one-time code).** Cross-repo
  contract; mitigated here by adding a Sanctum token expiration to bound exposure.
