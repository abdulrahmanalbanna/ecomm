"use client";

/**
 * Reviews section: summary histogram, sort control, infinite list and the
 * review submission form (React Hook Form + Zod).
 *
 * Data:
 *   - `useReviewsInfinite` — page-based `useInfiniteQuery` with a
 *     `Load more` button (no virtualization needed at this scale).
 *   - `useSubmitReview` — optimistic prepend into every cached page.
 *
 * Auth: guests see a sign-in callout instead of the form; the mutation
 * itself throws `ApiError(401)` before hitting the network when there is no
 * session (`withAuth`).
 */

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { useSession } from "@/lib/auth/session";
import { useReviewSummary, useReviewsInfinite, useSubmitReview } from "../hooks";
import { reviewSchema, REVIEW_FORM_DEFAULTS, type ReviewFormValues } from "../schemas";
import type { ProductDetail, ReviewSort } from "../types";
import { ReviewsSkeleton, StarRating, StarRatingInput } from "./Primitives";

const SORT_OPTIONS: Array<{ id: ReviewSort; labelKey: string }> = [
  { id: "newest", labelKey: "sortNewest" },
  { id: "helpful", labelKey: "sortHelpful" },
  { id: "highest", labelKey: "sortHighest" },
  { id: "lowest", labelKey: "sortLowest" },
];

function relativeTime(iso: string, t: ReturnType<typeof useTranslations<"pdp.reviews">>) {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const days = Math.max(0, Math.round((Date.now() - date.getTime()) / 86_400_000));
  if (days < 1) return t("ago", { count: "اليوم" });
  if (days < 30) return t("daysAgo", { count: days });
  return t("monthsAgo", { count: Math.round(days / 30) });
}

function initials(name: string): string {
  return name.trim().slice(0, 2).toUpperCase() || "—";
}

export function ReviewsSection({ product }: { product: ProductDetail }) {
  const t = useTranslations("pdp.reviews");
  const [sort, setSort] = useState<ReviewSort>("newest");
  const summary = useReviewSummary(product.id, product.summary);
  const query = useReviewsInfinite(product.id, sort);
  const [formOpen, setFormOpen] = useState(false);

  const reviews = query.data?.pages.flatMap((page) => page.items) ?? [];
  const total = query.data?.pages[0]?.meta.total ?? summary.data?.count ?? 0;
  const hasMore = Boolean(query.hasNextPage);

  return (
    <section id="reviews" className="scroll-mt-24 space-y-6" aria-labelledby="reviews-title">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 id="reviews-title" className="font-display text-xl font-black text-muted-900">
            {t("title")}
          </h2>
          <p className="mt-1 text-[13px] text-muted-400">
            {total > 0 ? t("subtitle", { count: total }) : t("subtitleEmpty")}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setFormOpen((v) => !v)}
          className="inline-flex h-10 items-center gap-2 rounded-xl bg-primary-800 px-4 text-[13px] font-bold text-white transition-colors hover:bg-secondary-500 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
        >
          {t("writeReview")}
        </button>
      </div>

      {/* summary histogram */}
      {summary.data && summary.data.count > 0 && (
        <div className="grid gap-6 rounded-2xl border border-muted-200/80 bg-surface p-5 sm:grid-cols-[auto_1fr]">
          <div className="flex flex-col items-center justify-center gap-1.5 sm:pe-6">
            <span className="font-display text-4xl font-black text-primary-800 tabular">
              {summary.data.average.toFixed(1)}
            </span>
            <StarRating rating={summary.data.average} size={14} />
            <span className="text-[12px] text-muted-400">{t("subtitle", { count: summary.data.count })}</span>
          </div>
          <div className="space-y-1.5">
            {summary.data.distribution
              .map((count, index) => ({ stars: 5 - index, count }))
              .map(({ stars, count }) => {
                const pct = summary.data && summary.data.count > 0 ? (count / summary.data.count) * 100 : 0;
                return (
                  <div key={stars} className="flex items-center gap-2.5">
                    <span className="w-10 text-[11.5px] font-bold text-muted-400 tabular">{stars} ★</span>
                    <span
                      className="h-2 flex-1 overflow-hidden rounded-full bg-muted-200"
                      role="img"
                      aria-label={`${stars} ${t("title")}: ${count}`}
                    >
                      <span
                        className="block h-full rounded-full bg-secondary-500 transition-all duration-500"
                        style={{ width: `${pct}%` }}
                      />
                    </span>
                    <span className="w-8 text-end text-[11.5px] font-medium text-muted-400 tabular">{count}</span>
                  </div>
                );
              })}
          </div>
        </div>
      )}

      {formOpen && <ReviewForm productId={product.id} onDone={() => setFormOpen(false)} />}

      {/* sort control */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-[12.5px] font-bold text-muted-500" id="sort-label">
          {t("sortLabel")}:
        </span>
        <div role="group" aria-labelledby="sort-label" className="flex flex-wrap gap-1.5">
          {SORT_OPTIONS.map((option) => {
            const active = sort === option.id;
            return (
              <button
                key={option.id}
                type="button"
                aria-pressed={active}
                onClick={() => setSort(option.id)}
                className={`rounded-full border px-3.5 py-1.5 text-[12px] font-bold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 ${
                  active
                    ? "border-primary-800 bg-primary-800 text-white"
                    : "border-muted-200 bg-surface text-muted-500 hover:border-secondary-500 hover:text-secondary-700"
                }`}
              >
                {t(option.labelKey as "newest")}
              </button>
            );
          })}
        </div>
      </div>

      {/* list */}
      {query.isLoading ? (
        <ReviewsSkeleton />
      ) : query.isError ? (
        <div className="rounded-2xl border border-dashed border-muted-200 bg-primary-50/40 p-8 text-center">
          <p className="text-[13.5px] font-medium text-muted-500">{t("error")}</p>
          <button
            type="button"
            onClick={() => query.refetch()}
            className="mt-4 inline-flex h-10 items-center rounded-xl border border-muted-200 bg-surface px-4 text-[13px] font-bold text-muted-700 transition-colors hover:border-secondary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
          >
            {t("retry")}
          </button>
        </div>
      ) : reviews.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-muted-200 bg-primary-50/40 p-8 text-center">
          <p className="text-[13.5px] font-medium text-muted-500">{t("empty")}</p>
        </div>
      ) : (
        <ul className="space-y-3">
          {reviews.map((review) => (
            <li
              key={review.id}
              className={`rounded-2xl border p-4 sm:p-5 ${
                review.status === "pending"
                  ? "border-secondary-500/40 bg-secondary-100/40"
                  : "border-muted-200/80 bg-surface"
              }`}
            >
              <div className="flex items-start gap-3">
                <span
                  aria-hidden="true"
                  className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-800 text-[12px] font-black text-secondary-300"
                >
                  {initials(review.author.name)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span className="text-[13.5px] font-bold text-muted-900">{review.author.name}</span>
                    {review.isVerifiedPurchase && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[10.5px] font-bold text-success">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" aria-hidden="true">
                          <path d="m4.5 12.5 5 5 10-11" />
                        </svg>
                        {t("verified")}
                      </span>
                    )}
                    {review.status === "pending" && (
                      <span className="rounded-full bg-warning-soft px-2 py-0.5 text-[10.5px] font-bold text-warning">
                        {t("submitting")}
                      </span>
                    )}
                  </div>
                  <div className="mt-1 flex items-center gap-2">
                    <StarRating rating={review.rating} size={12} />
                    <span className="text-[11.5px] text-muted-400">{relativeTime(review.createdAt, t)}</span>
                  </div>
                  {review.title && (
                    <h3 className="mt-2 text-[13.5px] font-bold text-muted-800">{review.title}</h3>
                  )}
                  {review.comment && (
                    <p className="mt-1.5 text-[13px] leading-6 text-muted-500">{review.comment}</p>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {hasMore && (
        <div className="flex justify-center">
          <button
            type="button"
            onClick={() => query.fetchNextPage()}
            disabled={query.isFetchingNextPage}
            className="inline-flex h-11 items-center gap-2 rounded-xl border border-muted-200 bg-surface px-6 text-[13px] font-bold text-muted-700 transition-colors hover:border-secondary-500 hover:text-secondary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 disabled:opacity-60"
          >
            {query.isFetchingNextPage ? t("loadingMore") : t("loadMore")}
          </button>
        </div>
      )}
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Review form                                                         */
/* ------------------------------------------------------------------ */

function ReviewForm({ productId, onDone }: { productId: string; onDone: () => void }) {
  const t = useTranslations("pdp.reviews");
  const session = useSession();
  const submit = useSubmitReview(productId);

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ReviewFormValues>({
    resolver: zodResolver(reviewSchema),
    defaultValues: REVIEW_FORM_DEFAULTS,
    mode: "onBlur",
  });

  const rating = watch("rating");

  if (session.status !== "authenticated") {
    return (
      <div className="rounded-2xl border border-dashed border-muted-200 bg-primary-50/40 p-6 text-center">
        <p className="text-[13.5px] font-medium text-muted-500">{t("authRequired")}</p>
        <a
          href={`/${session.status === "loading" ? "" : "login"}`}
          className="mt-4 inline-flex h-10 items-center rounded-xl bg-primary-800 px-5 text-[13px] font-bold text-white transition-colors hover:bg-secondary-500 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
        >
          {t("signIn")}
        </a>
      </div>
    );
  }

  const onSubmit = handleSubmit(async (values) => {
    try {
      await submit.mutateAsync(values);
      reset(REVIEW_FORM_DEFAULTS);
      onDone();
    } catch {
      // `useSubmitReview` rolls back the optimistic copy; the inline error
      // state is surfaced through `formState.errors` for validation failures.
    }
  });

  return (
    <form
      onSubmit={onSubmit}
      noValidate
      className="space-y-5 rounded-2xl border border-muted-200/80 bg-surface p-5 sm:p-6"
      aria-labelledby="review-form-title"
    >
      <div>
        <h3 id="review-form-title" className="font-display text-[16px] font-extrabold text-muted-900">
          {t("formTitle")}
        </h3>
        <p className="mt-1 text-[12.5px] text-muted-400">{t("formSubtitle")}</p>
      </div>

      {/* rating */}
      <div className="space-y-1.5">
        <label className="text-[12.5px] font-bold text-muted-500" htmlFor="review-rating">
          {t("rating")}
        </label>
        <div id="review-rating">
          <StarRatingInput
            value={rating}
            onChange={(next) => setValue("rating", next, { shouldValidate: true, shouldDirty: true })}
            error={Boolean(errors.rating)}
          />
        </div>
        {errors.rating && (
          <p className="text-[12px] font-medium text-danger" role="alert">
            {t("errors.ratingMin")}
          </p>
        )}
      </div>

      {/* title */}
      <div className="space-y-1.5">
        <label className="text-[12.5px] font-bold text-muted-500" htmlFor="review-title">
          {t("title")}
        </label>
        <input
          id="review-title"
          type="text"
          autoComplete="off"
          placeholder={t("titlePlaceholder")}
          aria-invalid={Boolean(errors.title)}
          aria-describedby={errors.title ? "review-title-error" : undefined}
          {...register("title")}
          className="h-11 w-full rounded-xl border border-muted-200 bg-background px-3.5 text-[13.5px] text-muted-900 outline-none transition-colors placeholder:text-muted-300 focus:border-secondary-500 focus:ring-2 focus:ring-secondary-500/30 aria-[invalid=true]:border-danger"
        />
        {errors.title && (
          <p id="review-title-error" className="text-[12px] font-medium text-danger" role="alert">
            {errors.title.message ? t(errors.title.message as "titleMin") : t("errors.titleMin")}
          </p>
        )}
      </div>

      {/* comment */}
      <div className="space-y-1.5">
        <label className="text-[12.5px] font-bold text-muted-500" htmlFor="review-comment">
          {t("comment")}
        </label>
        <textarea
          id="review-comment"
          rows={4}
          placeholder={t("commentPlaceholder")}
          aria-invalid={Boolean(errors.comment)}
          aria-describedby={errors.comment ? "review-comment-error" : undefined}
          {...register("comment")}
          className="w-full resize-y rounded-xl border border-muted-200 bg-background px-3.5 py-3 text-[13.5px] leading-6 text-muted-900 outline-none transition-colors placeholder:text-muted-300 focus:border-secondary-500 focus:ring-2 focus:ring-secondary-500/30 aria-[invalid=true]:border-danger"
        />
        {errors.comment && (
          <p id="review-comment-error" className="text-[12px] font-medium text-danger" role="alert">
            {errors.comment.message ? t(errors.comment.message as "commentMin") : t("errors.commentMin")}
          </p>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <button
          type="submit"
          disabled={isSubmitting || submit.isPending}
          className="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-800 px-6 text-[13.5px] font-bold text-white transition-colors hover:bg-secondary-500 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isSubmitting || submit.isPending ? t("submitting") : t("submit")}
        </button>
        {submit.isSuccess && (
          <p className="text-[12.5px] font-medium text-success" role="status">
            {t("success")}
          </p>
        )}
        {submit.isError && (
          <p className="text-[12.5px] font-medium text-danger" role="alert">
            {t("error")}
          </p>
        )}
      </div>
    </form>
  );
}
