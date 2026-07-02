# Deployment

Target stack: **Fly.io** for the Laravel API, **Vercel** for the React app
and Docusaurus docs, **Cloudflare** for DNS, **Resend** for transactional
email.

This file is a checklist, not an automated script — most of these steps
require user accounts and credentials that have to be set up by hand the
first time.

## Prerequisites

You need accounts on:
- [Fly.io](https://fly.io) — API hosting
- [Vercel](https://vercel.com) — frontend + docs hosting
- [Cloudflare](https://cloudflare.com) — DNS
- [Resend](https://resend.com) — transactional email
- A domain registrar with the domain you want to use (e.g. Cloudflare Registrar)

Plus CLI tools installed locally:
- `flyctl` — `iwr https://fly.io/install.ps1 -useb | iex`
- `vercel` — `npm i -g vercel`

## DNS first

Point the domain at Cloudflare:
1. Add the domain to Cloudflare → copy the two NS records
2. Update nameservers at the registrar to those NS records
3. Wait for propagation (typically <1 hour)

Plan three subdomains:
- `coffee.example.com` — React app (Vercel)
- `api.coffee.example.com` — Laravel API (Fly.io)
- `docs.coffee.example.com` — Docusaurus (Vercel)

## Resend setup

1. Create the project, add the sending domain
2. Add the DKIM CNAME records to Cloudflare
3. Wait for verification (Resend will email you when ready)
4. Generate an API key — keep it for the Fly.io secrets step

## Fly.io API deploy

In `coffee-tracker-laravel/`:

```
fly launch --name coffee-tracker-laravel --region yyz --no-deploy
```

(The name must match `app` in `fly.toml` — the live app is
`coffee-tracker-laravel`; an earlier draft of this doc said
`coffee-tracker-api`, which broke the rollback runbook below.)

Edit `fly.toml` if needed. **This project runs SQLite in production**, on a
Fly persistent volume mounted at `/data` (`docker/entrypoint.sh` runs
`php artisan migrate --force` on every boot). Postgres is a reasonable
alternative if you'd rather not depend on a single volume, but it is *not*
what's wired today — switching would mean changing `DB_CONNECTION` and the
volume setup.

Set secrets:
```
fly secrets set \
  APP_KEY=$(php artisan key:generate --show) \
  APP_URL=https://api.coffee.example.com \
  MAIL_MAILER=resend \
  RESEND_KEY=re_... \
  MAIL_FROM_ADDRESS=hello@coffee.example.com \
  MAIL_FROM_NAME="Specialty Coffee Roasters" \
  SANCTUM_STATEFUL_DOMAINS=coffee.example.com \
  CRON_FAILURE_EMAIL=ops@coffee.example.com \
  GOOGLE_CLIENT_ID=... \
  GOOGLE_CLIENT_SECRET=... \
  GOOGLE_REDIRECT_URI=https://api.coffee.example.com/auth/google/callback
```

Deploy:
```
fly deploy
fly ssh console -C "php artisan migrate --force"
fly ssh console -C "php artisan db:seed --force"   # only on first deploy if seeding
```

Add the cron schedule via Fly's machine scheduler or a `[processes]` block:
```toml
[processes]
  app = "php-fpm"
  cron = "php artisan schedule:work"
```

Map the custom domain:
```
fly certs add api.coffee.example.com
```
Add the Cloudflare records that `fly certs show` prints.

## Vercel React deploy

In `coffee-tracker-react/`:

```
vercel link
vercel env add VITE_API_BASE
# value: https://api.coffee.example.com
vercel --prod
vercel domains add coffee.example.com
```

Make sure to update `src/api.js` to read `import.meta.env.VITE_API_BASE`
instead of the hardcoded `http://localhost:8000/api`. (Currently
hardcoded — will fail in production until fixed.)

## Vercel Docusaurus deploy

If you want a separate docs site:
```
cd ../coffee-tracker-docs
npx create-docusaurus@latest . classic --typescript=false
# Replace ./docs/intro.md with content from coffee-tracker-laravel/docs/
vercel --prod
vercel domains add docs.coffee.example.com
```

Or skip Docusaurus entirely and serve the markdown via the Vercel static
file server — your call.

## Smoke test

After deploy, hit:
- `GET https://api.coffee.example.com/api/roasters` → 200 with roaster list
- `GET https://coffee.example.com/` → React app loads
- `POST https://api.coffee.example.com/api/auth/register` → 201 with token
- Check email actually arrives (verification link)
- Sign in via Google → callback succeeds, token issued
- Manually run import: `fly ssh console -C "php artisan roasters:import-all"`
- Confirm scheduled jobs are running: `fly logs -i <machine-id> | grep schedule`

## CI

GitHub Actions (`.github/workflows/ci.yml`) runs on every push/PR to `main`:
- `php artisan test` against SQLite (migrations + full suite)

Deploy is **gated on CI**: `.github/workflows/fly-deploy.yml` triggers on the
`CI` workflow completing, and only runs `flyctl deploy` when that run's
conclusion was `success` — so a red build can't ship.

Recommended next steps (not yet wired): static analysis (Larastan) and a code
style check. NOTE: the codebase follows a deliberate house style that differs
from Laravel Pint's defaults, so a `pint --test` gate would need a project
`pint.json` (or a one-time repo-wide reformat) first — don't add it blindly.

## Rollback

**Database snapshots.** The boot script (`docker/entrypoint.sh`) writes a
timestamped copy of `/data/database.sqlite` to `/data/backups/` BEFORE running
`migrate --force`, keeping the 7 most recent. To recover from a bad migration:
```
fly ssh console
ls -1t /data/backups/                       # pick the snapshot from before the deploy
cp /data/backups/database-<ts>.sqlite /data/database.sqlite
```
An image rollback alone does NOT undo a schema migration already applied to the
volume — restore the snapshot too.

Fly:
```
fly releases -a coffee-tracker-laravel       # find the previous release ID
fly deploy --image registry.fly.io/coffee-tracker-laravel:deployment-<id> -a coffee-tracker-laravel
```

Vercel:
```
vercel rollback   # uses the dashboard's previous deployment
```

## Monitoring & alerting

The API exposes a health probe and emits two in-app liveness signals. The
goal: if the site goes down, the scheduler stops, or mail breaks, you find
out from a monitor — not from a user.

### Health probe — `GET /up`

`https://api.roastmap.ca/up` returns JSON and an HTTP status:
- **200 `healthy`** — database reachable and the scheduler is ticking.
- **503 `degraded`** — database unreachable, or the scheduler hasn't checked
  in for ~15 min (i.e. `schedule:work` died). These are the only two things
  that flip the status — a 503 means "page someone now."

The body also reports **informational** checks that never cause a 503:
`mail.last_sent` (when the transport last accepted a message) and
`imports.errors` (active roasters whose last import failed). Acting on those
is the weekly digest's job, not an uptime page. No secrets are in the body,
so it's safe to expose unauthenticated.

### External uptime monitor (you set this up — ~10 min)

Create a free monitor on [UptimeRobot](https://uptimerobot.com) or
[Better Stack](https://betterstack.com/uptime) and add two HTTP checks:
- `https://api.roastmap.ca/up` — treat any non-200 as down (catches API,
  database, **and** a dead scheduler in one check).
- `https://roastmap.ca/` — the React app is up.

Point alerts at your email / SMS / Slack. Done.

### How "is the scheduler alive?" works

`schedule:work` (started in `docker/entrypoint.sh`) bumps a `scheduler.tick`
heartbeat every 5 minutes via `App\Models\SystemHeartbeat`. The entrypoint
also seeds one tick at boot so `/up` doesn't false-alarm right after a
deploy. If the scheduler process dies, the tick goes stale and `/up` flips to
503 — which your uptime monitor then alerts on.

### Optional per-job cron pings (healthchecks.io)

For per-job granularity ("did the **daily import** specifically run and
succeed?"), create checks on [healthchecks.io](https://healthchecks.io) and
set their ping URLs as Fly secrets:
```
fly secrets set \
  HEALTHCHECK_IMPORT_URL=https://hc-ping.com/<uuid> \
  HEALTHCHECK_DIGEST_URL=https://hc-ping.com/<uuid> \
  -a coffee-tracker-laravel
```
The scheduler pings the base URL on success and `{url}/fail` on failure
(`config/services.php → healthchecks`). Unset = disabled; the in-app
`scheduler.tick` behind `/up` still covers overall liveness.

### Verify production mail is actually configured

The single highest-value check. Mail only works if these are set on Fly:
```
fly secrets list -a coffee-tracker-laravel   # names only; confirm presence
# expect: MAIL_MAILER (=resend), RESEND_KEY, MAIL_FROM_ADDRESS, CRON_FAILURE_EMAIL
```
Send a live test, then confirm `mail.last_sent` updates on `/up`:
```
fly ssh console -a coffee-tracker-laravel -C \
  "php artisan tinker --execute='Mail::raw(\"roastmap mail test\", fn(\$m)=>\$m->to(\"you@example.com\")->subject(\"test\"));'"
```

### Error tracking — Sentry (you set this up — ~5 min)

Uncaught exceptions are reported to [Sentry](https://sentry.io) from
`app/Exceptions/Handler.php` via `Sentry\Laravel\Integration`. The SDK is inert
until a DSN is set, so nothing leaves dev or a key-less deploy. To turn it on in
production:
1. Create a free Sentry account → new project → platform **Laravel**.
2. Copy the DSN (Project → Settings → Client Keys / SDK Setup).
3. Set it as a Fly secret:
   ```
   fly secrets set SENTRY_LARAVEL_DSN=https://...ingest.sentry.io/... -a coffee-tracker-laravel
   ```
4. Add an alert rule in Sentry (e.g. email on every new issue) so a crash pages
   you instead of only showing a user a 500.

Defaults (`config/sentry.php`): captures 100% of errors, performance tracing
**off** (no quota surprises — set `SENTRY_TRACES_SAMPLE_RATE` to enable),
`send_default_pii` off, and the GET /up probe excluded so uptime polling never
floods the feed. Verify after setting the secret:
```
fly ssh console -a coffee-tracker-laravel -C "php artisan sentry:test"
```

### Ops notifications — daily + weekly digests (wired)

- **Daily** (`reports:daily-ops`, 11:30 UTC): roasters added in the last 24h,
  active roasters failing import (with the error message), outstanding variant
  rejections, and mail-delivery confirmation. Sends every day — its reliable
  arrival is itself the "mail + scheduler are alive" signal. `--only-when-notable`
  suppresses all-clear days; `--dry-run` prints the JSON instead of sending.
- **Weekly** (`reports:weekly-digest`, Mon 13:00 UTC): the fuller data-quality
  audit (imports, dropped variants, likely duplicates, address gaps).
- Both email the ops address (`mail.from.address`; override with `--email`).

### Still optional (not wired)

- **Sentry release tagging** — `SENTRY_RELEASE` is unset, so errors aren't
  grouped by deploy. Wire the git SHA through the Docker build to enable it.
- **Performance tracing / profiling** — off by default; enable via
  `SENTRY_TRACES_SAMPLE_RATE` if you ever want latency data.
