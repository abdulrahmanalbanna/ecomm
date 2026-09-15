import {apiClient} from "@/lib/api/client";
import type {Category,Product} from "./catalog";
export const getFeaturedProducts=()=>apiClient.get<Product[]>("/v1/catalog/products?featured=1");
export const getCategories=()=>apiClient.get<Category[]>("/v1/catalog/categories");
export const getBanners=()=>apiClient.get<unknown[]>("/banners"); // TODO: no backend route yet (banners table exists, API pending)

export type ShopFeature={id:string;title:string;desc:string;icon:string};
export type ShopStat={value:number;suffix:string;label:string};
export type ShopSettings={
  store:{name:string;phone:string|null;logo:string|null;footer_logo:string|null;fav_icon:string|null;copyright_text:string|null;currency:string};
  header:Record<string,unknown>|null;
  footer:Record<string,unknown>|null;
  features:ShopFeature[];
  stats:ShopStat[];
  ticker_items:string[];
  free_shipping_threshold:number;
  currency:string;
  maintenance_mode:boolean;
};

/**
 * Public storefront settings served by Laravel
 * `GET {NEXT_PUBLIC_API_URL}/v1/settings` (no auth required).
 */
export const getPublicSettings=()=>apiClient.get<ShopSettings>("/v1/settings");
