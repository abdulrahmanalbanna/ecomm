-- =============================================================================
-- verify-schema.sql
-- Commerce Single Vendor — PostgreSQL Schema Verification
-- Purpose : Non-destructive verification of the baseline schema.
--           Checks named required objects against system catalogs.
--           Reports PASS / WARN / FAIL for each category.
--           Returns exit code 3 on any FAIL (via \q on error or DO block RAISE).
-- Usage   : Execute via verify-schema.ps1 or verify-schema.sh (Docker-native)
-- =============================================================================

\set ON_ERROR_STOP 1
\set VERBOSITY terse

-- Use a temporary table to accumulate results
CREATE TEMP TABLE _verify_results (
    category   TEXT    NOT NULL,
    check_name TEXT    NOT NULL,
    status     TEXT    NOT NULL,   -- PASS | WARN | FAIL
    detail     TEXT
);

-- =============================================================================
-- SECTION 1 — PostgreSQL Version
-- =============================================================================
INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'PostgreSQL Version',
    'version >= 14',
    CASE WHEN current_setting('server_version_num')::INT >= 140000 THEN 'PASS' ELSE 'FAIL' END,
    version();

-- =============================================================================
-- SECTION 2 — Extensions
-- =============================================================================
DO $$
DECLARE
    exts TEXT[] := ARRAY['citext', 'ltree', 'pgcrypto'];
    ext  TEXT;
    installed BOOLEAN;
BEGIN
    FOREACH ext IN ARRAY exts LOOP
        SELECT COUNT(*) > 0
          INTO installed
          FROM pg_extension
         WHERE extname = ext;

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Extension',
            ext,
            CASE WHEN installed THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN installed THEN 'Installed'
                 ELSE 'NOT INSTALLED — run: CREATE EXTENSION IF NOT EXISTS ' || ext
            END
        );
    END LOOP;
END;
$$;

-- Validate pg_stat_statements is actually queryable (requires shared_preload_libraries)
DO $$
DECLARE
    ok BOOLEAN;
BEGIN
    BEGIN
        EXECUTE 'SELECT COUNT(*) > 0 FROM pg_stat_statements' INTO ok;
        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Extension', 'pg_stat_statements operational', 'PASS',
            'shared_preload_libraries active; statistics are collecting.'
        );
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Extension', 'pg_stat_statements operational', 'WARN',
            'Extension installed but not preloaded. Restart pgsql container after compose.yaml change.'
        );
    END;
END;
$$;

-- =============================================================================
-- SECTION 3 — Enum Types
-- =============================================================================
DO $$
DECLARE
    required_enums TEXT[] := ARRAY['order_status_enum', 'payment_status_enum'];
    etype TEXT;
    exists_flag BOOLEAN;
BEGIN
    FOREACH etype IN ARRAY required_enums LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM pg_type
         WHERE typname = etype AND typtype = 'e';

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Enum Type', etype,
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Type exists'
                 ELSE 'Missing — run 001_extensions_enums.sql' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 4 — Core Tables (non-partitioned)
-- =============================================================================
DO $$
DECLARE
    required_tables TEXT[] := ARRAY[
        'roles', 'permissions', 'role_permissions',
        'users', 'sessions', 'password_reset_tokens',
        'customer_profiles', 'cities', 'addresses',
        'categories',
        'brands', 'products', 'attribute_definitions', 'product_variants',
        'inventory', 'inventory_reservations',
        'carts', 'cart_items', 'wishlists',
        'order_items',
        'shipping_methods', 'shipments',
        'payment_gateways', 'payments', 'payment_attempts',
        'payment_transactions', 'payment_webhook_events',
        'installment_plans', 'installments', 'refunds',
        'coupons', 'coupon_usages', 'banners',
        'product_reviews',
        'notifications',
        'business_settings'
    ];
    tname TEXT;
    exists_flag BOOLEAN;
BEGIN
    FOREACH tname IN ARRAY required_tables LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM information_schema.tables
         WHERE table_schema = 'public'
           AND table_name   = tname
           AND table_type   = 'BASE TABLE';

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Table', tname,
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Exists'
                 ELSE 'MISSING' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 5 — Partitioned Parent Tables
-- =============================================================================
DO $$
DECLARE
    partitioned_tables TEXT[] := ARRAY[
        'inventory_movements', 'order_events', 'audit_logs'
    ];
    tname TEXT;
    strategy TEXT;
BEGIN
    FOREACH tname IN ARRAY partitioned_tables LOOP
        SELECT
            CASE pt.partstrat
                WHEN 'r' THEN 'RANGE'
                WHEN 'l' THEN 'LIST'
                WHEN 'h' THEN 'HASH'
                ELSE NULL
            END
          INTO strategy
          FROM pg_class c
          JOIN pg_partitioned_table pt ON pt.partrelid = c.oid
         WHERE c.relname = tname AND c.relnamespace = 'public'::regnamespace;

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Partitioned Table', tname,
            CASE WHEN strategy IS NOT NULL THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN strategy IS NOT NULL
                 THEN 'PARTITION BY ' || strategy
                 ELSE 'MISSING or not partitioned' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 6 — Child Partitions (spot-check current year month partitions exist)
-- =============================================================================
DO $$
DECLARE
    cur_year  INT := EXTRACT(YEAR  FROM now())::INT;
    cur_month INT := EXTRACT(MONTH FROM now())::INT;
    parents TEXT[] := ARRAY['inventory_movements', 'order_events', 'audit_logs'];
    parent TEXT;
    expected_partition TEXT;
    partition_count INT;
    exists_flag BOOLEAN;
BEGIN
    -- Verify default partitions
    FOREACH parent IN ARRAY parents LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM pg_class child
          JOIN pg_inherits i ON i.inhrelid = child.oid
          JOIN pg_class par ON par.oid = i.inhparent
         WHERE par.relname = parent
           AND par.relnamespace = 'public'::regnamespace
           AND child.relname = parent || '_default';

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Partition', parent || '_default',
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Default partition exists'
                 ELSE 'Default partition MISSING' END
        );

        -- Verify current month partition
        expected_partition := parent || '_' || cur_year || '_' || LPAD(cur_month::TEXT, 2, '0');
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM pg_class child
          JOIN pg_inherits i ON i.inhrelid = child.oid
          JOIN pg_class par ON par.oid = i.inhparent
         WHERE par.relname = parent
           AND par.relnamespace = 'public'::regnamespace
           AND child.relname = expected_partition;

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Partition', expected_partition,
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Current month partition exists'
                 ELSE 'Current month partition MISSING' END
        );

        -- Total child count
        SELECT COUNT(*)
          INTO partition_count
          FROM pg_class child
          JOIN pg_inherits i ON i.inhrelid = child.oid
          JOIN pg_class par ON par.oid = i.inhparent
         WHERE par.relname = parent
           AND par.relnamespace = 'public'::regnamespace;

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Partition', parent || ' — total children',
            'PASS',
            partition_count::TEXT || ' child partitions (36 monthly + 1 default expected)'
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 7 — PostgreSQL Functions
-- =============================================================================
DO $$
DECLARE
    required_functions TEXT[] := ARRAY[
        'fn_set_updated_at',
        'fn_update_product_search_vector',
        'fn_update_review_search_vector',
        'fn_validate_order_status_transition',
        'fn_reserve_inventory_v2',
        'fn_reserve_inventory_batch_v2',
        'fn_release_inventory_reservation',
        'fn_convert_inventory_reservation'
    ];
    fname TEXT;
    exists_flag BOOLEAN;
BEGIN
    FOREACH fname IN ARRAY required_functions LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM pg_proc
         WHERE proname = fname
           AND pronamespace = 'public'::regnamespace;

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Function', fname,
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Function exists'
                 ELSE 'MISSING — run 018_functions_triggers.sql' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 8 — Triggers
-- =============================================================================
DO $$
DECLARE
    -- (trigger_name, table_name) pairs
    required_triggers TEXT[][] := ARRAY[
        ARRAY['trg_users_updated_at',               'users'],
        ARRAY['trg_customer_profiles_updated_at',   'customer_profiles'],
        ARRAY['trg_addresses_updated_at',           'addresses'],
        ARRAY['trg_categories_updated_at',          'categories'],
        ARRAY['trg_products_updated_at',            'products'],
        ARRAY['trg_product_variants_updated_at',    'product_variants'],
        ARRAY['trg_inventory_updated_at',           'inventory'],
        ARRAY['trg_carts_updated_at',               'carts'],
        ARRAY['trg_orders_updated_at',              'orders'],
        ARRAY['trg_shipping_methods_updated_at',    'shipping_methods'],
        ARRAY['trg_shipments_updated_at',           'shipments'],
        ARRAY['trg_payment_gateways_updated_at',    'payment_gateways'],
        ARRAY['trg_payments_updated_at',            'payments'],
        ARRAY['trg_installment_plans_updated_at',   'installment_plans'],
        ARRAY['trg_installments_updated_at',        'installments'],
        ARRAY['trg_refunds_updated_at',             'refunds'],
        ARRAY['trg_coupons_updated_at',             'coupons'],
        ARRAY['trg_product_reviews_updated_at',     'product_reviews'],
        ARRAY['trg_business_settings_updated_at',    'business_settings'],
        ARRAY['trg_cities_updated_at',               'cities'],
        ARRAY['trg_brands_updated_at',               'brands'],
        ARRAY['trg_banners_updated_at',              'banners'],
        ARRAY['trg_products_search_vector',         'products'],
        ARRAY['trg_reviews_search_vector',          'product_reviews'],
        ARRAY['trg_orders_status_transition',       'orders']
    ];
    trig TEXT[];
    exists_flag BOOLEAN;
BEGIN
    FOREACH trig SLICE 1 IN ARRAY required_triggers LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM information_schema.triggers
         WHERE trigger_name = trig[1]
           AND event_object_table = trig[2]
           AND trigger_schema = 'public';

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Trigger', trig[1] || ' ON ' || trig[2],
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Trigger exists and attached'
                 ELSE 'MISSING — run 018_functions_triggers.sql' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 9 — Materialized Views
-- =============================================================================
DO $$
DECLARE
    required_mvs TEXT[] := ARRAY[
        'mv_daily_sales', 'mv_monthly_revenue', 'mv_product_performance',
        'mv_inventory_status', 'mv_category_performance'
    ];
    mvname TEXT;
    exists_flag BOOLEAN;
    has_unique_idx BOOLEAN;
BEGIN
    FOREACH mvname IN ARRAY required_mvs LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM pg_matviews
         WHERE matviewname = mvname AND schemaname = 'public';

        -- Check for UNIQUE index (required for CONCURRENTLY refresh)
        SELECT COUNT(*) > 0
          INTO has_unique_idx
          FROM pg_indexes
         WHERE tablename  = mvname
           AND schemaname = 'public'
           AND indexdef LIKE '%UNIQUE%';

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Materialized View', mvname,
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag AND has_unique_idx THEN 'Exists; UNIQUE index present — CONCURRENTLY refresh supported'
                 WHEN exists_flag                    THEN 'Exists; no UNIQUE index — CONCURRENTLY not supported'
                 ELSE 'MISSING — run 019_materialized_views.sql' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 10 — Row Level Security Absence Verification
-- =============================================================================
DO $$
DECLARE
    rls_table_count INT;
    rls_policy_count INT;
    rls_role_count INT;
    rls_func_count INT;
BEGIN
    -- 1. Check RLS-enabled application tables count (must be 0)
    SELECT COUNT(*)
      INTO rls_table_count
      FROM pg_class
     WHERE relnamespace = 'public'::regnamespace
       AND relkind IN ('r', 'p')
       AND relrowsecurity = TRUE;

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'RLS Absence', 'RLS-enabled application tables',
        CASE WHEN rls_table_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        CASE WHEN rls_table_count = 0 THEN '0 RLS-enabled application tables'
             ELSE 'FAIL — ' || rls_table_count || ' table(s) have RLS enabled' END
    );

    -- 2. Check Application RLS policies count (must be 0)
    SELECT COUNT(*)
      INTO rls_policy_count
      FROM pg_policies
     WHERE schemaname = 'public';

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'RLS Absence', 'Application RLS policies',
        CASE WHEN rls_policy_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        CASE WHEN rls_policy_count = 0 THEN '0 application RLS policies'
             ELSE 'FAIL — ' || rls_policy_count || ' policy(ies) exist' END
    );

    -- 3. Check RLS-only roles non-existence (app_customer, app_staff, app_admin, app_service)
    SELECT COUNT(*)
      INTO rls_role_count
      FROM pg_roles
     WHERE rolname IN ('app_customer', 'app_staff', 'app_admin', 'app_service');

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'RLS Absence', 'RLS-only roles (app_*)',
        CASE WHEN rls_role_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        CASE WHEN rls_role_count = 0 THEN '0 RLS-only roles exist'
             ELSE 'FAIL — ' || rls_role_count || ' RLS role(s) found' END
    );

    -- 4. Check RLS-only function non-existence (fn_current_user_id)
    SELECT COUNT(*)
      INTO rls_func_count
      FROM pg_proc
     WHERE proname = 'fn_current_user_id'
       AND pronamespace = 'public'::regnamespace;

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'RLS Absence', 'RLS function (fn_current_user_id)',
        CASE WHEN rls_func_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        CASE WHEN rls_func_count = 0 THEN 'Function fn_current_user_id absent'
             ELSE 'FAIL — Function fn_current_user_id exists' END
    );
END;
$$;

-- =============================================================================
-- SECTION 11 — Key Indexes (spot-check named indexes)
-- =============================================================================
DO $$
DECLARE
    required_indexes TEXT[] := ARRAY[
        'idx_users_active_email',
        'idx_sessions_active_token',
        'idx_addresses_one_default_per_user',
        'idx_categories_path_gist',
        'idx_products_fts',
        'idx_products_specifications',
        'idx_products_tags',
        'idx_inventory_in_stock',
        'idx_inv_movements_brin_created',
        'idx_orders_placed_at_brin',
        'idx_order_events_brin',
        'idx_audit_brin_created',
        'idx_audit_new_values_gin',
        'idx_coupons_active_code',
        'idx_payments_reconciliation'
    ];
    idxname TEXT;
    exists_flag BOOLEAN;
BEGIN
    FOREACH idxname IN ARRAY required_indexes LOOP
        SELECT COUNT(*) > 0
          INTO exists_flag
          FROM pg_indexes
         WHERE indexname  = idxname
           AND schemaname = 'public';

        INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
            'Index', idxname,
            CASE WHEN exists_flag THEN 'PASS' ELSE 'FAIL' END,
            CASE WHEN exists_flag THEN 'Exists'
                 ELSE 'MISSING — run 017_indexes.sql or appropriate migration' END
        );
    END LOOP;
END;
$$;

-- =============================================================================
-- SECTION 12 — Seed / Reference Data
-- =============================================================================
DO $$
DECLARE
    role_count     INT;
    perm_count     INT;
    gateway_count  INT;
    shipping_count INT;
BEGIN
    SELECT COUNT(*) INTO role_count     FROM roles;
    SELECT COUNT(*) INTO perm_count     FROM permissions;
    SELECT COUNT(*) INTO gateway_count  FROM payment_gateways;
    SELECT COUNT(*) INTO shipping_count FROM shipping_methods;

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'Seed Data', 'roles (admin, staff, customer)',
        CASE WHEN role_count >= 3 THEN 'PASS' ELSE 'FAIL' END,
        'Count: ' || role_count
    );

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'Seed Data', 'permissions',
        CASE WHEN perm_count >= 30 THEN 'PASS' ELSE 'FAIL' END,
        'Count: ' || perm_count
    );

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'Seed Data', 'payment_gateways (tabby, tamara)',
        CASE WHEN gateway_count >= 2 THEN 'PASS' ELSE 'FAIL' END,
        'Count: ' || gateway_count
    );

    INSERT INTO _verify_results (category, check_name, status, detail) VALUES (
        'Seed Data', 'shipping_methods',
        CASE WHEN shipping_count >= 2 THEN 'PASS' ELSE 'FAIL' END,
        'Count: ' || shipping_count
    );
END;
$$;

-- =============================================================================
-- SECTION 13 — Dynamic Counts (diagnostic — not used as FAIL criteria)
-- =============================================================================
INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'Catalog Count',
    'Total tables in public schema',
    'PASS',
    (SELECT COUNT(*)::TEXT FROM information_schema.tables
      WHERE table_schema = 'public' AND table_type = 'BASE TABLE') || ' tables';

INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'Catalog Count',
    'Partitioned parent tables',
    'PASS',
    (SELECT COUNT(*)::TEXT FROM pg_partitioned_table pt
       JOIN pg_class c ON c.oid = pt.partrelid
      WHERE c.relnamespace = 'public'::regnamespace) || ' parent tables';

INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'Catalog Count',
    'Child partitions (all parents)',
    'PASS',
    (SELECT COUNT(*)::TEXT FROM pg_inherits i
       JOIN pg_class par ON par.oid = i.inhparent
      WHERE par.relnamespace = 'public'::regnamespace) || ' child partitions';

INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'Catalog Count',
    'Indexes in public schema',
    'PASS',
    (SELECT COUNT(*)::TEXT FROM pg_indexes WHERE schemaname = 'public') || ' indexes';

INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'Catalog Count',
    'PL/pgSQL functions in public schema',
    'PASS',
    (SELECT COUNT(*)::TEXT FROM pg_proc p
       JOIN pg_language l ON l.oid = p.prolang
      WHERE p.pronamespace = 'public'::regnamespace AND l.lanname = 'plpgsql') || ' functions';

INSERT INTO _verify_results (category, check_name, status, detail)
SELECT
    'Catalog Count',
    'Triggers (all tables)',
    'PASS',
    (SELECT COUNT(*)::TEXT FROM information_schema.triggers
      WHERE trigger_schema = 'public') || ' triggers';

-- =============================================================================
-- OUTPUT — formatted results table
-- =============================================================================
\echo ''
\echo '==============================================================='
\echo '  Commerce Single Vendor — Schema Verification Report'
\echo '==============================================================='

SELECT
    RPAD(status,   4, ' ')      AS " ",
    RPAD(category, 22, ' ')     AS "Category",
    RPAD(check_name, 46, ' ')   AS "Check",
    detail                      AS "Detail"
FROM _verify_results
ORDER BY
    CASE status WHEN 'FAIL' THEN 0 WHEN 'WARN' THEN 1 ELSE 2 END,
    category,
    check_name;

\echo ''
\echo '--- Summary ---'
SELECT
    status,
    COUNT(*) AS count
FROM _verify_results
GROUP BY status
ORDER BY CASE status WHEN 'FAIL' THEN 0 WHEN 'WARN' THEN 1 ELSE 2 END;

-- =============================================================================
-- FAIL GATE — exit non-zero if any required check failed
-- =============================================================================
DO $$
DECLARE
    fail_count INT;
BEGIN
    SELECT COUNT(*) INTO fail_count FROM _verify_results WHERE status = 'FAIL';
    IF fail_count > 0 THEN
        RAISE EXCEPTION 'Schema verification FAILED: % required check(s) did not pass. See report above.', fail_count
            USING ERRCODE = 'P0001';
    END IF;
    RAISE NOTICE 'All required schema checks PASSED (WARNs are non-critical).';
END;
$$;

DROP TABLE IF EXISTS _verify_results;
