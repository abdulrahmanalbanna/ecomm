import { NextIntlClientProvider } from "next-intl";
import { getMessages } from "next-intl/server";
import { notFound } from "next/navigation";
import { routing } from "@/lib/i18n/routing";
import { RTL_LOCALES, type Locale } from "@/types/locale";
import { Providers } from "@/components/layout/Providers";

export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!routing.locales.includes(locale as Locale)) notFound();

  //src/app/layout.tsx` is the
  // root layout, so `next typegen` reports "No root params detected").
  // Passing the locale explicitly to `getMessages` is the supported,
  // static-rendering-safe equivalent: it seeds the per-request message cache
  // for this locale instead of relying on the shared default-locale fallback.
  const messages = await getMessages({ locale });
  const dir = RTL_LOCALES.includes(locale as Locale) ? "rtl" : "ltr";

  // `data-scroll-behavior="smooth"` mirrors the `scroll-behavior: smooth` rule
  // in `globals.css` so Next.js can temporarily switch it to `auto` during
  // route transitions (otherwise it cannot restore scroll position).
  return (
    <html lang={locale} dir={dir} data-scroll-behavior="smooth">
      <body>
        <NextIntlClientProvider locale={locale} messages={messages}>
          <Providers>
            {children}
          </Providers>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}
