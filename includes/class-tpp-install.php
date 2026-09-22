<?php
/**
 * نصب/به‌روزرسانی — ساخت جدول‌های اختصاصی، فیلدهای پیش‌فرض و نقش‌ها.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Install {

        public static function activate() {
                self::create_tables();
                TPP_Fields::seed_defaults();
                TPP_Capabilities::register_roles();
                TPP_Categories::seed_review_category(); // 1.20.0 — دسته ارجاع پیش‌فرض
                TPP_SMS::seed_default_templates();
                // تنظیمات پیش‌فرض
                if ( ! get_option( 'tpp_settings' ) ) {
                        add_option( 'tpp_settings', TPP_Settings::defaults(), '', false );
                }
                update_option( 'tpp_db_version', TPP_DB_VERSION, false );
        }

        public static function deactivate() {
                // داده‌ها حفظ می‌شوند؛ فقط چیزهای موقت پاک می‌شوند
                delete_transient( 'tpp_login_fails_' . md5( '' ) );
        }

        /** بررسی ارتقا (در بوت) */
        public static function maybe_upgrade() {
                $installed = get_option( 'tpp_db_version' );
                if ( TPP_DB_VERSION !== $installed ) {
                        self::create_tables();
                        TPP_Fields::seed_defaults();
                        TPP_Fields::seed_missing_fields();
                        // ۱.۲۱.۰ — بازنشستگی شش فیلد حذف‌شده (وضعیت اینترنت/تلفن + وای‌فای‌ها):
                        // تعریف حذف می‌شود، ستون و داده تا اجرای «بروزآوری دیتابیس» باقی می‌ماند
                        TPP_Fields::retire_fields_v1210();
                        // ۱.۶.۰ — پرچم اجباری فیلدهای «اطلاعات اصلی» + اجباری آدرس کامل/نام مرکز + برچسب «شماره تلفن ثابت»
                        TPP_Fields::apply_default_flags_v160();
                        // ۱.۸.۰ — ترتیب کانونی فیلدها مطابق فرم ثبت سرویس (در فایل نمونه/خروجی/ایمپورت و sort_order)
                        TPP_Fields::apply_canonical_order_v180();
                        // قابلیت‌های جدید را به نقش‌های ذخیره‌شده اضافه کن (بدون دست‌زدن به سفارشی‌سازی‌ها و دسترسی فیلدها)
                        TPP_Capabilities::merge_new_caps( array(
                                'tpp_manager'   => array( 'tpp_send_sms' => true, 'tpp_delete_history' => true, 'tpp_view_activity' => true ),
                                'tpp_installer' => array( 'tpp_send_sms' => true ),
                        ) );
                        // ۱.۱۳.۱ — «ویرایش سریع» (⚡/🚀) قابلیت مستقل شد و به‌طور پیش‌فرض فقط مدیر کل سایت است؛
                        // روی نقش‌های ذخیره‌شده (حتی مدیر سرویس‌ها) صریحاً خاموش ثبت می‌شود — مدیر می‌تواند از «نقش‌ها و دسترسی‌ها» اعطا کند
                        TPP_Capabilities::merge_new_caps( array(
                                'tpp_manager'   => array( 'tpp_quick_edit' => false ),
                                'tpp_installer' => array( 'tpp_quick_edit' => false ),
                                'tpp_operator'  => array( 'tpp_quick_edit' => false ),
                                'tpp_reporter'  => array( 'tpp_quick_edit' => false ),
                        ) );
                        // ۱.۱۹.۰ — «دسته‌بندی پروژه‌ها» قابلیت مستقل؛ طبق درخواست کاربر به‌طور پیش‌فرض فقط مدیر کل سایت
                        TPP_Capabilities::merge_new_caps( array(
                                'tpp_manager'   => array( 'tpp_manage_categories' => false ),
                                'tpp_installer' => array( 'tpp_manage_categories' => false ),
                                'tpp_operator'  => array( 'tpp_manage_categories' => false ),
                                'tpp_reporter'  => array( 'tpp_manage_categories' => false ),
                        ) );
                        // ۱.۲۰.۰ — دو قابلیت بازبینی (سرویس‌های ارجاعی + اقدامات نصاب‌ها)؛
                        // پیش‌فرض فقط مدیر کل سایت — از «نقش‌ها و دسترسی‌ها» قابل اعطا
                        TPP_Capabilities::merge_new_caps( array(
                                'tpp_manager'   => array( 'tpp_review_queue' => false, 'tpp_review_installer' => false ),
                                'tpp_installer' => array( 'tpp_review_queue' => false, 'tpp_review_installer' => false ),
                                'tpp_operator'  => array( 'tpp_review_queue' => false, 'tpp_review_installer' => false ),
                                'tpp_reporter'  => array( 'tpp_review_queue' => false, 'tpp_review_installer' => false ),
                        ) );
                        // ۱.۱۲.۰ — گزارش فعالیت به‌طور پیش‌فرض برای همه نقش‌های ذخیره‌شده (حتی نقش‌های سفارشی) فعال می‌شود
                        self::grant_activity_to_all_roles();
                        // ۱.۱۰.۰ — پاک‌سازی دوره‌ای گزارش فعالیت (کرون روزانه)
                        if ( ! wp_next_scheduled( 'tpp_daily_cleanup' ) ) {
                                wp_schedule_event( time() + 3600, 'daily', 'tpp_daily_cleanup' );
                        }
                        // ۱.۱۰.۰ — تاریخچه موجود: روز هر رکورد برای تجمیع پر می‌شود
                        self::backfill_history_day();
                        // اگر نقش‌های پیش‌فرض حذف شده باشند، بازسازی شوند (نقش موجود دست‌نخورده می‌ماند)
                        foreach ( TPP_Capabilities::default_roles() as $slug => $def ) {
                                if ( null === get_role( $slug ) ) {
                                        add_role( $slug, $def['label'], array( 'read' => true, 'tpp_view' => true ) );
                                        TPP_Capabilities::set_role_caps( $slug, $def['caps'], array() );
                                }
                        }
                        TPP_SMS::seed_default_templates();
                        // ۱.۴.۰ — قالب پیش‌فرض «مشخصات ورود» برای نصب‌های موجود
                        TPP_SMS::seed_missing_templates();
                        // ۱.۱۲.۰ — ستون‌های پیشرفت دایری روی نصب‌های موجود
                        TPP_Progress::ensure_columns();
                        // ۱.۱۴.۰ — مهاجرت: JSON خرابی‌های چندتایی از ستون تکی قدیمی پر می‌شود
                        TPP_Progress::backfill_failures();
                        // ۱.۱۴.۰ — جدول گزارش کار روی نصب‌های موجود (dbDelta در ارتقا همیشه جدول جدید نمی‌سازد)
                        TPP_Workreport::ensure_table();
                        // ۱.۱۹.۰ — جدول دسته‌بندی‌ها + ستون‌های دسته/تگ سرویس‌ها
                        TPP_Categories::ensure_table();
                        TPP_Categories::ensure_columns();
                        // ۱.۲۰.۰ — سجل بازبینی اقدامات نصاب‌ها + دسته ارجاع پیش‌فرض
                        TPP_Review::ensure_table();
                        TPP_Categories::seed_review_category();
                        update_option( 'tpp_db_version', TPP_DB_VERSION, false );
                }
        }

        /** ۱.۱۲.۰ — فعال‌سازی قابلیت مشاهده گزارش فعالیت برای همه نقش‌های ذخیره‌شده (درخواست کاربر: پیش‌فرض برای همه) */
        private static function grant_activity_to_all_roles() {
                $all = get_option( TPP_Capabilities::CAPS_OPTION, array() );
                if ( ! is_array( $all ) ) {
                        return;
                }
                $changed = false;
                foreach ( $all as $slug => $def ) {
                        if ( ! is_array( $def ) || ! isset( $def['caps'] ) || ! is_array( $def['caps'] ) ) {
                                continue;
                        }
                        if ( ! array_key_exists( 'tpp_view_activity', $def['caps'] ) ) {
                                $all[ $slug ]['caps']['tpp_view_activity'] = true;
                                $changed = true;
                        }
                }
                if ( $changed ) {
                        update_option( TPP_Capabilities::CAPS_OPTION, $all, false );
                }
        }

        private static function create_tables() {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';

                $charset = '';
                if ( ! TPP_DB::is_external() ) {
                        global $wpdb;
                        $charset = $wpdb->get_charset_collate();
                } else {
                        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
                }

                $sql = array();

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'fields' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        group_key VARCHAR(20) NOT NULL DEFAULT 'service',
                        slug VARCHAR(64) NOT NULL,
                        label VARCHAR(190) NOT NULL,
                        field_type VARCHAR(20) NOT NULL DEFAULT 'text',
                        is_required TINYINT(1) NOT NULL DEFAULT 0,
                        is_searchable TINYINT(1) NOT NULL DEFAULT 1,
                        is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
                        options LONGTEXT NULL,
                        sort_order INT NOT NULL DEFAULT 0,
                        created_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY slug (slug),
                        KEY group_key (group_key)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'addresses' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        version INT UNSIGNED NOT NULL DEFAULT 1,
                        PRIMARY KEY  (id),
                        KEY created (created_at)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'services' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        address_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        version INT UNSIGNED NOT NULL DEFAULT 1,
                        progress_steps LONGTEXT NULL,
                        progress_done INT UNSIGNED NOT NULL DEFAULT 0,
                        progress_failure VARCHAR(20) NOT NULL DEFAULT '',
                        progress_updated_at DATETIME NULL,
                        progress_excluded LONGTEXT NULL,
                        PRIMARY KEY  (id),
                        KEY address_id (address_id),
                        KEY updated (updated_at),
                        KEY progress_done (progress_done),
                        KEY progress_failure (progress_failure)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'history' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        entity VARCHAR(20) NOT NULL DEFAULT 'service',
                        entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        address_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        revision INT UNSIGNED NOT NULL DEFAULT 1,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        action VARCHAR(20) NOT NULL DEFAULT 'update',
                        source VARCHAR(20) NOT NULL DEFAULT 'online',
                        changes LONGTEXT NULL,
                        is_conflict TINYINT(1) NOT NULL DEFAULT 0,
                        changed_at DATETIME NULL,
                        agg_day DATE NULL,
                        event_count INT UNSIGNED NOT NULL DEFAULT 0,
                        PRIMARY KEY  (id),
                        KEY entity (entity, entity_id),
                        KEY address_id (address_id),
                        KEY user_id (user_id),
                        KEY changed (changed_at),
                        KEY agg_day (agg_day),
                        KEY entity_day (entity, entity_id, agg_day)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'op_log' ) . " (
                        op_id VARCHAR(64) NOT NULL,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        result LONGTEXT NULL,
                        created_at DATETIME NULL,
                        PRIMARY KEY  (op_id)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'sync_log' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        ops_applied INT NOT NULL DEFAULT 0,
                        ops_conflicted INT NOT NULL DEFAULT 0,
                        ops_failed INT NOT NULL DEFAULT 0,
                        synced_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY user_id (user_id)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'field_archives' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        field_def LONGTEXT NULL,
                        values LONGTEXT NULL,
                        archived_at DATETIME NULL,
                        PRIMARY KEY  (id)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'sms_templates' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        title VARCHAR(190) NOT NULL,
                        body TEXT NULL,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        PRIMARY KEY  (id)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'sms_log' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        mobile VARCHAR(20) NOT NULL DEFAULT '',
                        message TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'sent',
                        error TEXT NULL,
                        created_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY user_id (user_id),
                        KEY service_id (service_id),
                        KEY created (created_at)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'view_log' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        entity VARCHAR(20) NOT NULL DEFAULT 'service',
                        entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        views INT UNSIGNED NOT NULL DEFAULT 1,
                        first_at DATETIME NULL,
                        last_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY user_id (user_id),
                        KEY entity (entity, entity_id),
                        KEY last_at (last_at)
                ) " . $charset . ';';

                $sql[] = 'CREATE TABLE ' . TPP_DB::table( 'search_log' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        query VARCHAR(190) NOT NULL DEFAULT '',
                        filters LONGTEXT NULL,
                        results INT UNSIGNED NOT NULL DEFAULT 0,
                        searches INT UNSIGNED NOT NULL DEFAULT 1,
                        first_at DATETIME NULL,
                        last_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY user_id (user_id),
                        KEY query (query),
                        KEY last_at (last_at)
                ) " . $charset . ';';

                // ۱.۱۴.۰ — گزارش کار روزانه هر کاربر
                $sql[] = TPP_Workreport::table_sql();

                // ۱.۱۹.۰ — دسته‌بندی پروژه‌ها و تگ‌های سیستمی
                $sql[] = TPP_Categories::table_sql();

                // ۱.۲۰.۰ — سجل بازبینی اقدامات نصاب‌ها
                $sql[] = TPP_Review::table_sql();

                if ( TPP_DB::is_external() ) {
                        // دیتابیس جداگانه — اجرای مستقیم DDL
                        foreach ( $sql as $q ) {
                                TPP_DB::query( $q );
                        }
                        // ستون‌های ایندکس‌شده فیلدهای داینامیک را هم اضافه کن (برای فیلدهای موجود)
                        self::ensure_dynamic_columns();
                } else {
                        dbDelta( $sql );
                }
                // ۱.۱۲.۰ — ستون‌های پیشرفت دایری (در هر دو مسیر — dbDelta ایندکس/ستون جدید را همیشه نمی‌سازد)
                TPP_Progress::ensure_columns();
                // ۱.۱۴.۰ — جدول گزارش کار (هر دو مسیر)
                TPP_Workreport::ensure_table();
                // ۱.۱۹.۰ — دسته‌بندی‌ها (هر دو مسیر)
                TPP_Categories::ensure_table();
                TPP_Categories::ensure_columns();
                // ۱.۲۰.۰ — سجل بازبینی (هر دو مسیر) + دسته ارجاع پیش‌فرض
                TPP_Review::ensure_table();
                TPP_Categories::seed_review_category();
                self::ensure_history_indexes();
        }

        /** ایندکس‌های تجمیع روزانه (dbDelta ایندکس‌های جدید را همیشه اضافه نمی‌کند → بررسی مستقیم) */
        private static function ensure_history_indexes() {
                $table = TPP_DB::table( 'history' );
                if ( ! $table ) {
                        return;
                }
                $db = TPP_DB::db();
                $existing = array();
                $rows = $db->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
                foreach ( (array) $rows as $r ) {
                        $existing[ strtoupper( $r['Key_name'] ) ] = true;
                }
                foreach ( array( 'agg_day' => '(`agg_day`)', 'entity_day' => '(`entity`, `entity_id`, `agg_day`)' ) as $name => $cols ) {
                        if ( ! isset( $existing[ strtoupper( $name ) ] ) ) {
                                TPP_DB::query( "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}" );
                        }
                }
        }

        /** روز رکوردهای تاریخچه موجود را برای تجمیع پر می‌کند (یک‌بار در ارتقا) */
        private static function backfill_history_day() {
                $table = TPP_DB::table( 'history' );
                if ( ! $table ) {
                        return;
                }
                TPP_DB::query( "UPDATE `{$table}` SET agg_day = DATE(changed_at) WHERE agg_day IS NULL" );
        }

        /** در دیتابیس جداگانه، dbDelta موجود نیست → ستون‌های داینامیک را دستی مطمئن شو */
        private static function ensure_dynamic_columns() {
                if ( 0 === (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'fields' ) ) ) {
                        return; // نصب تازه؛ seed_defaults ستون‌ها را می‌سازد
                }
                foreach ( TPP_Fields::all() as $f ) {
                        $table  = ( 'address' === $f['group_key'] ) ? TPP_DB::table( 'addresses' ) : TPP_DB::table( 'services' );
                        $column = ( 'textarea' === $f['field_type'] ) ? 'TEXT NULL' : 'VARCHAR(500) NULL DEFAULT NULL';
                        TPP_Fields::ensure_column( $table, $f['slug'], $column );
                }
        }
}
