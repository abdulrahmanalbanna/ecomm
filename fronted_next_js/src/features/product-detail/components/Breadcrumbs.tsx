"use client";

/**
 * Breadcrumb navigation: Home > Category > Product.
 *
 * Uses `next-intl`'s `Link` so the locale prefix is preserved. The final
 * (current) crumb is rendered as an `aria-current="page"` span rather than a
 * link, per WCAG breadcrumb guidance.
 */

import { Fragment } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/lib/i18n/navigation";
import type { ProductDetail } from "../types";

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

export function Breadcrumbs({ product }: { product: ProductDetail }) {
  const t = useTranslations("pdp");

  const items: BreadcrumbItem[] = [
    { label: t("breadcrumbHome"), href: "/" },
    ...(product.category
      ? [{ label: product.category.name, href: `/products?category=${encodeURIComponent(product.category.slug)}` } as BreadcrumbItem]
      : []),
    { label: product.name },
  ];

  return (
    <nav aria-label="Breadcrumb" className="text-[12.5px]">
      <ol className="flex flex-wrap items-center gap-1.5 text-muted-400">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          return (
            <Fragment key={`${item.label}-${index}`}>
              <li>
                {item.href && !isLast ? (
                  <Link
                    href={item.href}
                    className="font-medium transition-colors hover:text-primary-800 focus-visible:outline-none focus-visible:underline"
                  >
                    {item.label}
                  </Link>
                ) : (
                  <span
                    aria-current={isLast ? "page" : undefined}
                    className={isLast ? "font-bold text-muted-700 line-clamp-1" : "font-medium"}
                  >
                    {item.label}
                  </span>
                )}
              </li>
              {!isLast && (
                <li aria-hidden="true" className="text-muted-300">
                  /
                </li>
              )}
            </Fragment>
          );
        })}
      </ol>
    </nav>
  );
}
