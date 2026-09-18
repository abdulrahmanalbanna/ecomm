-- =============================================================================
-- 022 : Catalog Seed — categories, brands, products, variants, inventory
-- Source: backend storage/app/public/catalog/*.png (served as {APP_URL}/storage/catalog/*.png)
-- Frontend (Next.js) reads these via CategoryResource.image_url + ProductPublicResource.media[].url.
-- Run `php artisan storage:link` so public/storage → storage/app/public.
-- Depends on: 004_categories.sql, 005_products_attributes_variants.sql,
--             006_inventory.sql, 021_seed_data.sql
-- =============================================================================

BEGIN;

SET LOCAL client_encoding = 'UTF8';

-- ---------------------------------------------------------------------------
-- 1) CATEGORIES (7 roots, parent_id NULL; path/depth auto via trigger)
-- ---------------------------------------------------------------------------
INSERT INTO categories (parent_id, slug, name, description, image_url, is_active, sort_order) VALUES
(NULL, 'coffee',   'محضّرات القهوة',         'مكائن إسبريسو إيطالية للكافيهات والمطاعم',     '/storage/catalog/espresso.png', TRUE, 1),
(NULL, 'grinders', 'مطاحن القهوة',           'مطاحن إسبريسو وسنجل دوز بدقة طحن عالية',       '/storage/catalog/grinder.png',  TRUE, 2),
(NULL, 'cooling',  'التبريد والتجميد',       'ثلاجات عرض وصانعات ثلج بتوفير طاقة عالي',     '/storage/catalog/icemaker.png', TRUE, 3),
(NULL, 'cooking',  'الأفران والطهي التجاري', 'أفران ديك وكونفكشن للمخابز والمطابخ المركزية', '/storage/catalog/oven.png',     TRUE, 4),
(NULL, 'frying',   'معدات القلي',            'قلايات كهرباء وغاز بأحواض ومصارف زيت',         '/storage/catalog/fryer.png',    TRUE, 5),
(NULL, 'bakery',   'الحلويات والمخبوزات',    'عجانات وخفاقات وفردات لمعامل الحلويات',       '/storage/catalog/mixer.png',    TRUE, 6),
(NULL, 'drinks',   'المشروبات والعصائر',     'برادات عصير وسلاش وغلايات لكل قائمة مشروبات', '/storage/catalog/juice.png',    TRUE, 7)
ON CONFLICT (slug) DO UPDATE SET
  name = EXCLUDED.name, description = EXCLUDED.description,
  image_url = EXCLUDED.image_url, is_active = TRUE,
  sort_order = EXCLUDED.sort_order, updated_at = now();

-- ---------------------------------------------------------------------------
-- 2) BRANDS (8 from catalog.ts brands)
-- ---------------------------------------------------------------------------
INSERT INTO brands (slug, name, logo_url, website_url, description, is_active, is_featured) VALUES
('aurora',       'AURORA',       '/storage/catalog/espresso.png',       NULL, 'أورورا — إيطاليا / Italy',      TRUE, TRUE),
('caffente',     'CAFFENTE',     '/storage/catalog/espresso.png',     NULL, 'كافينتي — إيطاليا / Italy',     TRUE, TRUE),
('nordsteel',    'NORDSTEEL',    '/storage/catalog/oven.png',    NULL, 'نوردستيل — ألمانيا / Germany',  TRUE, TRUE),
('frostica',     'FROSTICA',     '/storage/catalog/icemaker.png',     NULL, 'فروستيكا — تركيا / Turkey',     TRUE, TRUE),
('turbochef',    'TURBOCHEF',    '/storage/catalog/fryer.png',    NULL, 'توربو شيف — أمريكا / USA',      TRUE, TRUE),
('milano-roast', 'MILANO ROAST', '/storage/catalog/espresso.png', NULL, 'ميلانو روست — إيطاليا / Italy', TRUE, TRUE),
('polaris-cool', 'POLARIS COOL', '/storage/catalog/showcase.png', NULL, 'بولاريس — إسبانيا / Spain',     TRUE, TRUE),
('cometa',       'COMETA',       '/storage/catalog/grinder.png',       NULL, 'كوميتا — إيطاليا / Italy',      TRUE, TRUE)
ON CONFLICT (slug) DO UPDATE SET
  name = EXCLUDED.name, logo_url = EXCLUDED.logo_url,
  description = EXCLUDED.description, is_active = TRUE,
  is_featured = EXCLUDED.is_featured, updated_at = now();

-- ---------------------------------------------------------------------------
-- 3) PRODUCTS — coffee (8) + grinders (8)
-- is_featured=TRUE only for bestSellers: cf-1, gr-2, co-1, ov-4, fr-3, bk-1, dr-1, cf-8
-- ---------------------------------------------------------------------------
INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='aurora'), 'cf-1', 'ماكينة إسبريسو فيوتزا ٢ جروب', 'مبادلات حرارية • بخار قوي • صناعة إيطالية', 'مبادلات حرارية • بخار قوي • صناعة إيطالية', 'AURORA', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','مبادلات حرارية • بخار قوي • صناعة إيطالية','rating',4.9,'reviews',84,'badge','الأكثر مبيعًا','free_shipping',true), TRUE, TRUE, 'published', 'ماكينة إسبريسو فيوتزا ٢ جروب', 'مبادلات حرارية • بخار قوي • صناعة إيطالية')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='caffente'), 'cf-2', 'ماكينة إسبريسو ستريما ٣ جروب', '٣ مجموعات • غلاية ١٤ لتر • شاشة رقمية', '٣ مجموعات • غلاية ١٤ لتر • شاشة رقمية', 'CAFFENTE', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٣ مجموعات • غلاية ١٤ لتر • شاشة رقمية','rating',4.8,'reviews',41,'free_shipping',true), TRUE, FALSE, 'published', 'ماكينة إسبريسو ستريما ٣ جروب', '٣ مجموعات • غلاية ١٤ لتر • شاشة رقمية')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='aurora'), 'cf-3', 'ماكينة أورورا برو ٢ جروب', 'تحكم حراري PID • جسم ستانلس كامل', 'تحكم حراري PID • جسم ستانلس كامل', 'AURORA', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تحكم حراري PID • جسم ستانلس كامل','rating',4.9,'reviews',63,'free_shipping',true), TRUE, FALSE, 'published', 'ماكينة أورورا برو ٢ جروب', 'تحكم حراري PID • جسم ستانلس كامل')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='caffente'), 'cf-4', 'ماكينة كلاسيكا ١ جروب', 'مثالية للركن الصغير • ضغط ٩ بار', 'مثالية للركن الصغير • ضغط ٩ بار', 'CAFFENTE', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','مثالية للركن الصغير • ضغط ٩ بار','rating',4.6,'reviews',122), TRUE, FALSE, 'published', 'ماكينة كلاسيكا ١ جروب', 'مثالية للركن الصغير • ضغط ٩ بار')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='milano-roast'), 'cf-5', 'ماكينة بروفيلو ٣ جروب', 'بروفايل ضغط متغير • للباريستا المحترف', 'بروفايل ضغط متغير • للباريستا المحترف', 'MILANO ROAST', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','بروفايل ضغط متغير • للباريستا المحترف','rating',5.0,'reviews',19,'badge','جديد','free_shipping',true), TRUE, FALSE, 'published', 'ماكينة بروفيلو ٣ جروب', 'بروفايل ضغط متغير • للباريستا المحترف')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='aurora'), 'cf-6', 'ماكينة كومباتتا ٢ جروب', 'أداء مستقر للاستخدام الشاق اليومي', 'أداء مستقر للاستخدام الشاق اليومي', 'AURORA', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','أداء مستقر للاستخدام الشاق اليومي','rating',4.7,'reviews',55,'free_shipping',true), TRUE, FALSE, 'published', 'ماكينة كومباتتا ٢ جروب', 'أداء مستقر للاستخدام الشاق اليومي')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='caffente'), 'cf-7', 'ماكينة مختصة جنيرازيون ٣ جروب', 'تصميم مفتوح • إضاءة LED للأكواب', 'تصميم مفتوح • إضاءة LED للأكواب', 'CAFFENTE', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تصميم مفتوح • إضاءة LED للأكواب','rating',4.8,'reviews',37,'free_shipping',true), TRUE, FALSE, 'published', 'ماكينة مختصة جنيرازيون ٣ جروب', 'تصميم مفتوح • إضاءة LED للأكواب')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='coffee'), (SELECT id FROM brands WHERE slug='milano-roast'), 'cf-8', 'ماكينة فيتوريو ١ جروب', 'اقتصادية • ضمان سنتان شامل', 'اقتصادية • ضمان سنتان شامل', 'MILANO ROAST', ARRAY['coffee','espresso','commercial'], '[{"url":"/storage/catalog/espresso.png","alt":"cf-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','اقتصادية • ضمان سنتان شامل','rating',4.5,'reviews',210,'badge','عرض خاص'), TRUE, TRUE, 'published', 'ماكينة فيتوريو ١ جروب', 'اقتصادية • ضمان سنتان شامل')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

-- grinders (COMETA) --
INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-1', 'مطحنة نيرا ٦٤ مم', 'حجارة مسطحة • ضبط تدريجي ناعم', 'حجارة مسطحة • ضبط تدريجي ناعم', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','حجارة مسطحة • ضبط تدريجي ناعم','rating',4.7,'reviews',96,'free_shipping',true), TRUE, FALSE, 'published', 'مطحنة نيرا ٦٤ مم', 'حجارة مسطحة • ضبط تدريجي ناعم')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-2', 'مطحنة نيرا تاتش ٦٥ مم', 'شاشة لمس • جرعات مبرمجة', 'شاشة لمس • جرعات مبرمجة', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','شاشة لمس • جرعات مبرمجة','rating',4.8,'reviews',71,'badge','الأكثر مبيعًا','free_shipping',true), TRUE, TRUE, 'published', 'مطحنة نيرا تاتش ٦٥ مم', 'شاشة لمس • جرعات مبرمجة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-3', 'مطحنة أوبليدج ٨٥ مم', 'للإسبريسو والمقطرة والتركي', 'للإسبريسو والمقطرة والتركي', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','للإسبريسو والمقطرة والتركي','rating',4.9,'reviews',28,'free_shipping',true), TRUE, FALSE, 'published', 'مطحنة أوبليدج ٨٥ مم', 'للإسبريسو والمقطرة والتركي')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-4', 'مطحنة أوبل ديجيتال ٦٤ مم', 'عداد رقمي • تبريد داخلي', 'عداد رقمي • تبريد داخلي', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','عداد رقمي • تبريد داخلي','rating',4.6,'reviews',88,'free_shipping',true), TRUE, FALSE, 'published', 'مطحنة أوبل ديجيتال ٦٤ مم', 'عداد رقمي • تبريد داخلي')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-5', 'مطحنة أوبل سنجل دوز', 'بلا احتباس • للمختصة', 'بلا احتباس • للمختصة', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','بلا احتباس • للمختصة','rating',4.7,'reviews',44), TRUE, FALSE, 'published', 'مطحنة أوبل سنجل دوز', 'بلا احتباس • للمختصة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-6', 'مطحنة كوميتا M80 فضي', '٦٣ مم • جسم ألمنيوم مصقول', '٦٣ مم • جسم ألمنيوم مصقول', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٦٣ مم • جسم ألمنيوم مصقول','rating',4.6,'reviews',39,'free_shipping',true), TRUE, FALSE, 'published', 'مطحنة كوميتا M80 فضي', '٦٣ مم • جسم ألمنيوم مصقول')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-7', 'مطحنة كوميتا Q13 أسود', '٧٥ مم • سرعة طحن أعلى', '٧٥ مم • سرعة طحن أعلى', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٧٥ مم • سرعة طحن أعلى','rating',4.7,'reviews',31), TRUE, FALSE, 'published', 'مطحنة كوميتا Q13 أسود', '٧٥ مم • سرعة طحن أعلى')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='grinders'), (SELECT id FROM brands WHERE slug='cometa'), 'gr-8', 'مطحنة ميثوس برو ٧٥ مم', 'تبريد نشط • ثبات حراري للذروة', 'تبريد نشط • ثبات حراري للذروة', 'COMETA', ARRAY['coffee','grinder','commercial'], '[{"url":"/storage/catalog/grinder.png","alt":"gr-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تبريد نشط • ثبات حراري للذروة','rating',4.9,'reviews',16,'badge','جديد','free_shipping',true), TRUE, FALSE, 'published', 'مطحنة ميثوس برو ٧٥ مم', 'تبريد نشط • ثبات حراري للذروة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

-- cooling (FROSTICA x4 + POLARIS COOL x4) --
INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='frostica'), 'co-1', 'صانعة ثلج بريز مكعبات ٩٥ كجم', 'إنتاج يومي ٩٥ كجم • تبريد هوائي', 'إنتاج يومي ٩٥ كجم • تبريد هوائي', 'FROSTICA', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/icemaker.png","alt":"co-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','إنتاج يومي ٩٥ كجم • تبريد هوائي','rating',4.8,'reviews',52,'badge','الأكثر مبيعًا','free_shipping',true), TRUE, TRUE, 'published', 'صانعة ثلج بريز مكعبات ٩٥ كجم', 'إنتاج يومي ٩٥ كجم • تبريد هوائي')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='frostica'), 'co-2', 'صانعة ثلج بريز ٨٤٠', '٨٥ كجم/يوم • خزان تخزين مدمج', '٨٥ كجم/يوم • خزان تخزين مدمج', 'FROSTICA', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/icemaker.png","alt":"co-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٨٥ كجم/يوم • خزان تخزين مدمج','rating',4.7,'reviews',36,'free_shipping',true), TRUE, FALSE, 'published', 'صانعة ثلج بريز ٨٤٠', '٨٥ كجم/يوم • خزان تخزين مدمج')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='frostica'), 'co-3', 'صانعة ثلج مجروش فروستا ٦٧ كجم', 'ثلج مجروش للعصائر والكوكتيلات', 'ثلج مجروش للعصائر والكوكتيلات', 'FROSTICA', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/icemaker.png","alt":"co-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','ثلج مجروش للعصائر والكوكتيلات','rating',4.6,'reviews',24,'free_shipping',true), TRUE, FALSE, 'published', 'صانعة ثلج مجروش فروستا ٦٧ كجم', 'ثلج مجروش للعصائر والكوكتيلات')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='frostica'), 'co-4', 'صانعة ثلج توب كول ٢٦ كجم', 'حجم مدمج • للمطابخ الصغيرة', 'حجم مدمج • للمطابخ الصغيرة', 'FROSTICA', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/icemaker.png","alt":"co-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','حجم مدمج • للمطابخ الصغيرة','rating',4.4,'reviews',143,'badge','عرض خاص'), TRUE, FALSE, 'published', 'صانعة ثلج توب كول ٢٦ كجم', 'حجم مدمج • للمطابخ الصغيرة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='polaris-cool'), 'co-5', 'ثلاجة عرض حلويات مقوسة ٩٠ سم', 'زجاج منحني • إضاءة داخلية دافئة', 'زجاج منحني • إضاءة داخلية دافئة', 'POLARIS COOL', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/showcase.png","alt":"co-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','زجاج منحني • إضاءة داخلية دافئة','rating',4.5,'reviews',67), TRUE, FALSE, 'published', 'ثلاجة عرض حلويات مقوسة ٩٠ سم', 'زجاج منحني • إضاءة داخلية دافئة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='polaris-cool'), 'co-6', 'ثلاجة عرض استانلس ٣٠٤ — ١٢٠ سم', 'ستانلس غذائي ٣٠٤ • أبواب منزلقة', 'ستانلس غذائي ٣٠٤ • أبواب منزلقة', 'POLARIS COOL', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/showcase.png","alt":"co-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','ستانلس غذائي ٣٠٤ • أبواب منزلقة','rating',4.8,'reviews',29,'free_shipping',true), TRUE, FALSE, 'published', 'ثلاجة عرض استانلس ٣٠٤ — ١٢٠ سم', 'ستانلس غذائي ٣٠٤ • أبواب منزلقة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='polaris-cool'), 'co-7', 'ثلاجة عرض ستيل ١٥٠ سم', 'عرض واسع • تحكم رقمي بالحرارة', 'عرض واسع • تحكم رقمي بالحرارة', 'POLARIS COOL', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/showcase.png","alt":"co-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','عرض واسع • تحكم رقمي بالحرارة','rating',4.7,'reviews',22,'free_shipping',true), TRUE, FALSE, 'published', 'ثلاجة عرض ستيل ١٥٠ سم', 'عرض واسع • تحكم رقمي بالحرارة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooling'), (SELECT id FROM brands WHERE slug='polaris-cool'), 'co-8', 'فريزر عرض آيس كريم واقف', 'زجاج مزدوج • درجة -٢٢ ثابتة', 'زجاج مزدوج • درجة -٢٢ ثابتة', 'POLARIS COOL', ARRAY['cooling','refrigeration','commercial'], '[{"url":"/storage/catalog/icemaker.png","alt":"co-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','زجاج مزدوج • درجة -٢٢ ثابتة','rating',4.6,'reviews',11,'badge','جديد','free_shipping',true), TRUE, FALSE, 'published', 'فريزر عرض آيس كريم واقف', 'زجاج مزدوج • درجة -٢٢ ثابتة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

-- cooking (NORDSTEEL x8) --
INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-1', 'فرن كهرباء ١ دور — صينية واحدة', 'تحكم علوي وسفلي مستقل', 'تحكم علوي وسفلي مستقل', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تحكم علوي وسفلي مستقل','rating',4.5,'reviews',156), TRUE, FALSE, 'published', 'فرن كهرباء ١ دور — صينية واحدة', 'تحكم علوي وسفلي مستقل')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-2', 'فرن غاز ١ دور — صينيتان', 'اشتعال ذاتي • موزع حرارة متساوٍ', 'اشتعال ذاتي • موزع حرارة متساوٍ', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','اشتعال ذاتي • موزع حرارة متساوٍ','rating',4.6,'reviews',98,'free_shipping',true), TRUE, FALSE, 'published', 'فرن غاز ١ دور — صينيتان', 'اشتعال ذاتي • موزع حرارة متساوٍ')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-3', 'فرن كهرباء ١ دور — ٣ صواني', 'مثالي للمخابز الناشئة', 'مثالي للمخابز الناشئة', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','مثالي للمخابز الناشئة','rating',4.6,'reviews',74,'free_shipping',true), TRUE, FALSE, 'published', 'فرن كهرباء ١ دور — ٣ صواني', 'مثالي للمخابز الناشئة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-4', 'فرن كهرباء ٢ دور — ٤ صواني', 'عزل حراري مضاعف • مؤقت رقمي', 'عزل حراري مضاعف • مؤقت رقمي', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','عزل حراري مضاعف • مؤقت رقمي','rating',4.8,'reviews',61,'badge','الأكثر مبيعًا','free_shipping',true), TRUE, TRUE, 'published', 'فرن كهرباء ٢ دور — ٤ صواني', 'عزل حراري مضاعف • مؤقت رقمي')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-5', 'فرن كهرباء ٣ دور — ٩ صواني', 'إنتاجية عالية للمعامل', 'إنتاجية عالية للمعامل', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','إنتاجية عالية للمعامل','rating',4.8,'reviews',33,'free_shipping',true), TRUE, FALSE, 'published', 'فرن كهرباء ٣ دور — ٩ صواني', 'إنتاجية عالية للمعامل')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-6', 'فرن كونفكشن غاز — ١٠ صواني', 'مروحة توزيع • بخار مباشر', 'مروحة توزيع • بخار مباشر', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','مروحة توزيع • بخار مباشر','rating',4.7,'reviews',27,'free_shipping',true), TRUE, FALSE, 'published', 'فرن كونفكشن غاز — ١٠ صواني', 'مروحة توزيع • بخار مباشر')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-7', 'فرن كونفكشن إيطالي — ١٠ صواني', 'برامج طهي محفوظة • صناعة إيطالية', 'برامج طهي محفوظة • صناعة إيطالية', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','برامج طهي محفوظة • صناعة إيطالية','rating',4.9,'reviews',18,'free_shipping',true), TRUE, FALSE, 'published', 'فرن كونفكشن إيطالي — ١٠ صواني', 'برامج طهي محفوظة • صناعة إيطالية')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='cooking'), (SELECT id FROM brands WHERE slug='nordsteel'), 'ov-8', 'فرن كومبي شيف توب', 'طهي بالبخار والحرارة معًا • ١٠ صواني', 'طهي بالبخار والحرارة معًا • ١٠ صواني', 'NORDSTEEL', ARRAY['cooking','oven','commercial'], '[{"url":"/storage/catalog/oven.png","alt":"ov-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','طهي بالبخار والحرارة معًا • ١٠ صواني','rating',5.0,'reviews',8,'badge','جديد','free_shipping',true), TRUE, FALSE, 'published', 'فرن كومبي شيف توب', 'طهي بالبخار والحرارة معًا • ١٠ صواني')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

-- frying (TURBOCHEF x8) --
INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-1', 'قلاية كهربائية ١١ لتر', 'حوض واحد • ترموستات أمان', 'حوض واحد • ترموستات أمان', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','حوض واحد • ترموستات أمان','rating',4.4,'reviews',201), TRUE, FALSE, 'published', 'قلاية كهربائية ١١ لتر', 'حوض واحد • ترموستات أمان')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-2', 'قلاية حوضين — ٨ لتر للحوض', 'شبتان • سلال ستانلس', 'شبتان • سلال ستانلس', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','شبتان • سلال ستانلس','rating',4.5,'reviews',132), TRUE, FALSE, 'published', 'قلاية حوضين — ٨ لتر للحوض', 'شبتان • سلال ستانلس')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-3', 'قلاية حوضين — ١١ لتر للحوض', 'تسخين سريع • مؤشرات حرارة', 'تسخين سريع • مؤشرات حرارة', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تسخين سريع • مؤشرات حرارة','rating',4.6,'reviews',118,'badge','الأكثر مبيعًا'), TRUE, TRUE, 'published', 'قلاية حوضين — ١١ لتر للحوض', 'تسخين سريع • مؤشرات حرارة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-4', 'قلاية ١٣ لتر بمصرف زيت', 'تصفية زيت سهلة بعد الخدمة', 'تصفية زيت سهلة بعد الخدمة', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تصفية زيت سهلة بعد الخدمة','rating',4.4,'reviews',87), TRUE, FALSE, 'published', 'قلاية ١٣ لتر بمصرف زيت', 'تصفية زيت سهلة بعد الخدمة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-5', 'قلاية بخزانة ١٦ لتر', 'خزانة سفلية للتخزين • مصرف', 'خزانة سفلية للتخزين • مصرف', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','خزانة سفلية للتخزين • مصرف','rating',4.6,'reviews',64,'free_shipping',true), TRUE, FALSE, 'published', 'قلاية بخزانة ١٦ لتر', 'خزانة سفلية للتخزين • مصرف')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-6', 'قلاية كهرباء حوضين ١٦ لتر', '١٦+١٦ لتر • للمطاعم المتوسطة', '١٦+١٦ لتر • للمطاعم المتوسطة', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','١٦+١٦ لتر • للمطاعم المتوسطة','rating',4.5,'reviews',59,'free_shipping',true), TRUE, FALSE, 'published', 'قلاية كهرباء حوضين ١٦ لتر', '١٦+١٦ لتر • للمطاعم المتوسطة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-7', 'قلاية غاز حوضين بمصرفين', 'شعلات عالية الكفاءة', 'شعلات عالية الكفاءة', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','شعلات عالية الكفاءة','rating',4.6,'reviews',45,'free_shipping',true), TRUE, FALSE, 'published', 'قلاية غاز حوضين بمصرفين', 'شعلات عالية الكفاءة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='frying'), (SELECT id FROM brands WHERE slug='turbochef'), 'fr-8', 'قلاية زيت خزانتين ٣٢ لتر', '٣٢ لتر مع مصرفين • تشغيل شاق', '٣٢ لتر مع مصرفين • تشغيل شاق', 'TURBOCHEF', ARRAY['cooking','fryer','commercial'], '[{"url":"/storage/catalog/fryer.png","alt":"fr-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٣٢ لتر مع مصرفين • تشغيل شاق','rating',4.7,'reviews',21,'badge','جديد','free_shipping',true), TRUE, FALSE, 'published', 'قلاية زيت خزانتين ٣٢ لتر', '٣٢ لتر مع مصرفين • تشغيل شاق')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

-- bakery (NORDSTEEL x8) + drinks (FROSTICA x8) --
INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-1', 'عجانة حلزونية ٣٥ لتر', 'محرك مزدوج السرعة • وعاء ستانلس', 'محرك مزدوج السرعة • وعاء ستانلس', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','محرك مزدوج السرعة • وعاء ستانلس','rating',4.7,'reviews',89,'free_shipping',true), TRUE, TRUE, 'published', 'عجانة حلزونية ٣٥ لتر', 'محرك مزدوج السرعة • وعاء ستانلس')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-2', 'خفاق حلويات ١٠ لتر', '٣ ملحقات • للكريمات والعجائن الخفيفة', '٣ ملحقات • للكريمات والعجائن الخفيفة', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٣ ملحقات • للكريمات والعجائن الخفيفة','rating',4.5,'reviews',144,'free_shipping',true), TRUE, FALSE, 'published', 'خفاق حلويات ١٠ لتر', '٣ ملحقات • للكريمات والعجائن الخفيفة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-3', 'عجانة حلزونية ٢٠ لتر', 'للمعامل الصغيرة • حماية غطاء', 'للمعامل الصغيرة • حماية غطاء', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','للمعامل الصغيرة • حماية غطاء','rating',4.6,'reviews',72,'free_shipping',true), TRUE, FALSE, 'published', 'عجانة حلزونية ٢٠ لتر', 'للمعامل الصغيرة • حماية غطاء')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-4', 'فردة عجين ٥٠ سم', 'سماكة قابلة للضبط • سير تفلون', 'سماكة قابلة للضبط • سير تفلون', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','سماكة قابلة للضبط • سير تفلون','rating',4.5,'reviews',38), TRUE, FALSE, 'published', 'فردة عجين ٥٠ سم', 'سماكة قابلة للضبط • سير تفلون')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-5', 'ماكينة تشيمني كيك', '٤ أسياخ دوارة • للمقاهي والفعاليات', '٤ أسياخ دوارة • للمقاهي والفعاليات', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','٤ أسياخ دوارة • للمقاهي والفعاليات','rating',4.6,'reviews',26,'badge','جديد','free_shipping',true), TRUE, FALSE, 'published', 'ماكينة تشيمني كيك', '٤ أسياخ دوارة • للمقاهي والفعاليات')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-6', 'مخمّر معجنات ١٦ صينية', 'تحكم بالرطوبة والحرارة', 'تحكم بالرطوبة والحرارة', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تحكم بالرطوبة والحرارة','rating',4.7,'reviews',31,'free_shipping',true), TRUE, FALSE, 'published', 'مخمّر معجنات ١٦ صينية', 'تحكم بالرطوبة والحرارة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-7', 'عجانة حلزونية ٦٠ لتر', 'لخطوط الإنتاج • عجلات تثبيت', 'لخطوط الإنتاج • عجلات تثبيت', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','لخطوط الإنتاج • عجلات تثبيت','rating',4.8,'reviews',17,'free_shipping',true), TRUE, FALSE, 'published', 'عجانة حلزونية ٦٠ لتر', 'لخطوط الإنتاج • عجلات تثبيت')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='bakery'), (SELECT id FROM brands WHERE slug='nordsteel'), 'bk-8', 'خفاق كوكيز ٢٠ لتر', 'خلاط كواكب • دقة خلط عالية', 'خلاط كواكب • دقة خلط عالية', 'NORDSTEEL', ARRAY['bakery','mixer','commercial'], '[{"url":"/storage/catalog/mixer.png","alt":"bk-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','خلاط كواكب • دقة خلط عالية','rating',4.6,'reviews',52,'badge','عرض خاص'), TRUE, FALSE, 'published', 'خفاق كوكيز ٢٠ لتر', 'خلاط كواكب • دقة خلط عالية')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-1', 'سلاش ١٢ لتر — ٣ أحواض', 'تبريد مزدوج • أحواض شفافة', 'تبريد مزدوج • أحواض شفافة', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-1","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تبريد مزدوج • أحواض شفافة','rating',4.8,'reviews',66,'badge','الأكثر مبيعًا','free_shipping',true), TRUE, TRUE, 'published', 'سلاش ١٢ لتر — ٣ أحواض', 'تبريد مزدوج • أحواض شفافة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-2', 'سلاش ١٢ لتر — حوضان', 'مثالي للبوفيهات والكافيهات', 'مثالي للبوفيهات والكافيهات', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-2","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','مثالي للبوفيهات والكافيهات','rating',4.7,'reviews',48,'free_shipping',true), TRUE, FALSE, 'published', 'سلاش ١٢ لتر — حوضان', 'مثالي للبوفيهات والكافيهات')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-3', 'براد عصير ١٨ لتر — ٣ أحواض', 'تحريك مستمر • تبريد سريع', 'تحريك مستمر • تبريد سريع', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-3","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تحريك مستمر • تبريد سريع','rating',4.6,'reviews',91,'free_shipping',true), TRUE, FALSE, 'published', 'براد عصير ١٨ لتر — ٣ أحواض', 'تحريك مستمر • تبريد سريع')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-4', 'براد عصير ١٨ لتر — حوض واحد', 'اقتصادي • صيانة سهلة', 'اقتصادي • صيانة سهلة', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-4","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','اقتصادي • صيانة سهلة','rating',4.4,'reviews',120), TRUE, FALSE, 'published', 'براد عصير ١٨ لتر — حوض واحد', 'اقتصادي • صيانة سهلة')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-5', 'غلاية ماء تجارية ١٢ لتر', 'ستانلس • مؤشر مستوى الماء', 'ستانلس • مؤشر مستوى الماء', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-5","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','ستانلس • مؤشر مستوى الماء','rating',4.3,'reviews',233), TRUE, FALSE, 'published', 'غلاية ماء تجارية ١٢ لتر', 'ستانلس • مؤشر مستوى الماء')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-6', 'حافظة قهوة ٤ لتر مع القاعدة', 'حفظ حراري ٤ ساعات', 'حفظ حراري ٤ ساعات', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-6","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','حفظ حراري ٤ ساعات','rating',4.5,'reviews',77), TRUE, FALSE, 'published', 'حافظة قهوة ٤ لتر مع القاعدة', 'حفظ حراري ٤ ساعات')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-7', 'دلة تجاهيز الذهبية', 'تصميم تراثي • مطلية ذهب', 'تصميم تراثي • مطلية ذهب', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-7","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','تصميم تراثي • مطلية ذهب','rating',4.9,'reviews',35,'badge','جديد'), TRUE, FALSE, 'published', 'دلة تجاهيز الذهبية', 'تصميم تراثي • مطلية ذهب')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

INSERT INTO products (category_id, brand_id, slug, name, description, short_description, brand, tags, media, specifications, is_active, is_featured, status, seo_title, seo_description) VALUES
((SELECT id FROM categories WHERE slug='drinks'), (SELECT id FROM brands WHERE slug='frostica'), 'dr-8', 'ماكينة ميلك شيك حوضان', 'خفق سريع • أكواب ستانلس', 'خفق سريع • أكواب ستانلس', 'FROSTICA', ARRAY['beverages','juice','commercial'], '[{"url":"/storage/catalog/juice.png","alt":"dr-8","type":"image","position":0}]'::jsonb, jsonb_build_object('spec','خفق سريع • أكواب ستانلس','rating',4.5,'reviews',41,'free_shipping',true), TRUE, FALSE, 'published', 'ماكينة ميلك شيك حوضان', 'خفق سريع • أكواب ستانلس')
ON CONFLICT (slug) DO UPDATE SET category_id=EXCLUDED.category_id, brand_id=EXCLUDED.brand_id, name=EXCLUDED.name, description=EXCLUDED.description, short_description=EXCLUDED.short_description, brand=EXCLUDED.brand, tags=EXCLUDED.tags, media=EXCLUDED.media, specifications=EXCLUDED.specifications, is_active=TRUE, is_featured=EXCLUDED.is_featured, status='published', seo_title=EXCLUDED.seo_title, seo_description=EXCLUDED.seo_description, updated_at=now();

-- ---------------------------------------------------------------------------
-- 4) VARIANTS (1 per product; sku=UPPER(slug); attributes='{}' passes validation)
-- cost_price ~70% of price (internal, exclude from storefront API)
-- ---------------------------------------------------------------------------
INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-1'), 'CF-1', 'ماكينة إسبريسو فيوتزا ٢ جروب', 58000, 63500, 40600, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-2'), 'CF-2', 'ماكينة إسبريسو ستريما ٣ جروب', 86000, NULL, 60200, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-3'), 'CF-3', 'ماكينة أورورا برو ٢ جروب', 72000, NULL, 50400, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-4'), 'CF-4', 'ماكينة كلاسيكا ١ جروب', 14500, NULL, 10150, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-5'), 'CF-5', 'ماكينة بروفيلو ٣ جروب', 95000, NULL, 66500, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-6'), 'CF-6', 'ماكينة كومباتتا ٢ جروب', 69000, NULL, 48300, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-7'), 'CF-7', 'ماكينة مختصة جنيرازيون ٣ جروب', 55600, NULL, 38920, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='cf-8'), 'CF-8', 'ماكينة فيتوريو ١ جروب', 11500, 12900, 8050, '{}'::jsonb, '[{"url":"/storage/catalog/espresso.png","alt":"CF-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-1'), 'GR-1', 'مطحنة نيرا ٦٤ مم', 3220, NULL, 2254, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-2'), 'GR-2', 'مطحنة نيرا تاتش ٦٥ مم', 4000, NULL, 2800, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-3'), 'GR-3', 'مطحنة أوبليدج ٨٥ مم', 9545, NULL, 6681.5, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-4'), 'GR-4', 'مطحنة أوبل ديجيتال ٦٤ مم', 3041, NULL, 2128.7, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-5'), 'GR-5', 'مطحنة أوبل سنجل دوز', 3680, NULL, 2576, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-6'), 'GR-6', 'مطحنة كوميتا M80 فضي', 4400, NULL, 3080, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-7'), 'GR-7', 'مطحنة كوميتا Q13 أسود', 4850, NULL, 3395, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='gr-8'), 'GR-8', 'مطحنة ميثوس برو ٧٥ مم', 7900, NULL, 5530, '{}'::jsonb, '[{"url":"/storage/catalog/grinder.png","alt":"GR-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-1'), 'CO-1', 'صانعة ثلج بريز مكعبات ٩٥ كجم', 10810, NULL, 7567, '{}'::jsonb, '[{"url":"/storage/catalog/icemaker.png","alt":"CO-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-2'), 'CO-2', 'صانعة ثلج بريز ٨٤٠', 12400, NULL, 8680, '{}'::jsonb, '[{"url":"/storage/catalog/icemaker.png","alt":"CO-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-3'), 'CO-3', 'صانعة ثلج مجروش فروستا ٦٧ كجم', 12400, NULL, 8680, '{}'::jsonb, '[{"url":"/storage/catalog/icemaker.png","alt":"CO-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-4'), 'CO-4', 'صانعة ثلج توب كول ٢٦ كجم', 2990, 3450, 2093, '{}'::jsonb, '[{"url":"/storage/catalog/icemaker.png","alt":"CO-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-5'), 'CO-5', 'ثلاجة عرض حلويات مقوسة ٩٠ سم', 3450, NULL, 2415, '{}'::jsonb, '[{"url":"/storage/catalog/showcase.png","alt":"CO-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-6'), 'CO-6', 'ثلاجة عرض استانلس ٣٠٤ — ١٢٠ سم', 9660, NULL, 6762, '{}'::jsonb, '[{"url":"/storage/catalog/showcase.png","alt":"CO-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-7'), 'CO-7', 'ثلاجة عرض ستيل ١٥٠ سم', 10224, NULL, 7156.8, '{}'::jsonb, '[{"url":"/storage/catalog/showcase.png","alt":"CO-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='co-8'), 'CO-8', 'فريزر عرض آيس كريم واقف', 17999, NULL, 12599.3, '{}'::jsonb, '[{"url":"/storage/catalog/icemaker.png","alt":"CO-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

-- variants: ov + fr --
INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-1'), 'OV-1', 'فرن كهرباء ١ دور — صينية واحدة', 2600, NULL, 1820, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-2'), 'OV-2', 'فرن غاز ١ دور — صينيتان', 3700, NULL, 2590, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-3'), 'OV-3', 'فرن كهرباء ١ دور — ٣ صواني', 4250, NULL, 2975, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-4'), 'OV-4', 'فرن كهرباء ٢ دور — ٤ صواني', 6610, NULL, 4627, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-5'), 'OV-5', 'فرن كهرباء ٣ دور — ٩ صواني', 10412, NULL, 7288.4, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-6'), 'OV-6', 'فرن كونفكشن غاز — ١٠ صواني', 14000, NULL, 9800, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-7'), 'OV-7', 'فرن كونفكشن إيطالي — ١٠ صواني', 23699, NULL, 16589.3, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='ov-8'), 'OV-8', 'فرن كومبي شيف توب', 40000, NULL, 28000, '{}'::jsonb, '[{"url":"/storage/catalog/oven.png","alt":"OV-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-1'), 'FR-1', 'قلاية كهربائية ١١ لتر', 750, NULL, 525, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-2'), 'FR-2', 'قلاية حوضين — ٨ لتر للحوض', 1187, NULL, 830.9, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-3'), 'FR-3', 'قلاية حوضين — ١١ لتر للحوض', 1350, NULL, 945, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-4'), 'FR-4', 'قلاية ١٣ لتر بمصرف زيت', 937, NULL, 655.9, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-5'), 'FR-5', 'قلاية بخزانة ١٦ لتر', 1900, NULL, 1330, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-6'), 'FR-6', 'قلاية كهرباء حوضين ١٦ لتر', 1750, NULL, 1225, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-7'), 'FR-7', 'قلاية غاز حوضين بمصرفين', 2350, NULL, 1645, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='fr-8'), 'FR-8', 'قلاية زيت خزانتين ٣٢ لتر', 3700, NULL, 2590, '{}'::jsonb, '[{"url":"/storage/catalog/fryer.png","alt":"FR-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

-- variants: bk + dr --
INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-1'), 'BK-1', 'عجانة حلزونية ٣٥ لتر', 4000, NULL, 2800, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-2'), 'BK-2', 'خفاق حلويات ١٠ لتر', 1850, NULL, 1295, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-3'), 'BK-3', 'عجانة حلزونية ٢٠ لتر', 2950, NULL, 2065, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-4'), 'BK-4', 'فردة عجين ٥٠ سم', 3300, NULL, 2310, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-5'), 'BK-5', 'ماكينة تشيمني كيك', 3450, NULL, 2415, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-6'), 'BK-6', 'مخمّر معجنات ١٦ صينية', 5200, NULL, 3640, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-7'), 'BK-7', 'عجانة حلزونية ٦٠ لتر', 6800, NULL, 4760, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='bk-8'), 'BK-8', 'خفاق كوكيز ٢٠ لتر', 3150, 3600, 2205, '{}'::jsonb, '[{"url":"/storage/catalog/mixer.png","alt":"BK-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-1'), 'DR-1', 'سلاش ١٢ لتر — ٣ أحواض', 7200, NULL, 5040, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-1","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-2'), 'DR-2', 'سلاش ١٢ لتر — حوضان', 5500, NULL, 3850, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-2","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-3'), 'DR-3', 'براد عصير ١٨ لتر — ٣ أحواض', 2850, NULL, 1995, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-3","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-4'), 'DR-4', 'براد عصير ١٨ لتر — حوض واحد', 1750, NULL, 1225, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-4","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-5'), 'DR-5', 'غلاية ماء تجارية ١٢ لتر', 385, NULL, 269.5, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-5","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-6'), 'DR-6', 'حافظة قهوة ٤ لتر مع القاعدة', 816, NULL, 571.2, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-6","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-7'), 'DR-7', 'دلة تجاهيز الذهبية', 500, NULL, 350, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-7","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

INSERT INTO product_variants (product_id, sku, name, price, compare_at_price, cost_price, attributes, media, is_active) VALUES
((SELECT id FROM products WHERE slug='dr-8'), 'DR-8', 'ماكينة ميلك شيك حوضان', 2650, NULL, 1855, '{}'::jsonb, '[{"url":"/storage/catalog/juice.png","alt":"DR-8","type":"image","position":0}]'::jsonb, TRUE)
ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id, name=EXCLUDED.name, price=EXCLUDED.price, compare_at_price=EXCLUDED.compare_at_price, cost_price=EXCLUDED.cost_price, media=EXCLUDED.media, is_active=TRUE, updated_at=now();

-- ---------------------------------------------------------------------------
-- 5) INVENTORY (1 row per variant; 1:1 enforced by UNIQUE(variant_id))
-- coffee=15, grinders=40, cooling=25, cooking=30, frying=60, bakery=40, drinks=50
-- ---------------------------------------------------------------------------
INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 15, 5, 20, FALSE FROM product_variants WHERE sku IN ('CF-1','CF-2','CF-3','CF-4','CF-5','CF-6','CF-7','CF-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 40, 5, 20, FALSE FROM product_variants WHERE sku IN ('GR-1','GR-2','GR-3','GR-4','GR-5','GR-6','GR-7','GR-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 25, 5, 20, FALSE FROM product_variants WHERE sku IN ('CO-1','CO-2','CO-3','CO-4','CO-5','CO-6','CO-7','CO-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 30, 5, 20, FALSE FROM product_variants WHERE sku IN ('OV-1','OV-2','OV-3','OV-4','OV-5','OV-6','OV-7','OV-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 60, 5, 20, FALSE FROM product_variants WHERE sku IN ('FR-1','FR-2','FR-3','FR-4','FR-5','FR-6','FR-7','FR-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 40, 5, 20, FALSE FROM product_variants WHERE sku IN ('BK-1','BK-2','BK-3','BK-4','BK-5','BK-6','BK-7','BK-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

INSERT INTO inventory (variant_id, quantity_on_hand, reorder_point, reorder_quantity, allow_backorder)
SELECT id, 50, 5, 20, FALSE FROM product_variants WHERE sku IN ('DR-1','DR-2','DR-3','DR-4','DR-5','DR-6','DR-7','DR-8')
ON CONFLICT (variant_id) DO UPDATE SET quantity_on_hand = EXCLUDED.quantity_on_hand, updated_at = now();

-- ---------------------------------------------------------------------------
-- 6) CONFIRMATION
-- ---------------------------------------------------------------------------
DO $$
DECLARE
    v_cat INT; v_brands INT; v_products INT; v_variants INT; v_inv INT;
BEGIN
    SELECT COUNT(*) INTO v_cat FROM categories;
    SELECT COUNT(*) INTO v_brands FROM brands;
    SELECT COUNT(*) INTO v_products FROM products;
    SELECT COUNT(*) INTO v_variants FROM product_variants;
    SELECT COUNT(*) INTO v_inv FROM inventory;
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Catalog seed 022 summary:';
    RAISE NOTICE '  categories    : %', v_cat;
    RAISE NOTICE '  brands        : %', v_brands;
    RAISE NOTICE '  products      : %', v_products;
    RAISE NOTICE '  variants      : %', v_variants;
    RAISE NOTICE '  inventory     : %', v_inv;
    RAISE NOTICE '========================================';
END;
$$;

COMMIT;
