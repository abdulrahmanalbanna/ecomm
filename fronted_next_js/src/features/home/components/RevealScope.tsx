"use client";

import { useRevealObserver } from "@/hooks/use-home";

/**
 * The *only* client boundary needed for the static home sections.
 *
 * `.reveal` elements start at `opacity: 0` in `globals.css` and only become
 * visible when the IntersectionObserver adds `.in`. Previously every static
 * section (`ProjectsGrid`, `BrandsMarquee`, `CtaBand`) was a client component
 * purely to attach that observer, which shipped their (large) JSX and helper
 * code to the browser.
 *
 * Now those sections are Server Components rendered inside this single thin
 * client wrapper: the observer is attached once, here, and scans the whole
 * subtree (the `MutationObserver` in `useRevealObserver` also picks up
 * anything inserted later). The visual behaviour is identical, but none of
 * the static section code reaches the client bundle.
 */
export function RevealScope({ children }: { children: React.ReactNode }) {
  const ref = useRevealObserver<HTMLDivElement>();
  return <div ref={ref}>{children}</div>;
}
