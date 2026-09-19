"use client";

/**
 * Variant selector: renders one control per product attribute (size, color,
 * …) and resolves the matching variant.
 *
 * The backend models variants as rows carrying attribute values; we group
 * those values by attribute name (`ProductAttribute.options`) and let the
 * user pick one value per group. When the chosen combination maps to an
 * inactive/missing variant we show the "unavailable" notice and disable the
 * CTA (handled by the parent via `resolvedVariant === null`).
 */

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import type { ProductAttribute, ProductDetail, ProductVariant } from "../types";
import type { VariantSelection } from "../schemas";

export function resolveVariant(
  variants: ProductVariant[],
  selection: VariantSelection,
): ProductVariant | null {
  const entries = Object.entries(selection).filter(([, value]) => value);
  if (entries.length === 0) return null;
  return (
    variants.find((variant) =>
      entries.every(([name, value]) => variant.attributes[name] === value),
    ) ?? null
  );
}

/** True when every attribute group has a selected value. */
export function isSelectionComplete(
  attributes: ProductAttribute[],
  selection: VariantSelection,
): boolean {
  return attributes.every((attr) => Boolean(selection[attr.name]));
}

export function VariantSelector({
  product,
  selection,
  onSelectionChange,
}: {
  product: ProductDetail;
  selection: VariantSelection;
  onSelectionChange: (next: VariantSelection) => void;
}) {
  const t = useTranslations("pdp");
  const attributes = product.attributes;

  const resolved = useMemo(
    () => resolveVariant(product.variants, selection),
    [product.variants, selection],
  );
  const complete = isSelectionComplete(attributes, selection);
  const unavailable = complete && !resolved;

  if (attributes.length === 0) return null;

  const pick = (name: string, value: string) =>
    onSelectionChange({ ...selection, [name]: value });

  return (
    <section className="space-y-4" aria-labelledby="variant-title">
      <h2 id="variant-title" className="font-display text-[15px] font-extrabold text-muted-900">
        {t("variants.title")}
      </h2>

      {attributes.map((attr) => {
        const selected = selection[attr.name];
        return (
          <fieldset key={attr.id} className="space-y-2">
            <legend className="text-[12.5px] font-bold text-muted-500">
              {selected
                ? t("variants.selected", { name: attr.name, value: selected })
                : t("variants.select", { name: attr.name })}
            </legend>
            <div className="flex flex-wrap gap-2">
              {attr.options.map((option) => {
                const active = selected === option.value;
                return (
                  <button
                    key={option.value}
                    type="button"
                    aria-pressed={active}
                    onClick={() => pick(attr.name, option.value)}
                    className={`min-h-10 rounded-xl border px-4 text-[13px] font-bold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 ${
                      active
                        ? "border-primary-800 bg-primary-800 text-white"
                        : "border-muted-200 bg-surface text-muted-700 hover:border-secondary-500 hover:text-secondary-700"
                    }`}
                  >
                    {option.value}
                  </button>
                );
              })}
            </div>
          </fieldset>
        );
      })}

      {unavailable && (
        <p className="flex items-center gap-2 text-[12.5px] font-medium text-danger" role="alert">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v4.5M12 16h.01" />
          </svg>
          {t("variants.unavailable")}
        </p>
      )}

      {resolved && product.variants.length > 1 && (
        <p className="text-[12px] font-medium text-muted-400">
          {resolved.sku ? `${t("info.sku")}: ${resolved.sku}` : null}
        </p>
      )}
    </section>
  );
}
