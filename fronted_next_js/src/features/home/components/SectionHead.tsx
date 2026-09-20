import type { ReactNode } from "react";
import { IconArrow } from "./Icons";

/**
 * Section heading, shared by the interactive (client) and the static
 * (server) home sections.
 *
 * Deliberately free of any client-only hook so it can render on the server.
 * Interactive callers pass `onAction` (a click handler); static/server
 * callers pass `actionHref` so the "view all" control is a real `<a>` —
 * which also works with JavaScript disabled and still scrolls smoothly
 * thanks to the `scroll-behavior: smooth` rule in `globals.css`.
 */
export function SectionHead({
  kicker,
  title,
  desc,
  action,
  onAction,
  actionHref,
  dark = false,
}: {
  kicker: string;
  title: string;
  desc?: string;
  action?: string;
  onAction?: () => void;
  actionHref?: string;
  dark?: boolean;
}) {
  return (
    <div className="reveal mb-7 flex flex-wrap items-end justify-between gap-4">
      <div>
        <p className={`mb-1.5 flex items-center gap-2 text-[12.5px] font-extrabold ${dark ? "text-secondary-400" : "text-secondary-600"}`}>
          <span className={`h-[2px] w-7 rounded-full ${dark ? "bg-secondary-400" : "bg-secondary-500"}`} />
          {kicker}
        </p>
        <h2 className={`font-display text-[26px] font-black leading-tight sm:text-[32px] ${dark ? "text-background" : "text-muted-900"}`}>
          {title}
        </h2>
        {desc && <p className={`mt-2 max-w-2xl text-[14px] font-medium leading-7 ${dark ? "text-primary-100/75" : "text-muted-500"}`}>{desc}</p>}
      </div>
      {action && (onAction ? (
        <button
          onClick={onAction}
          className={`group flex items-center gap-2 rounded-xl border px-4 py-2.5 text-[13px] font-extrabold transition-all duration-300 active:scale-95 ${
            dark
              ? "border-primary-600 text-secondary-300 hover:border-secondary-500 hover:bg-primary-800"
              : "border-muted-200 bg-surface text-primary-900 hover:border-secondary-500 hover:text-secondary-700"
          }`}
        >
          {action}
          <IconArrow size={16} className="transition-transform duration-300 group-hover:-translate-x-1" />
        </button>
      ) : (
        <a
          href={actionHref ?? "#top"}
          className={`group flex items-center gap-2 rounded-xl border px-4 py-2.5 text-[13px] font-extrabold transition-all duration-300 active:scale-95 ${
            dark
              ? "border-primary-600 text-secondary-300 hover:border-secondary-500 hover:bg-primary-800"
              : "border-muted-200 bg-surface text-primary-900 hover:border-secondary-500 hover:text-secondary-700"
          }`}
        >
          {action}
          <IconArrow size={16} className="transition-transform duration-300 group-hover:-translate-x-1" />
        </a>
      ))}
    </div>
  );
}

export type { ReactNode };
