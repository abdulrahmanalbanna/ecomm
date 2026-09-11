"use client";

import { branding } from "@/config/branding";

export default function Error({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="grid min-h-[70vh] place-items-center bg-background px-4 text-center">
      <div>
        <p className="text-sm font-bold text-secondary-700">{branding.name.en}</p>
        <h1 className="mt-2 font-display text-3xl font-black text-muted-900">Something went wrong.</h1>
        <p className="mt-2 text-sm text-muted-500">Please try again.</p>
        <button onClick={() => reset()} className="mt-6 rounded-xl bg-primary-900 px-5 py-3 font-bold text-secondary-300">Try again</button>
      </div>
    </main>
  );
}
