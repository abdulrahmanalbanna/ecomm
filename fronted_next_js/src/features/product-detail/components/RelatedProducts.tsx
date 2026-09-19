"use client";

/**
 * "You might also like" rail + a comparison table for similar products.
 *
 * - The rail is a horizontally scrollable, snap-aligned strip with arrow
 *   buttons (hidden when the strip cannot scroll).
 * - Hovering/focusing a card reveals a "Quick preview" button that jumps to
 *   that product's PDP.
 * - The comparison table lists up to 3 products side by side (the current
 *   product is always the first column) and is hidden entirely when there
 *   are no related items.
 */

import Image from "next/image";
import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/lib/i18n/navigation";
import { useRelatedProducts } from "../hooks";
import { formatPrice } from "@/features/home/catalog";
import type { ProductDetail, RelatedProduct } from "../types";
import { RelatedSkeleton, StarRating } from "./Primitives";

const MAX_COMPARE = 3;

export function RelatedProducts({
  product,
  initial,
}: {
  product: ProductDetail;
  initial: RelatedProduct[];
}) {
  const t = useTranslations("pdp");
  const { data, isLoading, isError } = useRelatedProducts(product, initial);
  const items = data ?? [];
  const railRef = useRef<HTMLDivElement | null>(null);
  const [canScroll, setCanScroll] = useState(false);

  const updateScrollState = useCallback(() => {
    const el = railRef.current;
    if (!el) return;
    setCanScroll(el.scrollWidth - el.clientWidth > 8);
  }, []);

  useEffect(() => {
    updateScrollState();
    const el = railRef.current;
    if (!el) return;
    const observer = new ResizeObserver(updateScrollState);
    observer.observe(el);
    return () => observer.disconnect();
  }, [updateScrollState, items.length]);

  const scrollBy = (direction: 1 | -1) => {
    const el = railRef.current;
    if (!el) return;
    el.scrollBy({ left: direction * el.clientWidth * 0.8, behavior: "smooth" });
  };

  if (isLoading) {
    return (
      <section className="space-y-5" aria-busy="true">
        <h2 className="font-display text-xl font-black text-muted-900">{t("related.title")}</h2>
        <RelatedSkeleton />
      </section>
    );
  }

  if (isError || items.length === 0) {
    return null;
  }

  const compareRows: Array<{ id: string; label: string; render: (item: RelatedProduct) => string }> = [
    { id: "price", label: t("compare.price"), render: (i) => `${formatPrice(i.price)} SAR` },
    { id: "brand", label: t("compare.brand"), render: (i) => i.brand ?? "—" },
    { id: "category", label: t("compare.category"), render: (i) => i.category ?? "—" },
    { id: "rating", label: t("compare.rating"), render: (i) => i.rating.toFixed(1) },
    {
      id: "availability",
      label: t("compare.availability"),
      render: (i) => (i.availability === "out-of-stock" ? t("info.outOfStock") : t("info.inStock")),
    },
  ];

  return (
    <section className="space-y-8" aria-labelledby="related-title">
      <div>
        <h2 id="related-title" className="font-display text-xl font-black text-muted-900">
          {t("related.title")}
        </h2>
        <p className="mt-1 text-[13px] text-muted-400">{t("related.subtitle")}</p>
      </div>

      {/* rail */}
      <div className="relative">
        {canScroll && (
          <div className="absolute -top-14 end-0 hidden gap-2 sm:flex">
            <button
              type="button"
              onClick={() => scrollBy(-1)}
              aria-label={t("related.previous")}
              className="grid h-10 w-10 place-items-center rounded-full border border-muted-200 bg-surface text-muted-700 transition-colors hover:border-secondary-500 hover:text-secondary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="m15 5-7 7 7 7" />
              </svg>
            </button>
            <button
              type="button"
              onClick={() => scrollBy(1)}
              aria-label={t("related.next")}
              className="grid h-10 w-10 place-items-center rounded-full border border-muted-200 bg-surface text-muted-700 transition-colors hover:border-secondary-500 hover:text-secondary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="m9 5 7 7-7 7" />
              </svg>
            </button>
          </div>
        )}

        <div
          ref={railRef}
          className="no-scrollbar -mx-1 flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth px-1 pb-2"
        >
          {items.map((item) => (
            <RelatedCard key={item.id} item={item} />
          ))}
        </div>
      </div>

      {/* comparison table */}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[34rem] border-collapse text-[13px]">
          <caption className="pb-3 text-start text-[12.5px] font-bold text-muted-400">
            {t("compare.title")}
          </caption>
          <thead>
            <tr className="border-b border-muted-200">
              <th scope="col" className="py-3 text-start text-[12px] font-bold text-muted-400">
                {t("compare.title")}
              </th>
              <th scope="col" className="py-3 text-start font-bold text-primary-800">
                {product.name}
              </th>
              {items.slice(0, MAX_COMPARE - 1).map((item) => (
                <th key={item.id} scope="col" className="py-3 text-start font-bold text-muted-700">
                  {item.name}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {compareRows.map((row) => (
              <tr key={row.id} className="border-b border-muted-100">
                <th scope="row" className="py-3 text-start text-[12px] font-medium text-muted-400">
                  {row.label}
                </th>
                <td className="py-3 font-bold text-muted-800">
                  {row.id === "price"
                    ? `${formatPrice(product.variants[0]?.price ?? 0)} SAR`
                    : row.id === "brand"
                      ? product.brand?.name ?? "—"
                      : row.id === "category"
                        ? product.category?.name ?? "—"
                        : row.id === "rating"
                          ? product.summary.average.toFixed(1)
                          : t("info.inStock")}
                </td>
                {items.slice(0, MAX_COMPARE - 1).map((item) => (
                  <td key={item.id} className="py-3 font-medium text-muted-600">
                    {row.render(item)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function RelatedCard({ item }: { item: RelatedProduct }) {
  const t = useTranslations("pdp");
  const discount = item.compareAtPrice
    ? Math.round(((item.compareAtPrice - item.price) / item.compareAtPrice) * 100)
    : 0;

  return (
    <article className="group relative flex w-56 shrink-0 snap-start flex-col overflow-hidden rounded-xl border border-muted-200/70 bg-surface transition-all duration-300 hover:-translate-y-1.5 hover:border-primary-300 hover:shadow-lift sm:w-64">
      <Link
        href={`/products/${encodeURIComponent(item.slug)}`}
        className="relative block aspect-square overflow-hidden bg-gradient-to-b from-primary-50 to-muted-200/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
      >
        <Image
          src={item.image}
          alt={item.name}
          fill
          loading="lazy"
          sizes="(min-width: 640px) 16rem, 14rem"
          className="object-cover transition-transform duration-500 group-hover:scale-[1.07]"
        />
        {discount > 0 && (
          <span className="absolute top-2.5 end-2.5 rounded-md bg-danger px-2 py-0.5 text-[10.5px] font-bold text-white">
            {t("actions.compare")} {discount}%
          </span>
        )}
        {/* quick preview */}
        <span className="absolute inset-x-3 bottom-3 flex translate-y-3 items-center justify-center gap-1.5 rounded-lg bg-surface/95 py-2 text-[12px] font-bold text-primary-900 opacity-0 shadow-soft transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:translate-y-0 group-focus-within:opacity-100">
          {t("quickPreview")}
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="m9 5 7 7-7 7" />
          </svg>
        </span>
      </Link>

      <div className="flex flex-1 flex-col p-3.5">
        <div className="flex items-center gap-1.5 text-[11px] text-muted-400">
          <StarRating rating={item.rating} size={11} />
          <span className="font-bold text-muted-500 tabular">{item.rating.toFixed(1)}</span>
        </div>
        <h3 className="mt-1 line-clamp-2 min-h-[2.5rem] font-display text-[13px] font-bold leading-6 text-muted-900">
          {item.name}
        </h3>
        <div className="mt-2 flex items-baseline gap-1.5">
          <span className="font-display text-base font-extrabold text-primary-800 tabular">
            {formatPrice(item.price)}
          </span>
          <span className="text-[10.5px] font-bold text-muted-400">SAR</span>
          {item.compareAtPrice && (
            <span className="text-[11px] text-muted-300 line-through tabular">
              {formatPrice(item.compareAtPrice)}
            </span>
          )}
        </div>
      </div>
    </article>
  );
}
