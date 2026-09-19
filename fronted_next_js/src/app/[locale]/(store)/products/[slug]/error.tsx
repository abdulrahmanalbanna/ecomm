"use client";

/**
 * PDP error boundary.
 *
 * Rendered when the server component throws (network failure, Laravel 5xx,
 * adapter crash). The route keeps the store chrome (header/footer) — only the
 * product area is replaced — and offers a retry that re-runs the server
 * render. A 404 is *not* an error: `page.tsx` calls `notFound()` for that,
 * which renders `not-found.tsx` instead.
 */

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/lib/i18n/navigation";
import { IconArrow } from "@/features/home/components/Icons";

export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const t = useTranslations("pdp.errors");

  // Surface the digest in development only; never log PII to the console.
  useEffect(() => {
    if (process.env.NODE_ENV !== "production" && error) {
      // Intentionally empty: the digest is attached for the error reporter.
    }
  }, [error]);

  return (
    <main className="grid min-h-[70vh] place-items-center bg-background px-4 py-16 text-center">
      <div className="max-w-md space-y-5">
        <div className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-danger-soft text-danger">
          <svg
            width={30}
            height={30}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M10.3 3.9 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
            <path d="M12 9v4m0 4h.01" />
          </svg>
        </div>

        <h1 className="font-display text-2xl font-black text-muted-900">{t("errorTitle")}</h1>
        <p className="text-[14px] leading-relaxed text-muted-500">{t("errorDesc")}</p>

        {error.digest ? (
          <p className="font-mono text-[11px] text-muted-300">{error.digest}</p>
        ) : null}

        <div className="flex flex-wrap items-center justify-center gap-3 pt-2">
          <button
            type="button"
            onClick={reset}
            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 text-[13.5px] font-extrabold text-white transition-all duration-200 hover:bg-secondary-500 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 focus-visible:ring-offset-2 active:scale-[0.99]"
          >
            {t("retry")}
          </button>
          <Link
            href="/"
            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-muted-200 bg-surface px-6 text-[13.5px] font-bold text-muted-700 transition-colors hover:border-secondary-500 hover:text-secondary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500"
          >
            {t("backHome")}
            <IconArrow size={15} />
          </Link>
        </div>
      </div>
    </main>
  );
}
