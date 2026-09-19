# Product Detail Page (PDP)

Feature documentation for the storefront product detail page, located at
`/{locale}/products/{slug}`.

Everything lives under `src/features/product-detail/` (feature-based folders),
with the route itself in `src/app/[locale]/(store)/products/[slug]/`.

---

## 1. Architecture

```
app/[locale]/(store)/products/[slug]/
├── page.tsx          Server Component: fetch (ISR) + metadata + JSON-LD
├── loading.tsx       Skeleton (server) shown on a cache miss
├── error.tsx         Error boundary (client) with retry
└── not-found.tsx     404 state (server)

features/product-detail/
├── types.ts          Frontend domain types (ProductDetail, ProductReview…)
├── schemas.ts        Zod schemas: review form, quantity, variant selection
├── api.ts            Laravel → frontend adapters + server/client fetchers
├── hooks.ts          TanStack Query + Zustand hooks (pdpKeys namespace)
├── components/
│   ├── ProductDetailPage.tsx   Composition root + mobile sticky bar
│   ├── Breadcrumbs.tsx         Home > Category > Product
│   ├── ProductGallery.tsx      Carousel, thumbnails, hover zoom, swipe
│   ├── ZoomDialog.tsx          Full-viewport zoom (dynamic import, ssr:false)
│   ├── ProductInfo.tsx         Name, price, rating, stock, description
│   ├── VariantSelector.tsx     Attribute picker + resolveVariant()
│   ├── ProductActions.tsx      Quantity, add-to-cart, wishlist, share
│   ├── ProductSpecs.tsx        Tabs: specs / details / shipping
│   ├── ReviewsSection.tsx      Summary, sort, infinite list, review form
│   ├── RelatedProducts.tsx     "You might also like" rail + compare table
│   └── Primitives.tsx          StarRating, StarRatingInput, QuantityInput,
│                               skeleton loaders
└── __tests__/         node:test unit tests (schemas, variants, adapters, cart)
```

### Data flow

```
            ┌──────────────────────────────────────────────────────┐
            │  page.tsx  (Server Component, RSC)                   │
            │  getProductDetailServer()  ── ISR revalidate: 3600s  │
            │  getRelatedServer()       ── ISR revalidate: 3600s  │
            └───────────────┬──────────────────────────────────────┘
                            │ props (serializable ProductDetail)
                            ▼
            ┌──────────────────────────────────────────────────────┐
            │  <ProductDetailPage>  (client composition root)      │
            │  owns: quantity, variantSelection                    │
            └───┬──────────┬──────────┬──────────┬──────────┬──────┘
                ▼          ▼          ▼          ▼          ▼
            Gallery     Info     Actions     Specs     Reviews / Related
                                                                  │
                  client-side TanStack Query (after hydration) ────┘
                  • useReviewsInfinite  • useReviewSummary
                  • useSubmitReview     • useWishlist / useToggleWishlist
                  • useRelatedProducts  • useAddToCart (Zustand)
```

**Why split it this way?** The product body is server-rendered and cached (ISR,
1 hour) so first paint is fast and SEO-crawled HTML is complete. Everything
*after* the first paint — reviews, wishlist membership, the related rail
revalidation — streams client-side so a slow reviews endpoint can never block
the PDP.

---

## 2. Backend contract

| Endpoint | Method | Auth | Used by |
| --- | --- | --- | --- |
| `/api/v1/catalog/products/{publicId\|slug}` | GET | public | `getProductDetailServer` (ISR) |
| `/api/v1/catalog/products?category_slug=…` | GET | public | `getRelatedServer` / `getRelatedClient` |
| `/api/v1/reviews/products/{uuid}/reviews` | GET | public | `getReviewsPage` (infinite query) |
| `/api/v1/reviews/products/{uuid}/summary` | GET | public | `getReviewSummary` |
| `/api/v1/reviews/products/{uuid}/reviews` | POST | Sanctum | `submitReview` |
| `/api/v1/wishlist` | GET/POST/DELETE | Sanctum | `getWishlist` / `toggleWishlist` |

All responses use the Laravel envelope `{ data, message?, meta? }`; `apiClient`
unwraps it and throws `ApiError` (with `.status` and `.validationErrors`) on
non-2xx.

### Graceful degradation

The **Reviews** and **Wishlist** modules are not yet wired to routes in this
repository. Their fetchers are written defensively:

- `getReviewsPage` / `getReviewSummary` / `getWishlist` catch any error and
  return an empty page / empty summary / empty list.
- The UI renders an empty state ("No reviews yet") instead of crashing.
- Wishlist buttons are hidden behind the auth gate, so a guest never triggers
  a request.

When those modules ship, **no UI change is required** — only the endpoint paths
in `api.ts` if they differ from the table above.

---

## 3. State management

### TanStack Query (`hooks.ts`)

All PDP query keys are namespaced under `["pdp", …]`:

```ts
pdpKeys.product(id)          // ["pdp", "product", id]
pdpKeys.reviews(id, sort)    // ["pdp", "reviews", id, sort]
pdpKeys.reviewSummary(id)    // ["pdp", "review-summary", id]
pdpKeys.related(id)          // ["pdp", "related", id]
pdpKeys.wishlist()           // ["pdp", "wishlist"]
```

Invalidate the whole feature at once with
`queryClient.invalidateQueries({ queryKey: ["pdp"] })`.

| Hook | Purpose |
| --- | --- |
| `useProductDetail` | Background refetch of the product (initial data from RSC) |
| `useReviewsInfinite` | Page-based infinite query (`initialPageParam: 1`) |
| `useReviewSummary` | Average + histogram; updated optimistically on submit |
| `useSubmitReview` | Optimistic prepend into every cached page + rollback |
| `useWishlist` / `useToggleWishlist` | Membership list + optimistic toggle |
| `useRelatedProducts` | Rail data (initial from RSC, refetches client-side) |
| `useAddToCart` | Zustand write + opens the drawer |
| `useInCartQty` | Line quantity without subscribing to the whole store |

### Zustand (`stores/cart.ts`)

The cart store is the source of truth for the drawer and badge. The PDP adds
quantity-aware methods:

```ts
add(id, message?)              // +1 (homepage "Add" buttons)
addQuantity(id, qty, max, msg) // PDP: clamps the *combined* total to `max`
setQuantity(id, qty, max?)     // overwrite (removes when <= 0)
```

`addQuantity` clamps with `clampQuantity()` from `schemas.ts`, so a stale `max`
on a re-rendered page can never produce an over-quantity line. Cart lines are
keyed by the backend `public_id` UUID.

---

## 4. Forms & validation

`src/features/product-detail/schemas.ts` is the **single source of truth** for
client-side validation *and* the request body shape, so the two cannot drift.

```ts
reviewSchema = z.object({
  rating:  z.number().min(1).max(5),
  title:   z.string().trim().min(3).max(100),
  comment: z.string().trim().min(10).max(1000),
})

quantitySchema(max)  // z.number().int().min(1).max(min(max, 99))
clampQuantity(v, max)
```

The review form uses React Hook Form + `@hookform/resolvers/zod`. Error
messages are **i18n keys** (`pdp.reviews.errors.*`), not hardcoded English, so
`zodResolver` output feeds straight into `t()`.

The quantity input validates through `quantitySchema(max).safeParse()` before
the mutation runs, and the store clamps again as a second line of defence.

---

## 5. Auth & permissions

`src/lib/auth/session.ts` provides `useSession()` (localStorage token +
`storage` event sync across tabs) and `withAuth(session, fn)`, which throws
`ApiError(401)` *before* any network call when there is no token.

| Capability | Guest | Authenticated |
| --- | --- | --- |
| View product / reviews | ✅ | ✅ |
| Add to cart | ✅ (local) | ✅ |
| Write a review | ❌ sign-in callout | ✅ |
| Wishlist toggle | ❌ sign-in tooltip | ✅ |

Guests never see a failed request: the review form is replaced by a sign-in
callout and the wishlist button is disabled with an explanatory `title`.

---

## 6. SEO

`page.tsx` emits:

- **`generateMetadata`** — title/description from `seo_title` / `seo_description`
  (falling back to name + short description), canonical URL, `alternates.languages`
  for en/ar/fr, OpenGraph + Twitter cards with the primary image.
- **JSON-LD `Product`** with `Offer` (price, `SAR`, availability) and
  `AggregateRating` (only when reviews exist, so an empty rating block never
  hurts rich results).

A 404 product returns `robots: { index: false }` metadata and renders
`not-found.tsx`.

---

## 7. Performance

| Technique | Where |
| --- | --- |
| ISR `revalidate: 3600` | `getProductDetailServer` / `getRelatedServer` |
| Streaming / deferred | Reviews + wishlist load after hydration |
| `next/dynamic` + `ssr: false` | `ZoomDialog` (never in the initial bundle) |
| `next/image` | Gallery + related rail |
| Lazy thumbnails | `loading="lazy"` on the thumbnail strip |
| Intersection Observer | `useRevealObserver` animates below-the-fold sections |
| Request dedup | React Query cache + RSC fetch memoization |
| Selectors | `useCartLineQty` / `selectCartItemCount` avoid store over-subscription |

---

## 8. Accessibility (WCAG 2.1 AA)

- **Gallery**: `role="group"` with `aria-label`, keyboard `←`/`→`/`Home`/`End`,
  thumbnails as a `role="tablist"` with `aria-current`, swipe on touch.
- **Star rating input**: `role="radiogroup"` + arrow-key navigation.
- **Breadcrumbs**: `nav[aria-label]`, final crumb is `aria-current="page"`.
- **Stock status**: colour *and* text (never colour alone).
- **Forms**: inline `role="alert"` errors, labelled inputs, focus-visible rings.
- **Zoom dialog**: focus trap, `Escape` to close, body scroll lock.
- **Out-of-stock**: the CTA is `disabled` (not just hidden) with an explanatory
  label.

---

## 9. Error handling

| Case | Behaviour |
| --- | --- |
| Product 404 | `getProductDetailServer` → `null` → `notFound()` |
| Product 5xx / network | thrown → route `error.tsx` with retry |
| Reviews endpoint down | empty state, page still fully usable |
| Review submit fails | optimistic entry rolled back, inline error |
| Wishlist toggle fails | optimistic change reverted |
| Guest attempts mutation | blocked client-side, sign-in prompt shown |
| Over-quantity add | clamped in the store, toast shown |

---

## 10. Testing

Unit tests use `node:test` + `tsx` (no Jest), matching the existing
`features/home/__tests__/` pattern:

```bash
npm test          # tsx --test src/**/*.test.ts
npm run typecheck # tsc --noEmit
npm run lint      # eslint .
```

| File | Covers |
| --- | --- |
| `__tests__/schemas.test.ts` | Review + quantity Zod schemas, `clampQuantity` |
| `__tests__/variants.test.ts` | `resolveVariant`, `isSelectionComplete` |
| `__tests__/adapters.test.ts` | `adaptProductDetail` (stock, media, SEO, tags) |
| `__tests__/cart.test.ts` | `addQuantity` clamping, toasts, selectors |

**These tests caught two real bugs on first run**, both since fixed:
`stock: 0` was misclassified as `preorder` instead of `out-of-stock`, and
whitespace-only tag names leaked into the UI.

---

## 11. Extending

### Add a new variant attribute (e.g. "voltage")

Nothing to do — `adaptAttributes` derives attribute groups and their options
from the variant rows themselves. A new attribute appears in the selector as
soon as the backend sends it.

### Add a PDP section

1. Create `components/MySection.tsx` (`"use client"` if it needs hooks).
2. Add its copy to `src/messages/{en,ar,fr}.json` under `pdp`.
3. Render it inside `ProductDetailPage.tsx`, wrapped in a `.reveal` div if it
   is below the fold.

### Change the review validation rules

Edit the constants in `schemas.ts` (`REVIEW_TITLE_MIN`, …). The form, the error
messages and the request body all derive from the single schema.

### Re-skin the page

Colours are semantic Tailwind tokens (`primary-*`, `secondary-*`, `muted-*`,
`success`/`warning`/`danger`). Change the `@theme` values in
`src/config/branding.ts` + `globals.css`; no component edits needed.

### Wire the real reviews/wishlist endpoints

Only the paths in `api.ts` (`getReviewsPage`, `getReviewSummary`,
`submitReview`, `getWishlist`, `toggleWishlist`) need updating — the hooks,
components and tests stay as-is.
