# Home Terboekt — Architecture (pre-implementation audit)

This document describes the **existing** Direct Property Booking and Management System target: the live Home Terboekt marketing website. Implementation agents must integrate into this architecture. Do not invent a parallel app, rewrite working pages, or assume a Node/React/ORM stack that is not present.

**Phase 2 (booking backend) is implemented** — see [Phase 2 — booking backend](#phase-2--booking-backend) at the bottom for paths, migrations, and configuration.

**Audit date:** 2026-09-13  
**Repo:** `https://github.com/Xeno9948/terboekt.git` (branch `main`)  
**Live site:** `https://hometerboekt.be`  
**Property:** Home Terboekt, Terboekt 28, 3600 Genk, Belgium — luxury holiday home, 4 bedrooms / 8 guests.

---

## 1. Stack summary

| Layer | What exists |
|---|---|
| Framework / language | **None.** Static HTML5 + vanilla JavaScript (ES2015+) + CSS3. No React, Vue, Next, PHP app, Python app, or TypeScript. |
| Package manager | **None at repo root.** No root `package.json`, lockfile, or build step. |
| Frontend | Multi-page site under `public/`. Shared chrome injected by JS. i18n via `data-i18n`. |
| Backend / API | **None.** No server routes, no REST/GraphQL, no `fetch()` in the live site JS. |
| Database / ORM | **None.** No schema, Prisma, SQL, SQLite, or CMS. |
| Hosting | Railway (`Xeno9948/terboekt` → `main`). Domain `hometerboekt.be` is forwarded to Railway. MySQL lives on Railway. |
| Auth | **None.** |
| Email | Client `mailto:` to `info@hometerboekt.be`. Formspree is documented in README but **not implemented**. |
| Jobs / cron | **None.** |
| Tests | **None** for the live site. |
| Env vars | **None.** No `.env`, `process.env`, or config loader. |

**Do not start from a SPA/framework scaffold.** Booking features must be HTML/CSS/JS pages (and, if a backend is required, a thin addition that the current host can run — typically PHP + MySQL on Combell — not a disconnected Node app).

---

## 2. Framework and language

- **Language:** HTML, CSS, vanilla JS. Default page language `nl` (`<html lang="nl">`).
- **Runtime:** Browser only. Local preview: `cd public && python3 -m http.server 8000` (`README.md`).
- **Build:** None. Files are served as written.
- **Editor tooling (not runtime):**
  - Pinegrow: `pinegrow.json` (`active-design-provider: plainhtml`, stylesheet `assets/css/style.css`).
  - VS Code: `.vscode/settings.json` (Piny). SFTP extension config is gitignored (see Hosting).
- **Vendored but unused:** `public/editor/` is a full **VvvebJS** drag-and-drop builder (Apache-2.0, `public/editor/package.json` name `vvvebjs`). It includes PHP save/upload scripts, Express, Gulp, Bootstrap 5 demos, and Docker. **It is not referenced by `index.html` / `genk.html` / `contact.html` and is not in git.** Do not treat VvvebJS, Bootstrap 5, or its PHP editor as the application stack. Do not deploy `public/editor/` to production (write endpoints, no auth).

---

## 3. Frontend architecture

### Pages (the application)

| Path | Role |
|---|---|
| `public/index.html` | Home: hero, property copy, rooms, practical info, map, gallery, **hardcoded rates**, CTA to book |
| `public/genk.html` | Area guide |
| `public/contact.html` | Booking request form + rate recap |

Every page loads, in this order:

1. `components/header.js`
2. `components/footer.js`
3. `assets/js/translations.js`
4. `assets/js/gallery.js` (home + genk only)
5. `assets/js/script.js`

### Shared chrome

- Placeholders: `#header-placeholder`, `#footer-placeholder`.
- `public/components/header.js` injects `<header>` (logo, nav, NL/EN/FR/DE switcher, “Reserveer nu”). Marks current page with `.active` / `aria-current`.
- `public/components/footer.js` injects `<footer>` then dispatches **`components:ready`**.
- `script.js` and `translations.js` boot on `DOMContentLoaded` **and** `components:ready` (header/footer are not in the initial HTML).

### Scripts are classic, not modules

Scripts have **no `type="module"`**. There is no bundler, no `import`/`export`. New JS must either:

- stay global functions on the page, or
- add a single new `<script src="...">` in the same pattern.

### i18n

- Dictionary: `public/assets/js/translations.js` → `const translations = { nl, en, fr, de }`.
- Persistence: `localStorage` key **`lang`**, default `'nl'`.
- Markup: `data-i18n="key"` (sets `textContent`); `data-i18n-placeholder` for placeholders.
- APIs to reuse: `applyTranslations(lang)`, `setLanguage(lang)`, `bindLanguageSwitcher()`.
- After injecting new DOM, call `applyTranslations(currentLang)` (or rely on `components:ready`).
- **Prices are not i18n keys.** Amounts are hardcoded in HTML (`€ 800`, etc.). Labels (`rate_weekend`, `low`, `high`, `fee_*`) are translated.

### Navigation contract

Header/footer links:

- `index.html` — Home (`nav_home`)
- `genk.html` — Bezoek Genk (`nav_genk`)
- `contact.html` — Boeken (`nav_contact`) + CTA `cta_book`

Any booking UI that is not `contact.html` **must** update both `header.js` and `footer.js` (and translation keys). Relative `.html` URLs, not client-side routers.

### External CDN (do not replace without reason)

- Google Fonts: Inter (400–700), Playfair Display (600–700)
- Font Awesome 6.5.1 (`cdnjs`) — icons via `<i class="fa-solid ...">`

### Images

- `public/assets/img/` — many JPEGs + `c-mine-terril.webp`. Referenced with kebab-case names (README’s WhatsApp filenames are stale).

---

## 4. Backend / API architecture

**There is no application backend.**

Live-site JS does not call HTTP APIs. The only “submit” is `window.location.href = mailto:...` in `initBookingForm()` (`public/assets/js/script.js`).

If booking needs persistence, payments, calendar blocking, or admin:

- That infrastructure **does not exist** and must be added.
- Prefer a **thin backend on the existing Combell host** (PHP endpoints next to `public/`, or a small `api/` folder uploaded to the same document root) over a separate Node/Vercel/Cloudflare app that would split deploy and design.
- Do not use `public/editor/save.php` / `upload.php` as a booking API.

---

## 5. Database and ORM

**None.** Property copy, rates, fees, and seasons live in HTML + `translations.js`.

A booking system will need a datastore. None is chosen. When adding one, keep a single source of truth for rates (today they are duplicated in `index.html` and `contact.html`).

---

## 6. Hosting and deployment

| Fact | Value |
|---|---|
| Domain | `https://hometerboekt.be` (also in OG tags on `index.html`) |
| Host | Combell / `webhosting.be` |
| SFTP host | `ftp.hometerboektbe.webhosting.be` |
| Protocol / port | SFTP, port 21 (as configured locally) |
| Remote path | `/` (document root) |
| What to upload | Contents of `public/` (HTML, `assets/`, `components/`) |
| CI/CD | None (no GitHub Actions, Netlify, Vercel, wrangler, Docker for the live site) |
| Local deploy config | `.vscode/sftp.json` — **gitignored** (`.gitignore`). Contains plaintext credentials. Never commit it. Never copy secrets into this repo. |

README still says “FTP/cPanel”; the editor config is SFTP.

Combell shared hosting typically supports **static files + PHP + MySQL + cPanel cron**. The current site uses **static files only**. Confirm PHP/MySQL/cron in the hosting panel before depending on them.

---

## 7. Existing authentication

**None.** No login, sessions, cookies (except whatever the browser uses for `localStorage`), JWT, OAuth, or password flows.

Admin for bookings would be net-new. Do not reuse VvvebJS `public/editor/` as admin (unauthenticated file write).

---

## 8. Existing email

| Mechanism | Status |
|---|---|
| `mailto:info@hometerboekt.be` | **Live.** Footer link + booking form (`script.js`). Subject: `Reservatieaanvraag Home Terboekt`. Body: name, email, phone, checkin, checkout, guests, message. |
| Formspree | **Not wired.** `README.md` still says replace `YOUR_FORM_ID` in `contact.html`. The form has `id="booking-form"` and `novalidate` — **no `action`**. |
| SMTP / transactional API | **None** (no Resend, SendGrid, Mailgun, PHP `mail()`, etc.). |

Owner inbox: **`info@hometerboekt.be`**.

---

## 9. Scheduled jobs / cron

**None** in the repo. No GitHub Actions, workers, or crontab files.

If reminders, expiry of holds, or iCal sync are needed, jobs must be added (Combell cron hitting a PHP/HTTP endpoint is the host-aligned option).

---

## 10. Design system and component library

**Custom CSS design system** in `public/assets/css/style.css`. Not Tailwind, not Bootstrap (except unused VvvebJS vendor). Not a JS component library.

### Tokens (`:root`)

```css
--color-primary: #141414;
--color-secondary: #f6f4f0;
--color-accent: #9a7340;
--color-accent-dark: #7a5a2e;
--color-text: #1c1c1c;
--color-text-muted: #3f3f3f;
--color-white: #ffffff;
--color-line: rgba(20, 20, 20, 0.1);
--font-main: "Inter", system-ui, -apple-system, sans-serif;
--font-heading: "Playfair Display", Georgia, serif;
--spacing-container: 1120px;
--spacing-padding: 1.5rem;
--header-height: 72px;
--radius: 14px;
--shadow: 0 14px 40px rgba(0, 0, 0, 0.1);
--transition: 180ms ease;
```

### Reuse these classes (do not invent a second visual language)

| Category | Classes |
|---|---|
| Layout | `.container`, `.split`, `.split.reverse`, `.soft`, `.dark`, `.text-center`, `.mb-3`, `.mb-4` |
| Type | `h1–h4`, `.lead`, `.section-label` / `.section-badge` / `.section-kicker`, `.caption`, `.note` |
| Buttons | `.btn .btn-primary`, `.btn-secondary`, `.btn-outline`, `.btn-link`, `.btn-nav` |
| Cards / grids | `.usp-card`, `.room-card`, `.price-card`, `.village-card`, `.card`, `.price-grid`, `.usp-grid`, `.room-grid`, `.cta-band` |
| Lists | `.checklist`, `.facts` / `.fact`, `.location-list` |
| Forms | `.contact-layout`, `.form-card`, `.form-group`, `.form-row`, `.checkbox`, `.form-error`, `.form-success`, `.form-help` |
| Heroes | `.hero`, `.page-hero`, `.hero-content`, `.hero-buttons` |
| Misc | `.photo-grid`, `.lightbox`, `header.scrolled` |

Breakpoints: **980px**, **768px**. Respect `prefers-reduced-motion`.

**CSS present but unused on live pages** (safe to reuse): `.reviews-head`, `.review-card`, `.review-score`, `.map-card`, `.include-grid`, `.media-frame`. Prefer these over new one-off styles.

Form controls: `input, select, textarea` are globally styled (border `#cfc8bc`, radius 10px, accent focus ring).

---

## 11. Existing forms

**One form:** `#booking-form` in `public/contact.html`.

Handler: `initBookingForm()` in `public/assets/js/script.js`.

| Field `name` / `id` | Type | Required | Notes |
|---|---|---|---|
| `name` | text | yes | `autocomplete="name"` |
| `email` | email | yes | |
| `phone` | tel | no | empty → `'—'` in mailto body |
| `checkin` | date | yes | `min` = today |
| `checkout` | date | yes | `min` = today; must be **>** checkin |
| `guests` | select 1–8 | yes | default **8** |
| `message` | textarea | no | |
| `rules` | checkbox | yes | links to Beaunita terms |

Validation: HTML5 `checkValidity()` / `reportValidity()`, plus checkout > checkin. Errors: `.form-error.show`. Success: `.form-success.show` then `form.reset()`. Submit is **preventDefault** + mailto (no server).

House rules URL (also footer + home):  
`https://www.beaunita.be/huur-en-boekingsvoorwaarden/`

---

## 12. Existing pricing / property content

### Capacity and stay rules (HTML + i18n)

- 4 bedrooms, **8 persons** (`feat_beds`)
- Check-in from **16:00**, check-out before **10:00**
- Pets not allowed; no parties
- Linen included; keybox access; parking up to 4 cars; wheelchair accessible
- Heated outdoor pool **1 Apr – 30 Sep**, weather dependent

### Rates (hardcoded in `index.html` and summarized on `contact.html`)

| Package | Nights / pattern | Low season | High season |
|---|---|---|---|
| Weekend | 2 nights, Fri–Sun | € 800 | € 900 |
| Extended weekend | Fri–Mon | € 1.100 | € 1.250 |
| Midweek | 4 nights, Mon–Fri | € 1.250 | € 1.500 |
| Week | 7 nights, Fri–Fri | € 2.100 | € 2.400 |

**Fees (also i18n `fee_*`):**

- Sunday evening extra until 19:00: **€ 150**
- Mandatory final cleaning: **€ 100**
- Tourist tax: **€ 1,50 per person per night**
- Deposit: **€ 500**

**Seasons (copy, not code):**

- High: April–September **and all school holidays**
- Low: November–March
- **October is unspecified** in copy — do not silently invent a season.

Included amenities: i18n keys `inc_1`–`inc_8` on `contact.html`.

**Do not keep three copies of prices.** Today HTML is the source of truth; booking logic should centralize rates (e.g. `public/assets/js/pricing.js`) and have pages read that object, or a future API.

---

## 13. Existing admin functionality

**None** for the live site. Content is edited in files (Pinegrow / IDE) and uploaded via SFTP.

Do not productize `public/editor/` as the CMS.

---

## 14. Environment-variable handling

**None.** No `.env*`, no secrets manager.

The only local secret file found: gitignored `.vscode/sftp.json` (SFTP username/password).

When adding a backend, introduce env/config **outside the web root or gitignored**, e.g. Combell panel env, or a PHP config file not in git. Do not put API keys in `public/assets/js/`.

Browser-only config that exists: `localStorage.lang`.

---

## 15. Tests and testing framework

- Live site: **no tests**, no Jest/Vitest/Playwright/Cypress.
- `public/editor/` has Bootstrap Sass jasmine helpers and `"test": "echo \"Error: no test specified\""` — **irrelevant**.

If tests are added, keep them at repo root (`tests/` or `e2e/`) and do not couple them to VvvebJS.

---

## Reusable existing components / services

Use these; do not duplicate.

| Piece | Path | Reuse how |
|---|---|---|
| Design tokens + UI classes | `public/assets/css/style.css` | New booking/admin screens |
| Header chrome + nav | `public/components/header.js` | Same inject pattern; update links if new routes |
| Footer chrome | `public/components/footer.js` | Same; keep `components:ready` |
| i18n | `public/assets/js/translations.js` | Add keys for NL/EN/FR/DE; `data-i18n` |
| Booking form UX | `public/contact.html` + `initBookingForm()` in `public/assets/js/script.js` | Extend fields/submit; keep form CSS |
| Lightbox | `public/assets/js/gallery.js` | Property photos |
| Page shell | `index.html` / `contact.html` | Fonts, FA, CSS, placeholders, script order |
| Rate presentation | `.price-grid` / `.price-card` | Calendar quote UI |
| CTA band | `.cta-band` | Confirmation / next-step |
| Property facts | `public/index.html` | Capacity, check-in/out, house rules URL |

---

## Shared types / interfaces (proposed from existing patterns)

There is no TypeScript. Use these shapes in JS (JSDoc optional) so APIs and forms stay aligned with `#booking-form`.

```js
/**
 * Guest booking request — field names MUST match #booking-form.
 * @typedef {Object} BookingRequest
 * @property {string} name
 * @property {string} email
 * @property {string} [phone]
 * @property {string} checkin   // YYYY-MM-DD
 * @property {string} checkout  // YYYY-MM-DD, strictly after checkin
 * @property {string} guests    // "1"…"8"
 * @property {string} [message]
 * @property {boolean} rules    // must be true
 */

/**
 * @typedef {'nl'|'en'|'fr'|'de'} Lang
 * localStorage key: "lang"
 */

/**
 * @typedef {'weekend'|'extended'|'midweek'|'week'} StayPackage
 */

/**
 * @typedef {Object} RateCard
 * @property {StayPackage} id
 * @property {number} nights
 * @property {number} lowEuros
 * @property {number} highEuros
 */

/**
 * Canonical rates (today duplicated in HTML — centralize here when implementing).
 * @type {RateCard[]}
 */
const RATES = [
  { id: 'weekend',  nights: 2, lowEuros: 800,  highEuros: 900 },
  { id: 'extended', nights: 3, lowEuros: 1100, highEuros: 1250 },
  { id: 'midweek',  nights: 4, lowEuros: 1250, highEuros: 1500 },
  { id: 'week',     nights: 7, lowEuros: 2100, highEuros: 2400 },
];

const FEES = {
  sundayEveningExtraEuros: 150,
  cleaningEuros: 100,
  touristTaxPerPersonPerNightEuros: 1.5,
  depositEuros: 500,
};

const PROPERTY = {
  name: 'Home Terboekt',
  address: 'Terboekt 28, 3600 Genk',
  email: 'info@hometerboekt.be',
  maxGuests: 8,
  bedrooms: 4,
  checkinFrom: '16:00',
  checkoutBefore: '10:00',
  houseRulesUrl: 'https://www.beaunita.be/huur-en-boekingsvoorwaarden/',
  currency: 'EUR',
};
```

Mailto body order today: `Naam`, `E-mail`, `Telefoon`, `Aankomst`, `Vertrek`, `Personen`, blank line, message. Keep that if mailto remains a fallback.

---

## Recommended folder / module boundaries

Keep the marketing site. Add booking as **adjacent pages and scripts**, not a new frontend.

```
public/                          # DEPLOY ROOT — existing site
  index.html                     # keep; CTA may point into booking flow
  genk.html                      # keep
  contact.html                   # keep as request/contact; or evolve into booking
  components/
    header.js                    # update nav if new pages
    footer.js
  assets/
    css/style.css                # extend; do not fork a second stylesheet
    js/
      script.js                  # site chrome + form boot
      translations.js            # all guest-facing strings
      gallery.js
      pricing.js                 # NEW: single rate/fee/season module
      booking/                   # NEW: guest booking UI logic only
        calendar.js
        quote.js
        checkout.js
    img/
  booking/                       # NEW optional: extra guest pages
    calendar.html
    confirm.html
  admin/                         # NEW if owner UI is required (auth first)
    index.html
    ...
  api/                           # NEW only if backend is added on this host
    availability.php             # example — not present today
    bookings.php
```

If the host cannot run PHP, put a **small** API in a sibling folder at repo root (`server/`) but still serve the same `public/` UI. Do not create a second visual app.

**Out of bounds**

- `public/editor/` — vendor, unused, unsafe to expose
- `_pginfo/`, `pinegrow.json` — design tool metadata
- `.vscode/sftp.json` — secrets

---

## Implementation constraints

Full checklist: [`IMPLEMENTATION_CONSTRAINTS.md`](./IMPLEMENTATION_CONSTRAINTS.md).

Non-negotiables:

1. Integrate into `public/` HTML/CSS/JS. No disconnected SPA.
2. Reuse `style.css` tokens and form/card/button classes.
3. Keep NL/EN/FR/DE via `translations.js` + `data-i18n`.
4. Keep header/footer inject + `components:ready` boot.
5. Do not rewrite working pages unless a booking flow requires it.
6. Do not deploy or depend on `public/editor/`.
7. No env vars exist; do not invent a toolchain that requires Vite/Next unless the team explicitly migrates hosting.
8. Contact email remains `info@hometerboekt.be` until a real mailer is added.
9. Centralize rates; HTML copies are not an API.
10. Max 8 guests; stay packages and fees above are business rules.

---

## README vs reality

`README.md` is partly stale:

- Formspree is **not** in `contact.html`.
- Image restore instructions refer to deleted WhatsApp filenames; current images are kebab-case in `public/assets/img/`.
- Structure omits `components/`, `gallery.js`, and i18n languages actually implemented (NL, EN, FR, DE).

Trust this file and the source over README for implementation.

---

## Phase 2 — booking backend

Implemented 2026-09-13. Thin **PHP 8 + PDO** on the same Combell document root. No Node, no React, no ORM. MySQL/MariaDB in production; **SQLite** for local migrate/tests (MySQL is not installed on the current dev machine).

### Why this stack

Combell shared hosting already serves `public/` over SFTP and typically runs PHP + MySQL. Putting a small PHP API under `public/api/` keeps one deploy and one origin. SQLite is only a local/dev fallback.

### Layout

```
.env.example                 # committed template — copy to .env (gitignored)
backend/                     # PHP domain layer (keep out of the web root if possible)
  bootstrap.php
  bin/migrate.php
  bin/create-admin.php
  bin/sync-calendars.php
  migrations/001_initial_schema.php
  migrations/002_seed.php
  src/                       # repositories, status, pricing, calendar, email, auth
  tests/run.php
public/assets/data/rates.json   # canonical published packages + fees (cents)
public/assets/js/pricing.js     # display helper; reads JSON / GET /api/rates
public/api/index.php            # JSON front controller
public/api/ical.php             # private iCal export (token)
public/api/cron.php             # calendar sync + expiry
public/admin/login.php          # session login (no default password)
public/admin/index.php          # stub, not the full dashboard
```

On Combell: upload `public/` contents to the document root **and** upload `backend/` next to that document root (`/backend`). `public/api/index.php` also looks for `../backend`. Deny HTTP to `/backend` (`.htaccess` already `Require all denied`). Put `.env` next to `backend/` or in the repo root — never under a JS folder.

### Configure

```bash
cp .env.example .env
# Set DATABASE_URL (mysql://… on Combell) or DB_HOST/DB_NAME/DB_USER/DB_PASSWORD
# Set AIRBNB_ICAL_URL (server-side only — never in frontend JS)
# Set ICAL_EXPORT_SECRET, APP_BASE_URL, APP_KEY
# SMTP_* and MANAGER_EMAIL when mail credentials exist
```

Airbnb iCal URL is read from `AIRBNB_ICAL_URL` at sync time. It is **not** stored in the database and not returned by API health payloads.

Booking.com: add a `calendar_connections` row later from admin (`POST /api/admin/calendars`) with `provider=booking_com`, `type=ical_import`, and a URL. No code change.

Bank details stay empty in `property_settings` until the manager fills them. Templates will say the manager will send transfer details rather than inventing an IBAN.

### Migrate

```bash
php backend/bin/migrate.php
```

Creates tables, seeds `deposit_percentage=30`, `deposit_deadline_days=7`, `currency=EUR`, published rate packages from `rates.json`, and an enabled Airbnb iCal connection.

Local without MySQL uses `sqlite:backend/storage/terboekt.sqlite` by default.

```bash
php backend/bin/create-admin.php --email=you@example.com --password='at-least-12-chars'
php backend/tests/run.php
php -S localhost:8080 -t public public/router.php
```

### Public APIs (no auth)

| Method | Path | Role |
|---|---|---|
| GET | `/api/health` | Liveness |
| GET | `/api/rates` | Published packages/fees |
| GET | `/api/availability?from=&to=` | Unavailable ranges + day map |
| GET | `/api/quote?checkin=&checkout=&guests=` | Server-side price breakdown (30% deposit) |
| POST | `/api/bookings` | Create **REQUESTED** hold (form field names: `name`, `email`, `phone`, `checkin`, `checkout`, `guests`, `message`, `rules`) |
| GET | `/api/ical?token=` | Private feed, `SUMMARY: Unavailable`, no guest PII |

### Admin APIs (session + CSRF + login rate limit)

`POST /api/admin/login`, `logout`, `GET /api/admin/me`, calendar refresh/health, booking list/transition, manual availability blocks, settings (including bank fields), `POST /api/admin/email/test` (does **not** send if SMTP env is empty).

`CONFIRMED` is only allowed when `actor_type=admin`. There is no public approval URL.

Cron (token): `GET/POST /api/cron/tick?token=` — expire overdue requests/deposits, refresh enabled iCal feeds. Failed iCal fetch **keeps** previously imported blocks (stale, never silently free).

### Domain rules honoured

- Stay dates are half-open `[check_in, check_out)`
- Money is integer cents (`Money::percent` for the 30% deposit)
- Statuses: `REQUESTED → AWAITING_DEPOSIT|REJECTED|EXPIRED|CANCELLED`; `AWAITING_DEPOSIT → CONFIRMED|REJECTED|EXPIRED|CANCELLED`; `CONFIRMED → CANCELLED`; terminals have no further transitions
- October is unspecified (not high/low) unless an admin date-range rule exists
- Huurwaarborg €500 is a separate refundable amount, not part of the 30% booking deposit
- No card payments

### Deferred (later phases)

- Full public booking UI (calendar widget / checkout pages) — APIs are ready; `contact.html` still uses mailto until wired
- Full admin dashboard (bookings table, rate editor, bank form) — login + APIs exist
- Combell MySQL credentials, SMTP credentials, and pasting `AIRBNB_ICAL_URL` into `.env`
- School-holiday calendar (not inventable from site copy)
- Nightly/default rate (not published on the site; package pricing is used)

---

## Phase 3 APIs

Hardened booking workflow, occupancy, and expiry (2026-09-13). Money remains integer cents. No card payments. 30% deposit. Bank details stay empty until the manager sets them. Guest-supplied prices are ignored. `CONFIRMED` is never automatic.

### Public

| Method | Path | Role |
|---|---|---|
| GET | `/api/availability?from=&to=` | Combined holds: iCal + CONFIRMED + REQUESTED + AWAITING_DEPOSIT + manual blocks. Half-open `[check_in, check_out)`. Stale/failed Airbnb sync marks the window unavailable (`calendar_reliable: false`) instead of treating gaps as free. |
| POST | `/api/bookings` | Validate guest + terms, **recalculate price server-side**, recheck availability in a transaction, create **REQUESTED**. `payment_due_at` = `created_at` + `deposit_deadline_days` (7). |
| POST | `/api/bookings/:id/confirm-transfer-intent` | Guest action → **AWAITING_DEPOSIT**. Body must include matching `email`. Wrong email → 404 (no PII leak of other bookings). |

`:id` is numeric id or `BOOK-…` reference.

### Admin (session + CSRF)

| Method | Path | Role |
|---|---|---|
| POST | `/api/admin/bookings/:id/confirm-deposit` | → **CONFIRMED** (manager only) |
| POST | `/api/admin/bookings/:id/reject` | → **REJECTED**, releases dates |
| POST | `/api/admin/bookings/:id/cancel` | → **CANCELLED**, releases dates |
| POST | `/api/admin/bookings/:id/extend-deadline` | Body `{ "days": n }` or `{ "payment_due_at": "…" }` |
| POST | `/api/admin/bookings/:id/release-hold` | Pending holds only → CANCELLED + occupancy released |
| PATCH | `/api/admin/bookings/:id` | Date change revalidates availability; guest count; admin price adjust; internal notes. Audited. |

Status changes go through `BookingStatusService` and `booking_status_history` + `booking_audit_log`. Emails are attempted via `EmailService`; a send failure does **not** roll back the booking.

### Concurrency

`occupancy_nights.stay_date` is unique. Hold creation uses a DB transaction (`BEGIN IMMEDIATE` on SQLite; mutex `SELECT … FOR UPDATE` on MySQL) plus occupancy inserts so two simultaneous POSTs cannot keep the same nights.

### Cron / expiry

Idempotent job: **REQUESTED** or **AWAITING_DEPOSIT** with `payment_due_at` (fallback `deposit_due_at`) in the past → **EXPIRED**, occupancy released, guest `booking_expired` email, manager notify.

Combell (every 15 minutes), token = `CRON_SECRET` (or `ICAL_EXPORT_SECRET`):

```
GET https://hometerboekt.be/api/cron.php?token=YOUR_CRON_SECRET
```

Equivalent: `GET /api/cron/tick?token=…` or CLI `php backend/bin/cron.php` (no HTTP token; do not expose that script on the web).

