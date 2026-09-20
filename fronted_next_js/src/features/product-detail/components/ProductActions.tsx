"use client";

/**
 * Product actions: quantity selector, add-to-cart, wishlist toggle, share.
 *
 * State:
 *   - Quantity is validated with `quantitySchema(max)` (Zod) before the cart
 *     mutation runs, and clamped again inside the Zustand store so a stale
 *     `max` can never produce an over-quantity line.
 *   - Add to cart is an *optimistic* Zustand update (`useAddToCart`); the
 *     drawer opens immediately and the toast is emitted by the store.
 *   - Wishlist requires an authenticated session; guests see a sign-in
 *     prompt instead of a failed request.
 *   - Share uses the Web Share API with a clipboard fallback.
 */

import { useState } from "react";
import { useTranslations } from "next-intl";
import { IconCart, IconCheck } from "@/features/home/components/Icons";
import { useAddToCart, useInCartQty, useToggleWishlist, useWishlist } from "../hooks";
import { useSession } from "@/lib/auth/session";
import { clampQuantity, quantitySchema } from "../schemas";
import { QuantityInput } from "./Primitives";
import type { ProductDetail, ProductVariant } from "../types";

export function ProductActions({
  product,
  variant,
  quantity,
  onQuantityChange,
}: {
  product: ProductDetail;
  variant: ProductVariant | null;
  quantity: number;
  onQuantityChange: (next: number) => void;
}) {
  const t = useTranslations("pdp");
  const session = useSession();
  const addToCart = useAddToCart();
  const inCart = useInCartQty(product.id);
  const wishlist = useWishlist();
  const toggleWishlist = useToggleWishlist(product.id);
  const [shared, setShared] = useState(false);

  const isOutOfStock = product.stock.availability === "out-of-stock";
  const isPreorder = product.stock.availability === "preorder";
  const max = Math.max(1, product.stock.quantity || (isPreorder ? 12 : 99));
  const price = variant?.price ?? 0;

  const validation = quantitySchema(max).safeParse(quantity);
  const [touched, setTouched] = useState(false);
  const showQtyError = touched && !validation.success;

  const handleAdd = () => {
    setTouched(true);
    if (!validation.success) return;
    addToCart.mutate({
      productId: product.id,
      productName: product.name,
      quantity,
      max,
      toast: t("info.addedToast", { name: product.name }),
    });
  };

  const handleWishlist = () => {
    if (session.status !== "authenticated") return;
    const already = Boolean(wishlist.data?.includes(product.id));
    toggleWishlist.mutate(!already);
  };

  const handleShare = async () => {
    const shareData = {
      title: product.seoTitle,
      text: t("actions.shareTitle", { name: product.name }),
      url: product.url,
    };
    try {
      if (typeof navigator !== "undefined" && navigator.share) {
        await navigator.share(shareData);
        return;
      }
      if (typeof navigator !== "undefined" && navigator.clipboard) {
        await navigator.clipboard.writeText(product.url);
      }
    } catch {
      // User cancelled the share sheet, or the clipboard API is unavailable.
      return;
    }
    setShared(true);
    window.setTimeout(() => setShared(false), 2000);
  };

  const inWishlist = Boolean(wishlist.data?.includes(product.id));
  const pending = addToCart.isPending;

  return (
    <div className="space-y-4">
      {/* quantity + CTA — single row, both controls vertically centered */}
      <div className="flex items-center gap-8">
        <QuantityInput
          label={t("actions.quantity")}
          value={quantity}
          onChange={onQuantityChange}
          max={max}
          disabled={isOutOfStock}
        />
        <div className="flex min-w-0 flex-1 flex-col justify-center">
          <button
            type="button"
            onClick={handleAdd}
            disabled={isOutOfStock || pending}
            aria-live="polite"
             className={`relative inline-flex h-10 mt-6 w-full items-center justify-center gap-2 self-stretch overflow-hidden rounded-xl font-display text-[15px] font-extrabold transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 active:scale-[0.99] ${
              isOutOfStock
                ? "bg-muted-200 text-muted-400"
                : "bg-primary-800 text-white hover:bg-secondary-500 hover:text-primary-950"
            }`}
          >
            {isOutOfStock ? (
              t("actions.outOfStock")
            ) : pending ? (
              t("actions.adding")
            ) : addToCart.isSuccess ? (
              <>
                <IconCheck size={17} />
                {t("actions.added")}
              </>
            ) : isPreorder ? (
              <>
                <IconCart size={17} />
                {t("actions.preorder")}
              </>
            ) : (
              <>
                <IconCart size={17} />
                {t("actions.addToCart")}
              </>
            )}
          </button>
        </div>
      </div>

      {/* quantity validation + in-cart status, below the CTA row */}
      <div className="space-y-1">
        {showQtyError && (
          <p className="text-[12px] font-medium text-danger" role="alert">
            {t("actions.minQuantity")}
          </p>
        )}
        {inCart > 0 && !isOutOfStock && (
          <p className="text-[12px] font-medium text-muted-500" role="status">
            {t("actions.added")} ({inCart})
          </p>
        )}
      </div>

      {/* price + VAT note */}
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-y border-dashed border-muted-200 py-3">
        <span className="font-display text-2xl font-black text-primary-800 tabular">
          {price.toLocaleString("en-US")}
        </span>
        <span className="text-[12px] font-bold text-muted-400">SAR</span>
        {variant?.compareAtPrice ? (
          <>
            <span className="text-[13px] text-muted-300 line-through tabular">
              {variant.compareAtPrice.toLocaleString("en-US")}
            </span>
            <span className="rounded-md bg-danger-soft px-2 py-0.5 text-[11px] font-bold text-danger">
              {t("info.saveAmount", {
                amount: (variant.compareAtPrice - price).toLocaleString("en-US"),
              })}
            </span>
          </>
        ) : null}
        <span className="ms-auto text-[11px] text-muted-400">{t("info.vatNote")}</span>
      </div>

      {/* secondary actions */}
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={handleWishlist}
          disabled={session.status !== "authenticated" || toggleWishlist.isPending}
          aria-pressed={inWishlist}
          aria-label={
            session.status !== "authenticated"
              ? t("actions.wishlistGuest")
              : inWishlist
                ? t("actions.wishlistRemove")
                : t("actions.wishlist")
          }
          title={
            session.status !== "authenticated" ? t("actions.wishlistGuest") : undefined
          }
          className={`inline-flex h-11 items-center gap-2 rounded-xl border px-4 text-[13px] font-bold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 disabled:cursor-not-allowed disabled:opacity-60 ${
            inWishlist
              ? "border-danger/40 bg-danger-soft text-danger"
              : "border-muted-200 bg-surface text-muted-700 hover:border-secondary-500 hover:text-secondary-700"
          }`}
        >
          <svg
            width={16}
            height={16}
            viewBox="0 0 24 24"
            fill={inWishlist ? "currentColor" : "none"}
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M12 20.5S3.5 15 3.5 9.2A4.7 4.7 0 0 1 12 6.4a4.7 4.7 0 0 1 8.5 2.8c0 5.8-8.5 11.3-8.5 11.3Z" />
          </svg>
          {inWishlist ? t("actions.wishlistAdded") : t("actions.wishlist")}
        </button>

        <button
          type="button"
          onClick={handleShare}
          aria-label={t("actions.share")}
          className="inline-flex h-11 items-center gap-2 rounded-xl border border-muted-200 bg-surface px-4 text-[13px] font-bold text-muted-700 transition-all duration-200 hover:border-secondary-500 hover:text-secondary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
        >
          <svg
            width={16}
            height={16}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <circle cx="18" cy="5.5" r="2.6" />
            <circle cx="6" cy="12" r="2.6" />
            <circle cx="18" cy="18.5" r="2.6" />
            <path d="m8.3 10.7 7.4-4M8.3 13.3l7.4 4" />
          </svg>
          {shared ? t("actions.shareCopied") : t("actions.share")}
        </button>
      </div>
    </div>
  );
}
