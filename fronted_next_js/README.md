## Run

```bash
npm install
cp .env.example .env.local
npm run dev
```

Routes: `/ar`, `/en`, `/fr`.

Laravel API base URL: `NEXT_PUBLIC_API_URL=http://localhost:8000/api`.

## Notes

- Home UI was moved under `features/home/components` rather than rewritten.
- Existing remote image URLs are allowed through `next.config.ts`.
- Zustand replaces the old cart context while keeping the `useCart()` API shape for migrated components.
- `next-intl` is configured for `ar`, `en`, and `fr`; Arabic is RTL.
- Laravel response envelope expected: `{ data, message, meta }`.
- No Laravel secrets belong in `NEXT_PUBLIC_*` variables.

cities
brand
banner
wishlist
