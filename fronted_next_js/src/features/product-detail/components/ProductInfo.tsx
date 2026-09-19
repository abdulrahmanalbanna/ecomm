"use client";

/**
 * Product information block: name, price, rating, inventory status,
 * expandable description and the SKU/brand/category meta grid.
 *
 * The description collapses to 4 lines with a "Read more" toggle so the
 * above-the-fold area stays compact on mobile.
 */

import { useId, useState } from "react";
import { useTranslations } from "next-intl";
import { StarRating } from "./Primitives";
import type { ProductDetail, ProductVariant } from "../types";

const DESCRIPTION_COLLAPSED_LINES = 4;

export function ProductInfo({
  product,
  variant,
  reviewCount,
  averageRating,
}: {
  product: ProductDetail;
  variant: ProductVariant | null;
  reviewCount: number;
  averageRating: number;
}) {
  const t = useTranslations("pdp");
  const [expanded, setExpanded] = useState(false);
  const descId = useId();

  const price = variant?.price ?? 0;
  const stock = product.stock;
  const lowStock = stock.availability === "low-stock";
  const outOfStock = stock.availability === "out-of-stock";
  const isPreorder = stock.availability === "preorder";

  const stockTone = outOfStock
    ? { dot: "bg-danger", text: "text-danger", soft: "bg-danger-soft" }
    : lowStock
      ? { dot: "bg-warning", text: "text-warning", soft: "bg-warning-soft" }
      : { dot: "bg-success", text: "text-success", soft: "bg-success-soft" };

  const stockLabel = outOfStock
    ? t("info.outOfStock")
    : lowStock
      ? t("info.lowStock", { count: stock.quantity })
      : isPreorder
        ? t("info.preorder", { days: stock.leadTimeDays ?? 7 })
        : t("info.inStock");

  return (
    <div className="space-y-5">
      {/* brand + name */}
      {product.brand && (
        <p className="text-[12px] font-bold uppercase tracking-wide text-secondary-700">
          {product.brand.name}
        </p>
      )}
      <h1 className="font-display text-2xl font-black leading-tight text-muted-900 sm:text-3xl">
        {product.name}
      </h1>

      {/* rating */}
      <div className="flex flex-wrap items-center gap-2.5">
        <StarRating rating={averageRating} size={15} showValue />
        <span className="text-[12.5px] font-medium text-muted-400">
          {reviewCount > 0
            ? t("info.reviewsCount", { count: reviewCount })
            : t("info.noReviews")}
        </span>
      </div>

      {/* stock status */}
      <div
        className={`inline-flex items-center gap-2 rounded-lg ${stockTone.soft} px-3 py-1.5 text-[12.5px] font-bold ${stockTone.text}`}
        role="status"
      >
        <span className={`h-2 w-2 rounded-full ${stockTone.dot} ${lowStock ? "pulse-dot" : ""}`} />
        {stockLabel}
      </div>

      {/* expandable description */}
      {product.description ? (
        <div>
          <p
            id={descId}
            className={`text-[14px] leading-7 text-muted-500 ${
              expanded ? "" : `line-clamp-${DESCRIPTION_COLLAPSED_LINES}`
            }`}
          >
            {product.description}
          </p>
          {product.description.length > 220 && (
            <button
              type="button"
              onClick={() => setExpanded((v) => !v)}
              aria-expanded={expanded}
              aria-controls={descId}
              className="mt-2 inline-flex items-center gap-1 text-[13px] font-bold text-secondary-700 transition-colors hover:text-secondary-600 focus-visible:outline-none focus-visible:underline"
            >
              {expanded ? t("info.readLess") : t("info.readMore")}
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.4"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
                className={`transition-transform duration-300 ${expanded ? "rotate-180" : ""}`}
              >
                <path d="m6 9 6 6 6-6" />
              </svg>
            </button>
          )}
        </div>
      ) : null}

      {/* meta grid */}
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-muted-200 pt-4 text-[12.5px] sm:grid-cols-3">
        {variant?.sku ? (
          <div>
            <dt className="font-medium text-muted-400">{t("info.sku")}</dt>
            <dd className="font-bold text-muted-700 tabular">{variant.sku}</dd>
          </div>
        ) : null}
        {product.brand ? (
          <div>
            <dt className="font-medium text-muted-400">{t("info.brand")}</dt>
            <dd className="font-bold text-muted-700">{product.brand.name}</dd>
          </div>
        ) : null}
        {product.category ? (
          <div>
            <dt className="font-medium text-muted-400">{t("info.category")}</dt>
            <dd className="font-bold text-muted-700">{product.category.name}</dd>
          </div>
        ) : null}
        <div>
          <dt className="font-medium text-muted-400">{t("info.availability")}</dt>
          <dd className={`font-bold ${stockTone.text}`}>{stockLabel}</dd>
        </div>
      </dl>
    </div>
  );
}
