# Customer portal

Static HTML, CSS and vanilla JavaScript; no framework and no build step. All copy is Russian; the brand stays `[BRAND]` until chosen.

- Pages: `public/*.html` (FULL_PLAN §10). Header and footer markup is repeated in each page; keep them in sync.
- Styles: `public/css/site.css` (tokens in `:root`).
- Scripts: ES modules. `public/js/app.js` is the page shell; `public/js/pages/*.js` hold page logic; `public/js/i18n/ru.js` holds every string scripts render. Static copy stays in the HTML.
- API access goes through `/shared/js/api-client.js` only.

Run it through the backend so `/shared/js` and `/api/v1` resolve (see the [root README](../README.md)):

```bash
./scripts/build-public.sh && open http://127.0.0.1:8000
```

Add `?mock=1` to any URL to serve responses from `docs/api/examples` (a "Демо-данные" badge appears); `?mock=0` switches back.
