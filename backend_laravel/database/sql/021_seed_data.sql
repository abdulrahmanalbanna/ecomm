-- =============================================================================
-- Migration 021 : Seed Data
-- Project       : commerce-single-vendor
-- Depends on    : All prior migrations
-- Contents      :
--   1. Roles          — admin, staff, customer
--   2. Permissions    — granular RBAC permission codes
--   3. Role→Permission assignments
--   4. Shipping Methods — Standard Shipping, Express Shipping,
--                         Same-Day Delivery, Free Shipping
--   5. Attribute Options — color and size options for products
--
-- NOTE: payment_gateways seed data (Tabby, Tamara) is in 010_payment_gateways.sql
--       because that migration owns the table definition.
-- =============================================================================

BEGIN;

-- This file contains UTF-8 content (Arabic + typographic characters).
-- Ensure the psql session interprets incoming bytes as UTF-8 regardless of
-- the client's default encoding.
SET LOCAL client_encoding = 'UTF8';

-- ===========================================================================
-- 1. ROLES
-- ===========================================================================
INSERT INTO roles (name, description) VALUES
    ('admin',    'Full system access: products, orders, users, reports, settings'),
    ('staff',    'Operational access: process orders, manage inventory, view reports'),
    ('customer', 'Self-service access: own orders, own profile, own addresses')
ON CONFLICT (name) DO NOTHING;

-- ===========================================================================
-- 2. PERMISSIONS
-- Dot-notation: resource.action
-- ===========================================================================
INSERT INTO permissions (code, description) VALUES
    -- User management
    ('users.view',              'View user list and profiles'),
    ('users.create',            'Create new user accounts'),
    ('users.edit',              'Edit existing user accounts'),
    ('users.delete',            'Soft-delete user accounts'),
    ('users.impersonate',       'Login as another user (admin only)'),

    -- Product catalog
    ('products.view',           'View all products including drafts'),
    ('products.create',         'Create new products'),
    ('products.edit',           'Edit products, variants, and media'),
    ('products.delete',         'Soft-delete products'),
    ('products.publish',        'Change product status to published/archived'),

    -- Categories
    ('categories.view',         'View category tree'),
    ('categories.manage',       'Create, edit, and reorder categories'),

    -- Inventory
    ('inventory.view',          'View stock levels'),
    ('inventory.adjust',        'Make manual inventory adjustments'),
    ('inventory.reports',       'Export inventory reports'),

    -- Orders
    ('orders.view',             'View all orders'),
    ('orders.process',          'Update order status (confirm, ship, deliver)'),
    ('orders.cancel',           'Cancel orders'),
    ('orders.export',           'Export order data'),

    -- Payments
    ('payments.view',           'View payment records and transactions'),
    ('payments.refund',         'Initiate refunds'),
    ('payments.reconcile',      'Access reconciliation reports'),

    -- Reviews
    ('reviews.view',            'View all reviews including unapproved'),
    ('reviews.approve',         'Approve or reject customer reviews'),
    ('reviews.delete',          'Delete reviews'),

    -- Promotions
    ('coupons.view',            'View coupon codes'),
    ('coupons.manage',          'Create, edit, deactivate coupons'),
    ('banners.view',            'View promotional banners'),
    ('banners.manage',          'Create, edit, and delete promotional banners'),

    -- Brands
    ('brands.view',             'View catalog brands'),
    ('brands.manage',           'Create, edit, and delete catalog brands'),

    -- Reports & analytics
    ('reports.sales',           'Access daily and monthly sales reports'),
    ('reports.products',        'Access product performance reports'),
    ('reports.inventory',       'Access inventory reports'),
    ('reports.customers',       'Access customer analytics'),

    -- System / settings
    ('settings.view',           'View system configuration'),
    ('settings.manage',         'Edit gateway config, shipping methods'),
    ('audit.view',              'Read audit log entries'),

    -- Customer self-service (scoped to own data)
    ('self.orders.view',        'Customer: view own orders'),
    ('self.profile.edit',       'Customer: edit own profile'),
    ('self.addresses.manage',   'Customer: manage own addresses'),
    ('self.reviews.create',     'Customer: write product reviews'),
    ('self.wishlist.manage',    'Customer: manage own wishlist items')
ON CONFLICT (code) DO NOTHING;

-- ===========================================================================
-- 3. ROLE → PERMISSION ASSIGNMENTS
-- ===========================================================================

-- admin: ALL permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT
    r.id,
    p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'admin'
ON CONFLICT DO NOTHING;

-- staff: operational permissions (no user management, no system settings)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'products.view', 'products.create', 'products.edit', 'products.publish',
    'categories.view', 'categories.manage',
    'brands.view', 'brands.manage',
    'inventory.view', 'inventory.adjust', 'inventory.reports',
    'orders.view', 'orders.process', 'orders.cancel', 'orders.export',
    'payments.view', 'payments.reconcile',
    'reviews.view', 'reviews.approve', 'reviews.delete',
    'coupons.view', 'coupons.manage',
    'banners.view', 'banners.manage',
    'reports.sales', 'reports.products', 'reports.inventory'
)
WHERE r.name = 'staff'
ON CONFLICT DO NOTHING;

-- customer: only self-service permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'self.orders.view',
    'self.profile.edit',
    'self.addresses.manage',
    'self.reviews.create',
    'self.wishlist.manage'
)
WHERE r.name = 'customer'
ON CONFLICT DO NOTHING;

-- ===========================================================================
-- 4. SHIPPING METHODS
-- ===========================================================================
INSERT INTO shipping_methods (name, carrier, estimated_days_min, estimated_days_max, base_rate, is_active)
VALUES
    ('Standard Shipping',   NULL,    3, 5, 15.00, TRUE),
    ('Express Shipping',    NULL,    1, 2, 35.00, TRUE),
    ('Same-Day Delivery',   NULL,    0, 0, 60.00, TRUE),
    ('Free Shipping',       NULL,    5, 7,  0.00, FALSE) -- Inactive until a free-shipping promotion is configured
ON CONFLICT (name) DO UPDATE
SET carrier = EXCLUDED.carrier,
    estimated_days_min = EXCLUDED.estimated_days_min,
    estimated_days_max = EXCLUDED.estimated_days_max,
    base_rate = EXCLUDED.base_rate,
    is_active = EXCLUDED.is_active,
    updated_at = now();

-- ==========================================================================
-- 5. ATTRIBUTE DEFINITIONS AND OPTIONS
-- ==========================================================================
INSERT INTO attribute_definitions
    (name, display_name, type, is_filterable, is_active, sort_order)
VALUES
    ('color', 'Color', 'select', TRUE, TRUE, 1),
    ('size',  'Size',  'select', TRUE, TRUE, 2)
ON CONFLICT (name) DO UPDATE
SET display_name = EXCLUDED.display_name,
    type = EXCLUDED.type,
    is_filterable = EXCLUDED.is_filterable,
    is_active = EXCLUDED.is_active,
    sort_order = EXCLUDED.sort_order,
    updated_at = now();

INSERT INTO attribute_options (attribute_id, value, display_name, sort_order)
  SELECT d.id, o.value, o.display, o.ord
  FROM (VALUES
      ('color', 'red',    'Red',    1),
      ('color', 'blue',   'Blue',   2),
      ('color', 'black',  'Black',  3),
      ('size',  's',      'Small',  1),
      ('size',  'm',      'Medium', 2),
      ('size',  'l',      'Large',  3),
      ('size',  'xl',     'X-Large',4)
  ) AS o(attr, value, display, ord)
  JOIN attribute_definitions d ON d.name = o.attr
  WHERE d.type IN ('select', 'multiselect')
  ON CONFLICT (attribute_id, value) DO NOTHING;

-- ==========================================================================
-- 6. BUSINESS SETTINGS
-- ==========================================================================
INSERT INTO business_settings (key, value, type, is_public, is_active, description)
VALUES
    ('store.name',            'My E-Commerce Store',         'string',  TRUE,  TRUE, 'Public store display name'),
    ('store.phone',           '+966500000000',              'string',  TRUE,  TRUE, 'Support contact phone number'),
    ('store.logo',            '/assets/images/logo.png',    'string',  TRUE,  TRUE, 'Primary header logo image URL'),
    ('store.footer_logo',     '/assets/images/footer.png',  'string',  TRUE,  TRUE, 'Footer logo image URL'),
    ('store.fav_icon',        '/assets/images/favicon.ico', 'string',  TRUE,  TRUE, 'Favicon icon URL'),
    ('store.copyright_text',  '© 2026 All Rights Reserved',   'string',  TRUE,  TRUE, 'Footer copyright notice text'),
    ('store.currency',        'SAR',                        'string',  TRUE,  TRUE, 'Default store currency code'),
    ('financial.tax_rate',    '15.00',                      'number',  FALSE, TRUE, 'VAT / Tax percentage rate'),
    ('system.maintenance_mode', 'false',                   'boolean', TRUE,  TRUE, 'System maintenance mode toggle flag')
ON CONFLICT (key) DO NOTHING;

-- ==========================================================================
-- 6b. HOMEPAGE CONTENT (extracted from fronted_next_js/src/features/home/catalog.ts,
--     src/config/branding.ts, Header.tsx, Chrome.tsx Footer, messages/ar.json)
-- ==========================================================================
INSERT INTO business_settings (key, value, type, is_public, is_active, description)
VALUES
(
    'features',
    $json$
    [
      {"id":"f1","title":"استيراد مباشر","desc":"نستورد من المصانع الأم مباشرة، فكل قطعة أصلية وبسعر بلا وسطاء.","icon":"cargo","is_active":true},
      {"id":"f2","title":"وكلاء معتمدون","desc":"وكالة رسمية لعلامات عالمية مع شهادات اعتماد وضمان مصنعي.","icon":"shield","is_active":true},
      {"id":"f3","title":"صيانة وقطع غيار","desc":"فريق فني متنقل وقطع غيار أصلية تصلك أينما كان مشروعك.","icon":"wrench","is_active":true},
      {"id":"f4","title":"استشارات مجانية","desc":"نرافقك من رسم المخطط حتى أول تشغيل، بتوصيات تناسب ميزانيتك.","icon":"compass","is_active":true}
    ]
    $json$,
    'json', TRUE, TRUE,
    'Homepage business features'),
(
    'stats',
    $json$
    [
      {"value":50,"suffix":"+","label":"مدينة نغطيها بالشحن","is_active":true},
      {"value":120,"suffix":"+","label":"مشروع جُهّز معنا","is_active":true},
      {"value":15,"suffix":"+","label":"سنة في السوق السعودي","is_active":true},
      {"value":98,"suffix":"%","label":"رضا عملائنا","is_active":true}
    ]
    $json$,
    'json', TRUE, TRUE,
    'Homepage business statistics'),
(
    'ticker_items',
    $json$
    [
      {"text":"شحن مجاني للطلبات فوق ١٬٥٠٠ ر.س","is_active":true},
      {"text":"ضمان سنتان على جميع المكائن","is_active":true},
      {"text":"تقسيط متاح عبر تمارا","is_active":true},
      {"text":"تركيب وتشغيل داخل الرياض وجدة","is_active":true},
      {"text":"خصم ٥٪ على أول طلب بكود TG5","is_active":true},
      {"text":"استشارة تجهيز مجانية لمشاريعك","is_active":true}
    ]
    $json$,
    'json', TRUE, TRUE,
    'Homepage promotional ticker items'),
(
    'homepage.header',
    $json$
    {
      "store_name_ar": "تجاهيز",
      "store_name_en": "TAGAHAYEEZ",
      "tagline_ar": "معدات تجارية للمطاعم والمقاهي والمخابز",
      "tagline_en": "Commercial equipment for restaurants, cafés and bakeries",
      "logo_header": "/branding/logo_header.png",
      "logo_footer": "/branding/logo_footer.png",
      "logo_metadata": "/branding/logo_metadata.png",
      "phone_display": "920 012 345",
      "phone_href": "tel:920000000",
      "whatsapp_url": "https://wa.me/966500000000",
      "search_placeholder_ar": "ابحث عن ماكينة، مطحنة، فرن…",
      "nav_all_categories_ar": "كل الأقسام",
      "nav_installments_note_ar": "تقسيط متاح عبر تمارا",
      "is_active": true
    }
    $json$,
    'json', TRUE, TRUE,
    'Homepage header: branding, logos, phone, whatsapp, search and nav copy'),
(
    'homepage.footer',
    $json$
    {
      "description_ar": "بيت المعدات التجارية في السعودية — من ماكينة الإسبريسو إلى خط مخبز متكامل، بوكالات معتمدة وفريق فني يوصلك حتى باب محلك.",
      "description_en": "The commercial equipment house in Saudi Arabia — from an espresso machine to a complete bakery line, with authorized agencies and a technical team that delivers to your door.",
      "address_ar": "الرياض، مستودعات تجاهيز",
      "address_en": "Riyadh, TAGAHAYEEZ warehouses",
      "hours_en": "Saturday – Thursday: 9 AM – 10 PM",
      "quick_links_ar": ["الرئيسية", "كل الأقسام", "العروض", "العلامات التجارية", "الأسئلة الشائعة", "سياسة الاستبدال"],
      "top_categories_ar": ["محضّرات القهوة", "مطاحن القهوة", "التبريد والتجميد", "الأفران والطهي التجاري", "معدات القلي", "الحلويات والمخبوزات"],
      "newsletter_title_en": "Equipment offers, first",
      "newsletter_desc_en": "Subscribe for new arrivals, seasonal offers and operating tips from our experts.",
      "payment_methods": ["مدى", "Visa", "Mastercard", "Apple Pay", "تمارا"],
      "copyright": "© 2025 TAGAHAYEEZ — All rights reserved",
      "tax_info": "VAT number: 310123456700003 • CR 1010456789",
      "is_active": true
    }
    $json$,
    'json', TRUE, TRUE,
    'Homepage footer: description, address, hours, links, newsletter, payments, copyright')
ON CONFLICT (key) DO UPDATE
SET value = EXCLUDED.value,
    type = EXCLUDED.type,
    is_public = EXCLUDED.is_public,
    is_active = EXCLUDED.is_active,
    description = EXCLUDED.description,
    updated_at = now();
-- ==========================================================================
INSERT INTO cities (name, country_code, is_active, sort_order)
VALUES
    ('Riyadh',  'SA', TRUE, 1),
    ('Jeddah',  'SA', TRUE, 2),
    ('Dammam',  'SA', TRUE, 3),
    ('Mecca',   'SA', TRUE, 4),
    ('Medina',  'SA', TRUE, 5)
ON CONFLICT (country_code, name) DO NOTHING;

-- ===========================================================================
-- CONFIRMATION
-- ===========================================================================
DO $$
DECLARE
    v_roles        INT;
    v_permissions  INT;
    v_rp           INT;
    v_gateways     INT;
    v_shipping     INT;
    v_attr_options INT;
    v_settings     INT;
    v_cities       INT;
BEGIN
    SELECT COUNT(*) INTO v_roles       FROM roles;
    SELECT COUNT(*) INTO v_permissions FROM permissions;
    SELECT COUNT(*) INTO v_rp          FROM role_permissions;
    SELECT COUNT(*) INTO v_gateways    FROM payment_gateways;
    SELECT COUNT(*) INTO v_shipping    FROM shipping_methods;
    SELECT COUNT(*) INTO v_attr_options FROM attribute_options;
    SELECT COUNT(*) INTO v_settings    FROM business_settings;
    SELECT COUNT(*) INTO v_cities      FROM cities;

    RAISE NOTICE '========================================';
    RAISE NOTICE 'Seed data summary:';
    RAISE NOTICE '  roles              : %', v_roles;
    RAISE NOTICE '  permissions        : %', v_permissions;
    RAISE NOTICE '  role_permissions   : %', v_rp;
    RAISE NOTICE '  payment_gateways   : % (seeded in 010)', v_gateways;
    RAISE NOTICE '  shipping_methods   : %', v_shipping;
    RAISE NOTICE '  attribute_options  : %', v_attr_options;
    RAISE NOTICE '  business_settings  : %', v_settings;
    RAISE NOTICE '  cities             : %', v_cities;
    RAISE NOTICE '========================================';
END;
$$;

COMMIT;
