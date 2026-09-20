"use client";

/**
 * Shared PDP primitives: star rating (display + input), skeleton blocks
 * and the quantity stepper.
 *
 * Accessibility:
 *   - `StarRatingInput` is a real radio group (`role="radiogroup"`) so screen
 *     readers announce "3 of 5 stars" and arrow keys move focus.
 *   - `QuantityInput` exposes `aria-label`s on the +/- buttons and announces
 *     the current value through `aria-live="polite"`.
 */

import { useId, useRef, type KeyboardEvent } from "react";
import { useTranslations } from "next-intl";
import { IconStar } from "@/features/home/components/Icons";
import { clampQuantity } from "../schemas";

/* ------------------------------------------------------------------ */
/* Star display                                                        */
/* ------------------------------------------------------------------ */

export function StarRating({
  rating,
  size = 14,
  className = "",
  showValue = false,
  count,
}: {
  rating: number;
  size?: number;
  className?: string;
  showValue?: boolean;
  count?: number;
}) {
  const t = useTranslations("pdp");
  const rounded = Math.round(rating);
  return (
    <span
      className={`inline-flex items-center gap-1 ${className}`}
      role="img"
      aria-label={t("info.reviewsCount", { count: count ?? rating })}
    >
      {Array.from({ length: 5 }).map((_, i) => (
        <IconStar
          key={i}
          size={size}
          className={i < rounded ? "text-secondary-500" : "text-muted-200"}
        />
      ))}
      {showValue && (
        <span className="ms-1 text-[12px] font-bold text-muted-500 tabular">{rating.toFixed(1)}</span>
      )}
    </span>
  );
}

/* ------------------------------------------------------------------ */
/* Star input (radio group)                                            */
/* ------------------------------------------------------------------ */

export function StarRatingInput({
  value,
  onChange,
  disabled = false,
  error = false,
}: {
  value: number;
  onChange: (next: number) => void;
  disabled?: boolean;
  error?: boolean;
}) {
  const t = useTranslations("pdp");
  const groupName = useId();
  const refs = useRef<Array<HTMLButtonElement | null>>([]);

  const focusSibling = (index: number) => {
    const target = refs.current[index];
    if (target) target.focus();
  };

  const onKeyDown = (index: number) => (event: KeyboardEvent<HTMLButtonElement>) => {
    switch (event.key) {
      case "ArrowRight":
      case "ArrowUp":
        event.preventDefault();
        focusSibling(Math.min(4, index + 1));
        onChange(index + 2);
        break;
      case "ArrowLeft":
      case "ArrowDown":
        event.preventDefault();
        focusSibling(Math.max(0, index - 1));
        onChange(index);
        break;
      case "Home":
        event.preventDefault();
        focusSibling(0);
        onChange(1);
        break;
      case "End":
        event.preventDefault();
        focusSibling(4);
        onChange(5);
        break;
      default:
        break;
    }
  };

  return (
    <div
      role="radiogroup"
      aria-label={t("reviews.rating")}
      aria-describedby="rating-hint"
      aria-invalid={error}
      className="inline-flex items-center gap-1"
    >
      {Array.from({ length: 5 }).map((_, index) => {
        const starValue = index + 1;
        const isActive = value >= starValue;
        return (
          <button
            key={starValue}
            ref={(el) => {
              refs.current[index] = el;
            }}
            type="button"
            role="radio"
            aria-checked={isActive}
            aria-label={t("gallery.indicator", { index: starValue, total: 5 })}
            disabled={disabled}
            onClick={() => onChange(starValue)}
            onKeyDown={onKeyDown(index)}
            className={`rounded-md p-0.5 transition-transform duration-150 hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 disabled:cursor-not-allowed disabled:opacity-50 ${
              isActive ? "text-secondary-500" : "text-muted-200 hover:text-secondary-300"
            }`}
          >
            <IconStar size={26} className={isActive ? "fill-current" : ""} />
          </button>
        );
      })}
      <span id="rating-hint" className="sr-only">
        {t("reviews.ratingHint")}
      </span>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Quantity stepper                                                    */
/* ------------------------------------------------------------------ */

export function QuantityInput({
  value,
  onChange,
  min = 1,
  max,
  disabled = false,
  label,
}: {
  value: number;
  onChange: (next: number) => void;
  min?: number;
  max: number;
  disabled?: boolean;
  label?: string;
}) {
  const t = useTranslations("pdp");
  const upper = Math.max(min, max);
  const atMin = value <= min;
  const atMax = value >= upper;

  return (
    <div className="flex flex-col gap-1.5">
      {label && (
        <span className="text-[12px] font-bold text-muted-500" id="qty-label">
          {label}
        </span>
      )}
      <div
        className="inline-flex items-center rounded-xl border border-muted-200 bg-surface p-1 focus-within:border-secondary-500 focus-within:ring-2 focus-within:ring-secondary-500/30"
        role="group"
        aria-labelledby={label ? "qty-label" : undefined}
      >
        <button
          type="button"
          onClick={() => onChange(clampQuantity(value - 1, upper))}
          disabled={disabled || atMin}
          aria-label={t("actions.decrease")}
          className="grid h-8 w-9 place-items-center rounded-lg text-muted-700 transition-colors hover:bg-primary-50 disabled:pointer-events-none disabled:opacity-40"
        >
          <span aria-hidden="true" className="text-lg leading-none">−</span>
        </button>
        <input
          type="number"
          inputMode="numeric"
          pattern="[0-9]*"
          value={Number.isFinite(value) ? value : ""}
          min={min}
          max={upper}
          disabled={disabled}
          aria-label={t("actions.quantity")}
          aria-live="polite"
          onChange={(event) => onChange(clampQuantity(Number(event.target.value), upper))}
          onBlur={(event) => onChange(clampQuantity(Number(event.target.value) || min, upper))}
          className="h-8 w-12 border-0 bg-transparent text-center font-bold text-muted-900 outline-none tabular [appearance:textfield] focus:ring-0 disabled:opacity-50"
        />
        <button
          type="button"
          onClick={() => onChange(clampQuantity(value + 1, upper))}
          disabled={disabled || atMax}
          aria-label={t("actions.increase")}
          className="grid h-8 w-9 place-items-center rounded-lg text-muted-700 transition-colors hover:bg-primary-50 disabled:pointer-events-none disabled:opacity-40"
        >
          <span aria-hidden="true" className="text-lg leading-none">+</span>
        </button>
      </div>
      {value >= upper && upper > 0 && (
        <span className="text-[11px] font-medium text-warning" role="status">
          {t("actions.maxQuantity", { count: upper })}
        </span>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Skeletons (shape-matched to the real components)                    */
/* ------------------------------------------------------------------ */

export function Skeleton({ className = "" }: { className?: string }) {
  return <div className={`animate-pulse rounded-lg bg-muted-200/70 ${className}`} aria-hidden="true" />;
}

export function GallerySkeleton() {
  return (
    <div className="space-y-3" aria-hidden="true">
      <Skeleton className="aspect-square w-full rounded-2xl" />
      <div className="flex gap-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-16 shrink-0 rounded-lg" />
        ))}
      </div>
    </div>
  );
}

export function InfoSkeleton() {
  return (
    <div className="space-y-4" aria-hidden="true">
      <Skeleton className="h-4 w-24" />
      <Skeleton className="h-9 w-3/4" />
      <Skeleton className="h-5 w-1/3" />
      <Skeleton className="h-20 w-full" />
      <Skeleton className="h-12 w-2/3" />
      <Skeleton className="h-11 w-full" />
    </div>
  );
}

export function ReviewsSkeleton() {
  return (
    <div className="space-y-4" aria-hidden="true">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="space-y-2.5 rounded-xl border border-muted-200/70 p-4">
          <div className="flex items-center gap-3">
            <Skeleton className="h-9 w-9 rounded-full" />
            <Skeleton className="h-4 w-28" />
          </div>
          <Skeleton className="h-3 w-20" />
          <Skeleton className="h-3 w-full" />
          <Skeleton className="h-3 w-5/6" />
        </div>
      ))}
    </div>
  );
}

export function RelatedSkeleton() {
  return (
    <div className="grid grid-cols-2 gap-4 md:grid-cols-4" aria-hidden="true">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="space-y-2.5">
          <Skeleton className="aspect-square w-full rounded-xl" />
          <Skeleton className="h-3 w-3/4" />
          <Skeleton className="h-4 w-1/2" />
        </div>
      ))}
    </div>
  );
}
