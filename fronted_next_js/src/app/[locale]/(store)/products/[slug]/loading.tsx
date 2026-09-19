import { getLocale, getTranslations } from "next-intl/server";
import { GallerySkeleton, InfoSkeleton, RelatedSkeleton, ReviewsSkeleton } from "@/features/product-detail/components/Primitives";

/**
 * PDP loading state.
 *
 * Shown while the server component fetches the product (ISR cache miss or a
 * first request to a fresh `[slug]`). The skeleton mirrors the real layout —
 * two columns on desktop, stacked on mobile — so the shift on hydration is
 * minimal (no layout shift beyond the image aspect ratio).
 */
export default async function Loading() {
  const locale = await getLocale();
  const t = await getTranslations({ locale, namespace: "pdp" });

  return (
    <main className="min-h-screen bg-background" aria-busy="true" aria-label={t("loading")}>
      <div className="mx-auto max-w-7xl px-4 py-6 lg:px-8 lg:py-10">
        {/* breadcrumb skeleton */}
        <div className="flex items-center gap-2" aria-hidden="true">
          <div className="h-3 w-16 animate-pulse rounded bg-muted-200/70" />
          <span className="text-muted-300">/</span>
          <div className="h-3 w-24 animate-pulse rounded bg-muted-200/70" />
          <span className="text-muted-300">/</span>
          <div className="h-3 w-32 animate-pulse rounded bg-muted-200/70" />
        </div>

        <div className="mt-6 grid grid-cols-1 gap-8 lg:mt-10 lg:grid-cols-2 lg:gap-12 xl:gap-16">
          <GallerySkeleton />
          <InfoSkeleton />
        </div>

        <div className="mt-14 space-y-14 lg:mt-20">
          <ReviewsSkeleton />
          <RelatedSkeleton />
        </div>
      </div>
    </main>
  );
}
