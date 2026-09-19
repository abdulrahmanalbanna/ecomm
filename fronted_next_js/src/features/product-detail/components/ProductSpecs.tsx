"use client";

/**
 * Product specifications: tabbed layout (Specifications / Details / Shipping
 * & warranty / Reviews) that degrades to a stacked accordion on mobile.
 *
 * The Reviews tab is a placeholder anchor here — the full reviews section
 * (`ReviewsSection`) is rendered below the tabs and deep-linkable via
 * `#reviews`.
 */

import { useState } from "react";
import { useTranslations } from "next-intl";
import type { ProductDetail } from "../types";

type TabId = "specs" | "description" | "shipping";

export function ProductSpecs({
  product,
  reviewCount,
  onJumpToReviews,
}: {
  product: ProductDetail;
  reviewCount: number;
  onJumpToReviews: () => void;
}) {
  const t = useTranslations("pdp");
  const [active, setActive] = useState<TabId>("specs");

  const tabs: Array<{ id: TabId; label: string }> = [
    { id: "specs", label: t("specs.tabSpecs") },
    { id: "description", label: t("specs.tabDescription") },
    { id: "shipping", label: t("specs.tabShipping") },
  ];

  return (
    <section className="space-y-5" aria-labelledby="specs-title">
      <h2 id="specs-title" className="font-display text-xl font-black text-muted-900">
        {t("specs.title")}
      </h2>

      {/* tab list */}
      <div
        role="tablist"
        aria-label={t("specs.title")}
        className="flex flex-wrap gap-1.5 border-b border-muted-200 pb-px"
      >
        {tabs.map((tab) => {
          const isActive = active === tab.id;
          return (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={isActive}
              aria-controls={`panel-${tab.id}`}
              id={`tab-${tab.id}`}
              onClick={() => setActive(tab.id)}
              className={`relative rounded-t-lg px-4 py-2.5 text-[13px] font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 ${
                isActive
                  ? "text-primary-900"
                  : "text-muted-400 hover:text-muted-700"
              }`}
            >
              {tab.label}
              {isActive && (
                <span
                  aria-hidden="true"
                  className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-secondary-500"
                />
              )}
            </button>
          );
        })}
        <button
          type="button"
          role="tab"
          aria-selected={false}
          onClick={onJumpToReviews}
          className="rounded-t-lg px-4 py-2.5 text-[13px] font-bold text-muted-400 transition-colors hover:text-muted-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
        >
          {t("specs.tabReviews", { count: reviewCount })}
        </button>
      </div>

      {/* panels */}
      {active === "specs" && (
        <div
          role="tabpanel"
          id="panel-specs"
          aria-labelledby="tab-specs"
          tabIndex={0}
          className="anim-fade focus-visible:outline-none"
        >
          {product.specifications.length > 0 ? (
            <dl className="grid grid-cols-1 gap-x-8 gap-y-px overflow-hidden rounded-xl border border-muted-200/80 sm:grid-cols-2">
              {product.specifications.map((spec) => (
                <div
                  key={spec.label}
                  className="flex items-baseline justify-between gap-4 bg-surface px-4 py-3 even:bg-primary-50/40"
                >
                  <dt className="text-[12.5px] font-medium text-muted-400">{spec.label}</dt>
                  <dd className="text-right text-[13px] font-bold text-muted-800">{spec.value}</dd>
                </div>
              ))}
            </dl>
          ) : (
            <p className="rounded-xl border border-dashed border-muted-200 bg-primary-50/40 p-6 text-center text-[13px] text-muted-400">
              {t("specs.noSpecs")}
            </p>
          )}
        </div>
      )}

      {active === "description" && (
        <div
          role="tabpanel"
          id="panel-description"
          aria-labelledby="tab-description"
          tabIndex={0}
          className="anim-fade space-y-4 focus-visible:outline-none"
        >
          <p className="whitespace-pre-line text-[14px] leading-7 text-muted-500">
            {product.description || product.shortDescription}
          </p>
          {product.tags.length > 0 && (
            <ul className="flex flex-wrap gap-2">
              {product.tags.map((tag) => (
                <li
                  key={tag}
                  className="rounded-full bg-primary-50 px-3 py-1 text-[11.5px] font-bold text-primary-800"
                >
                  {tag}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {active === "shipping" && (
        <div
          role="tabpanel"
          id="panel-shipping"
          aria-labelledby="tab-shipping"
          tabIndex={0}
          className="anim-fade grid gap-3 sm:grid-cols-3 focus-visible:outline-none"
        >
          {[
            { icon: "truck", title: t("specs.shippingText") },
            { icon: "shield", title: t("specs.warranty") },
            { icon: "returns", title: t("specs.returns") },
          ].map((card, index) => (
            <div
              key={index}
              className="rounded-xl border border-muted-200/80 bg-surface p-4"
            >
              <span className="mb-2 grid h-9 w-9 place-items-center rounded-lg bg-primary-50 text-primary-800">
                {card.icon === "truck" ? (
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M2.5 5.5h11v11h-11zM13.5 9h4.2l2.8 3.2v4.3h-7" />
                    <circle cx="6.5" cy="17.5" r="1.7" />
                    <circle cx="16.8" cy="17.5" r="1.7" />
                  </svg>
                ) : card.icon === "shield" ? (
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M12 3 5 5.8v5.4c0 4.4 2.9 7.6 7 9.3 4.1-1.7 7-4.9 7-9.3V5.8Z" />
                    <path d="m9 11.6 2.1 2.1 4-4.2" />
                  </svg>
                ) : (
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M4 9h11v9H4zM15 12h3.5l2 2.2V18H15" />
                    <path d="M8 6.5h6" />
                  </svg>
                )}
              </span>
              <p className="text-[12.5px] leading-6 text-muted-500">{card.title}</p>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
