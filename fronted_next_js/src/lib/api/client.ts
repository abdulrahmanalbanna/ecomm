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

async function request<T>(path:string,init:RequestInit={}):Promise<LaravelResponse<T>>{
 const response=await fetch(`${baseUrl ?? ""}${path}`,{...init,headers:{Accept:"application/json","Content-Type":"application/json",...(init.headers??{})},cache:"no-store"});
 const payload=await response.json().catch(()=>null) as LaravelResponse<T>&{errors?:LaravelValidationErrors};
 if(!response.ok) throw new ApiError(response.status,payload?.errors,payload?.message);
 return payload;
}
export const apiClient={
 get:<T>(path:string,init?:RequestInit)=>request<T>(path,{...init,method:"GET"}),
 post:<T>(path:string,body:unknown,init?:RequestInit)=>request<T>(path,{...init,method:"POST",body:JSON.stringify(body)}),
 put:<T>(path:string,body:unknown,init?:RequestInit)=>request<T>(path,{...init,method:"PUT",body:JSON.stringify(body)}),
 patch:<T>(path:string,body:unknown,init?:RequestInit)=>request<T>(path,{...init,method:"PATCH",body:JSON.stringify(body)}),
 delete:<T>(path:string,init?:RequestInit)=>request<T>(path,{...init,method:"DELETE"})
};
