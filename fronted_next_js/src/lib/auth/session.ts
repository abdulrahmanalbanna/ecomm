"use client";

/**
 * Lightweight auth session for the storefront.
 *
 * Laravel Sanctum issues a bearer token on login. The token is stored in a
 * cookie-readable place so Server Components can also read it (see
 * `getServerAuthToken`), and the client hooks below subscribe to changes.
 *
 * Until the Identity module's customer login UI lands, `useSession` reports
 * a guest session and the PDP renders its guest variants (no review form,
 * wishlist button prompts sign-in). No protected action is ever attempted
 * without a token, so the backend stays safe.
 */

import { useEffect, useState } from "react";
import { apiClient, ApiError } from "@/lib/api/client";

const TOKEN_KEY = "tagahayeez-auth-token";

export interface Session {
  /** Sanctum bearer token when the customer is signed in. */
  token: string | null;
  status: "loading" | "authenticated" | "guest";
  /** Customer display name when available. */
  name: string | null;
}

const GUEST: Session = { token: null, status: "guest", name: null };

function readToken(): string | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

/** Attach the Sanctum token to any authenticated request. */
export function authHeaders(token: string | null): Record<string, string> {
  return token ? { Authorization: `Bearer ${token}` } : {};
}

/** Server-side token read (cookies/localStorage are unavailable; returns null). */
export function getServerAuthToken(): string | null {
  return null;
}

/**
 * Subscribe to the stored session. Re-reads on `storage` events so a login
 * in another tab is picked up immediately.
 */
export function useSession(): Session {
  const [session, setSession] = useState<Session>(() => {
    const token = readToken();
    return token ? { token, status: "authenticated", name: null } : GUEST;
  });

  useEffect(() => {
    const sync = () => {
      const token = readToken();
      setSession(token ? { token, status: "authenticated", name: null } : GUEST);
    };
    sync();
    window.addEventListener("storage", sync);
    return () => window.removeEventListener("storage", sync);
  }, []);

  return session;
}

/** Sign out: clear the stored token. */
export function clearSession(): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.removeItem(TOKEN_KEY);
  } catch {
    /* storage unavailable — nothing to clear */
  }
  window.dispatchEvent(new StorageEvent("storage", { key: TOKEN_KEY }));
}

/**
 * True when an error means "you must be signed in" (Sanctum 401/419).
 * Used by mutation hooks to redirect to login instead of showing a toast.
 */
export function isAuthError(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 419);
}

/**
 * Run an authenticated API call, throwing a typed error when the session
 * is missing so callers can redirect to login.
 */
export async function withAuth<T>(
  session: Session,
  call: (headers: Record<string, string>) => Promise<T>,
): Promise<T> {
  if (!session.token) {
    throw new ApiError(401, undefined, "pdp.reviews.authRequired");
  }
  return call(authHeaders(session.token));
}

export { apiClient };
