export type LaravelResponse<T>={data:T;message?:string;meta?:Record<string,unknown>};
export type LaravelValidationErrors=Record<string,string[]>;
export class ApiError extends Error {constructor(public status:number,public validationErrors?:LaravelValidationErrors,message?:string){super(message??`API request failed (${status})`);this.name="ApiError";}}

const rawBaseUrl = process.env.NEXT_PUBLIC_API_URL;
if (!rawBaseUrl) console.warn("NEXT_PUBLIC_API_URL is not configured; API calls will fail until it is set.");

/**
 * Base URL for the Laravel API, configured via the `NEXT_PUBLIC_API_URL`
 * environment variable (e.g. `http://localhost:8000/api`).
 * Trailing slashes are stripped so callers can safely do `${base}/v1/...`.
 */
export const getApiBaseUrl = () => (rawBaseUrl ?? "").replace(/\/+$/, "");

const baseUrl = getApiBaseUrl();

/** RequestInit + the Next.js fetch extension used for ISR (`next.revalidate`). */
export type NextFetchInit = RequestInit & { next?: { revalidate?: number | false } };

async function request<T>(path:string,init:NextFetchInit={}):Promise<LaravelResponse<T>>{
 // `cache: "no-store"` and `next: { revalidate }` are mutually exclusive —
 // Next.js logs "only one should be specified" when both are present. The
 // no-store default is therefore applied only when the caller has not opted
 // into ISR (and has not set an explicit cache mode).
 const wantsRevalidate = init.next?.revalidate != null;
 const response=await fetch(`${baseUrl ?? ""}${path}`,{...(wantsRevalidate?{}:{cache: init.cache ?? "no-store"}), ...init,headers:{Accept:"application/json","Content-Type":"application/json",...(init.headers??{})}});
 const payload=await response.json().catch(()=>null) as LaravelResponse<T>&{errors?:LaravelValidationErrors};
 if(!response.ok) throw new ApiError(response.status,payload?.errors,payload?.message);
 return payload;
}
export const apiClient={
 get:<T>(path:string,init?:NextFetchInit)=>request<T>(path,{...init,method:"GET"}),
 post:<T>(path:string,body:unknown,init?:NextFetchInit)=>request<T>(path,{...init,method:"POST",body:JSON.stringify(body)}),
 put:<T>(path:string,body:unknown,init?:NextFetchInit)=>request<T>(path,{...init,method:"PUT",body:JSON.stringify(body)}),
 patch:<T>(path:string,body:unknown,init?:NextFetchInit)=>request<T>(path,{...init,method:"PATCH",body:JSON.stringify(body)}),
 delete:<T>(path:string,init?:NextFetchInit)=>request<T>(path,{...init,method:"DELETE"})
};

/**
 * Transform a Laravel API response envelope into typed data.
 * Laravel public endpoints return { data: <resource>, meta?:... }.
 * We extract the `data` field so callers get the resource directly.
 */
export function extractData<T>(response: LaravelResponse<T>): T {
  return response.data;
}

/**
 * Transform Laravel CategoryResource into the frontend Category type.
 * Laravel CategoryResource fields: id, parent_id, slug, name, description,
 * image_url, is_active, sort_order, path, depth, children, created_at, updated_at
 */
export interface LaravelCategory {
  id: number;
  parent_id?: number;
  slug: string;
  name: string;
  description: string;
  image_url?: string | null;
  is_active: boolean;
  sort_order?: number;
  path?: string;
  depth?: number;
  children?: LaravelCategory[];
  created_at?: string;
  updated_at?: string;
}

/**
 * The brand column as the backend actually serves it.
 *
 * `products.brand` is a plain `VARCHAR(150)` string (see
 * `database/sql/005_products_attributes_variants.sql`), so the public
 * resource emits a scalar — not an object. A future refactor may replace it
 * with the `brands` table relation (`brand_id`), hence the union.
 */
export type LaravelBrand =
  | string
  | null
  | undefined
  | { id: number; name: string; slug?: string | null; is_active?: boolean };

/**
 * The tags column as the backend actually serves it.
 *
 * `products.tags` is a Postgres `TEXT[]` decoded to a flat string array by
 * `PostgresTextArray` (see `app/Shared/Infrastructure/Database/Casts/PostgresTextArray.php`),
 * so the public resource emits plain strings — not tag objects. The object
 * shape is tolerated because the frontend assumed it before the migration.
 */
export type LaravelTag = string | { id: number; name: string; slug?: string | null; is_active?: boolean };

/**
 * Transform Laravel ProductPublicResource into the frontend Product type.
 * Laravel ProductPublicResource fields: public_id, slug, name, description,
 * short_description, brand, tags, media, specifications, is_featured,
 * seo_title, seo_description, category, attributes, variants
 */
export interface LaravelProduct {
  public_id: string;
  slug: string;
  name: string;
  description: string;
  short_description?: string | null;
  brand?: LaravelBrand;
  tags?: LaravelTag[] | null;
  media?: Array<{ id: number; url?: string | null; alt_text?: string | null; type?: string | null }> | null;
  specifications?: Record<string, string> | null;
  is_featured: boolean;
  seo_title?: string | null;
  seo_description?: string | null;
  category?: LaravelCategory | null;
  attributes?: Array<{ id: number; name: string; code?: string | null; is_active: boolean }> | null;
  variants?: Array<{ id: number; sku?: string | null; name?: string | null; price: number; compare_at_price?: number | null; weight_grams?: number | null; dimensions?: Record<string, string> | null; is_active: boolean }> | null;
}

/**
 * Transform Laravel ProductVariantPublicResource into a frontend-friendly variant.
 * Laravel ProductVariantPublicResource fields: id, sku, name, price,
 * compare_at_price, weight_grams, dimensions, attributes, media, is_active
 */
export interface LaravelProductVariant {
  id: number;
  sku?: string | null;
  name?: string | null;
  price: string;
  compare_at_price?: string | null;
  weight_grams?: number | null;
  dimensions?: Record<string, string> | null;
  attributes?: Array<{ id: number; name: string; code?: string | null; is_active: boolean }> | null;
  media?: Array<{ id: number; url?: string | null; alt_text?: string | null; type?: string | null }> | null;
  is_active: boolean;
}

/**
 * Transform Laravel PublicSettingsResource into the frontend ShopSettings type.
 * Laravel returns all whitelisted public settings cast to PHP values.
 */
export interface LaravelShopSettings {
  store: {
    name: string;
    phone?: string | null;
    logo?: string | null;
    footer_logo?: string | null;
    fav_icon?: string | null;
    copyright_text?: string | null;
    currency: string;
  };
  header?: Record<string, unknown> | null;
  footer?: Record<string, unknown> | null;
  features: Array<{ id: string; title: string; desc: string; icon: string }>;
  stats: Array<{ value: number; suffix: string; label: string }>;
  ticker_items: string[];
  free_shipping_threshold: number;
  currency: string;
  maintenance_mode: boolean;
}

/**
 * Transform Laravel CategoryPublicController index response.
 * The controller returns { data: CategoryResource[] }
 */
export interface LaravelCategoryResponse {
  data: LaravelCategory[];
  meta?: Record<string, unknown>;
}

/**
 * Transform Laravel ProductPublicController index response.
 * The controller returns { data: ProductPublicResource[], meta: pagination }
 */
export interface LaravelProductListResponse {
  data: LaravelProduct[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
