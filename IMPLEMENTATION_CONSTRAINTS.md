# Implementation constraints — Direct booking on Home Terboekt

Read [`ARCHITECTURE.md`](./ARCHITECTURE.md) first. This is the short contract for every implementation agent.

The live product is a **static HTML/CSS/vanilla JS** site in `public/`, deployed by **SFTP** to Combell (`https://hometerboekt.be`). There is **no** React/Next, **no** root `package.json`, **no** API, **no** database, **no** auth, **no** cron, **no** env vars, and **no** tests.

## Must do

- Add booking UI **inside** `public/`, using existing page shell, fonts, Font Awesome, and `assets/css/style.css` tokens/classes (`.btn`, `.form-card`, `.price-card`, `.container`, `.cta-band`, etc.).
- Keep the inject pattern: `#header-placeholder` / `#footer-placeholder`, `components/header.js`, `components/footer.js`, event `components:ready`.
- Keep classic `<script src>` tags (not ES modules unless you update every page consistently).
- Localize every new guest string in **nl, en, fr, de** in `public/assets/js/translations.js` with `data-i18n`. Persist language with `localStorage` key `lang`.
- Reuse `#booking-form` field names: `name`, `email`, `phone`, `checkin`, `checkout`, `guests` (1–8), `message`, `rules`.
- Treat current HTML rates/fees as business rules until centralized (see `ARCHITECTURE.md` shared types). One source of truth — do not add a third hardcoded copy.
- Owner email: `info@hometerboekt.be`. House rules: `https://www.beaunita.be/huur-en-boekingsvoorwaarden/`.
- If a backend is required, prefer **thin PHP (or equivalent) on the same Combell document root** (`public/api/`) over a separate hosted SPA. Confirm PHP/MySQL/cron in the panel before depending on them.
- Gitignore secrets. Never commit `.vscode/sftp.json` or new `.env` files under `public/`.

## Must not do

- Do not scaffold a new Next/Vite/React/Shopify/WordPress app as the product.
- Do not rewrite `index.html` / `genk.html` / working CSS unless the booking flow truly requires it.
- Do not use `public/editor/` (VvvebJS + unauthenticated PHP write). It is not the CMS and must not be deployed.
- Do not assume Formspree is live (README is stale; form uses `mailto:`).
- Do not assume October is high or low season — copy only defines Apr–Sep (+ school holidays) as high and Nov–Mar as low.
- Do not put API keys in client JS.
- Do not change capacity above 8 guests or drop i18n.

## Where new code goes

```
public/assets/js/pricing.js          # rates, fees, seasons
public/assets/js/booking/            # calendar, quote, checkout
public/booking/*.html                # extra guest pages if contact.html is not enough
public/admin/                        # only with real auth
public/api/                          # only if this host runs the backend
```

Update `header.js` and `footer.js` if you add routes besides `contact.html`.
