# Home Page

Feature documentation for the storefront home page, located at `/{locale}`.

Everything lives under `src/features/home/` (feature-based folders), with the
route itself in `src/app/[locale]/(store)/`.

---

## 1. Architecture

```
app/[locale]/(store)/
├── page.tsx          Server Component: fetch (RSC) + metadata
├── loading.tsx       Skeleton (server) shown while the route loads
└── error.tsx         Error boundary (client) with retry

features/home/
├── catalog.ts        Domain types (Category, Product) + offline fallback data
├── api.ts            Laravel → frontend adapters + server/client fetchers
├── use-catalog.ts    Shared live catalog snapshot (module singleton + hook)
├── use-settings.ts   Shared storefront settings (module cache + hook)
├── components/
│   ├── HomePage.tsx              Composition root; passes the snapshot by props
│   ├── CartProductLookupSync.tsx Installs the live `byId` map into the cart store
│   ├── Header.tsx                Ticker, logo, search, category nav, cart badge
│   ├── Hero.tsx                  Hero copy, rotating city chip, category strip
│   ├── HomeSections.tsx          CategoryTiles, ProductRail, ProjectsGrid,
│   │                             TabsSection, BrandsMarquee, WhyUs, CtaBand
│   ├── Chrome.tsx                CartDrawer, ChatWidget, MobileNav, Footer, Toasts
│   ├── ProductCard.tsx           Re-exports from features/catalog
│   ├── Icons.tsx                 Inline SVG icon set + categoryIcon()
│   └── HomeCopy.ts               (disabled placeholder, kept for future use)
└── __tests__/         node:test unit tests (adapters, hrefs)
```

### Data flow

```
            ┌──────────────────────────────────────────────────────┐
            │  page.tsx  (Server Component, RSC)                   │
            │  getHomeServerData()  ── Promise.allSettled, no ISR  │
            └───────────────┬──────────────────────────────────────┘
                            │ props (serializable settings/categories/
                            │        products/byId/byCategory)
                            ▼
            ┌──────────────────────────────────────────────────────┐
            │  <HomePage>  (client composition root)               │
            │  owns nothing; the snapshot is passed down by props  │
            └───┬──────────┬──────────┬──────────┬──────────┬──────┘
                ▼          ▼          ▼          ▼          ▼
             Header      Hero     Sections    Footer     CartDrawer / Chat
                                                                   │
                   client-side refetch (after hydration) ──────────┘
                   • useShopSettings()  • useCatalog()
                   • useCart() (Zustand, persisted in localStorage)
```

**Why split it this way?** The page is server-rendered so first paint is fast and
SEO-crawled HTML is complete, but the home route deliberately does **not** use
ISR: the catalog and storefront settings are fetched on every request via
[`Promise.allSettled`](../src/features/home/api.ts:197), so a merchandising change
in the backend is visible on the next request without a redeploy. Because every
fetch is *settled* (never rejected), a partial backend outage degrades to the
static fallback catalog instead of erroring the whole page.

---

## 2. Backend contract

| Endpoint | Method | Auth | Used by |
| --- | --- | --- | --- |
| `/api/v1/homepage` | GET | public | [`getHomepage`](../src/features/home/api.ts:19) (RSC + `useShopSettings`) |
| `/api/v1/settings` | GET | public | [`getPublicSettings`](../src/features/home/api.ts:12) (legacy alternate) |
| `/api/v1/catalog/categories` | GET | public | [`getCategories`](../src/features/home/api.ts:28) |
| `/api/v1/catalog/products?per_page=100` | GET | public | [`getProducts`](../src/features/home/api.ts:47) |
| `/api/v1/catalog/products?featured=1` | GET | public | [`getFeaturedProducts`](../src/features/home/api.ts:37) (available, unused by the page) |
| `/banners` | GET | public | [`getBanners`](../src/features/home/api.ts:86) — **no backend route yet** |

All responses use the Laravel envelope `{ data, message?, meta? }`; `apiClient`
unwraps it and throws `ApiError` (with `.status` and `.validationErrors`) on
non-2xx.

`getProducts` is defensive about **three** response shapes, because the catalog
list endpoint can return a bare array or a paginated envelope:

```ts
{ data: ProductPublicResource[] }                       // plain list
{ data: { data: [...], meta: { current_page, last_page, per_page, total } } }  // paginated
[ ... ]                                                 // bare array
```

### Graceful degradation

The **banners** module has no backend route yet ([`getBanners`](../src/features/home/api.ts:86)
carries a `TODO`), and several `ShopSettings` fields may be empty. Everything is
written defensively so the page never crashes:

- [`getHomeServerData`](../src/features/home/api.ts:189) wraps the three fetches in
  `Promise.allSettled`; a rejected request yields `null` settings or an empty
  array, and the outer `catch` returns a fully empty snapshot.
- [`useCatalog`](../src/features/home/use-catalog.ts:104) keeps the static snapshot
  on failure (`snapshot = null` ⇒ statics are rendered) and never surfaces an
  error string to the UI.
- [`useShopSettings`](../src/features/home/use-settings.ts:64) catches any error
  and resolves to [`fallbackSettings`](../src/features/home/use-settings.ts:7) — an
  *empty* shape with no mock storefront data, so the UI never claims stale
  business content.
- Empty `ticker_items` / `features` / `stats` simply hide their sections
  (`<Ticker>` returns `null`, `WhyUs` renders no blocks).

When the banners endpoint ships, **no UI change is required** — only the path in
`api.ts` if it differs from the table above.

---

## 3. State management

The home page intentionally avoids a global data library. State lives in three
small, independent mechanisms:

### 3.1 Catalog snapshot ([`use-catalog.ts`](../src/features/home/use-catalog.ts))

A module-level singleton — one request for the whole page, shared by every
section via props (no context provider):

```ts
let inflight: Promise<void> | null = null;
let snapshot: { categories: Category[]; products: Product[] } | null = null;
const subscribers = new Set<() => void>();
```

| Export | Purpose |
| --- | --- |
| [`useCatalog(initial?)`](../src/features/home/use-catalog.ts:104) | Subscribe to the snapshot; seeds from `initial`, else loads once |
| [`setCatalogSnapshot(initial)`](../src/features/home/use-catalog.ts:94) | Prime the singleton from the RSC payload |
| [`getCatalogSnapshot()`](../src/features/home/use-catalog.ts:100) | Read the current snapshot imperatively |

Returns a [`CatalogState`](../src/features/home/use-catalog.ts:33) with
`categories`, `products`, `byId`, `byCategory`, `live`, `loading`, `error`.
`byId` / `byCategory` are the lookup maps the whole feature is keyed on.

### 3.2 Storefront settings ([`use-settings.ts`](../src/features/home/use-settings.ts))

Same pattern, one module-level `cached` value + `inflight` promise, plus an
explicit hydration rule:

> The initial state is **always** [`fallbackSettings`](../src/features/home/use-settings.ts:7)
> (never the module `cached` value) so the first client render is identical to
> the server prerender. The live value is only applied inside `useEffect`.

Without this, client-side navigation after a live load would hydrate with
different ticker/feature data than the server HTML and throw a React
hydration-mismatch error.

### 3.3 Cart ([`stores/cart.ts`](../src/stores/cart.ts))

Zustand + `persist` (`name: "tagahayeez-cart-v2"`). The store is the source of
truth for the drawer, the badge and the toasts:

```ts
add(id, message?)              // +1 (home "Add" buttons)
addQuantity(id, qty, max, msg) // PDP: clamps the *combined* total to `max`
setQuantity(id, qty, max?)     // overwrite (removes when <= 0)
inc / dec / remove / clear
```

Cart lines are keyed by the backend `public_id` UUID. Because that id is opaque,
the store cannot resolve display data on its own — it uses a shared lookup map
installed once by [`CartProductLookupSync`](../src/features/home/components/CartProductLookupSync.tsx:7):

```
HomePage → <CartProductLookupSync byId={byId} /> → setCartProductLookup(byId)
                                                              │
resolveProduct(id) ← productLookup.get(id) ───────────────────┘
                   ← staticProducts.find(...)   (fallback: stale localStorage lines / SSR)
```

---

## 4. The static fallback catalog ([`catalog.ts`](../src/features/home/catalog.ts))

`catalog.ts` is deliberately *not* deleted after the backend migration. It is
the offline contract: the shape the UI renders when the API is unreachable.

| Export | Role |
| --- | --- |
| [`Category`](../src/features/home/catalog.ts:20) / [`Product`](../src/features/home/catalog.ts:32) | Frontend domain types |
| [`productHref(p)`](../src/features/home/catalog.ts:62) | PDP href: prefers `slug`, falls back to `id` (backend resolves both), URL-encoded |
| [`pickLocale(locale, ar, en)`](../src/features/home/catalog.ts:29) | Arabic for `ar`, English fallback for `en`/`fr` |
| [`IMG`](../src/features/home/catalog.ts:70) | Backend storage URLs (`{origin}/storage/catalog/*.png`) |
| `categories` / `products` | 7 categories, 56 products — the fallback catalog |
| `newArrivals` / `bestSellers` | Static id lists (currently unused; `TabsSection` derives its own split) |
| `projects` / `brands` / `cities` | Static marketing copy for `ProjectsGrid`, `BrandsMarquee`, `Hero` |
| [`formatPrice(n)`](../src/features/home/catalog.ts:211) | `en-US` grouping (SAR amounts) |
| `productById` / `byCategory` | Static lookups |

**Images:** the backend (`storage/app/public/catalog`, served as
`{APP_URL}/storage/catalog/*.png`) is the single source of truth — the frontend
no longer ships `public/images/*`. [`resolveMediaUrl`](../src/lib/media.ts:44)
handles absolute URLs, legacy `/images/` paths, relative `/storage/...`, bare
filenames and empty input (→ backend fallback file), so old seeded data cannot
produce a broken `src`.

---

## 5. Adapters ([`api.ts`](../src/features/home/api.ts))

The adapters are the only place that knows the Laravel resource shapes.

| Function | Responsibility |
| --- | --- |
| [`adaptCategory`](../src/features/home/api.ts:129) | `CategoryResource` → `Category`; maps slug → frontend id, falls back to the slug |
| [`adaptProduct`](../src/features/home/api.ts:158) | `ProductPublicResource` → `Product` |
| [`pickDefaultVariant`](../src/features/home/api.ts:146) | First active variant with `price > 0`, else first active, else first |
| [`getHomeServerData`](../src/features/home/api.ts:189) | RSC entry: 3 parallel settled fetches + adapters + lookup maps |

`adaptProduct` derives:

- **`price` / `oldPrice`** from the default variant; `oldPrice` is only set when
  `compare_at_price` is *greater* than `price` (a lower compare-at price is not a
  discount and must not render a struck-through number).
- **`image`** from the first media entry with a `url`, resolved through
  `resolveMediaUrl` with the backend `hero.png` as fallback.
- **`category`** from `raw.category.slug` via `SLUG_TO_FRONTEND_ID`, defaulting
  to `"coffee"` when the product has no category.
- **Unmapped fields** (`rating`, `reviews`, `badge`, `freeShipping`,
  `startsFrom`) are reset to neutral defaults (`0` / `0` / `undefined`) — live
  products never inherit static marketing badges they do not have.

`SLUG_TO_FRONTEND_ID` maps the seven known backend slugs to the stable frontend
ids (`coffee`, `grinders`, `cooling`, `cooking`, `frying`, `bakery`, `drinks`);
an unknown slug passes through unchanged so the UI still renders.

---

## 6. i18n & RTL

All copy flows through `next-intl` namespaces under `home.*` in
`src/messages/{en,ar,fr}.json`:

| Namespace | Consumer |
| --- | --- |
| `home.hero` | [`Hero`](../src/features/home/components/Hero.tsx:33) |
| `home.categoriesSection` | [`CategoryTiles`](../src/features/home/components/HomeSections.tsx:85), [`ProductRail`](../src/features/home/components/HomeSections.tsx:161) |
| `home.promo` | `PromoTile` + per-category promo copy |
| `home.projects` / `home.weekly` / `home.brands` / `home.why` / `home.cta` | Matching sections in [`HomeSections`](../src/features/home/components/HomeSections.tsx) |
| `home.cart` / `home.chat` / `home.mobile` / `home.footer` | [`Chrome`](../src/features/home/components/Chrome.tsx) |
| `home.product` | [`ProductCard`](../src/features/catalog/components/ProductCard.tsx:72), `AddButton` |
| `home.footer` + `settings.footer` | API copy wins per-locale, message file is the fallback |

Direction is set by the `[locale]` segment (`ar` ⇒ RTL). Components that must
stay LTR (the ticker, the brands marquee, phone numbers) pin `dir="ltr"`; the
marquee items re-pin `dir="rtl"` so Arabic brand names still render correctly.
Horizontal scrollers invert their scroll direction for RTL:

```ts
railRef.current?.scrollBy({ left: dir * 320 * (document.documentElement.dir === "rtl" ? 1 : -1), behavior: "smooth" });
```

Static bilingual data (`brands`, `cities`, `projects`) is resolved at render
time with [`pickLocale`](../src/features/home/catalog.ts:29) or `useLocale()`.

---

## 7. SEO

[`page.tsx`](../src/app/[locale]/(store)/page.tsx:8) emits:

- **`generateMetadata`** — locale-specific title from `branding.name`
  (`ar`/`en`/`fr` variants), `brandTagline` description, `alternates.languages`
  for en/ar/fr, `icons`, OpenGraph + Twitter cards with the metadata logo.
- **Server-rendered HTML** — categories, products and settings are in the first
  response, so crawlers see the full catalog without executing JS.

The route has **no** `revalidate` export; content is fresh on every request.

---

## 8. Performance

| Technique | Where |
| --- | --- |
| RSC fetch, no ISR | [`getHomeServerData`](../src/features/home/api.ts:189) — always-fresh catalog |
| `Promise.allSettled` | The three fetches run in parallel; one failure cannot delay the others |
| Single request per page | `inflight` dedup in [`use-catalog`](../src/features/home/use-catalog.ts:65) / [`use-settings`](../src/features/home/use-settings.ts:30) |
| Props, not context | The snapshot is passed down once; no provider re-renders |
| `next/image` | Every image, with explicit `sizes` per breakpoint |
| `priority` / `fetchPriority="high"` | Hero image and header logo |
| `loading="lazy"` | Below-the-fold imagery |
| Intersection Observer | [`useRevealObserver`](../src/hooks/use-home.ts:17) + [`useInView`](../src/hooks/use-home.ts:101) animate only visible sections |
| MutationObserver | Re-observes `.reveal` nodes inserted when live data replaces the fallback |
| Selectors | `useCartStore((s) => ...)` / `selectCartItemCount` avoid store over-subscription |
| Inline SVG icons | [`Icons.tsx`](../src/features/home/components/Icons.tsx) ships no icon font or sprite |

---

## 9. Accessibility (WCAG 2.1 AA)

- **Search**: results dropdown closes on outside-click, each result is a real
  `<Link>` with the product name as accessible text, and the empty state
  announces `noResults`.
- **Cart drawer**: `role="dialog"` + `aria-label`, labelled close button, body
  scroll lock while open, and per-line `aria-label`s for
  increase/decrease/remove that include the product name.
- **Mobile nav**: `nav[aria-label]`, active state marked with both colour and a
  top indicator bar.
- **Category nav**: `nav[aria-label="categories"]`, buttons with visible
  focus rings.
- **Toasts**: `pointer-events-none` container — they never block interaction.
- **Decorative elements**: patterns, steam wisps and the rotating stamp are
  `aria-hidden`.
- **Reduced motion**: [`usePrefersReducedMotion`](../src/hooks/use-home.ts:3)
  disables the count-up animation and renders the final value directly.
- **Hydration-safe badges**: cart counts render only after `useMounted()`, so
  the server HTML and the first client render agree.

---

## 10. Error handling

| Case | Behaviour |
| --- | --- |
| Homepage endpoint down | `fallbackSettings` (empty shape); ticker/features/stats simply do not render |
| Categories endpoint down | empty array ⇒ static `categories` render |
| Products endpoint down | empty array ⇒ static `products` render |
| All endpoints down | `getHomeServerData` catch ⇒ fully static page, still fully usable |
| Unknown backend category slug | passes through as the frontend id; rail falls back to static items for that id |
| Product with no variants | `pickDefaultVariant` → `null` ⇒ `price: 0`, card still renders |
| Stale localStorage cart line | `byId` miss → `resolveProduct` static miss → minimal placeholder row (still removable) |
| Route-level render error | route `error.tsx` with a retry button |
| Route is loading | route `loading.tsx` skeleton (`aria-busy`) |

---

## 11. Testing

Unit tests use `node:test` + `tsx` (no Jest):

```bash
npm test          # tsx --test src/**/*.test.ts
npm run typecheck # tsc --noEmit
npm run lint      # eslint .
```

| File | Covers |
| --- | --- |
| [`__tests__/adapters.test.ts`](../src/features/home/__tests__/adapters.test.ts) | `adaptCategory`, `adaptProduct` (variant price, compare-at, media, slug), `productHref` |

The `productHref` cases pin the two identity rules the cards depend on: the
slug is preferred, the `public_id` is a valid fallback, and free-form slugs are
URL-encoded.

---

## 12. Extending

### Add a homepage section

1. Create `components/MySection.tsx` (`"use client"` if it needs hooks).
2. Add its copy to `src/messages/{en,ar,fr}.json` under a new `home.*` namespace.
3. Render it inside [`HomePage`](../src/features/home/components/HomePage.tsx:17),
   wrapped in a `.reveal` div if it is below the fold, and pass it the snapshot
   props it needs (`categories`, `byId`, `settings`).

### Add a category

Nothing to do in the UI — a new backend category appears as soon as
`GET /v1/catalog/categories` returns it: `adaptCategory` maps the slug, the tile
renders, and `HomePage` maps `categories` into one `ProductRail` per category.
Only add a static entry to `catalog.ts` if you want it in the offline fallback.

### Serve a new settings field

1. Add it to [`ShopSettings`](../src/features/home/api.ts:91) in `api.ts`.
2. Add a neutral default to [`fallbackSettings`](../src/features/home/use-settings.ts:7).
3. Consume it via `useShopSettings(initialFromRSC)` — the RSC payload in
   [`HomePage`](../src/features/home/components/HomePage.tsx:17) already threads
   settings through every chrome component.

### Wire the banners endpoint

Only [`getBanners`](../src/features/home/api.ts:86) needs a real path and a
return type — add a section in step 1 above. The fetcher is already isolated, so
nothing else changes.

### Re-skin the page

Colours are semantic Tailwind tokens (`primary-*`, `secondary-*`, `muted-*`,
`success`/`warning`/`danger`). Change the `@theme` values in
`src/config/branding.ts` + `globals.css`; no component edits needed.

### Replace the static fallback catalog

When the backend is authoritative for every field, delete the `products` /
`categories` arrays in [`catalog.ts`](../src/features/home/catalog.ts:83) and the
two static fallbacks in `resolveProduct` / the sections. Keep the *types* and
[`productHref`](../src/features/home/catalog.ts:62) — they are imported across
features (`features/catalog`, `stores/cart`).
