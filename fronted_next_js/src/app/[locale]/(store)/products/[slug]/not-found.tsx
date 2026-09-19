import { getLocale, getTranslations } from "next-intl/server";
import { Link } from "@/lib/i18n/navigation";
import { IconArrow } from "@/features/home/components/Icons";

/**
 * PDP not-found state.
 *
 * Reached when `getProductDetailServer` resolves to `null` (Laravel 404) or
 * when a user follows a stale link to a product that was delisted. This is a
 * server component so it renders even if the client bundle fails to load.
 *
 * `getLocale()` reads the locale resolved by `next-intl`'s request config
 * (falling back to the default locale), so no fragile param plumbing.
 */
export default async function NotFound() {
  const locale = await getLocale();
  const t = await getTranslations({ locale, namespace: "pdp.errors" });

  return (
    <main className="grid min-h-[70vh] place-items-center bg-background px-4 py-16 text-center">
      <div className="max-w-md space-y-5">
        <p className="font-display text-6xl font-black text-primary-800">404</p>

        <h1 className="font-display text-2xl font-black text-muted-900">{t("notFoundTitle")}</h1>
        <p className="text-[14px] leading-relaxed text-muted-500">{t("notFoundDesc")}</p>

        <div className="pt-2">
          <Link
            href="/"
            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 text-[13.5px] font-extrabold text-white transition-all duration-200 hover:bg-secondary-500 hover:text-primary-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 focus-visible:ring-offset-2 active:scale-[0.99]"
          >
            {t("browseCatalog")}
            <IconArrow size={15} />
          </Link>
        </div>
      </div>
    </main>
  );
}
