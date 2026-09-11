import * as React from "react";
import { cn } from "@/lib/utils";

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> { variant?: "default" | "outline"; }
export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(({className,variant="default",...props},ref)=><button ref={ref} className={cn("inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-secondary-500 disabled:pointer-events-none disabled:opacity-50",variant==="default"?"bg-secondary-500 text-primary-950 hover:bg-secondary-400":"border border-muted-200 bg-surface text-primary-900 hover:border-secondary-500",className)} {...props}/>);
Button.displayName="Button";
