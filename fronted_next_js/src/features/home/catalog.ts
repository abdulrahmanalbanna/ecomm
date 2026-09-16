/**
 * Catalog domain types + OFFLINE FALLBACK data.
 *
 * Live data flow (configured via `NEXT_PUBLIC_API_URL`, see `.env.example`):
 * - Storefront settings (`tickerItems`, `stats`, `features`,
 *   `FREE_SHIPPING_THRESHOLD`) → `GET {NEXT_PUBLIC_API_URL}/v1/settings`
 *   via `getPublicSettings()` (`./api`) consumed through `useShopSettings()`
 *   (`./use-settings`). The statics below are only the fallback rendered
 *   until the API responds (or when it is unreachable).
 * - Categories / products → `GET {NEXT_PUBLIC_API_URL}/v1/catalog/...`
 *   (see `./api`). The static `categories`/`products` below remain as
 *   fallback until that migration lands.
 */
export type Category = {
  id: string;
  name: string;
  desc: string;
  image: string;
  tint: string;
};

/** Return Arabic string for `ar`, English fallback for `en`/`fr`. */
export const pickLocale = (locale: string | undefined, ar: string, en: string) =>
  locale === "ar" ? ar : en;

export type Product = {
  id: string;
  name: string;
  spec: string;
  price: number;
  oldPrice?: number;
  image: string;
  category: string;
  freeShipping?: boolean;
  startsFrom?: boolean;
  badge?: "جديد" | "الأكثر مبيعًا" | "عرض خاص";
  rating: number;
  reviews: number;
};

export const FREE_SHIPPING_THRESHOLD = 1500;

export const IMG = {
  hero: "/images/hero.png",
  espresso: "/images/espresso.png",
  grinder: "/images/grinder.png",
  icemaker: "/images/icemaker.png",
  showcase: "/images/showcase.png",
  fryer: "/images/fryer.png",
  oven: "/images/oven.png",
  juice: "/images/juice.png",
  mixer: "/images/mixer.png",
  banner: "/images/banner.png",
};

export const categories: Category[] = [
  { id: "coffee", name: "محضّرات القهوة", desc: "مكائن إسبريسو إيطالية للكافيهات والمطاعم", image: IMG.espresso, tint: "#FF6A00" },
  { id: "grinders", name: "مطاحن القهوة", desc: "مطاحن إسبريسو وسنجل دوز بدقة طحن عالية", image: IMG.grinder, tint: "#062B6F" },
  { id: "cooling", name: "التبريد والتجميد", desc: "ثلاجات عرض وصانعات ثلج بتوفير طاقة عالي", image: IMG.icemaker, tint: "#0B4EA2" },
  { id: "cooking", name: "الأفران والطهي التجاري", desc: "أفران ديك وكونفكشن للمخابز والمطابخ المركزية", image: IMG.oven, tint: "#D65600" },
  { id: "frying", name: "معدات القلي", desc: "قلايات كهرباء وغاز بأحواض ومصارف زيت", image: IMG.fryer, tint: "#B44A00" },
  { id: "bakery", name: "الحلويات والمخبوزات", desc: "عجانات وخفاقات وفردات لمعامل الحلويات", image: IMG.mixer, tint: "#1459B8" },
  { id: "drinks", name: "المشروبات والعصائر", desc: "برادات عصير وسلاش وغلايات لكل قائمة مشروبات", image: IMG.juice, tint: "#FF862E" },
];

export const products: Product[] = [
  // ===== محضرات القهوة =====
  { id: "cf-1", name: "ماكينة إسبريسو فيوتزا ٢ جروب", spec: "مبادلات حرارية • بخار قوي • صناعة إيطالية", price: 58000, oldPrice: 63500, image: IMG.espresso, category: "coffee", freeShipping: true, badge: "الأكثر مبيعًا", rating: 4.9, reviews: 84 },
  { id: "cf-2", name: "ماكينة إسبريسو ستريما ٣ جروب", spec: "٣ مجموعات • غلاية ١٤ لتر • شاشة رقمية", price: 86000, image: IMG.espresso, category: "coffee", freeShipping: true, rating: 4.8, reviews: 41 },
  { id: "cf-3", name: "ماكينة أورورا برو ٢ جروب", spec: "تحكم حراري PID • جسم ستانلس كامل", price: 72000, image: IMG.espresso, category: "coffee", freeShipping: true, rating: 4.9, reviews: 63 },
  { id: "cf-4", name: "ماكينة كلاسيكا ١ جروب", spec: "مثالية للركن الصغير • ضغط ٩ بار", price: 14500, image: IMG.espresso, category: "coffee", rating: 4.6, reviews: 122 },
  { id: "cf-5", name: "ماكينة بروفيلو ٣ جروب", spec: "بروفايل ضغط متغير • للباريستا المحترف", price: 95000, image: IMG.espresso, category: "coffee", freeShipping: true, badge: "جديد", rating: 5.0, reviews: 19 },
  { id: "cf-6", name: "ماكينة كومباتتا ٢ جروب", spec: "أداء مستقر للاستخدام الشاق اليومي", price: 69000, image: IMG.espresso, category: "coffee", freeShipping: true, rating: 4.7, reviews: 55 },
  { id: "cf-7", name: "ماكينة مختصة جنيرازيون ٣ جروب", spec: "تصميم مفتوح • إضاءة LED للأكواب", price: 55600, image: IMG.espresso, category: "coffee", freeShipping: true, rating: 4.8, reviews: 37 },
  { id: "cf-8", name: "ماكينة فيتوريو ١ جروب", spec: "اقتصادية • ضمان سنتان شامل", price: 11500, oldPrice: 12900, image: IMG.espresso, category: "coffee", badge: "عرض خاص", rating: 4.5, reviews: 210 },

  // ===== المطاحن =====
  { id: "gr-1", name: "مطحنة نيرا ٦٤ مم", spec: "حجارة مسطحة • ضبط تدريجي ناعم", price: 3220, image: IMG.grinder, category: "grinders", freeShipping: true, rating: 4.7, reviews: 96 },
  { id: "gr-2", name: "مطحنة نيرا تاتش ٦٥ مم", spec: "شاشة لمس • جرعات مبرمجة", price: 4000, image: IMG.grinder, category: "grinders", freeShipping: true, badge: "الأكثر مبيعًا", rating: 4.8, reviews: 71 },
  { id: "gr-3", name: "مطحنة أوبليدج ٨٥ مم", spec: "للإسبريسو والمقطرة والتركي", price: 9545, image: IMG.grinder, category: "grinders", freeShipping: true, rating: 4.9, reviews: 28 },
  { id: "gr-4", name: "مطحنة أوبل ديجيتال ٦٤ مم", spec: "عداد رقمي • تبريد داخلي", price: 3041, image: IMG.grinder, category: "grinders", freeShipping: true, rating: 4.6, reviews: 88 },
  { id: "gr-5", name: "مطحنة أوبل سنجل دوز", spec: "بلا احتباس • للمختصة", price: 3680, image: IMG.grinder, category: "grinders", rating: 4.7, reviews: 44 },
  { id: "gr-6", name: "مطحنة كوميتا M80 فضي", spec: "٦٣ مم • جسم ألمنيوم مصقول", price: 4400, image: IMG.grinder, category: "grinders", freeShipping: true, rating: 4.6, reviews: 39 },
  { id: "gr-7", name: "مطحنة كوميتا Q13 أسود", spec: "٧٥ مم • سرعة طحن أعلى", price: 4850, image: IMG.grinder, category: "grinders", rating: 4.7, reviews: 31 },
  { id: "gr-8", name: "مطحنة ميثوس برو ٧٥ مم", spec: "تبريد نشط • ثبات حراري للذروة", price: 7900, image: IMG.grinder, category: "grinders", freeShipping: true, badge: "جديد", rating: 4.9, reviews: 16 },

  // ===== التبريد =====
  { id: "co-1", name: "صانعة ثلج بريز مكعبات ٩٥ كجم", spec: "إنتاج يومي ٩٥ كجم • تبريد هوائي", price: 10810, image: IMG.icemaker, category: "cooling", freeShipping: true, badge: "الأكثر مبيعًا", rating: 4.8, reviews: 52 },
  { id: "co-2", name: "صانعة ثلج بريز ٨٤٠", spec: "٨٥ كجم/يوم • خزان تخزين مدمج", price: 12400, image: IMG.icemaker, category: "cooling", freeShipping: true, rating: 4.7, reviews: 36 },
  { id: "co-3", name: "صانعة ثلج مجروش فروستا ٦٧ كجم", spec: "ثلج مجروش للعصائر والكوكتيلات", price: 12400, image: IMG.icemaker, category: "cooling", freeShipping: true, rating: 4.6, reviews: 24 },
  { id: "co-4", name: "صانعة ثلج توب كول ٢٦ كجم", spec: "حجم مدمج • للمطابخ الصغيرة", price: 2990, oldPrice: 3450, image: IMG.icemaker, category: "cooling", badge: "عرض خاص", rating: 4.4, reviews: 143 },
  { id: "co-5", name: "ثلاجة عرض حلويات مقوسة ٩٠ سم", spec: "زجاج منحني • إضاءة داخلية دافئة", price: 3450, image: IMG.showcase, category: "cooling", rating: 4.5, reviews: 67 },
  { id: "co-6", name: "ثلاجة عرض استانلس ٣٠٤ — ١٢٠ سم", spec: "ستانلس غذائي ٣٠٤ • أبواب منزلقة", price: 9660, image: IMG.showcase, category: "cooling", freeShipping: true, rating: 4.8, reviews: 29 },
  { id: "co-7", name: "ثلاجة عرض ستيل ١٥٠ سم", spec: "عرض واسع • تحكم رقمي بالحرارة", price: 10224, image: IMG.showcase, category: "cooling", freeShipping: true, rating: 4.7, reviews: 22 },
  { id: "co-8", name: "فريزر عرض آيس كريم واقف", spec: "زجاج مزدوج • درجة -٢٢ ثابتة", price: 17999, image: IMG.icemaker, category: "cooling", freeShipping: true, badge: "جديد", rating: 4.6, reviews: 11 },

  // ===== الأفران =====
  { id: "ov-1", name: "فرن كهرباء ١ دور — صينية واحدة", spec: "تحكم علوي وسفلي مستقل", price: 2600, image: IMG.oven, category: "cooking", rating: 4.5, reviews: 156 },
  { id: "ov-2", name: "فرن غاز ١ دور — صينيتان", spec: "اشتعال ذاتي • موزع حرارة متساوٍ", price: 3700, image: IMG.oven, category: "cooking", freeShipping: true, rating: 4.6, reviews: 98 },
  { id: "ov-3", name: "فرن كهرباء ١ دور — ٣ صواني", spec: "مثالي للمخابز الناشئة", price: 4250, image: IMG.oven, category: "cooking", freeShipping: true, rating: 4.6, reviews: 74 },
  { id: "ov-4", name: "فرن كهرباء ٢ دور — ٤ صواني", spec: "عزل حراري مضاعف • مؤقت رقمي", price: 6610, image: IMG.oven, category: "cooking", freeShipping: true, badge: "الأكثر مبيعًا", rating: 4.8, reviews: 61 },
  { id: "ov-5", name: "فرن كهرباء ٣ دور — ٩ صواني", spec: "إنتاجية عالية للمعامل", price: 10412, image: IMG.oven, category: "cooking", freeShipping: true, rating: 4.8, reviews: 33 },
  { id: "ov-6", name: "فرن كونفكشن غاز — ١٠ صواني", spec: "مروحة توزيع • بخار مباشر", price: 14000, image: IMG.oven, category: "cooking", freeShipping: true, rating: 4.7, reviews: 27 },
  { id: "ov-7", name: "فرن كونفكشن إيطالي — ١٠ صواني", spec: "برامج طهي محفوظة • صناعة إيطالية", price: 23699, image: IMG.oven, category: "cooking", freeShipping: true, rating: 4.9, reviews: 18 },
  { id: "ov-8", name: "فرن كومبي شيف توب", spec: "طهي بالبخار والحرارة معًا • ١٠ صواني", price: 40000, image: IMG.oven, category: "cooking", freeShipping: true, badge: "جديد", rating: 5.0, reviews: 8 },

  // ===== القلي =====
  { id: "fr-1", name: "قلاية كهربائية ١١ لتر", spec: "حوض واحد • ترموستات أمان", price: 750, image: IMG.fryer, category: "frying", rating: 4.4, reviews: 201 },
  { id: "fr-2", name: "قلاية حوضين — ٨ لتر للحوض", spec: "شبتان • سلال ستانلس", price: 1187, image: IMG.fryer, category: "frying", rating: 4.5, reviews: 132 },
  { id: "fr-3", name: "قلاية حوضين — ١١ لتر للحوض", spec: "تسخين سريع • مؤشرات حرارة", price: 1350, image: IMG.fryer, category: "frying", badge: "الأكثر مبيعًا", rating: 4.6, reviews: 118 },
  { id: "fr-4", name: "قلاية ١٣ لتر بمصرف زيت", spec: "تصفية زيت سهلة بعد الخدمة", price: 937, image: IMG.fryer, category: "frying", rating: 4.4, reviews: 87 },
  { id: "fr-5", name: "قلاية بخزانة ١٦ لتر", spec: "خزانة سفلية للتخزين • مصرف", price: 1900, image: IMG.fryer, category: "frying", freeShipping: true, rating: 4.6, reviews: 64 },
  { id: "fr-6", name: "قلاية كهرباء حوضين ١٦ لتر", spec: "١٦+١٦ لتر • للمطاعم المتوسطة", price: 1750, image: IMG.fryer, category: "frying", freeShipping: true, rating: 4.5, reviews: 59 },
  { id: "fr-7", name: "قلاية غاز حوضين بمصرفين", spec: "شعلات عالية الكفاءة", price: 2350, image: IMG.fryer, category: "frying", freeShipping: true, rating: 4.6, reviews: 45 },
  { id: "fr-8", name: "قلاية زيت خزانتين ٣٢ لتر", spec: "٣٢ لتر مع مصرفين • تشغيل شاق", price: 3700, image: IMG.fryer, category: "frying", freeShipping: true, badge: "جديد", rating: 4.7, reviews: 21 },

  // ===== الحلويات والمخبوزات =====
  { id: "bk-1", name: "عجانة حلزونية ٣٥ لتر", spec: "محرك مزدوج السرعة • وعاء ستانلس", price: 4000, image: IMG.mixer, category: "bakery", freeShipping: true, startsFrom: true, rating: 4.7, reviews: 89 },
  { id: "bk-2", name: "خفاق حلويات ١٠ لتر", spec: "٣ ملحقات • للكريمات والعجائن الخفيفة", price: 1850, image: IMG.mixer, category: "bakery", freeShipping: true, startsFrom: true, rating: 4.5, reviews: 144 },
  { id: "bk-3", name: "عجانة حلزونية ٢٠ لتر", spec: "للمعامل الصغيرة • حماية غطاء", price: 2950, image: IMG.mixer, category: "bakery", freeShipping: true, rating: 4.6, reviews: 72 },
  { id: "bk-4", name: "فردة عجين ٥٠ سم", spec: "سماكة قابلة للضبط • سير تفلون", price: 3300, image: IMG.mixer, category: "bakery", rating: 4.5, reviews: 38 },
  { id: "bk-5", name: "ماكينة تشيمني كيك", spec: "٤ أسياخ دوارة • للمقاهي والفعاليات", price: 3450, image: IMG.mixer, category: "bakery", freeShipping: true, badge: "جديد", rating: 4.6, reviews: 26 },
  { id: "bk-6", name: "مخمّر معجنات ١٦ صينية", spec: "تحكم بالرطوبة والحرارة", price: 5200, image: IMG.mixer, category: "bakery", freeShipping: true, rating: 4.7, reviews: 31 },
  { id: "bk-7", name: "عجانة حلزونية ٦٠ لتر", spec: "لخطوط الإنتاج • عجلات تثبيت", price: 6800, image: IMG.mixer, category: "bakery", freeShipping: true, rating: 4.8, reviews: 17 },
  { id: "bk-8", name: "خفاق كوكيز ٢٠ لتر", spec: "خلاط كواكب • دقة خلط عالية", price: 3150, oldPrice: 3600, image: IMG.mixer, category: "bakery", badge: "عرض خاص", rating: 4.6, reviews: 52 },

  // ===== المشروبات =====
  { id: "dr-1", name: "سلاش ١٢ لتر — ٣ أحواض", spec: "تبريد مزدوج • أحواض شفافة", price: 7200, image: IMG.juice, category: "drinks", freeShipping: true, badge: "الأكثر مبيعًا", rating: 4.8, reviews: 66 },
  { id: "dr-2", name: "سلاش ١٢ لتر — حوضان", spec: "مثالي للبوفيهات والكافيهات", price: 5500, image: IMG.juice, category: "drinks", freeShipping: true, rating: 4.7, reviews: 48 },
  { id: "dr-3", name: "براد عصير ١٨ لتر — ٣ أحواض", spec: "تحريك مستمر • تبريد سريع", price: 2850, image: IMG.juice, category: "drinks", freeShipping: true, rating: 4.6, reviews: 91 },
  { id: "dr-4", name: "براد عصير ١٨ لتر — حوض واحد", spec: "اقتصادي • صيانة سهلة", price: 1750, image: IMG.juice, category: "drinks", rating: 4.4, reviews: 120 },
  { id: "dr-5", name: "غلاية ماء تجارية ١٢ لتر", spec: "ستانلس • مؤشر مستوى الماء", price: 385, image: IMG.juice, category: "drinks", rating: 4.3, reviews: 233 },
  { id: "dr-6", name: "حافظة قهوة ٤ لتر مع القاعدة", spec: "حفظ حراري ٤ ساعات", price: 816, image: IMG.juice, category: "drinks", rating: 4.5, reviews: 77 },
  { id: "dr-7", name: "دلة تجاهيز الذهبية", spec: "تصميم تراثي • مطلية ذهب", price: 500, image: IMG.juice, category: "drinks", badge: "جديد", rating: 4.9, reviews: 35 },
  { id: "dr-8", name: "ماكينة ميلك شيك حوضان", spec: "خفق سريع • أكواب ستانلس", price: 2650, image: IMG.juice, category: "drinks", freeShipping: true, rating: 4.5, reviews: 41 },
];

export const newArrivals = ["cf-5", "gr-8", "co-8", "ov-8", "fr-8", "bk-5", "dr-7", "cf-1"];
export const bestSellers = ["cf-1", "co-1", "gr-2", "ov-4", "fr-3", "bk-1", "dr-1", "cf-8"];

export const projects = [
  { id: "p1", name: "تجهيز كافيهات", count: 46, image: IMG.hero, tag: "من الفكرة إلى الافتتاح" },
  { id: "p2", name: "تجهيز مطاعم", count: 58, image: IMG.fryer, tag: "مطابخ تشغيل كامل" },
  { id: "p3", name: "محلات الحلويات", count: 24, image: IMG.showcase, tag: "عرض وتبريد وقوالب" },
  { id: "p4", name: "تجهيز مخابز", count: 19, image: IMG.banner, tag: "خط إنتاج متكامل" },
  { id: "p5", name: "سوبر ماركت", count: 15, image: IMG.icemaker, tag: "تبريد وعرض وتخزين" },
  { id: "p6", name: "فنادق وضيافة", count: 12, image: IMG.juice, tag: "بوفيه وخدمة غرف" },
];

export const brands = [
  { ar: "أورورا", en: "AURORA", since: "إيطاليا", sinceEn: "Italy" },
  { ar: "كافينتي", en: "CAFFENTE", since: "إيطاليا", sinceEn: "Italy" },
  { ar: "نوردستيل", en: "NORDSTEEL", since: "ألمانيا", sinceEn: "Germany" },
  { ar: "فروستيكا", en: "FROSTICA", since: "تركيا", sinceEn: "Turkey" },
  { ar: "توربو شيف", en: "TURBOCHEF", since: "أمريكا", sinceEn: "USA" },
  { ar: "ميلانو روست", en: "MILANO ROAST", since: "إيطاليا", sinceEn: "Italy" },
  { ar: "بولاريس", en: "POLARIS COOL", since: "إسبانيا", sinceEn: "Spain" },
  { ar: "كوميتا", en: "COMETA", since: "إيطاليا", sinceEn: "Italy" },
];

// export const features = [
//   { id: "f1", title: "استيراد مباشر", desc: "نستورد من المصانع الأم مباشرة، فكل قطعة أصلية وبسعر بلا وسطاء.", icon: "cargo" },
//   { id: "f2", title: "وكلاء معتمدون", desc: "وكالة رسمية لعلامات عالمية مع شهادات اعتماد وضمان مصنعي.", icon: "shield" },
// ];

export const stats = [
  { value: 30, suffix: "+", label: "مدينة نغطيها بالشحن" },
  { value: 120, suffix: "+", label: "مشروع جُهّز معنا" },
  { value: 15, suffix: "+", label: "سنة في السوق السعودي" },
  { value: 98, suffix: "%", label: "رضا عملائنا" },
];



export const cities: { ar: string; en: string }[] = [
  { ar: "الرياض", en: "Riyadh" },
  { ar: "جدة", en: "Jeddah" },
  { ar: "الدمام", en: "Dammam" },
  { ar: "مكة", en: "Makkah" },
  { ar: "المدينة", en: "Madinah" },
  { ar: "أبها", en: "Abha" },
];

export const formatPrice = (n: number) => n.toLocaleString("en-US");

export const productById = (id: string) => products.find((p) => p.id === id)!;
export const byCategory = (catId: string) => products.filter((p) => p.category === catId);
