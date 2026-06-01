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
fly launch --name coffee-tracker-api --region yyz --no-deploy
```

Edit `fly.toml` if needed (default Postgres + persistent volume for
SQLite if you stay on SQLite — but Postgres is safer for production).

Set secrets:
```
fly secrets set \
  APP_KEY=$(php artisan key:generate --show) \
  APP_URL=https://api.coffee.example.com \
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

Recommended (not yet set up):
- GitHub Actions on push: run `php artisan test` and `npm run vitest`
- Fail the merge if either suite fails
- Auto-deploy `main` to Fly.io + Vercel via their respective GitHub integrations

## Rollback

Fly:
```
fly releases       # find the previous release ID
fly deploy --image registry.fly.io/coffee-tracker-api:deployment-<id>
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

### Not yet wired (future workstreams)

- **Error tracking** — `spatie/laravel-ignition` is the dev error page only;
  no prod exception alerting yet. Add Sentry or Spatie Flare.
- **Ops notifications** — a daily summary email (roasters added, import
  errors, variant rejections) is not built; rejections currently surface
  only in the weekly digest, and additions nowhere.
