# Admin panel

Operator panel in static HTML, CSS and vanilla JavaScript, published at `/admin/` by `scripts/build-public.sh`.

- Pages: `public/*.html`, one per FULL_PLAN §3 section. Sections whose admin API arrives in a later phase show what is coming and when.
- Components: `public/js/components/` — `data-table.js` (search, sort, pagination, loading/empty/error states), `status-badge.js`, `toast.js`, `confirm-dialog.js` (optional audit reason).
- The server enforces every permission. The UI only hides actions a role cannot take (IMPLEMENTATION_PLAN §5.9).

Operator login with TOTP arrives in Phase 2; until then the pages show public data only.
