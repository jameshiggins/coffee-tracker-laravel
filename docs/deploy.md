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
