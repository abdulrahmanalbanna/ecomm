import type { SVGProps } from "react";

type P = SVGProps<SVGSVGElement> & { size?: number };

const base = (p: P) => {
  const { size = 20, ...rest } = p;
  return {
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.8,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    ...rest,
  };
};

export const IconCart = (p: P) => (
  <svg {...base(p)}>
    <path d="M3 4h2.2l2 11.2a1.6 1.6 0 0 0 1.6 1.3h7.9a1.6 1.6 0 0 0 1.6-1.2L20 8H6" />
    <circle cx="9.4" cy="20" r="1.4" fill="currentColor" stroke="none" />
    <circle cx="16.8" cy="20" r="1.4" fill="currentColor" stroke="none" />
  </svg>
);

export const IconSearch = (p: P) => (
  <svg {...base(p)}>
    <circle cx="10.5" cy="10.5" r="6.2" />
    <path d="m20 20-4.6-4.6" />
  </svg>
);

export const IconUser = (p: P) => (
  <svg {...base(p)}>
    <circle cx="12" cy="8" r="3.6" />
    <path d="M4.8 20c.9-3.6 3.8-5.4 7.2-5.4s6.3 1.8 7.2 5.4" />
  </svg>
);

export const IconPhone = (p: P) => (
  <svg {...base(p)}>
    <path d="M5.5 4h3l1.5 4-2 1.5a12.5 12.5 0 0 0 6.5 6.5L16 14l4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 3.5 6.2 2 2 0 0 1 5.5 4Z" />
  </svg>
);

export const IconWhatsApp = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 3.6a8.4 8.4 0 0 0-7.2 12.7L3.6 20.4l4.2-1.1A8.4 8.4 0 1 0 12 3.6Z" />
    <path d="M8.8 9.2c0 3.3 2.7 6 6 6l.9-1.6-1.9-1-.8.6a4.8 4.8 0 0 1-2.6-2.6l.6-.8-1-1.9Z" />
  </svg>
);

export const IconChevron = (p: P) => (
  <svg {...base(p)}>
    <path d="m9 5 7 7-7 7" />
  </svg>
);

export const IconArrow = (p: P) => (
  <svg {...base(p)}>
    <path d="M20 12H4m6-6-6 6 6 6" />
  </svg>
);

export const IconTruck = (p: P) => (
  <svg {...base(p)}>
    <path d="M2.5 5.5h11v11h-11zM13.5 9h4.2l2.8 3.2v4.3h-7" />
    <circle cx="6.5" cy="17.5" r="1.7" />
    <circle cx="16.8" cy="17.5" r="1.7" />
  </svg>
);

export const IconShield = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 3 5 5.8v5.4c0 4.4 2.9 7.6 7 9.3 4.1-1.7 7-4.9 7-9.3V5.8Z" />
    <path d="m9 11.6 2.1 2.1 4-4.2" />
  </svg>
);

export const IconWrench = (p: P) => (
  <svg {...base(p)}>
    <path d="M14.5 6.5a4 4 0 0 1 5-1.4l-2.8 2.8.8 2 2 .8L22.3 8a4 4 0 0 1-5.4 4.9L8.5 21.3a2 2 0 0 1-2.8-2.8l8.4-8.4a4 4 0 0 1 .4-3.6Z" />
  </svg>
);

export const IconCompass = (p: P) => (
  <svg {...base(p)}>
    <circle cx="12" cy="12" r="8.6" />
    <path d="m15.5 8.5-2 5-5 2 2-5Z" />
  </svg>
);

export const IconCargo = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 3 3.5 7v10L12 21l8.5-4V7Z" />
    <path d="M3.5 7 12 11l8.5-4M12 11v10" />
  </svg>
);

export const IconSnow = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 3v18M5 6.5l14 11M19 6.5l-14 11M12 3l-2 2m2-2 2 2M12 21l-2-2m2 2 2-2" />
  </svg>
);

export const IconFlame = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 3c1 3-3.5 4.8-3.5 9a3.5 3.5 0 0 0 7 0c0-1.5-.8-2.6-1.5-3.5 2.6.7 4.5 3 4.5 6A6.5 6.5 0 0 1 5.5 14C5.5 8.5 12 7.5 12 3Z" />
  </svg>
);

export const IconCup = (p: P) => (
  <svg {...base(p)}>
    <path d="M5 8h11v6.5A4.5 4.5 0 0 1 11.5 19h-2A4.5 4.5 0 0 1 5 14.5Z" />
    <path d="M16 9.5h1.8a2.4 2.4 0 0 1 0 4.8H16M8 4.5c0 .9.9 1 .9 1.9M11.5 4.5c0 .9.9 1 .9 1.9" />
  </svg>
);

export const IconCake = (p: P) => (
  <svg {...base(p)}>
    <path d="M5 20v-7.5A1.5 1.5 0 0 1 6.5 11h11a1.5 1.5 0 0 1 1.5 1.5V20M4 20h16" />
    <path d="M5 15c1.5 1.3 2.8 1.3 4.3 0 1.5 1.3 2.9 1.3 4.4 0 1.4 1.3 2.8 1.3 4.3 0M12 11V8.5M12 6.5c-.8 0-1.2-.5-1.2-1.1 0-.8 1.2-2 1.2-2s1.2 1.2 1.2 2c0 .6-.4 1.1-1.2 1.1Z" />
  </svg>
);

export const IconBlender = (p: P) => (
  <svg {...base(p)}>
    <path d="M8 3h8l-1.2 10H9.2ZM9.2 13 8.5 19h7l-.7-6M8.5 19H15.5" />
    <path d="M12 6v4" />
  </svg>
);

export const IconOven = (p: P) => (
  <svg {...base(p)}>
    <rect x="4" y="4" width="16" height="16" rx="1.5" />
    <path d="M4 9h16M8 6.5h.01M11 6.5h.01" />
    <rect x="7.5" y="12" width="9" height="5" rx="0.8" />
  </svg>
);

export const IconFridge = (p: P) => (
  <svg {...base(p)}>
    <rect x="6" y="3" width="12" height="18" rx="1.5" />
    <path d="M6 10h12M9 6v2M9 13v3" />
  </svg>
);

export const IconGrinder = (p: P) => (
  <svg {...base(p)}>
    <path d="M8 3h8l-1 5H9ZM9.5 8h5l-.8 4h-3.4ZM10.3 12v3.5h3.4V12" />
    <path d="M8.5 21h7l-.6-5.5H9.1Z" />
  </svg>
);

export const IconFryer = (p: P) => (
  <svg {...base(p)}>
    <path d="M4 8h16v5a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3Z" />
    <path d="M7 16v3M17 16v3M8 5.5c.8 0 1.3-.6 1.3-1.3M12 5.5c.8 0 1.3-.6 1.3-1.3M16 5.5c.8 0 1.3-.6 1.3-1.3" />
  </svg>
);

export const IconStar = (p: P) => (
  <svg {...base(p)}>
    <path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9Z" fill="currentColor" stroke="none" />
  </svg>
);

export const IconCheck = (p: P) => (
  <svg {...base(p)}>
    <path d="m4.5 12.5 5 5 10-11" />
  </svg>
);

export const IconPlus = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 5v14M5 12h14" />
  </svg>
);

export const IconMinus = (p: P) => (
  <svg {...base(p)}>
    <path d="M5 12h14" />
  </svg>
);

export const IconX = (p: P) => (
  <svg {...base(p)}>
    <path d="m6 6 12 12M18 6 6 18" />
  </svg>
);

export const IconTrash = (p: P) => (
  <svg {...base(p)}>
    <path d="M4.5 6.5h15M9.5 6V4.5h5V6M7 6.5l.8 12a1.5 1.5 0 0 0 1.5 1.4h5.4a1.5 1.5 0 0 0 1.5-1.4l.8-12M10 10.5v6M14 10.5v6" />
  </svg>
);

export const IconPin = (p: P) => (
  <svg {...base(p)}>
    <path d="M12 21s-6.5-5.6-6.5-10.4A6.5 6.5 0 0 1 12 4a6.5 6.5 0 0 1 6.5 6.6C18.5 15.4 12 21 12 21Z" />
    <circle cx="12" cy="10.5" r="2.2" />
  </svg>
);

export const IconClock = (p: P) => (
  <svg {...base(p)}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M12 7v5l3.2 2" />
  </svg>
);

export const IconSend = (p: P) => (
  <svg {...base(p)}>
    <path d="M20.5 3.5 3.5 10.8l6.6 2.6 2.6 6.6Z" />
    <path d="M20.5 3.5 10.1 13.4" />
  </svg>
);

export const IconHome = (p: P) => (
  <svg {...base(p)}>
    <path d="m4 11 8-7 8 7v8.5a1 1 0 0 1-1 1h-4.5V15h-5v5.5H5a1 1 0 0 1-1-1Z" />
  </svg>
);

export const IconGrid = (p: P) => (
  <svg {...base(p)}>
    <rect x="4" y="4" width="6.5" height="6.5" rx="1" />
    <rect x="13.5" y="4" width="6.5" height="6.5" rx="1" />
    <rect x="4" y="13.5" width="6.5" height="6.5" rx="1" />
    <rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1" />
  </svg>
);

export const categoryIcon = (id: string, size = 22) => {
  switch (id) {
    case "coffee": return <IconCup size={size} />;
    case "grinders": return <IconGrinder size={size} />;
    case "cooling": return <IconFridge size={size} />;
    case "cooking": return <IconOven size={size} />;
    case "frying": return <IconFryer size={size} />;
    case "bakery": return <IconCake size={size} />;
    case "drinks": return <IconBlender size={size} />;
    default: return <IconGrid size={size} />;
  }
};
