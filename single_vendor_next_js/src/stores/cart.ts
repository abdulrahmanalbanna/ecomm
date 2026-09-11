"use client";
import { create } from "zustand";
import { persist } from "zustand/middleware";
import { productById } from "@/features/home/catalog";

export type CartLine = { id: string; qty: number };
type Toast = { id: number; msg: string };

type CartState = {
  lines: CartLine[]; drawerOpen: boolean; toasts: Toast[]; badgeKey: number;
  add: (id:string, message?: string)=>void; inc:(id:string)=>void; dec:(id:string)=>void; remove:(id:string)=>void; clear:()=>void;
  setDrawerOpen:(v:boolean)=>void;
};

let toastId = 0;
export const useCartStore = create<CartState>()(persist((set) => ({
  lines: [], drawerOpen:false, toasts:[], badgeKey:0,
  setDrawerOpen:(drawerOpen)=>set({drawerOpen}),
  add:(id,message)=>set((s)=>{
    const exists=s.lines.find(x=>x.id===id);
    const lines=exists?s.lines.map(x=>x.id===id?{...x,qty:x.qty+1}:x):[...s.lines,{id,qty:1}];
    const msg=message ?? `Added ${productById(id).name} to cart`; const tid=++toastId;
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
  const subtotal=state.lines.reduce((n,x)=>n+productById(x.id).price*x.qty,0);
  return {...state,count,subtotal};
}
