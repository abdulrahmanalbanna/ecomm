## Run

```bash
npm install
cp .env.example .env.local
npm run dev
```

Routes: `/ar`, `/en`, `/fr`.

Laravel API base URL: `NEXT_PUBLIC_API_URL=http://localhost:8000/api`.

## Laravel Integration

This Next.js storefront is now connected to a Laravel backend for live catalog data:

- **Live Categories & Products**: Fetched from Laravel API (`/v1/catalog/categories`, `/v1/catalog/products`)
- **Hydration Safety**: Uses an empty API-backed loading state on first render and during SSR
- **Shared Catalog Snapshot**: Single coalesced data source prevents duplicate API calls
- **Cart Integration**: Live product lookup for cart items using Laravel `public_id` UUIDs
- **Search & Navigation**: Header search and category navigation use live data

### API Endpoints Used

- `GET {NEXT_PUBLIC_API_URL}/v1/homepage` → normalized storefront homepage payload
- `GET {NEXT_PUBLIC_API_URL}/v1/settings` → public settings compatibility endpoint
- `GET {NEXT_PUBLIC_API_URL}/v1/catalog/categories` → Category tree
- `GET {NEXT_PUBLIC_API_URL}/v1/catalog/`products`` → Product catalog (with pagination)
- `GET {NEXT_PUBLIC_API_URL}/v1/catalog/products/{slug}` → Single product
- `GET {NEXT_PUBLIC_API_URL}/v1/catalog/categories/{slug}` → Single category

### Response Format

Laravel responses use envelope format: `{ data, message?, meta? }`
- Catalog data: `{ data: { data: [...], meta: {...} } }`
- Settings: `{ data: {...} }`

The homepage endpoint filters private/inactive settings and inactive JSON items
on Laravel, and returns stable empty collections/objects when content is
missing or malformed.

### Configuration

- **next.config.ts**: Updated with Laravel media URL patterns (`/storage/**`)
- **.env.example**: Includes Laravel API URL and endpoint documentation
- **TypeScript**: Full type safety for Laravel resources and API responses

## Notes

- Home UI was moved under `features/home/components` rather than rewritten.
- Existing remote image URLs are allowed through `next.config.ts` (Laravel media, qwenlm.ai).
- Zustand replaces the old cart context while keeping the `useCart()` API shape for migrated components.
- `next-intl` is configured for `ar`, `en`, and `fr`; Arabic is RTL.
- No Laravel secrets belong in `NEXT_PUBLIC_*` variables.
