# Customer website

The site is Russian-language HTML, CSS and vanilla JavaScript. Vite bundles the shared API client, GSAP, Three.js and self-hosted fonts into `dist/`; no frontend framework is used. Source pages are in `src/`, static files in `public/`.

```bash
cd front
npm ci
npm run dev    # http://127.0.0.1:5173; API requests proxy to localhost:8000
npm run lint
npm run build
```

From the repository root, `./scripts/build-public.sh` copies a completed `front/dist/` into Laravel's `backend/public/` alongside the admin and shared assets. Local mock responses are copied by default; production deploy passes `MOCKS=0`.

The production server can deploy without Node. Build locally from the release commit, write that commit's full hash to `front/dist/.source-revision`, and upload the entire `front/dist/` to the server's `front/dist/`. The deploy script verifies the hash against the server checkout before putting Laravel into maintenance mode. See `DESIGN.md` for the responsive, motion and asset rules.
