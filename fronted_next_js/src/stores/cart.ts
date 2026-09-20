"use client";
import { create } from "zustand";
import { persist } from "zustand/middleware";
import { products as staticProducts, type Product } from "@/features/home/catalog";
import { clampQuantity, QUANTITY_MAX_HARD } from "@/lib/quantity";

export type CartLine = { id: string; qty: number };
type Toast = { id: number; msg: string };

type CartState = {
  lines: CartLine[]; drawerOpen: boolean; toasts: Toast[]; badgeKey: number;
  /** Add one unit of a product (existing lines increment by 1). */
  add: (id:string, message?: string)=>void;
  /** Add an explicit quantity, clamped to `[1, max]` (used by the PDP). */
  addQuantity: (id:string, qty:number, max:number, message?: string)=>void;
  /** Overwrite the quantity for a line (removes it when <= 0). */
  setQuantity:(id:string, qty:number, max?:number)=>void;
  inc:(id:string)=>void; dec:(id:string)=>void; remove:(id:string)=>void; clear:()=>void;
  setDrawerOpen:(v:boolean)=>void;
};

/**
 * Shared product lookup map used by the cart to resolve cart-line ids
 * (now the backend `public_id` UUID) to display data and price. The
 * live map is installed by `HomePage` once the catalog snapshot is
 * available; we fall back to the static catalog for any unknown ids
 * (e.g. stale lines from before the migration) and for SSR.
 */
let productLookup: Map<string, Product> | null = null;

export function setCartProductLookup(map: Map<string, Product> | null) {
  productLookup = map;
}

function resolveProduct(id: string): Product | undefined {
  if (productLookup) {
    const found = productLookup.get(id);
    if (found) return found;
  }
  return staticProducts.find((p) => p.id === id);
}

let toastId = 0;
function pushToast(set: (fn: (s: CartState) => Partial<CartState>) => void, msg: string) {
  const tid = ++toastId;
  setTimeout(() => set((x) => ({ toasts: x.toasts.filter((t) => t.id !== tid) })), 2600);
  set((s) => ({ toasts: [...s.toasts.slice(-2), { id: tid, msg }] }));
}

export const useCartStore = create<CartState>()(persist((set) => ({
  lines: [], drawerOpen:false, toasts:[], badgeKey:0,
  setDrawerOpen:(drawerOpen)=>set({drawerOpen}),
  add:(id,message)=>set((s)=>{
    const exists=s.lines.find(x=>x.id===id);
    const lines=exists?s.lines.map(x=>x.id===id?{...x,qty:x.qty+1}:x):[...s.lines,{id,qty:1}];
    const p = resolveProduct(id);
    const msg=message ?? (p ? `Added ${p.name} to cart` : "Added to cart");
    const tid=++toastId;
    setTimeout(()=>set(x=>({toasts:x.toasts.filter(t=>t.id!==tid)})),2600);
    return {lines,badgeKey:s.badgeKey+1,toasts:[...s.toasts.slice(-2),{id:tid,msg}]};
  }),
  addQuantity:(id,qty,max,message)=>set((s)=>{
    const existing = s.lines.find((x) => x.id === id);
    const base = existing?.qty ?? 0;
    // Clamp the *combined* quantity so a second visit can't exceed inventory.
    const next = clampQuantity(base + Math.max(1, Math.trunc(qty) || 1), max || QUANTITY_MAX_HARD);
    const lines = existing
      ? s.lines.map((x) => (x.id === id ? { ...x, qty: next } : x))
      : [...s.lines, { id, qty: next }];
    const p = resolveProduct(id);
    const msg = message ?? (p ? `Added ${p.name} to cart` : "Added to cart");
    const tid=++toastId;
    setTimeout(()=>set(x=>({toasts:x.toasts.filter(t=>t.id!==tid)})),2600);
    return {lines,badgeKey:s.badgeKey+1,toasts:[...s.toasts.slice(-2),{id:tid,msg}]};
  }),
  setQuantity:(id,qty,max)=>set((s)=>{
    const next = clampQuantity(qty, max || QUANTITY_MAX_HARD);
    return {lines: s.lines.map((x)=>x.id===id?{...x,qty:next}:x), badgeKey: s.badgeKey+1};
  }),
  inc:(id)=>set(s=>({lines:s.lines.map(x=>x.id===id?{...x,qty:x.qty+1}:x)})),
  dec:(id)=>set(s=>({lines:s.lines.map(x=>x.id===id?{...x,qty:x.qty-1}:x).filter(x=>x.qty>0)})),
  remove:(id)=>set(s=>({lines:s.lines.filter(x=>x.id!==id)})),
  clear:()=>set({lines:[]})
}), {name:"tagahayeez-cart-v2"}));

export function useCart() {
  const state=useCartStore();
  const count=state.lines.reduce((n,x)=>n+x.qty,0);
  const subtotal=state.lines.reduce((n,x)=>{
    const p = resolveProduct(x.id);
    return n + (p ? p.price * x.qty : 0);
  }, 0);
  return {...state,count,subtotal};
}

/**
 * Read the current quantity of a cart line without subscribing to the
 * whole store (used by the PDP quantity selector to show "in cart: n").
 */
export function useCartLineQty(id: string): number {
  return useCartStore((s) => s.lines.find((x) => x.id === id)?.qty ?? 0);
}

/** Total number of units in the cart (selector form, for badges/hooks). */
export const selectCartItemCount = (s: CartState): number => s.lines.reduce((n, x) => n + x.qty, 0);

/** Sum of `price * qty` across all lines. */
export const selectCartTotal = (s: CartState): number =>
  s.lines.reduce((n, x) => {
    const p = resolveProduct(x.id);
    return n + (p ? p.price * x.qty : 0);
  }, 0);

export { resolveProduct };
