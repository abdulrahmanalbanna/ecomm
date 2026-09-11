import {NextIntlClientProvider} from "next-intl";
import {getMessages, setRequestLocale} from "next-intl/server";
import {notFound} from "next/navigation";
import {routing} from "@/lib/i18n/routing";
import {RTL_LOCALES, type Locale} from "@/types/locale";
import {Providers} from "@/components/layout/Providers";
import {LanguageSwitcher} from "@/components/common/LanguageSwitcher";

export default async function LocaleLayout({children,params}:{children:React.ReactNode;params:Promise<{locale:string}>}) {
  const {locale}=await params;
  if(!routing.locales.includes(locale as Locale)) notFound();
  // Required for static rendering: without this, all locales share the
  // default-locale messages at prerender time, so switching locale only
  // flips `dir` while `t()` text stays stuck.
  setRequestLocale(locale);
  const messages=await getMessages();
  const dir=RTL_LOCALES.includes(locale as Locale)?"rtl":"ltr";
  return <html lang={locale} dir={dir}><body><NextIntlClientProvider locale={locale} messages={messages}><Providers><LanguageSwitcher />{children}</Providers></NextIntlClientProvider></body></html>;
}
export function generateStaticParams(){return routing.locales.map(locale=>({locale}));}
