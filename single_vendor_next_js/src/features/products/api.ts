import {apiClient} from "@/lib/api/client";
import type {Product} from "@/features/home/catalog";
export const getProducts=()=>apiClient.get<Product[]>("/products");
export const getProductBySlug=(slug:string)=>apiClient.get<Product>(`/products/${encodeURIComponent(slug)}`);
