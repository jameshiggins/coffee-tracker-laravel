# The Backend, Explained Like You're Five

No jargon. This explains what the *backend* (the Laravel app in this folder)
actually does, using everyday comparisons. When a real name matters, it's in
`code font` so you can find it in the other docs.

If you remember one thing: **the backend is the back office of a coffee shop
that never sleeps.** Customers never walk into the back office. They see the
website (that's the *other* project, the React app). The back office quietly
keeps the shelves stocked, answers the website's questions, sends the mail, and
sets off an alarm when something breaks.

---

## The cast of characters

### 1. The big book of everything (the database)

Imagine a wall of labelled boxes. Each box holds index cards of one kind:

- **Roasters** — one card per coffee company (name, city, website, map pin).
- **Coffees** — one card per bean a roaster sells.
- **Variants** — the *sizes* of a bean: a 250g bag, a 1kg bag — each with its
  own price and "in stock?" tick. One coffee can have several variants.
- **Users** — people who signed up.
- **Tastings** — a user's notes/rating on a coffee.
- **Wishlist** — coffees a user wants to be told about when they're back.

In real life this "wall of boxes" is a single file called `database.sqlite`. In
production it lives on a little disk attached to the server so it survives
restarts.

### 2. The night-shift stock-checker (the importer)

Every night the back office sends one worker out to visit *every roaster's
website* and write down what's for sale and at what price. That worker is the
`RoasterImporter`.

Here's the clever part. Coffee shops build their websites with different kits —
some use Shopify, some WooCommerce, some plain HTML. So the worker carries a few
pairs of **reading glasses** (we call them *scrapers*): one for Shopify, one for
WooCommerce, and a generic pair for everything else. The worker tries them on
until one lets them read the menu, then remembers which pair worked for next
time.

**The golden rule: update the card, don't throw it out.** When the worker sees a
coffee that's already in our boxes, they *edit the existing card* instead of
making a new one. Why? Because your tasting notes are paper-clipped to that card.
Make a new card and your notes float away. And when a coffee disappears from a
roaster's site, the worker doesn't shred the card — they stamp it **"no longer
sold."** The card (and any notes on it) stays. (In the code this is the
"stable-ID upsert + soft-remove" pattern — the spine of the whole system.)

If a price looks impossible (a $0 bag, or $900/kg — usually a parsing mistake),
the worker *refuses to write it down* and drops a note in a "rejected" pile
(`scraper_rejection_log`) so we can see which sites changed their layout.

### 3. The waiter (the API)

When someone visits the website and asks *"show me light roasts in stock near
Edmonton,"* the website doesn't rummage through the boxes itself. It asks the
**waiter** (the API), who fetches exactly the right cards and brings them back.
The waiter only ever hands over neat answers — never the keys to the boxes.

### 4. The wristband (login)

When you log in, you get a **wristband** (a token). Every time you ask the waiter
for something that's *yours* (your wishlist, posting a tasting), you flash the
wristband. No wristband, no personal service. Lose it / log out and the wristband
is destroyed — it can't be reused. (This is "Sanctum bearer tokens.")

### 5. The row of alarm clocks (the schedule)

The back office has alarm clocks that go off on their own (the "cron" schedule):

- **11:00 every day** — send the stock-checker out (the nightly import).
- **11:30 every day** — email *you* the **daily ops summary**: what was added,
  what's broken, and whether the mail is flowing.
- **14:00 every day** — email customers whose wishlisted coffees came back.
- **Monday** — email you a deeper weekly health report.
- **1st of the month** — tidy up roaster street addresses / map pins.
- **every 5 minutes** — tick a "still alive" clock (more on this next).

### 6. The smoke detectors (monitoring)

This is the part you asked for: *how do I know when nothing's working?* Three
detectors, each watching a different kind of failure:

- **A button the robot presses every minute** (`GET /up`). It's a tiny page that
  answers "are the lights on?" — is the database reachable, and did the
  night-worker tick their 5-minute clock recently? If not, the page turns red
  (status 503) and an outside watchdog service emails/texts you. This catches the
  scary silent failure: *the server is up but the night-worker quietly died, so
  the shelves stop getting restocked.*
- **The daily email** (from clock #2 above). Its job is the *content* — "3 new
  roasters, 2 are failing, mail works." And a sneaky bonus: **if the daily email
  simply stops arriving, that itself is a warning** the mail or the scheduler
  broke.
- **A fire camera** (Sentry). If the code itself crashes mid-request, Sentry
  snaps a photo of exactly what went wrong and files it for you. (It only starts
  watching once you give it a key — see `deploy.md`.)

Together: the button watches the *building*, the email watches the *work*, the
camera watches the *code*.

### 7. The post office (email)

All outgoing mail — "confirm your email," "your coffee is back," the ops
reports — is handed to a post office called **Resend**, which actually delivers
it. The back office writes the letters; Resend stamps and mails them.

---

## A day in the life

```
11:00  Stock-checker visits ~35 roaster sites, updates the cards,
       marks sold-out beans "no longer sold", logs any junk prices.
11:30  You get the daily ops email: "Added 2, 1 failing import, mail OK."
14:00  Customers with back-in-stock wishlist beans get an email.
all day Every minute, a watchdog presses /up. Every 5 min the worker
       ticks its heartbeat. If either goes quiet → you get paged.
       Meanwhile the waiter answers website questions non-stop.
```

## If something looks wrong, where do I look?

- **"Is the site even up?"** → open `https://api.roastmap.ca/up`. Green/200 =
  healthy. Red/503 = database or scheduler is down.
- **"What happened today?"** → your daily ops email (or run it on demand:
  `php artisan reports:daily-ops --dry-run`).
- **"Did the code crash somewhere?"** → your Sentry dashboard (once configured).
- **"Is a specific roaster failing?"** → the admin pages at `/admin/roasters`
  show a red row, or the daily email names it.

## Want the grown-up version?

- `system-overview.md` — the same story with the real moving parts and arrows.
- `architecture.md` — *why* it's built this way (the design trade-offs).
- `developer-guide.md` — how to run and change it.
- `admin-guide.md` — your day-to-day operator controls.
- `deploy.md` — how it gets online, plus monitoring/alerting setup.
