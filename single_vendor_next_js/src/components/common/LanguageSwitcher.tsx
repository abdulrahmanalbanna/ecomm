"use client";
import {useLocale} from "next-intl";
import {usePathname,useRouter} from "@/lib/i18n/navigation";
import {routing} from "@/lib/i18n/routing";

export function LanguageSwitcher(){
 const locale=useLocale(); const pathname=usePathname(); const router=useRouter();
 return <div className="fixed right-6 top-1 z-[100] hidden md:block"><label className="sr-only" htmlFor="language-switcher">Language</label><select id="language-switcher" value={locale} onChange={e=>router.replace(pathname,{locale:e.target.value})} className="rounded-lg border border-muted-200 bg-surface px-2 py-1 text-xs font-bold shadow-soft">{routing.locales.map(l=><option key={l} value={l}>{l.toUpperCase()}</option>)}</select></div>;
}
