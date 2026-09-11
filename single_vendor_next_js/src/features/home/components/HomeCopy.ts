"use client";

import {useTranslations} from "next-intl";

export function useHomeCopy() {
  return useTranslations("home");
}
