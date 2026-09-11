export default function Loading() {
  return (
    <main className="min-h-screen bg-background" aria-busy="true" aria-label="Loading">
      <section className="bg-primary-900 px-4 py-16">
        <div className="mx-auto grid max-w-7xl gap-8 lg:grid-cols-2">
          <div className="space-y-5">
            <div className="h-5 w-48 animate-pulse rounded-full bg-primary-800" />
            <div className="h-16 w-3/4 animate-pulse rounded-xl bg-primary-800" />
            <div className="h-5 w-full animate-pulse rounded bg-primary-800" />
            <div className="h-5 w-5/6 animate-pulse rounded bg-primary-800" />
          </div>
          <div className="aspect-[4/3] animate-pulse rounded-2xl bg-primary-800" />
        </div>
      </section>
      <section className="mx-auto max-w-7xl px-4 py-14 lg:px-8">
        <div className="mb-7 h-9 w-80 animate-pulse rounded bg-muted-200" />
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => <div key={i} className="aspect-square animate-pulse rounded-xl bg-muted-200/70" />)}
        </div>
      </section>
    </main>
  );
}
