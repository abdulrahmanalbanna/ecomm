import {apiClient} from "@/lib/api/client";
import type {Category,Product} from "./catalog";
export const getFeaturedProducts=()=>apiClient.get<Product[]>("/home/featured-products");
export const getCategories=()=>apiClient.get<Category[]>("/categories");
export const getBanners=()=>apiClient.get<unknown[]>("/banners");
