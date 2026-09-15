"use client";
import { create } from "zustand";
import { persist } from "zustand/middleware";
import { products as staticProducts, type Product } from "@/features/home/catalog";

export type CartLine = { id: string; qty: number };
type Toast = { id: number; msg: string };

type CartState = {
  lines: CartLine[]; drawerOpen: boolean; toasts: Toast[]; badgeKey: number;
  add: (id:string, message?: string)=>void; inc:(id:string)=>void; dec:(id:string)=>void; remove:(id:string)=>void; clear:()=>void;
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

export { resolveProduct };
