"use client";

/**
 * Product gallery: carousel + thumbnail strip + hover zoom + mobile swipe.
 *
 * Behaviour:
 *   - Desktop: hover over the main image pans a magnified crop (CSS
 *     transform on a scaled `<img>`); clicking opens a full-size dialog.
 *   - Mobile: horizontal swipe navigates between images (touch events with
 *     a 40px threshold so a tap never triggers a slide change).
 *   - Keyboard: `←`/`→` move between images, `Home`/`End` jump, `Enter`
 *     opens the zoom dialog. Focus stays on the main image region.
 *   - Thumbnails are lazy-loaded (`loading="lazy"`) and expose
 *     `aria-current` for the active one.
 *
 * The zoom dialog is dynamically imported (`next/dynamic`) so the heavy
 * full-viewport overlay never ships in the initial JS bundle.
 */

import Image from "next/image";
import dynamic from "next/dynamic";
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { useTranslations } from "next-intl";
import type { ProductImage } from "../types";

const ZoomDialog = dynamic(() => import("./ZoomDialog").then((m) => m.ZoomDialog), {
  ssr: false,
  loading: () => null,
});

const SWIPE_THRESHOLD_PX = 40;

export function ProductGallery({ images, name }: { images: ProductImage[]; name: string }) {
  const t = useTranslations("pdp.gallery");
  const [index, setIndex] = useState(0);
  const [zoomOpen, setZoomOpen] = useState(false);
  const mainRef = useRef<HTMLDivElement | null>(null);
  const [pan, setPan] = useState<{ x: number; y: number } | null>(null);

  const count = images.length;
  const current = images[Math.min(index, count - 1)] ?? images[0];

  const go = useCallback(
    // Pan resets with the slide change (event handler, not an effect) so the
    // magnified crop never carries over to the next image.
    (next: number) => {
      setIndex(((next % count) + count) % count);
      setPan(null);
    },
    [count],
  );

  const previous = useCallback(() => go(index - 1), [go, index]);
  const next = useCallback(() => go(index + 1), [go, index]);

  // Keyboard navigation on the main image region.
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    switch (event.key) {
      case "ArrowLeft":
        event.preventDefault();
        previous();
        break;
      case "ArrowRight":
        event.preventDefault();
        next();
        break;
      case "Home":
        event.preventDefault();
        go(0);
        break;
      case "End":
        event.preventDefault();
        go(count - 1);
        break;
      case "Enter":
      case " ":
        event.preventDefault();
        setZoomOpen(true);
        break;
      default:
        break;
    }
  };

  // Mobile swipe handling.
  const touchStart = useRef<number | null>(null);
  const onTouchStart = (clientX: number) => {
    touchStart.current = clientX;
  };
  const onTouchEnd = (clientX: number) => {
    if (touchStart.current == null) return;
    const delta = clientX - touchStart.current;
    if (Math.abs(delta) > SWIPE_THRESHOLD_PX) {
      // In RTL the gesture direction is inverted.
      go(index + (delta < 0 ? 1 : -1));
    }
    touchStart.current = null;
  };

  // Hover zoom: translate the scaled image toward the pointer.
  const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
    const el = mainRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const x = (event.clientX - rect.left) / rect.width;
    const y = (event.clientY - rect.top) / rect.height;
    setPan({ x: Math.min(1, Math.max(0, x)), y: Math.min(1, Math.max(0, y)) });
  };

  // Pan is cleared by `go()` on slide change and by `onPointerLeave` below.

  if (count === 0) {
    return (
      <div className="grid aspect-square place-items-center rounded-2xl border border-dashed border-muted-200 bg-primary-50/50 p-8 text-center">
        <p className="text-sm font-medium text-muted-400">{t("empty")}</p>
      </div>
    );
  }

  return (
    <section aria-label={t("label")} className="space-y-3">
      {/* main image */}
      <div
        ref={mainRef}
        tabIndex={0}
        role="button"
        aria-label={t("zoomOpen")}
        onKeyDown={onKeyDown}
        onClick={() => setZoomOpen(true)}
        onPointerMove={onPointerMove}
        onPointerLeave={() => setPan(null)}
        onTouchStart={(e) => onTouchStart(e.touches[0]?.clientX ?? 0)}
        onTouchEnd={(e) => onTouchEnd(e.changedTouches[0]?.clientX ?? 0)}
        className="group relative aspect-square w-full cursor-zoom-in overflow-hidden rounded-2xl border border-muted-200/70 bg-gradient-to-b from-primary-50 to-muted-200/40 outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 focus-visible:ring-offset-2"
      >
        <Image
          src={current.url}
          alt={current.alt}
          fill
          priority={index === 0}
          sizes="(min-width: 1024px) 50vw, 100vw"
          className={`object-cover transition-transform duration-300 ease-out group-hover:scale-[1.8] ${
            pan ? "transition-none" : ""
          }`}
          style={
            pan
              ? { transformOrigin: `${pan.x * 100}% ${pan.y * 100}%` }
              : undefined
          }
        />
        {/* hover hint */}
        <span className="pointer-events-none absolute bottom-3 end-3 inline-flex items-center gap-1.5 rounded-full bg-primary-950/80 px-3 py-1 text-[11px] font-bold text-background opacity-0 transition-opacity duration-300 group-hover:opacity-100">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5M11 8v6M8 11h6" />
          </svg>
          {t("zoom")}
        </span>

        {/* arrows (desktop) */}
        {count > 1 && (
          <>
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                previous();
              }}
              aria-label={t("previous")}
              className="absolute top-1/2 start-2 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-surface/90 text-muted-700 shadow-soft transition-all hover:bg-surface hover:text-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
            >
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="m15 5-7 7 7 7" />
              </svg>
            </button>
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                next();
              }}
              aria-label={t("next")}
              className="absolute top-1/2 end-2 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-surface/90 text-muted-700 shadow-soft transition-all hover:bg-surface hover:text-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
            >
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="m9 5 7 7-7 7" />
              </svg>
            </button>
          </>
        )}

        {/* counter */}
        <span
          className="pointer-events-none absolute bottom-3 start-3 rounded-full bg-primary-950/75 px-2.5 py-0.5 text-[11px] font-bold text-background tabular"
          role="status"
        >
          {t("indicator", { index: index + 1, total: count })}
        </span>
      </div>

      {/* thumbnails */}
      {count > 1 && (
        <div
          className="flex gap-2 overflow-x-auto no-scrollbar pb-1"
          role="tablist"
          aria-label={t("label")}
        >
          {images.map((image, i) => {
            const active = i === index;
            return (
              <button
                key={image.id}
                type="button"
                role="tab"
                aria-selected={active}
                aria-current={active ? "true" : undefined}
                aria-label={t("thumbnail", { index: i + 1, total: count })}
                onClick={() => go(i)}
                className={`relative h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2 bg-primary-50 transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 ${
                  active
                    ? "border-secondary-500 ring-2 ring-secondary-500/30"
                    : "border-transparent opacity-70 hover:opacity-100"
                }`}
              >
                <Image
                  src={image.url}
                  alt=""
                  fill
                  loading="lazy"
                  sizes="64px"
                  className="object-cover"
                />
              </button>
            );
          })}
        </div>
      )}

      {zoomOpen && (
        <ZoomDialog
          images={images}
          name={name}
          initialIndex={index}
          onClose={() => setZoomOpen(false)}
        />
      )}
    </section>
  );
}
