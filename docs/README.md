# Specialty Coffee Roasters — Documentation

Source content for the public docs site. Plain Markdown so it can be rendered
by Docusaurus, MkDocs, or any other static site generator at deploy time.

## Sections

**Understand the system**

- [System overview](system-overview.md) — how the whole thing works: the two apps, the data model, and the key data flows (visitor request, nightly import, scheduling, observability)
- [Backend, explained like you're five](backend-eli5.md) — the plain-English, no-jargon tour of what the backend actually does
- [Architecture](architecture.md) — the *why*: high-level design decisions and trade-offs

**Run & operate it**

- [Admin guide](admin-guide.md) — for the directory operator (you): daily flow, adding roasters, moderation, and monitoring
- [Developer guide](developer-guide.md) — for contributors and the next maintainer: stack, layout, running locally, adding a scraper
- [Deployment](deploy.md) — shipping to Fly.io + Vercel, plus monitoring & alerting setup

**For visitors**

- [User guide](user-guide.md) — for people browsing the directory and tracking tastings
- [Privacy policy](../coffee-tracker-react/src/pages/Privacy.jsx) and [Terms](../coffee-tracker-react/src/pages/Terms.jsx) live in the React app
