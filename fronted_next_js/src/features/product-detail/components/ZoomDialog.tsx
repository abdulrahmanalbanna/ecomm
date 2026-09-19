"use client";

/**
 * Full-viewport zoom dialog for the product gallery.
 *
 * Rendered through `next/dynamic` (`ssr: false`) from `ProductGallery`, so it
 * is excluded from the initial client bundle. Focus is trapped inside the
 * dialog while open and `Escape` closes it.
 */

import Image from "next/image";
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { useTranslations } from "next-intl";
import type { ProductImage } from "../types";

export function ZoomDialog({
  images,
  name,
  initialIndex,
  onClose,
}: {
  images: ProductImage[];
  name: string;
  initialIndex: number;
  onClose: () => void;
}) {
  const t = useTranslations("pdp.gallery");
  const [index, setIndex] = useState(initialIndex);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const count = images.length;
  const current = images[Math.min(index, count - 1)] ?? images[0];

  const go = useCallback(
    (next: number) => setIndex(((next % count) + count) % count),
    [count],
  );

  // Focus the close button on mount so keyboard users land somewhere safe.
  useEffect(() => {
    closeButtonRef.current?.focus();
  }, []);

  // Lock body scroll + close on Escape.
  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const onKey = (event: KeyboardEvent | globalThis.KeyboardEvent) => {
      if (event.key === "Escape") {
        onClose();
      } else if (event.key === "ArrowLeft") {
        go(index - 1);
      } else if (event.key === "ArrowRight") {
        go(index + 1);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", onKey);
    };
  }, [go, index, onClose]);

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === "ArrowLeft") {
      event.preventDefault();
      go(index - 1);
    } else if (event.key === "ArrowRight") {
      event.preventDefault();
      go(index + 1);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[120] flex items-center justify-center bg-primary-950/90 p-4 backdrop-blur-sm anim-fade"
      role="dialog"
      aria-modal="true"
      aria-label={t("zoom")}
    >
      <button
        type="button"
        onClick={onClose}
        aria-label={t("zoomClose")}
        className="absolute inset-0 h-full w-full cursor-zoom-out"
      />

      <div className="relative z-10 flex max-h-full w-full max-w-5xl flex-col gap-3">
        <div className="flex justify-end">
          <button
            ref={closeButtonRef}
            type="button"
            onClick={onClose}
            aria-label={t("zoomClose")}
            className="grid h-10 w-10 place-items-center rounded-full bg-surface/15 text-background transition-colors hover:bg-surface/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
              <path d="m6 6 12 12M18 6 6 18" />
            </svg>
          </button>
        </div>

        <div
          className="relative aspect-square w-full overflow-hidden rounded-2xl bg-primary-950"
          onKeyDown={onKeyDown}
          tabIndex={0}
          role="img"
          aria-label={current.alt}
        >
          <Image
            src={current.url}
            alt={current.alt}
            fill
            sizes="(min-width: 1024px) 60vw, 100vw"
            className="object-contain"
          />
        </div>

        <div className="flex items-center justify-between gap-3 text-background">
          <span className="text-[12px] font-bold tabular" role="status">
            {t("indicator", { index: index + 1, total: count })}
          </span>
          <span className="line-clamp-1 text-[12px] font-medium opacity-80">{name}</span>
          {count > 1 && (
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => go(index - 1)}
                aria-label={t("previous")}
                className="grid h-9 w-9 place-items-center rounded-full bg-surface/15 transition-colors hover:bg-surface/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                  <path d="m15 5-7 7 7 7" />
                </svg>
              </button>
              <button
                type="button"
                onClick={() => go(index + 1)}
                aria-label={t("next")}
                className="grid h-9 w-9 place-items-center rounded-full bg-surface/15 transition-colors hover:bg-surface/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                  <path d="m9 5 7 7-7 7" />
                </svg>
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
