# Migration audit of the supplied React + Vite project

## Current stack found
- React 18 + ReactDOM
- Vite 6
- TypeScript
- Tailwind CSS 4 via `@tailwindcss/vite`
- React Router is installed but the supplied home flow does not use it in `App.tsx`
- Framer Motion, Lucide, dnd-kit, Recharts, canvas-confetti and date-fns are installed
- Supabase is installed but no Supabase import/API usage was found in `src`

## Existing UI inventory
- `Header.tsx`: logo, ticker, search, account/cart actions, category navigation
- `Hero.tsx`: hero copy, animated shipping city, CTA, image, trust chips
- `HomeSections.tsx`: category tiles, product rails, projects, tabs, brands, value proposition, CTA
- `ProductCard.tsx`: stars, add-to-cart, product cards and promo tile
- `Chrome.tsx`: cart drawer, chat widget, mobile navigation, footer and toasts
- `Icons.tsx`: reusable SVG icon set
- `CartContext.tsx`: persisted cart + drawer + toast state; replaced by Zustand store in this migration

## Data/assets
The home page currently uses local mock catalog data from `src/data/catalog.ts`. Product/category imagery is supplied as remote `image.qwenlm.ai` URLs rather than local files.

## Tailwind
The project uses Tailwind v4 CSS-first configuration in `src/index.css` with custom `@theme` tokens for pine, brass, ink, paper, card, shadows and fonts. There is no `tailwind.config.*` file.

## shadcn/ui
No shadcn/ui-generated components were present in the supplied project. This migration initializes the shadcn conventions and adds a small `Button` primitive without changing the existing home UI.

## API/state/forms
- No `fetch`, Axios, or Supabase data calls were found in the current source.
- Cart state is persisted to localStorage.
- No React Hook Form/Zod forms were present.
- No authentication state was present.

## Important design-preservation decision
The legacy home components were moved instead of rewritten. This keeps the supplied Tailwind classes, animations, spacing and content intact while establishing the Next.js feature boundary. API-backed sections can be connected through `features/*/api.ts` later.
