"use client";

/**
 * Product Detail Page composition root.
 *
 * This is the single client component that owns the *shared* interaction
 * state for the PDP and wires every section together:
 *
 *   - `quantity`           — stepper value shared by the actions block and
 *                            the mobile sticky bar, clamped to real inventory.
 *   - `variantSelection`   — one value per attribute group; the resolved
 *                            variant (`resolveVariant`) drives price, SKU and
 *                            the CTA's enabled state.
 *
 * Layout:
 *   - Desktop (`lg`): two columns — gallery on the inline-start side, the
 *     info / variant / actions stack on the other.
 *   - Tablet: single column, gallery on top.
 *   - Mobile: same single column plus a sticky bottom bar that mirrors the
 *     price and the primary CTA (`bottom-bar` sits above the site's mobile
 *     nav, see `z-index` in `Chrome.tsx`).
 *
 * Everything below the fold is wrapped in `.reveal` containers animated by
 * the IntersectionObserver in `useRevealObserver` (progressive enhancement:
 * content is visible without JS).
 */

import { useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { useRevealObserver } from "@/hooks/use-home";
import { IconCart } from "@/features/home/components/Icons";
import { clampQuantity, type VariantSelection } from "../schemas";
import { useAddToCart } from "../hooks";
import { VariantSelector, isSelectionComplete, resolveVariant } from "./VariantSelector";
import { Breadcrumbs } from "./Breadcrumbs";
import { ProductGallery } from "./ProductGallery";
import { ProductInfo } from "./ProductInfo";
import { ProductActions } from "./ProductActions";
import { ProductSpecs } from "./ProductSpecs";
import { ReviewsSection } from "./ReviewsSection";
import { RelatedProducts } from "./RelatedProducts";
import type { ProductDetail, RelatedProduct } from "../types";

export interface ProductDetailPageProps {
  product: ProductDetail;
  /** Server-prefetched related items (hydrated client-side afterwards). */
  initialRelated: RelatedProduct[];
}

export function ProductDetailPage({ product, initialRelated }: ProductDetailPageProps) {
  const t = useTranslations("pdp");
  const addToCart = useAddToCart();
  const revealRef = useRevealObserver<HTMLDivElement>();

  const [quantity, setQuantity] = useState(1);
  const [selection, setSelection] = useState<VariantSelection>({});

  const variant = useMemo(
    () => resolveVariant(product.variants, selection),
    [product.variants, selection],
  );

  // When attributes exist but the user hasn't picked one of every group, the
  // price/SKU shown are the product's defaults and the CTA waits.
  const needsSelection = product.attributes.length > 0 && !isSelectionComplete(product.attributes, selection);
  const selectionInvalid = isSelectionComplete(product.attributes, selection) && !variant;

  const isOutOfStock = product.stock.availability === "out-of-stock";
  const isPreorder = product.stock.availability === "preorder";
  const max = Math.max(1, product.stock.quantity || (isPreorder ? 12 : 99));
  const price = variant?.price ?? product.variants[0]?.price ?? 0;

  const reviewCount = product.summary.count;
  const averageRating = product.summary.average;

  const handleQuantityChange = (next: number) => {
    setQuantity(clampQuantity(next, max));
  };

  const handleAddToCart = () => {
    if (needsSelection || selectionInvalid || isOutOfStock) return;
    addToCart.mutate({
      productId: product.id,
      productName: product.name,
      quantity: clampQuantity(quantity, max),
      max,
      toast: t("info.addedToast", { name: product.name }),
    });
  };

  const ctaLabel = isOutOfStock
    ? t("actions.outOfStock")
    : needsSelection
      ? t("variants.select", { name: product.attributes[0]?.name ?? "" })
      : isPreorder
        ? t("actions.preorder")
        : t("stickyBar.addToCart");

  return (
    <main className="min-h-screen bg-background pb-28 lg:pb-16">
      <div className="mx-auto max-w-7xl px-4 py-6 lg:px-8 lg:py-10">
        <Breadcrumbs product={product} />

        <div className="mt-6 grid grid-cols-1 gap-8 lg:mt-10 lg:grid-cols-2 lg:gap-12 xl:gap-16">
          {/* Gallery — inline-start column on desktop */}
          <div className="lg:sticky lg:top-8 lg:self-start">
            <ProductGallery images={product.images} name={product.name} />
          </div>

          {/* Info + variants + actions */}
          <div className="space-y-6">
            <ProductInfo
              product={product}
              variant={variant}
              reviewCount={reviewCount}
              averageRating={averageRating}
            />

            <VariantSelector
              product={product}
              selection={selection}
              onSelectionChange={setSelection}
            />

            <ProductActions
              product={product}
              variant={variant}
              quantity={quantity}
              onQuantityChange={handleQuantityChange}
            />

            {selectionInvalid && (
              <p className="text-[13px] font-bold text-danger" role="alert">
                {t("variants.unavailable")}
              </p>
            )}
          </div>
        </div>

        {/* Below the fold — revealed on scroll */}
        <div ref={revealRef as React.RefObject<HTMLDivElement>} className="mt-14 space-y-14 lg:mt-20">
          <div className="reveal">
            <ProductSpecs product={product} reviewCount={reviewCount} onJumpToReviews={jumpToReviews} />
          </div>

          <div className="reveal">
            <ReviewsSection product={product} />
          </div>

          <div className="reveal">
            <RelatedProducts product={product} initial={initialRelated} />
          </div>
        </div>
      </div>

      {/* Mobile sticky product bar */}
      <div
        className="fixed inset-x-0 bottom-0 z-[70] border-t border-muted-200 bg-surface/95 backdrop-blur md:hidden"
        role="region"
        aria-label={product.name}
      >
        <div className="flex items-center gap-3 px-4 py-3">
          <div className="min-w-0 flex-1">
            <p className="truncate text-[12px] font-bold text-muted-500">{product.brand?.name}</p>
            <p className="flex items-baseline gap-1.5">
              <span className="font-display text-lg font-black text-primary-800 tabular">
                {price.toLocaleString("en-US")}
              </span>
              <span className="text-[11px] font-bold text-muted-400">SAR</span>
            </p>
          </div>

          <button
            type="button"
            onClick={handleAddToCart}
            disabled={isOutOfStock || needsSelection || selectionInvalid || addToCart.isPending}
            aria-live="polite"
            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 text-[13.5px] font-extrabold text-white transition-all duration-200 hover:bg-secondary-500 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 active:scale-[0.99]"
          >
            <IconCart size={16} />
            {ctaLabel}
          </button>
        </div>
      </div>
    </main>
  );
}

/** Smooth-scroll to the reviews section (deep-link target `#reviews`). */
function jumpToReviews(): void {
  if (typeof document === "undefined") return;
  const target = document.getElementById("reviews");
  if (!target) return;
  target.scrollIntoView({ behavior: "smooth", block: "start" });
  // Move focus for keyboard/AT users after the scroll settles.
  window.setTimeout(() => target.focus({ preventScroll: true }), 400);
}
