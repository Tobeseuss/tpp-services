<?php
/**
 * REST API — namespace: tpp/v1
 * احراز هویت: کوکی وردپرس + nonce (X-WP-Nonce) یا هدر X-TPP-Token (همیشه — حتی آفلاین).
 * همه پاسخ‌های داده‌ای تابع دسترسی فیلد کاربر هستند (فیلدهای پنهان حذف می‌شوند).
 *
 * + پشتیبان admin-ajax: اگر افزونه امنیتی/تنظیمات سرور دسترسی به REST-API را محدود کرده باشد،
 *   همان مسیرها از طریق admin-ajax.php (action=tpp_api) هم قابل استفاده‌اند — اپ خودکار تشخیص می‌دهد.
 *
 * این API برای استفاده افزونه‌های دیگر شما هم در دسترس است (مستندات: DEVELOPERS.md).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Rest {

        const NS = 'tpp/v1';

        /** @var int|null سطح بافر خروجی که برای مسیرهای خودمان شروع کرده‌ایم */
        private $buffer_base = null;

        public function __construct() {
                add_action( 'rest_api_init', array( $this, 'register_routes' ) );
                add_filter( 'rest_post_dispatch', array( $this, 'add_nonce_header' ), 10, 3 );
                // خروجی‌های ناخواسته (notice/warning افزونه‌های دیگر) نباید JSON پاسخ REST را خراب کنند
                add_action( 'rest_api_init', array( $this, 'start_route_buffer' ), 1 );
                add_filter( 'rest_pre_serve_request', array( $this, 'clean_route_buffer' ), 9999, 4 );
                // پشتیبان admin-ajax برای زمانی که REST-API توسط افزونه امنیتی/سرور مسدود است
                add_action( 'wp_ajax_tpp_api', array( $this, 'ajax_relay' ) );
                add_action( 'wp_ajax_nopriv_tpp_api', array( $this, 'ajax_relay' ) );
        }

        /* -------------------- ابزارها -------------------- */

        public static function ok( $data, $status = 200 ) {
                return new WP_REST_Response( $data, $status );
        }

        public static function err( $code, $message, $status = 400 ) {
                return new WP_Error( $code, $message, array( 'status' => $status ) );
        }

        public static function user_id( WP_REST_Request $request ) {
                $user = wp_get_current_user();
                return $user ? (int) $user->ID : 0;
        }

        /** آیا کاربر فعلی دسترسی افزونه دارد؟ */
        public static function can_access() {
                $user = wp_get_current_user();
                if ( ! $user || ! $user->ID ) {
                        return false;
                }
                return tpp()->auth()->user_has_tpp_access( (int) $user->ID );
        }

        public static function perm_access() {
                return self::can_access() ? true : self::err( 'tpp_forbidden', 'دسترسی به افزونه ندارید.', 403 );
        }

        public static function perm_cap( $cap ) {
                if ( ! self::can_access() ) {
                        return self::err( 'tpp_forbidden', 'دسترسی به افزونه ندارید.', 403 );
                }
                $user_id = get_current_user_id();
                return TPP_Capabilities::user_can( $user_id, $cap ) ? true : self::err( 'tpp_forbidden', 'دسترسی لازم برای این عملیات را ندارید.', 403 );
        }

        /** ساختار کاربر + قابلیت‌ها + اسکیمای فیلدهای قابل مشاهده */
        public static function bootstrap_payload( $user_id, $fresh_token = null ) {
                $user     = get_userdata( $user_id );
                $caps     = TPP_Capabilities::user_caps( $user_id );
                $visible  = TPP_Capabilities::visible_fields( $user_id );
                $settings = tpp()->settings();

                $schema = array( 'address' => array(), 'service' => array() );
                foreach ( TPP_Fields::all() as $f ) {
                        if ( empty( $visible[ $f['slug'] ] ) ) {
                                continue;
                        }
                        $schema[ $f['group_key'] ][] = array(
                                'slug'          => $f['slug'],
                                'label'         => $f['label'],
                                'type'          => $f['field_type'],
                                'is_required'   => (bool) $f['is_required'],
                                'is_searchable' => (bool) $f['is_searchable'],
                                'is_sensitive'  => (bool) $f['is_sensitive'],
                                'options'       => (array) $f['options'],
                                'sort'          => (int) $f['sort_order'],
                        );
                }

                return array(
                        'version' => TPP_VERSION,
                        'token'    => $fresh_token,
                        'user'     => array(
                                'id'    => (int) $user->ID,
                                'name'  => $user->display_name,
                                'login' => $user->user_login,
                        ),
                        'caps'     => array_keys( $caps ),
                        'is_manager' => TPP_Capabilities::is_manager( $user_id ),
                        'is_wp_admin' => user_can( $user_id, 'manage_options' ),
                        'schema'   => $schema,
                        'settings' => array(
                                'rows_per_page'     => (int) $settings->get( 'rows_per_page', 25 ),
                                'default_match'     => (string) $settings->get( 'default_match_key', 'f_phone' ),
                                'heartbeat_min'     => (int) $settings->get( 'heartbeat_minutes', 5 ),
                                'offline_cache_size'=> (int) $settings->get( 'offline_cache_size', 5000 ),
                        ),
                        'sms'      => array(
                                'configured'    => tpp()->sms()->is_configured(),
                                'mobile_field'  => TPP_SMS::mobile_field_slug(),
                                'copy_template' => tpp()->sms()->copy_template(),
                        ),
                        'progress' => TPP_Progress::catalog(), // ۱.۱۲.۰ — مراحل دایری + خرابی‌ها برای اپ
                        'stats'    => tpp()->services()->stats(),
                        'last_sync'=> tpp()->sync()->last_sync( $user_id ),
                        'site'     => get_bloginfo( 'name' ),
                        'login_url'  => wp_login_url(),
                        'logout_url' => wp_logout_url(),
                        'nonce'    => wp_create_nonce( 'wp_rest' ),
                );
        }

        /* -------------------- مسیرها -------------------- */

        /**
         * جدول مسیرها — منبع یگانه برای ثبت REST و پشتیبان admin-ajax.
         * الگو: pattern => array( METHOD => array( 'perm' => callback, 'cb' => handler, 'args' => optional ) )
         */
        public static function route_table() {
                return array(
                        /* --- احراز هویت --- */
                        'ping'                     => array( 'GET' => array( 'perm' => '__return_true', 'cb' => 'ping' ) ),
                        'login'                    => array( 'POST' => array( 'perm' => '__return_true', 'cb' => 'login' ) ),
                        'bootstrap'                => array( 'GET' => array( 'perm' => '__return_true', 'cb' => 'bootstrap' ) ),
                        'logout'                   => array( 'POST' => array( 'perm' => 'perm_access', 'cb' => 'logout' ) ),

                        /* --- داده‌ها --- */
                        'search'                   => array( 'GET' => array( 'perm' => 'perm_cap_view', 'cb' => 'search', 'args' => array(
                                'query'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'page'     => array( 'type' => 'integer', 'default' => 1 ),
                                'per_page' => array( 'type' => 'integer', 'default' => 25 ),
                                'sort'     => array( 'type' => 'string', 'default' => 'updated' ),
                                'order'    => array( 'type' => 'string', 'default' => 'DESC' ),
                                'group'    => array( 'type' => 'integer', 'default' => 0 ),
                                /* بازه زمانی ویرایش — YYYY-MM-DD میلادی (الگوریتم شمسی→میلادی در اپ انجام می‌شود؛ مقایسه به وقت تهران) */
                                'upd_from' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'upd_to'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                /* ۱.۱۲.۰ — فیلتر وضعیت پیشرفت دایری: none|progress|done|fail|fail_los|fail_phone|fail_internet|fail_other */
                                'progress_status'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                                'progress_step'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                                'progress_step_state' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'done' ),
                                /* ۱.۱۹.۰ — فیلتر دسته‌بندی/تگ‌ها */
                                'category' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'tags'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                        ) ) ),
                        'services'                 => array( 'POST' => array( 'perm' => 'perm_create', 'cb' => 'create_service' ) ),
                        /* ۱.۱۳.۰ — تغییر گروهی/تکی پیشرفت دایری + خرابی + فیلدهای سرویس روی چند سرویس */
                        'services/bulk'            => array( 'POST' => array( 'perm' => 'perm_quick', 'cb' => 'bulk_services' ) ),
                        'services/(?P<id>\d+)'     => array(
                                'GET'    => array( 'perm' => 'perm_cap_view', 'cb' => 'get_service' ),
                                'PUT'    => array( 'perm' => 'perm_edit', 'cb' => 'update_service' ),
                                'DELETE' => array( 'perm' => 'perm_delete', 'cb' => 'delete_service' ),
                        ),
                        'addresses/(?P<id>\d+)'    => array( 'GET' => array( 'perm' => 'perm_cap_view', 'cb' => 'get_address' ) ),
                        'addresses/suggest'        => array( 'GET' => array( 'perm' => 'perm_cap_view', 'cb' => 'suggest_addresses' ) ),

                        /* --- مقادیر فیلد برای کشویی جستجوی اجاکسی فیلترها (۱.۹.۰) --- */
                        'values'                   => array( 'GET' => array( 'perm' => 'perm_cap_view', 'cb' => 'field_values', 'args' => array(
                                'field' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'q'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'limit' => array( 'type' => 'integer', 'default' => 100 ),
                        ) ) ),

                        /* --- بررسی موارد تکراری — فقط مدیر کل (۱.۹.۰) --- */
                        'duplicates'               => array( 'GET' => array( 'perm' => 'perm_admin', 'cb' => 'duplicates_list', 'args' => array(
                                'fields'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'include_dismissed' => array( 'type' => 'integer', 'default' => 0 ),
                        ) ) ),
                        'duplicates/merge'         => array( 'POST' => array( 'perm' => 'perm_admin', 'cb' => 'duplicates_merge' ) ),
                        /* ۱.۱۱.۰ — ادغام گروهی همه گروه‌های بدون تناقض */
                        'duplicates/merge_safe'    => array( 'POST' => array( 'perm' => 'perm_admin', 'cb' => 'duplicates_merge_safe' ) ),
                        'duplicates/dismiss'       => array( 'POST' => array( 'perm' => 'perm_admin', 'cb' => 'duplicates_dismiss' ) ),
                        'duplicates/restore'       => array( 'POST' => array( 'perm' => 'perm_admin', 'cb' => 'duplicates_restore' ) ),

                        /* --- تاریخچه --- */
                        'history'                  => array( 'GET' => array( 'perm' => 'perm_history', 'cb' => 'history' ) ),
                        'history/delete'           => array( 'POST' => array( 'perm' => 'perm_delete_history', 'cb' => 'history_delete' ) ),
                        'history/(?P<id>\d+)/restore' => array( 'POST' => array( 'perm' => 'perm_edit', 'cb' => 'restore_history' ) ),

                        /* --- گزارش فعالیت (بازدید/جستجو) — ۱.۱۰.۰ --- */
                        'activity/views'           => array( 'GET' => array( 'perm' => 'perm_activity', 'cb' => 'activity_views', 'args' => array(
                                'user_id'    => array( 'type' => 'integer', 'default' => 0 ),
                                'service_id' => array( 'type' => 'integer', 'default' => 0 ),
                                'page'       => array( 'type' => 'integer', 'default' => 1 ),
                                'per_page'   => array( 'type' => 'integer', 'default' => 50 ),
                        ) ) ),
                        'activity/searches'        => array( 'GET' => array( 'perm' => 'perm_activity', 'cb' => 'activity_searches', 'args' => array(
                                'user_id'  => array( 'type' => 'integer', 'default' => 0 ),
                                'q'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'page'     => array( 'type' => 'integer', 'default' => 1 ),
                                'per_page' => array( 'type' => 'integer', 'default' => 50 ),
                        ) ) ),
                        /* ۱.۱۱.۰ — گزارش یکپارچه همه فعالیت‌ها + آمار + خروجی CSV */
                        'activity/log'             => array( 'GET' => array( 'perm' => 'perm_activity', 'cb' => 'activity_log', 'args' => array(
                                'user_id'    => array( 'type' => 'integer', 'default' => 0 ),
                                'type'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                                'action'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                                'service_id' => array( 'type' => 'integer', 'default' => 0 ),
                                'q'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'from'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'to'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'page'       => array( 'type' => 'integer', 'default' => 1 ),
                                'per_page'   => array( 'type' => 'integer', 'default' => 50 ),
                        ) ) ),
                        'activity/stats'           => array( 'GET' => array( 'perm' => 'perm_activity', 'cb' => 'activity_stats' ) ),
                        'activity/export'          => array( 'GET' => array( 'perm' => 'perm_activity', 'cb' => 'activity_export' ) ),

                        /* --- گزارش کار (۱.۱۴.۰) — گزارش روزانه اقدامات هر کاربر برای مدیران --- */
                        'workreport'               => array(
                                'GET'  => array( 'perm' => 'perm_access', 'cb' => 'workreport_day', 'args' => array(
                                        'date'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                        'user_id' => array( 'type' => 'integer', 'default' => 0 ),
                                        'days'    => array( 'type' => 'integer', 'default' => 1 ),
                                        /* ۱.۱۹.۰ — گزارش بازه‌ای n-روزه/هفتگی/ماهانه (وقتی from/to هر دو باشند) */
                                        'from'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                        'to'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                ) ),
                                'POST' => array( 'perm' => 'perm_access', 'cb' => 'workreport_add' ),
                        ),
                        'workreport/from_activity' => array( 'POST' => array( 'perm' => 'perm_access', 'cb' => 'workreport_add_activity' ) ),
                        'workreport/line'          => array( 'POST' => array( 'perm' => 'perm_access', 'cb' => 'workreport_line' ) ),
                        'workreport/(?P<id>\d+)'   => array(
                                'PUT'    => array( 'perm' => 'perm_access', 'cb' => 'workreport_update' ),
                                'DELETE' => array( 'perm' => 'perm_access', 'cb' => 'workreport_delete' ),
                        ),

                        /* --- ۱.۱۹.۰ — روزهای دارای فعالیت کاربر (برای هایلایت تقویم) --- */
                        'activity/days'            => array( 'GET' => array( 'perm' => 'perm_access', 'cb' => 'activity_days', 'args' => array(
                                'from'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'to'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'user_id' => array( 'type' => 'integer', 'default' => 0 ),
                        ) ) ),
                        /* --- ۱.۲۱.۰ — روزهای دارای گزارش کار (تقویم مجزای گزارش کار) --- */
                        'workreport/days'          => array( 'GET' => array( 'perm' => 'perm_access', 'cb' => 'workreport_days', 'args' => array(
                                'from'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'to'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'user_id' => array( 'type' => 'integer', 'default' => 0 ),
                        ) ) ),
                        /* --- ۱.۲۱.۰ — بروزآوری دیتابیس: انتقال ستون‌های یتیم به «توضیحات متفرقه» + حذف --- */
                        'tools/db-update'          => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'tools_db_update' ) ),

                        /* --- ۱.۱۹.۰ — دسته‌بندی پروژه‌ها و تگ‌های سیستمی --- */
                        'categories'               => array(
                                'GET'  => array( 'perm' => 'perm_access', 'cb' => 'categories_list' ),
                                'POST' => array( 'perm' => 'perm_categories', 'cb' => 'categories_add' ),
                        ),
                        'categories/(?P<id>\d+)'   => array(
                                'PUT'    => array( 'perm' => 'perm_categories', 'cb' => 'categories_update' ),
                                'DELETE' => array( 'perm' => 'perm_categories', 'cb' => 'categories_delete' ),
                        ),

                        /* --- 1.20.0 — بازبینی: صف سرویس‌های ارجاعی + سجل اقدامات نصاب‌ها --- */
                        'review/queue'             => array( 'GET' => array( 'perm' => 'perm_review_queue', 'cb' => 'review_queue' ) ),
                        'review/assign'            => array( 'POST' => array( 'perm' => 'perm_review_queue', 'cb' => 'review_assign' ) ),
                        'review/changes'           => array( 'GET' => array( 'perm' => 'perm_review_installer', 'cb' => 'review_changes', 'args' => array(
                                'date'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'from'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'to'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                                'user_id' => array( 'type' => 'integer', 'default' => 0 ),
                                'status'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                        ) ) ),
                        'review/keep'              => array( 'POST' => array( 'perm' => 'perm_review_installer', 'cb' => 'review_keep' ) ),
                        'review/revert'            => array( 'POST' => array( 'perm' => 'perm_review_installer', 'cb' => 'review_revert' ) ),

                        /* --- همگام‌سازی آفلاین --- */
                        'sync'                     => array( 'POST' => array( 'perm' => 'perm_access', 'cb' => 'sync' ) ),

                        /* --- ایمپورت --- */
                        'import/preview'           => array( 'POST' => array( 'perm' => 'perm_import', 'cb' => 'import_preview' ) ),
                        'import/analyze'           => array( 'POST' => array( 'perm' => 'perm_import', 'cb' => 'import_analyze' ) ),
                        'import/commit'            => array( 'POST' => array( 'perm' => 'perm_import', 'cb' => 'import_commit' ) ),

                        /* --- خروجی‌ها --- */
                        /* اکسل/PDF از نتایج جستجو: برای همه کاربرانی که اجازه مشاهده سرویس‌ها را دارند (درخواست کاربر) */
                        'export/xlsx'              => array( 'GET' => array( 'perm' => 'perm_export_results', 'cb' => 'export_xlsx' ) ),
                        'export/pdf'               => array( 'GET' => array( 'perm' => 'perm_export_results', 'cb' => 'export_pdf' ) ),
                        'export/print'             => array( 'GET' => array( 'perm' => 'perm_export_results', 'cb' => 'export_print' ) ),
                        'template'                 => array( 'GET' => array( 'perm' => 'perm_access', 'cb' => 'template' ) ),
                        'backup'                   => array( 'GET' => array( 'perm' => 'perm_settings', 'cb' => 'backup' ) ),
                        'backup/info'              => array( 'GET' => array( 'perm' => 'perm_settings', 'cb' => 'backup_info' ) ),
                        'backup/sql'               => array( 'GET' => array( 'perm' => 'perm_settings', 'cb' => 'backup_sql' ) ),
                        'backup/zip'               => array( 'GET' => array( 'perm' => 'perm_settings', 'cb' => 'backup_zip' ) ),
                        'restore'                  => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'restore' ) ),
                        /* آپلود تکه‌ای پشتیبان — رفع محدودیت upload_max_filesize/post_max_size (۱.۱۰.۰) */
                        'restore/begin'            => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'restore_begin' ) ),
                        'restore/chunk'            => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'restore_chunk' ) ),
                        'restore/finish'           => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'restore_finish' ) ),
                        'restore/cancel'           => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'restore_cancel' ) ),
                        /* ۱.۱۵.۰ — پشتیبان‌های ذخیره‌شده روی سرور (پوشه افزونه): فهرست/ساخت/بازگردانی یک‌کلیکی/حذف/دانلود */
                        'backup/list'              => array( 'GET'  => array( 'perm' => 'perm_settings', 'cb' => 'backup_stored_list' ) ),
                        'backup/stored'            => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'backup_stored_create' ) ),
                        'backup/stored/restore'    => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'backup_stored_restore' ) ),
                        'backup/stored/delete'     => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'backup_stored_delete' ) ),
                        'backup/stored/download'   => array( 'GET'  => array( 'perm' => 'perm_settings', 'cb' => 'backup_stored_download', 'args' => array(
                                'filename' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_file_name' ),
                        ) ) ),
                        /* ۱.۱۸.۰ — اصلاح اعداد فارسی/عربی → انگلیسی در فیلدها (با پشتیبان خودکار) */
                        'numbers/fix'              => array( 'POST' => array( 'perm' => 'perm_settings', 'cb' => 'numbers_fix', 'args' => array(
                                'dry_run' => array( 'type' => 'boolean', 'default' => false ),
                        ) ) ),

                        /* --- مدیریت فیلدها --- */
                        'fields'                   => array(
                                'GET'  => array( 'perm' => 'perm_access', 'cb' => 'fields_list' ),
                                'POST' => array( 'perm' => 'perm_fields', 'cb' => 'fields_add' ),
                        ),
                        'fields/(?P<id>\d+)'       => array(
                                'PUT'    => array( 'perm' => 'perm_fields', 'cb' => 'fields_update' ),
                                'DELETE' => array( 'perm' => 'perm_fields', 'cb' => 'fields_delete' ),
                        ),
                        'fields/reorder'           => array( 'POST' => array( 'perm' => 'perm_fields', 'cb' => 'fields_reorder' ) ),

                        /* --- نقش‌ها --- */
                        'roles'                    => array(
                                'GET'  => array( 'perm' => 'perm_roles', 'cb' => 'roles_list' ),
                                'POST' => array( 'perm' => 'perm_roles', 'cb' => 'roles_add' ),
                        ),
                        'roles/(?P<slug>[a-z0-9_\-]+)' => array(
                                'PUT'    => array( 'perm' => 'perm_roles', 'cb' => 'roles_update' ),
                                'DELETE' => array( 'perm' => 'perm_roles', 'cb' => 'roles_delete' ),
                        ),

                        /* --- تنظیمات --- */
                        'settings'                 => array(
                                'GET'  => array( 'perm' => 'perm_settings', 'cb' => 'settings_get' ),
                                'PUT'  => array( 'perm' => 'perm_settings', 'cb' => 'settings_update' ),
                        ),
                        'stats'                    => array( 'GET' => array( 'perm' => 'perm_cap_view', 'cb' => 'stats' ) ),

                        /* --- کاربران افزونه (۱.۱۱.۰ — برای اتصال افزونه‌های دیگر) --- */
                        'users'                    => array( 'GET' => array( 'perm' => 'perm_roles', 'cb' => 'users_list', 'args' => array(
                                'q' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                        ) ) ),

                        /* --- مرکز API (۱.۱۱.۰): توکن‌ها + مستندات زنده --- */
                        'api/tokens'               => array(
                                'GET'    => array( 'perm' => 'perm_access', 'cb' => 'api_tokens_list' ),
                                'POST'   => array( 'perm' => 'perm_access', 'cb' => 'api_tokens_issue' ),
                                'DELETE' => array( 'perm' => 'perm_access', 'cb' => 'api_tokens_revoke' ),
                        ),
                        'api/docs'                 => array( 'GET' => array( 'perm' => 'perm_access', 'cb' => 'api_docs', 'args' => array(
                                'format' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                        ) ) ),

                        /* --- پنل پیامک (SMS.ir) --- */
                        'sms/status'               => array( 'GET' => array( 'perm' => 'perm_sms', 'cb' => 'sms_status' ) ),
                        'sms/send'                 => array( 'POST' => array( 'perm' => 'perm_sms', 'cb' => 'sms_send' ) ),
                        'sms/templates'            => array(
                                'GET'  => array( 'perm' => 'perm_sms', 'cb' => 'sms_templates_list' ),
                                'POST' => array( 'perm' => 'perm_settings', 'cb' => 'sms_templates_add' ),
                        ),
                        'sms/templates/(?P<id>\d+)' => array(
                                'PUT'    => array( 'perm' => 'perm_settings', 'cb' => 'sms_template_update' ),
                                'DELETE' => array( 'perm' => 'perm_settings', 'cb' => 'sms_template_delete' ),
                        ),
                        'sms/log'                  => array( 'GET' => array( 'perm' => 'perm_settings', 'cb' => 'sms_log_list' ) ),
                );
        }

        public function register_routes() {
                foreach ( self::route_table() as $pattern => $methods ) {
                        $routes = array();
                        foreach ( $methods as $method => $entry ) {
                                $def = array(
                                        'methods'             => $method,
                                        'permission_callback' => '__return_true' === $entry['perm'] ? '__return_true' : array( $this, $entry['perm'] ),
                                        'callback'            => array( $this, $entry['cb'] ),
                                );
                                if ( ! empty( $entry['args'] ) ) {
                                        $def['args'] = $entry['args'];
                                }
                                $routes[] = $def;
                        }
                        register_rest_route( self::NS, '/' . $pattern, $routes );
                }
        }

        /* -------------------- پشتیبان admin-ajax (وقتی REST در دسترس نیست) -------------------- */

        /** تطبیق مسیر درخواستی با جدول مسیرها → array(entry, url_params) یا null */
        public static function match_route( $route, $method ) {
                $table = self::route_table();
                $route = trim( (string) $route, '/' );
                // ۱) تطبیق کامل (مسیرهای بدون پارامتر)
                if ( isset( $table[ $route ] ) ) {
                        $methods = $table[ $route ];
                        if ( isset( $methods[ $method ] ) ) {
                                return array( $methods[ $method ], array() );
                        }
                        return null;
                }
                // ۲) تطبیق الگوی پارامتری
                foreach ( $table as $pattern => $methods ) {
                        if ( false === strpos( $pattern, '(' ) ) {
                                continue;
                        }
                        if ( ! isset( $methods[ $method ] ) ) {
                                continue;
                        }
                        if ( preg_match( '#^' . $pattern . '$#', $route, $m ) ) {
                                $params = array();
                                foreach ( $m as $k => $v ) {
                                    if ( ! is_int( $k ) ) {
                                        $params[ $k ] = $v;
                                    }
                                }
                                return array( $methods[ $method ], $params );
                        }
                }
                return null;
        }

        /** ارسال JSON با پاک‌سازی خروجی‌های ناخواسته (notice/warning) قبل از بدنه */
        private static function json_out( $data, $status = 200 ) {
                while ( ob_get_level() > 0 ) {
                        ob_end_clean();
                }
                wp_send_json( $data, $status );
        }

        /** شروع بافر خروجی برای مسیرهای خودمان — تا noticeهای وسط پردازش در JSON نشت نکنند */
        public function start_route_buffer() {
                $uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
                $route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '';
                if ( false !== strpos( $uri, '/tpp/v1' ) || false !== strpos( $route, '/tpp/v1' ) ) {
                        $this->buffer_base = ob_get_level();
                        ob_start();
                }
        }

        /** پاک‌سازی بافر قبل از ارسال پاسخ REST (فقط تا سطح پایه‌ای که خودمان شروع کردیم) */
        public function clean_route_buffer( $served, $result = null, $request = null, $server = null ) {
                if ( null !== $this->buffer_base ) {
                        while ( ob_get_level() > $this->buffer_base ) {
                                ob_end_clean();
                        }
                        $this->buffer_base = null;
                }
                return $served;
        }

        /** رله admin-ajax — همان هندلرهای REST با همان دسترسی‌ها */
        public function ajax_relay() {
                // خروجی‌های قبلی (از افزونه‌های دیگر یا noticeها) را دور بریز
                while ( ob_get_level() > 0 ) {
                        ob_end_clean();
                }
                ob_start();
                $route  = isset( $_REQUEST['route'] ) ? trim( (string) wp_unslash( $_REQUEST['route'] ), '/' ) : '';
                $method = isset( $_REQUEST['method'] ) ? strtoupper( (string) wp_unslash( $_REQUEST['method'] ) ) : 'GET';
                if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) {
                        $method = 'POST';
                }

                // احراز هویت توکنی (اگر کاربر با کوکی شناسایی نشده باشد)
                if ( ! get_current_user_id() ) {
                        $token = '';
                        if ( isset( $_SERVER['HTTP_X_TPP_TOKEN'] ) ) {
                                $token = trim( (string) wp_unslash( $_SERVER['HTTP_X_TPP_TOKEN'] ) );
                        } elseif ( isset( $_REQUEST['token'] ) ) {
                                $token = trim( (string) wp_unslash( $_REQUEST['token'] ) );
                        }
                        if ( '' !== $token ) {
                                $uid = tpp()->auth()->validate_token( $token );
                                if ( $uid ) {
                                        wp_set_current_user( $uid );
                                }
                        }
                }

                if ( '' === $route ) {
                        self::json_out( array( 'code' => 'tpp_no_route', 'message' => 'مسیر API مشخص نشده است.', 'data' => array( 'status' => 400 ) ), 400 );
                }
                $matched = self::match_route( $route, $method );
                if ( ! $matched ) {
                        self::json_out( array( 'code' => 'tpp_rest_no_route', 'message' => 'مسیر API یافت نشد: ' . $route, 'data' => array( 'status' => 404 ) ), 404 );
                }
                list( $entry, $url_params ) = $matched;

                // ساخت درخواست معادل
                $req = new WP_REST_Request( $method, '/' . self::NS . '/' . $route );
                $query = array();
                if ( isset( $_REQUEST['args'] ) ) {
                        $decoded = json_decode( (string) wp_unslash( $_REQUEST['args'] ), true );
                        if ( is_array( $decoded ) ) {
                                $query = $decoded;
                        }
                }
                if ( 'GET' === $method && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
                        // لینک مستقیم (دانلودها): پارامترهای GET خودشان کوئری هستند
                        foreach ( $_GET as $k => $v ) {
                                if ( in_array( $k, array( 'action', 'route', 'method', 'token', 'args' ), true ) ) {
                                        continue;
                                }
                                $query[ $k ] = wp_unslash( $v );
                        }
                }
                $req->set_query_params( $query );
                $req->set_url_params( $url_params );
                if ( ! empty( $_FILES['file'] ) && is_array( $_FILES['file'] ) ) {
                        $req->set_file_params( $_FILES );
                }
                $body_json = isset( $_REQUEST['body'] ) ? (string) wp_unslash( $_REQUEST['body'] ) : '';
                if ( '' !== $body_json ) {
                        $req->set_header( 'Content-Type', 'application/json; charset=utf-8' );
                        $req->set_body( $body_json );
                }
                if ( isset( $_SERVER['HTTP_X_TPP_TOKEN'] ) ) {
                        $req->set_header( 'X-TPP-Token', trim( (string) wp_unslash( $_SERVER['HTTP_X_TPP_TOKEN'] ) ) );
                }

                // بررسی دسترسی
                if ( '__return_true' !== $entry['perm'] ) {
                        $perm = call_user_func( array( $this, $entry['perm'] ), $req );
                        if ( true !== $perm ) {
                                $status = 403;
                                $code   = 'tpp_forbidden';
                                $msg    = 'دسترسی به این عملیات را ندارید.';
                                if ( is_wp_error( $perm ) ) {
                                        $code   = $perm->get_error_code();
                                        $msg    = $perm->get_error_message();
                                        $data   = $perm->get_error_data();
                                        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
                                }
                                self::json_out( array( 'code' => $code, 'message' => $msg, 'data' => array( 'status' => $status ) ), $status );
                        }
                }

                // اجرای هندلر (هندلرهای دانلود خودشان خروجی می‌دهند و exit می‌کنند)
                try {
                        $result = call_user_func( array( $this, $entry['cb'] ), $req );
                } catch ( Throwable $e ) {
                        self::json_out( array( 'code' => 'tpp_server_error', 'message' => 'خطای سرور: ' . $e->getMessage(), 'data' => array( 'status' => 500 ) ), 500 );
                }

                if ( is_wp_error( $result ) ) {
                        $data   = $result->get_error_data();
                        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
                        self::json_out( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $data ), $status );
                }
                if ( $result instanceof WP_REST_Response ) {
                        self::json_out( $result->get_data(), $result->get_status() );
                }
                self::json_out( $result, 200 );
        }

        /* -------------------- هندلرها: احراز هویت -------------------- */

        public function ping( WP_REST_Request $request ) {
                $authed = self::can_access();
                $result = array(
                        'ok'         => true,
                        'authed'     => $authed,
                        'time'       => TPP_Date::now(),
                        'version'    => TPP_VERSION,
                        'site'       => get_bloginfo( 'name' ),
                        'login_url'  => wp_login_url(),
                );
                if ( is_user_logged_in() ) {
                        tpp()->auth()->heartbeat_cookie_refresh(); // تمدید خودکار نشست
                        $result['nonce'] = wp_create_nonce( 'wp_rest' );
                        $result['user']  = array( 'id' => get_current_user_id(), 'name' => wp_get_current_user()->display_name );
                }
                return self::ok( $result );
        }

        /** هدر X-TPP-Nonce روی پاسخ‌های REST — تمدید خودکار nonce در سمت اپ */
        public function add_nonce_header( $response, $server = null, $request = null ) {
                if ( is_user_logged_in() && $response instanceof WP_REST_Response ) {
                        $response->header( 'X-TPP-Nonce', wp_create_nonce( 'wp_rest' ) );
                }
                return $response;
        }

        public function login( WP_REST_Request $request ) {
                $username = (string) $request->get_param( 'username' );
                $password = (string) $request->get_param( 'password' );
                if ( '' === $username || '' === $password ) {
                        return self::err( 'tpp_missing', 'نام کاربری و رمز عبور الزامی است.', 400 );
                }
                $result = tpp()->auth()->login( $username, $password, true );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 401 );
                }
                return self::ok( self::bootstrap_payload( $result['user']['id'], $result['token'] ) );
        }

        public function bootstrap( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                $token   = (string) $request->get_header( 'X-TPP-Token' );

                if ( $user_id && tpp()->auth()->user_has_tpp_access( $user_id ) ) {
                        // کاربر با کوکی یا توکن شناسایی شده
                        $fresh = null;
                        if ( '' === $token ) {
                                // ورود با کوکی وردپرس → صدور توکن برای اپ
                                $fresh = tpp()->auth()->issue_token( $user_id, 'اپ TPP' );
                        } else {
                                // اگر توکن ارسالی نامعتبر است یا متعلق به کاربر دیگری است → چرخش توکن
                                $token_uid = tpp()->auth()->validate_token( $token );
                                if ( ! $token_uid || (int) $token_uid !== (int) $user_id ) {
                                        $fresh = tpp()->auth()->issue_token( $user_id, 'اپ TPP' );
                                }
                        }
                        return self::ok( self::bootstrap_payload( $user_id, $fresh ) );
                }

                if ( '' !== $token ) {
                        $uid = tpp()->auth()->validate_token( $token );
                        if ( $uid ) {
                                return self::ok( self::bootstrap_payload( $uid ) );
                        }
                }
                $err = self::err( 'tpp_unauthorized', 'ورود لازم است.', 401 );
                $err->add_data( array( 'status' => 401, 'login_url' => wp_login_url() ) );
                return $err;
        }

        public function logout( WP_REST_Request $request ) {
                $token = (string) $request->get_header( 'X-TPP-Token' );
                $all   = (int) $request->get_param( 'all' );
                $wp    = (int) $request->get_param( 'wp' );
                if ( $all ) {
                        tpp()->auth()->revoke_all( get_current_user_id() );
                } elseif ( '' !== $token ) {
                        tpp()->auth()->revoke_token( $token );
                }
                // خروج پیوندی از وردپرس (حالت امبد در سایت)
                if ( $wp && is_user_logged_in() ) {
                        wp_logout();
                }
                return self::ok( array( 'status' => 'logged_out' ) );
        }

        /* -------------------- هندلرها: داده‌ها -------------------- */

        public function perm_cap_view()  { return self::perm_cap( 'tpp_view_services' ); }
        public function perm_create()    { return self::perm_cap( 'tpp_create_services' ); }
        public function perm_edit()      { return self::perm_cap( 'tpp_edit_services' ); }
        /** ۱.۱۳.۱ — ویرایش سریع (⚡/🚀): قابلیت مستقل؛ به‌طور پیش‌فرض فقط مدیر کل سایت، قابل اعطا به نقش‌ها */
        public function perm_quick()     { return self::perm_cap( 'tpp_quick_edit' ); }
        public function perm_delete()    { return self::perm_cap( 'tpp_delete_services' ); }
        public function perm_import()    { return self::perm_cap( 'tpp_import' ); }
        public function perm_export()    { return self::perm_cap( 'tpp_export' ); }

        /**
         * خروجی اکسل/PDF از نتایج جستجوی صفحه سرویس‌ها — به‌صورت پیش‌فرض برای همه کاربران
         * (هر کاربری که اجازه مشاهده سرویس‌ها را دارد؛ ستون‌های خروجی هم فقط فیلدهای قابل مشاهده همان کاربر است)
         */
        public function perm_export_results() {
                $r1 = self::perm_cap( 'tpp_view_services' );
                if ( true === $r1 ) {
                        return true;
                }
                return self::perm_cap( 'tpp_export' );
        }
        public function perm_fields()    { return self::perm_cap( 'tpp_manage_fields' ); }
        public function perm_categories() { return self::perm_cap( 'tpp_manage_categories' ); } // ۱.۱۹.۰ — دسته‌بندی پروژه‌ها
        public function perm_roles()     { return self::perm_cap( 'tpp_manage_roles' ); }
        public function perm_settings()  { return self::perm_cap( 'tpp_manage_settings' ); }
        public function perm_history()   { return self::perm_cap( 'tpp_view_history' ); }
        public function perm_delete_history() { return self::perm_cap( 'tpp_delete_history' ); }
        public function perm_activity()  { return self::perm_cap( 'tpp_view_activity' ); }
        public function perm_review_queue()    { return self::perm_cap( 'tpp_review_queue' ); }     // 1.20.0 — صف سرویس‌های ارجاعی
        public function perm_review_installer(){ return self::perm_cap( 'tpp_review_installer' ); } // 1.20.0 — سجل اقدامات نصاب‌ها
        public function perm_sms()       { return self::perm_cap( 'tpp_send_sms' ); }

        /** بررسی موارد تکراری و عملیات حساس مشابه — فقط مدیر کل سایت (manage_options) */
        public static function perm_admin() {
                if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                        return self::err( 'tpp_admin_only', 'این بخش فقط برای مدیر کل سایت (Administrator) در دسترس است.', 403 );
                }
                return true;
        }

        public function search( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                $filters = $request->get_param( 'filters' );
                if ( is_string( $filters ) ) {
                        $decoded = json_decode( $filters, true );
                        $filters = is_array( $decoded ) ? $decoded : array();
                }
                $args    = array(
                        'query'    => (string) $request->get_param( 'query' ),
                        'filters'  => is_array( $filters ) ? $filters : array(),
                        'page'     => (int) $request->get_param( 'page' ),
                        'per_page' => (int) $request->get_param( 'per_page' ),
                        'group'    => (int) $request->get_param( 'group' ),
                        'sort'     => sanitize_key( (string) $request->get_param( 'sort' ) ),
                        'order'    => strtoupper( (string) $request->get_param( 'order' ) ),
                        'upd_from' => (string) $request->get_param( 'upd_from' ),
                        'upd_to'   => (string) $request->get_param( 'upd_to' ),
                        'progress_status'     => sanitize_key( (string) $request->get_param( 'progress_status' ) ),
                        'progress_step'       => sanitize_key( (string) $request->get_param( 'progress_step' ) ),
                        'progress_step_state' => sanitize_key( (string) $request->get_param( 'progress_step_state' ) ),
                        /* ۱.۱۹.۰ — فیلتر دسته‌بندی/تگ‌ها */
                        'category' => (string) $request->get_param( 'category' ),
                        'tags'     => (string) $request->get_param( 'tags' ),
                );
                $args = apply_filters( 'tpp_search_args', $args, $request );
                $result = tpp()->services()->search( $args, $user_id );
                // ۱.۱۰.۰ — ثبت تاریخچه جستجو (عبارت/فیلتر + تعداد نتایج)
                if ( $user_id ) {
                        TPP_Activity::log_search( $user_id, $args['query'], $args['filters'], isset( $result['total'] ) ? (int) $result['total'] : 0 );
                }
                return self::ok( $result );
        }

        /* -------------------- هندلرها: گزارش فعالیت (۱.۱۰.۰) -------------------- */

        public function activity_views( WP_REST_Request $request ) {
                return self::ok( TPP_Activity::views_log( array(
                        'user_id'    => (int) $request->get_param( 'user_id' ),
                        'service_id' => (int) $request->get_param( 'service_id' ),
                ), (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ) );
        }

        public function activity_searches( WP_REST_Request $request ) {
                return self::ok( TPP_Activity::searches_log( array(
                        'user_id' => (int) $request->get_param( 'user_id' ),
                        'q'       => (string) $request->get_param( 'q' ),
                ), (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ) );
        }

        /* -------------------- هندلرها: گزارش یکپارچه فعالیت (۱.۱۱.۰) -------------------- */

        /** فید یکپارچه همه فعالیت‌ها (تغییر/بازدید/جستجو/پیامک) با فیلتر و صفحه‌بندی */
        public function activity_log( WP_REST_Request $request ) {
                return self::ok( TPP_Activity::unified_log( array(
                        'user_id'    => (int) $request->get_param( 'user_id' ),
                        'type'       => (string) $request->get_param( 'type' ),
                        'action'     => (string) $request->get_param( 'action' ),
                        'service_id' => (int) $request->get_param( 'service_id' ),
                        'q'          => (string) $request->get_param( 'q' ),
                        'from'       => (string) $request->get_param( 'from' ),
                        'to'         => (string) $request->get_param( 'to' ),
                ), (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ) );
        }

        /** آمار فعالیت (بازه‌های امروز/۷روز/۳۰روز + کاربران برتر + سرویس‌های پربازدید + عبارات پرتکرار) */
        public function activity_stats( WP_REST_Request $request ) {
                return self::ok( TPP_Activity::stats() );
        }

        /** خروجی CSV فید یکپارچه — همان فیلترهای activity/log */
        public function activity_export( WP_REST_Request $request ) {
                $csv = TPP_Activity::export_csv( array(
                        'user_id'    => (int) $request->get_param( 'user_id' ),
                        'type'       => (string) $request->get_param( 'type' ),
                        'action'     => (string) $request->get_param( 'action' ),
                        'service_id' => (int) $request->get_param( 'service_id' ),
                        'q'          => (string) $request->get_param( 'q' ),
                        'from'       => (string) $request->get_param( 'from' ),
                        'to'         => (string) $request->get_param( 'to' ),
                ) );
                $name = 'tpp-activity-' . gmdate( 'Ymd-His' ) . '.csv';
                nocache_headers();
                header( 'Content-Type: text/csv; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="' . $name . '"' );
                echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
                exit;
        }

        /* -------------------- هندلرها: گزارش کار (۱.۱۴.۰) --------------------
         * هر کاربر افزونه گزارش کار خودش را دارد؛ مدیران (قابلیت tpp_view_activity) گزارش سایر کاربران را هم می‌بینند.
         * افزودن/ویرایش/حذف فقط توسط مالک خود گزارش انجام می‌شود. */

        /** GET workreport?date=Y-m-d[&user_id=N] — گزارش روز + روزهای دارای گزارش + (مدیران) کاربران
         *  GET workreport?from=Y-m-d&to=Y-m-d — ۱.۱۹.۰ گزارش بازه‌ای (n-روزه/هفتگی/ماهانه) گروه‌بندی روزانه
         *  پاسخ تک‌روز شامل: feed (فعالیت‌های روز بدون جستجو + آدرس سرویس) + activity_days (هایلایت تقویم)
         *  + retention (اخطار نگهداشت تاریخچه) + actions (فهرست اقدامات برای افزودن دستی) */
        public function workreport_day( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                $owner   = (int) $request->get_param( 'user_id' );
                if ( $owner > 0 && $owner !== $user_id ) {
                        if ( ! TPP_Capabilities::user_can( $user_id, 'tpp_view_activity' ) ) {
                                return self::err( 'tpp_forbidden', 'مشاهده گزارش کار سایر کاربران فقط برای مدیران ممکن است.', 403 );
                        }
                } else {
                        $owner = $user_id;
                }
                $from = trim( (string) $request->get_param( 'from' ) );
                $to   = trim( (string) $request->get_param( 'to' ) );

                if ( '' !== $from && '' !== $to ) {
                        // حالت بازه‌ای (n-روزه/هفتگی/ماهانه)
                        $payload = TPP_Workreport::range( $owner, $from, $to );
                        if ( is_wp_error( $payload ) ) {
                                return self::err( $payload->get_error_code(), $payload->get_error_message(), 400 );
                        }
                        $payload['can_edit'] = ( $owner === $user_id );
                        if ( TPP_Capabilities::user_can( $user_id, 'tpp_view_activity' ) ) {
                                $payload['users'] = TPP_Workreport::users_facet();
                        }
                        return self::ok( $payload );
                }

                $payload = TPP_Workreport::day( $owner, (string) $request->get_param( 'date' ) );
                $payload['days'] = TPP_Workreport::days_with_reports( $owner );
                $payload['can_edit'] = ( $owner === $user_id ); // مالک خود گزارش؛ مدیر فقط مشاهده می‌کند
                if ( TPP_Capabilities::user_can( $user_id, 'tpp_view_activity' ) ) {
                        // مدیران همیشه فهرست کاربرانِ دارای گزارش را می‌بینند (برای انتخاب و مشاهده)
                        $payload['users'] = TPP_Workreport::users_facet();
                }

                /* ۱.۱۹.۰ — فید فعالیت همان روز (بدون جستجوها) با آدرس سرویس‌ها — برای همه کاربران (خودِ گزارش) */
                $payload['feed'] = TPP_Activity::workreport_feed( $owner, (string) $payload['date'] );

                /* ۱.۱۹.۰ — روزهای دارای فعالیت در ماه جاری گزارش (هایلایت تقویم) */
                $month_from = substr( (string) $payload['date'], 0, 7 ) . '-01';
                $month_to   = date_create( $month_from . ' +1 month -1 day' );
                $payload['activity_days'] = TPP_Activity::activity_days( $owner, $month_from, $month_to ? $month_to->format( 'Y-m-d' ) : $month_from );

                /* ۱.۱۹.۰ — اطلاعات نگهداشت + فهرست اقدامات برای افزودن دستی */
                $payload['retention'] = TPP_Activity::retention_info();
                $payload['actions']   = TPP_Workreport::actions();
                return self::ok( $payload );
        }

        /** GET activity/days?from&to[&user_id] — ۱.۱۹.۰ روزهای دارای فعالیت (خود کاربر؛ مدیر می‌تواند دیگران را) */
        public function activity_days( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                $owner   = (int) $request->get_param( 'user_id' );
                if ( $owner > 0 && $owner !== $user_id ) {
                        if ( ! TPP_Capabilities::user_can( $user_id, 'tpp_view_activity' ) ) {
                                return self::err( 'tpp_forbidden', 'مشاهده فعالیت سایر کاربران فقط برای مدیران ممکن است.', 403 );
                        }
                } else {
                        $owner = $user_id;
                }
                $from = trim( (string) $request->get_param( 'from' ) );
                $to   = trim( (string) $request->get_param( 'to' ) );
                if ( '' === $from || '' === $to ) {
                        return self::err( 'tpp_bad_range', 'بازه from و to (YYYY-MM-DD) الزامی است.', 400 );
                }
                return self::ok( array(
                        'from'  => $from,
                        'to'    => $to,
                        'user_id' => $owner,
                        'days'  => TPP_Activity::activity_days( $owner, $from, $to ),
                ) );
        }

        /**
         * ۱.۲۱.۰ — روزهای دارای گزارش کار (برای تقویم مجزای گزارش کار).
         * خروجی سبک: فقط تاریخ + تعداد اقلام — بدون اقلام.
         */
        public function workreport_days( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                $owner   = (int) $request->get_param( 'user_id' );
                if ( $owner > 0 && $owner !== $user_id ) {
                        if ( ! TPP_Capabilities::user_can( $user_id, 'tpp_view_activity' ) ) {
                                return self::err( 'tpp_forbidden', 'مشاهده گزارش سایر کاربران فقط برای مدیران ممکن است.', 403 );
                        }
                } else {
                        $owner = $user_id;
                }
                $from = trim( (string) $request->get_param( 'from' ) );
                $to   = trim( (string) $request->get_param( 'to' ) );
                if ( '' === $from || '' === $to ) {
                        return self::err( 'tpp_bad_range', 'بازه from و to (YYYY-MM-DD) الزامی است.', 400 );
                }
                return self::ok( array(
                        'from'    => $from,
                        'to'      => $to,
                        'user_id' => $owner,
                        'days'    => TPP_Workreport::report_days( $owner, $from, $to ),
                ) );
        }

        /* -------------------- هندلرها: دسته‌بندی پروژه‌ها (۱.۱۹.۰) --------------------
         * خواندن برای همه کاربران افزونه (فرم سرویس/فیلترها)؛ ایجاد/ویرایش/حذف فقط دارندگان
         * قابلیت tpp_manage_categories (پیش‌فرض: فقط مدیر کل سایت). */

        /** GET categories — بدون پارامتر: {categories:[…], tags:[…]} (شکل قدیمی کامل)
         *  ۱.۲۱.۰ — با kind (+q/page/per_page): {items:[…], total, page, per_page} برای
         *  کامبوباکس آجاکسی فرم سرویس و فهرست صفحه‌بندی‌شده «دسته‌بندی پروژه‌ها» */
        public function categories_list( WP_REST_Request $request ) {
                $shape = static function ( $c ) {
                        return array(
                                'id'        => (int) $c['id'],
                                'label'     => (string) $c['label'],
                                'sort'      => (int) $c['sort_order'],
                                'usage'     => TPP_Categories::usage_count( (int) $c['id'] ),
                                'is_review' => ! empty( $c['is_review'] ), // 1.20.0 — دسته ارجاع پیش‌فرض
                        );
                };

                /* شکل قدیمی — همه تعریف‌ها (فرم/فیلترها/کش آفلاین) */
                $kind = sanitize_key( (string) $request->get_param( 'kind' ) );
                if ( ! in_array( $kind, array( 'category', 'tag' ), true ) ) {
                        return self::ok( array(
                                'categories' => array_map( $shape, TPP_Categories::categories() ),
                                'tags'       => array_map( $shape, TPP_Categories::tags() ),
                        ) );
                }

                /* ۱.۲۱.۰ — جستجو + صفحه‌بندی */
                $q       = trim( (string) $request->get_param( 'q' ) );
                $page    = max( 1, (int) $request->get_param( 'page' ) );
                $per     = min( 100, max( 5, (int) $request->get_param( 'per_page' ) ) );
                $q_norm  = TPP_Date::en_num( $q );
                $all     = array_map( $shape, ( 'tag' === $kind ) ? TPP_Categories::tags() : TPP_Categories::categories() );
                if ( '' !== $q ) {
                        $all = array_values( array_filter( $all, static function ( $c ) use ( $q_norm ) {
                                return false !== mb_stripos( TPP_Date::en_num( (string) $c['label'] ), $q_norm );
                        } ) );
                }
                $total = count( $all );
                $items = array_slice( $all, ( $page - 1 ) * $per, $per );
                return self::ok( array(
                        'kind'     => $kind,
                        'items'    => $items,
                        'total'    => $total,
                        'page'     => $page,
                        'per_page' => $per,
                ) );
        }

        /** POST categories {kind, label} */
        public function categories_add( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $id = TPP_Categories::add( array(
                        'kind'  => isset( $body['kind'] ) ? (string) $body['kind'] : 'category',
                        'label' => isset( $body['label'] ) ? (string) $body['label'] : '',
                ) );
                if ( is_wp_error( $id ) ) {
                        return self::err( $id->get_error_code(), $id->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'added', 'id' => (int) $id ), 201 );
        }

        /** PUT categories/{id} {label, sort_order} */
        public function categories_update( WP_REST_Request $request ) {
                $id   = (int) $request['id'];
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = TPP_Categories::update( $id, $body );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'updated', 'id' => $id ) );
        }

        /** DELETE categories/{id} — دسته در حال استفاده حذف نمی‌شود؛ تگ از سرویس‌ها جدا و حذف می‌شود */
        public function categories_delete( WP_REST_Request $request ) {
                $id     = (int) $request['id'];
                $result = TPP_Categories::delete( $id );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'deleted', 'id' => $id ) );
        }

        /* ------------------- 1.20.0 — بازبینی ------------------- */

        /** GET review/queue — سرویس‌های ارجاع‌شده با دسته «ثبت جهت بازبینی» + دسته‌ها/تگ‌ها برای مودال تعیین دسته */
        public function review_queue( WP_REST_Request $request ) {
                $data = TPP_Review::queue();
                // فهرست دسته‌ها/تگ‌ها برای انتخاب بازبین (دسته ارجاعی هم موجود است)
                $shape = static function ( $c ) {
                        return array( 'id' => (int) $c['id'], 'label' => (string) $c['label'], 'is_review' => ! empty( $c['is_review'] ) );
                };
                $data['categories'] = array_map( $shape, TPP_Categories::categories() );
                $data['tags']       = array_map( $shape, TPP_Categories::tags() );
                return self::ok( $data );
        }

        /** POST review/assign {service_id, category, tags} — تعیین دسته/تگ سرویس ارجاعی توسط بازبین */
        public function review_assign( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $service_id = isset( $body['service_id'] ) ? (int) $body['service_id'] : 0;
                if ( $service_id <= 0 ) {
                        return self::err( 'tpp_bad_service', 'شناسه سرویس الزامی است.', 400 );
                }
                $category = $body['category'] ?? null;
                $tags     = $body['tags'] ?? null;
                $result = TPP_Review::assign( $service_id, $category, $tags, get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                if ( is_array( $result ) && ! empty( $result['status'] ) && 'nochange' === $result['status'] ) {
                        return self::err( 'tpp_no_change', 'تغییری ارسال نشده است.', 400 );
                }
                return self::ok( array( 'status' => 'assigned', 'id' => $service_id, 'result' => $result ) );
        }

        /** GET review/changes ?date|from&to[&user_id][&status] — سجل اقدامات نصاب‌ها بر حسب روز */
        public function review_changes( WP_REST_Request $request ) {
                return self::ok( TPP_Review::changes( array(
                        'date'    => (string) $request->get_param( 'date' ),
                        'from'    => (string) $request->get_param( 'from' ),
                        'to'      => (string) $request->get_param( 'to' ),
                        'user_id' => (int) $request->get_param( 'user_id' ),
                        'status'  => (string) $request->get_param( 'status' ),
                ) ) );
        }

        /** POST review/keep {id} — علامت «نگه‌داشته شد» (تغییر باقی می‌ماند) */
        public function review_keep( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $id = isset( $body['id'] ) ? (int) $body['id'] : 0;
                $result = TPP_Review::keep( $id, get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'kept', 'id' => $id ) );
        }

        /** POST review/revert {id} — بازگردانی وضعیت قبل از تغییر */
        public function review_revert( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $id = isset( $body['id'] ) ? (int) $body['id'] : 0;
                $result = TPP_Review::revert( $id, get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                    return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( is_array( $result ) ? $result : array( 'status' => 'reverted', 'id' => $id ) );
        }

        /** POST workreport {date, content, service_id} — افزودن قلم دستی به گزارش روز جاری کاربر */
        public function workreport_add( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $user_id = get_current_user_id();
                // متن ارسالی یا خودش قلم گزارش است یا با انتخاب سرویس طبق قالب ساخته می‌شود
                $content = isset( $body['content'] ) ? (string) $body['content'] : '';
                $service_id = isset( $body['service_id'] ) ? (int) $body['service_id'] : 0;
                $prefix = isset( $body['prefix'] ) ? trim( (string) $body['prefix'] ) : '';
                if ( '' === $content && $service_id > 0 && '' !== $prefix ) {
                        // افزودن دستی با سرویس — قالب استاندارد ساخته شود (رفع مشکل/تحویل سرویس/متن اقدام)
                        $line = TPP_Workreport::build_line( $service_id, $prefix );
                        if ( is_wp_error( $line ) ) {
                                return self::err( $line->get_error_code(), $line->get_error_message(), 400 );
                        }
                        $content = $line;
                }
                $id = TPP_Workreport::add( $user_id, isset( $body['date'] ) ? (string) $body['date'] : '', $content, $service_id );
                if ( is_wp_error( $id ) ) {
                        return self::err( $id->get_error_code(), $id->get_error_message(), 400 );
                }
                $item = TPP_Workreport::get_item( $id );
                return self::ok( array( 'status' => 'added', 'item' => TPP_Workreport::shape_item( $item ) ), 201 );
        }

        /** POST workreport/from_activity {src: view|change, row_id, date} — تبدیل ردیف فعالیت روز به قلم گزارش */
        public function workreport_add_activity( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $user_id = get_current_user_id();
                $item = TPP_Workreport::add_from_activity(
                        $user_id,
                        isset( $body['src'] ) ? (string) $body['src'] : 'change',
                        isset( $body['row_id'] ) ? (int) $body['row_id'] : 0,
                        isset( $body['date'] ) ? (string) $body['date'] : ''
                );
                if ( is_wp_error( $item ) ) {
                        return self::err( $item->get_error_code(), $item->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'added', 'item' => $item ), 201 );
        }

        /** POST workreport/line {service_id, prefix} — فقط ساخت متن قالب (پیش‌نمایش؛ چیزی ثبت نمی‌شود) */
        public function workreport_line( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $line = TPP_Workreport::build_line(
                        isset( $body['service_id'] ) ? (int) $body['service_id'] : 0,
                        isset( $body['prefix'] ) ? (string) $body['prefix'] : ''
                );
                if ( is_wp_error( $line ) ) {
                        return self::err( $line->get_error_code(), $line->get_error_message(), 400 );
                }
                return self::ok( array( 'line' => $line ) );
        }

        /** PUT workreport/{id} {content[, service_id]} — ویرایش قلم (فقط مالک) */
        public function workreport_update( WP_REST_Request $request ) {
                $id   = (int) $request['id'];
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = TPP_Workreport::update_item(
                        $id,
                        get_current_user_id(),
                        isset( $body['content'] ) ? (string) $body['content'] : null,
                        array_key_exists( 'service_id', $body ) ? (int) $body['service_id'] : null
                );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                $item = TPP_Workreport::get_item( $id );
                return self::ok( array( 'status' => 'updated', 'item' => TPP_Workreport::shape_item( $item ) ) );
        }

        /** DELETE workreport/{id} — حذف قلم (فقط مالک) */
        public function workreport_delete( WP_REST_Request $request ) {
                $id = (int) $request['id'];
                $result = TPP_Workreport::delete_item( $id, get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'deleted', 'id' => $id ) );
        }

        public function create_service( WP_REST_Request $request ) {
                $body   = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = tpp()->services()->create( $body, array(
                        'user_id' => get_current_user_id(),
                        'source'  => 'online',
                        'op_id'   => isset( $body['op_id'] ) ? $body['op_id'] : '',
                ) );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                $row = tpp()->services()->get( $result['id'] );
                $result['data'] = $row ? tpp()->services()->shape_row( $row ) : null;
                return self::ok( $result, 201 );
        }

        /**
         * ۱.۱۳.۰ — تغییر گروهی/تکی پیشرفت دایری + وضعیت خرابی + فیلدهای سرویس.
         * بدنه: {ids: [1,2,…], progress: {mode, step|steps, skipped_policy, failures|failure}, service: {slug: val}, dry_run}
         * mode: '' (بدون تغییر) | up_to (تنظیم تا مرحله — آبشاری) | add | remove | clear
         * ۱.۱۴.۰ — progress.failures آرایه خرابی‌ها (چند خرابی همزمان؛ آرایه خالی = رفع همه)
         */
        public function bulk_services( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $ids = array();
                if ( isset( $body['ids'] ) && is_array( $body['ids'] ) ) {
                        foreach ( $body['ids'] as $id ) {
                                $ids[] = (int) $id;
                        }
                }
                if ( empty( $ids ) ) {
                        return self::err( 'tpp_no_ids', 'فهرست شناسه سرویس‌ها (ids) خالی است.', 400 );
                }
                $opts = array();
                foreach ( array( 'mode', 'step', 'steps', 'skipped_policy', 'failure', 'failures', 'service', 'dry_run' ) as $k ) {
                        if ( array_key_exists( $k, $body ) ) {
                                $opts[ $k ] = $body[ $k ];
                        }
                }
                if ( isset( $body['progress'] ) && is_array( $body['progress'] ) ) {
                        $opts = array_merge( $opts, $body['progress'] );
                }
                if ( empty( $opts ) ) {
                        return self::err( 'tpp_no_ops', 'هیچ عملیاتی مشخص نشده است (progress یا service).', 400 );
                }
                $result = tpp()->services()->apply_bulk( $ids, $opts, array(
                        'user_id' => get_current_user_id(),
                        'source'  => 'bulk',
                ) );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        public function get_service( WP_REST_Request $request ) {
                $id     = (int) $request['id'];
                $row    = tpp()->services()->get( $id );
                if ( ! $row ) {
                        return self::err( 'tpp_not_found', 'سرویس یافت نشد.', 404 );
                }
                $user_id = get_current_user_id();
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $payload = tpp()->services()->shape_row( $row, $visible );
                // تاریخچه سرویس
                if ( TPP_Capabilities::user_can( $user_id, 'tpp_view_history' ) ) {
                        $history = TPP_History::for_entity( 'service', $id, 100 );
                        foreach ( $history as &$h ) {
                                $h['changes'] = TPP_History::filter_changes( $h['changes'], $visible );
                        }
                        unset( $h );
                        $payload['history'] = $history;
                }
                // سرویس‌های دیگر همین آدرس
                $siblings = array();
                foreach ( tpp()->services()->address_services( (int) $row['address_id'] ) as $svc ) {
                        if ( (int) $svc['id'] !== $id ) {
                                $siblings[] = tpp()->services()->shape_row( $svc, $visible );
                        }
                }
                $payload['siblings'] = $siblings;
                // ۱.۱۰.۰ — ثبت بازدید + آخرین بازدیدها (برای کاربرانی که اجازه گزارش فعالیت دارند)
                if ( $user_id ) {
                        TPP_Activity::log_view( $user_id, $id, 'service' );
                        if ( TPP_Capabilities::user_can( $user_id, 'tpp_view_activity' ) ) {
                                $payload['recent_views'] = TPP_Activity::recent_views( $id, 8 );
                        }
                }
                return self::ok( $payload );
        }

        public function update_service( WP_REST_Request $request ) {
                $id   = (int) $request['id'];
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = tpp()->services()->update( $id, $body, array(
                        'user_id'      => get_current_user_id(),
                        'source'       => 'online',
                        'base_version' => isset( $body['base_version'] ) ? (int) $body['base_version'] : 0,
                        'op_id'        => isset( $body['op_id'] ) ? $body['op_id'] : '',
                ) );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                $row = tpp()->services()->get( $id );
                $result['data'] = $row ? tpp()->services()->shape_row( $row ) : null;
                return self::ok( $result );
        }

        public function delete_service( WP_REST_Request $request ) {
                $id   = (int) $request['id'];
                $body = $request->get_json_params();
                $result = tpp()->services()->delete( $id, array(
                        'user_id' => get_current_user_id(),
                        'source'  => 'online',
                        'op_id'   => is_array( $body ) && isset( $body['op_id'] ) ? $body['op_id'] : '',
                ) );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        public function get_address( WP_REST_Request $request ) {
                $id      = (int) $request['id'];
                $address = tpp()->services()->get_address( $id );
                if ( ! $address ) {
                        return self::err( 'tpp_not_found', 'آدرس یافت نشد.', 404 );
                }
                $user_id = get_current_user_id();
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $payload = tpp()->services()->shape_address( $address, $visible );
                $services = array();
                foreach ( tpp()->services()->address_services( $id ) as $svc ) {
                        $services[] = tpp()->services()->shape_row( $svc, $visible );
                }
                $payload['services'] = $services;
                if ( TPP_Capabilities::user_can( $user_id, 'tpp_view_history' ) ) {
                        $payload['history'] = array_map( function( $h ) use ( $visible ) {
                                $h['changes'] = TPP_History::filter_changes( $h['changes'], $visible );
                                return $h;
                        }, TPP_History::address_timeline( $id, 200 ) );
                }
                return self::ok( $payload );
        }

        public function suggest_addresses( WP_REST_Request $request ) {
                $query = (string) $request->get_param( 'query' );
                if ( mb_strlen( $query ) < 2 ) {
                        return self::ok( array() );
                }
                return self::ok( tpp()->services()->suggest_addresses( $query ) );
        }

        /* -------------------- هندلرها: کشویی جستجوی اجاکسی + موارد تکراری (۱.۹.۰) -------------------- */

        /** مقادیر یکتای یک فیلد برای کشویی فیلترها — ?field=f_center_name&q=…&limit=… */
        public function field_values( WP_REST_Request $request ) {
                $slug   = trim( (string) $request->get_param( 'field' ) );
                $result = tpp()->services()->distinct_values( $slug, (string) $request->get_param( 'q' ), (int) $request->get_param( 'limit' ) );
                if ( is_wp_error( $result ) ) {
                        $data   = $result->get_error_data();
                        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
                        return self::err( $result->get_error_code(), $result->get_error_message(), $status );
                }
                return self::ok( array( 'field' => $slug, 'values' => $result ) );
        }

        /** فهرست گروه‌های سرویس تکراری — ?fields=f_phone,…&include_dismissed=1 */
        public function duplicates_list( WP_REST_Request $request ) {
                $result = tpp()->services()->find_duplicates( array(
                        'fields'            => (string) $request->get_param( 'fields' ),
                        'include_dismissed' => (int) $request->get_param( 'include_dismissed' ) ? 1 : 0,
                ) );
                return self::ok( $result );
        }

        /** ادغام گروه تکراری — {primary_id: 12, ids: [12, 45, 78], pick: {f_phone: 45, …}} (۱.۱۱.۰ — pick: حل تعارض فیلدی) */
        public function duplicates_merge( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $primary_id = (int) ( $body['primary_id'] ?? 0 );
                $ids        = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_map( 'intval', $body['ids'] ) : array();
                if ( ! $primary_id || empty( $ids ) ) {
                        return self::err( 'tpp_bad_merge', 'سرویس اصلی و فهرست سرویس‌های ادغام لازم است.', 400 );
                }
                $pick = array();
                if ( isset( $body['pick'] ) && is_array( $body['pick'] ) ) {
                        foreach ( $body['pick'] as $slug => $src_id ) {
                                if ( preg_match( '/^[a-z0-9_]{1,64}$/', (string) $slug ) ) {
                                        $pick[ (string) $slug ] = (int) $src_id;
                                }
                        }
                }
                $result = tpp()->services()->merge_duplicates( $primary_id, $ids, array(
                        'user_id' => get_current_user_id(),
                        'pick'    => $pick,
                ) );
                if ( is_wp_error( $result ) ) {
                        $data   = $result->get_error_data();
                        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
                        return self::err( $result->get_error_code(), $result->get_error_message(), $status );
                }
                return self::ok( $result );
        }

        /** ادغام گروهی همه گروه‌های بدون تناقض — {keys?: ['12_45', …]} (خالی = همه) */
        public function duplicates_merge_safe( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $keys = isset( $body['keys'] ) && is_array( $body['keys'] ) ? array_map( 'strval', $body['keys'] ) : array();
                $result = tpp()->services()->merge_safe_duplicates( $keys, array( 'user_id' => get_current_user_id() ) );
                return self::ok( $result );
        }

        /** علامت‌گذاری گروه به‌عنوان «باقی می‌ماند به حالت فعلی» — {ids: [12, 45]} */
        public function duplicates_dismiss( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $ids = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_map( 'intval', $body['ids'] ) : array();
                if ( count( $ids ) < 2 ) {
                        return self::err( 'tpp_bad_group', 'گروه تکراری نامعتبر است (حداقل ۲ سرویس).', 400 );
                }
                $key = tpp()->services()->dismiss_duplicate_group( $ids );
                return self::ok( array( 'status' => 'dismissed', 'key' => $key ) );
        }

        /** بازگردانی گروه/گروه‌های علامت‌خورده — {key: '12_45'} یا {key: 'all'} */
        public function duplicates_restore( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $key    = isset( $body['key'] ) ? (string) $body['key'] : 'all';
                $result = tpp()->services()->restore_duplicate_groups( $key );
                return self::ok( array( 'status' => 'restored', 'restored' => (int) $result ) );
        }

        /* -------------------- هندلرها: تاریخچه -------------------- */

        public function history( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                if ( ! TPP_Capabilities::user_can( $user_id, 'tpp_view_all_history' ) ) {
                        $request->set_param( 'user_id', $user_id ); // فقط تاریخچه خودش
                }
                $args = array(
                        'user_id'    => $request->get_param( 'user_id' ) ? (int) $request->get_param( 'user_id' ) : 0,
                        'action'     => (string) $request->get_param( 'action' ),
                        'entity'     => (string) $request->get_param( 'entity' ),
                        'address_id' => (int) $request->get_param( 'address_id' ),
                        'conflict'   => (int) $request->get_param( 'conflict' ),
                );
                if ( ! empty( $args['user_id'] ) && ! TPP_Capabilities::user_can( $user_id, 'tpp_view_all_history' ) ) {
                        $args['user_id'] = $user_id;
                }
                $page    = max( 1, (int) $request->get_param( 'page' ) );
                $per     = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 30 ) );
                $result  = TPP_History::global_log( $args, $page, $per );
                $visible = TPP_Capabilities::visible_fields( $user_id );
                foreach ( $result['rows'] as &$h ) {
                        $h['changes'] = TPP_History::filter_changes( $h['changes'], $visible );
                }
                unset( $h );
                return self::ok( $result );
        }

        public function restore_history( WP_REST_Request $request ) {
                $result = TPP_History::restore( (int) $request['id'], get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        /** حذف تکی/گروهی رکوردهای تاریخچه — {ids:[1,2,3]} */
        public function history_delete( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $ids = isset( $body['ids'] ) && is_array( $body['ids'] ) ? $body['ids'] : array();
                if ( empty( $ids ) ) {
                        return self::err( 'tpp_no_ids', 'شناسه‌ای برای حذف ارسال نشده است.', 400 );
                }
                $deleted = TPP_History::delete_entries( $ids );
                return self::ok( array( 'status' => 'deleted', 'deleted' => (int) $deleted ) );
        }

        /* -------------------- هندلرها: همگام‌سازی -------------------- */

        public function sync( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                $ops  = isset( $body['ops'] ) && is_array( $body['ops'] ) ? $body['ops'] : array();
                if ( empty( $ops ) ) {
                        return self::ok( array( 'results' => array(), 'summary' => array( 'applied' => 0, 'conflicted' => 0, 'failed' => 0 ) ) );
                }
                $result = tpp()->sync()->handle_batch( $ops, get_current_user_id() );
                return self::ok( $result );
        }

        /* -------------------- هندلرها: ایمپورت/خروجی -------------------- */

        public function import_preview( WP_REST_Request $request ) {
                $files = $request->get_file_params();
                if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                        return self::err( 'tpp_no_file', 'فایلی ارسال نشده است.', 400 );
                }
                $file = $files['file'];
                if ( ! empty( $file['error'] ) ) {
                        $hint = 1 === (int) $file['error'] ? ' (حجم فایل از حد مجاز آپلود سرور بیشتر است — در تنظیمات، حجم کمتر یا فشرده‌تر انتخاب کنید)' : '';
                        return self::err( 'tpp_upload_error', 'خطا در آپلود فایل (کد ' . (int) $file['error'] . ').' . $hint, 400 );
                }
                try {
                        $result = tpp()->import()->preview( $file['tmp_name'], $file['name'], get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_import_error', 'خطا در پردازش فایل اکسل: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        /** تحلیل تشابه‌ها: ردیف‌های مشابه با آدرس‌های/کد ملی‌های ثبت‌شده */
        public function import_analyze( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $session_id = (string) ( $body['session_id'] ?? '' );
                if ( '' === $session_id ) {
                        return self::err( 'tpp_no_session', 'شناسه نشست ایمپورت ارسال نشده است.', 400 );
                }
                try {
                        $result = tpp()->import()->analyze( $session_id, $body, get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_analyze_error', 'خطا در تحلیل تشابه‌ها: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        public function import_commit( WP_REST_Request $request ) {
                $body   = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $session = (string) ( $body['session_id'] ?? '' );
                if ( '' === $session ) {
                        return self::err( 'tpp_no_session', 'شناسه نشست ایمپورت ارسال نشده است.', 400 );
                }
                try {
                        $result = tpp()->import()->commit( $session, $body, get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_import_error', 'خطا در ثبت ایمپورت: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        private function export_args( WP_REST_Request $request ) {
                $filters = $request->get_param( 'filters' );
                if ( is_string( $filters ) ) {
                        $decoded = json_decode( $filters, true );
                        $filters = is_array( $decoded ) ? $decoded : array();
                }
                return array(
                        'query'    => (string) $request->get_param( 'query' ),
                        'filters'  => is_array( $filters ) ? $filters : array(),
                        'upd_from' => (string) $request->get_param( 'upd_from' ),
                        'upd_to'   => (string) $request->get_param( 'upd_to' ),
                        // ۱.۱۲.۰ — فیلتر وضعیت دایری در خروجی‌ها هم اعمال می‌شود
                        'progress_status'     => sanitize_key( (string) $request->get_param( 'progress_status' ) ),
                        'progress_step'       => sanitize_key( (string) $request->get_param( 'progress_step' ) ),
                        'progress_step_state' => sanitize_key( (string) $request->get_param( 'progress_step_state' ) ),
                        // ۱.۲۱.۰ رفع باگ «خروجی همیشه کل سرویس‌ها»: فیلتر دسته/تگ هم مثل صفحه جستجو اعمال شود
                        'category' => (string) $request->get_param( 'category' ),
                        'tags'     => (string) $request->get_param( 'tags' ),
                );
        }

        public function export_xlsx( WP_REST_Request $request ) {
                $result = tpp()->export()->xlsx( $this->export_args( $request ), get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 500 );
                }
                TPP_XLSX_Writer::download( $result['filename'], $result['content'] );
        }

        /** خروجی PDF بومی (فونت فارسی تعبیه‌شده — بدون نیاز به چاپ مرورگر) */
        public function export_pdf( WP_REST_Request $request ) {
                $result = tpp()->export()->pdf( $this->export_args( $request ), get_current_user_id(), 'گزارش سرویس‌ها' );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 500 );
                }
                TPP_PDF::download( $result['filename'], $result['content'] );
        }

        /** پشتیبان کامل SQL */
        public function backup_sql( WP_REST_Request $request ) {
                $sql = tpp()->export()->sql_dump();
                TPP_Zip::download( 'tpp-database-' . gmdate( 'Ymd-His' ) . '.sql', $sql, 'application/sql; charset=utf-8' );
        }

        /** پشتیبان کامل ZIP (دیتابیس + تاریخچه + فایل‌های افزونه) */
        public function backup_zip( WP_REST_Request $request ) {
                $result = tpp()->export()->backup_zip();
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 500 );
                }
                TPP_Zip::download( $result['filename'], $result['content'], 'application/zip' );
        }

        public function export_print( WP_REST_Request $request ) {
                $html = tpp()->export()->print_html( $this->export_args( $request ), get_current_user_id(), 'گزارش سرویس‌ها' );
                if ( is_wp_error( $html ) ) {
                        return self::err( $html->get_error_code(), $html->get_error_message(), 500 );
                }
                nocache_headers();
                header( 'Content-Type: text/html; charset=utf-8' );
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
                exit;
        }

        public function template( WP_REST_Request $request ) {
                $result = tpp()->export()->template();
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 500 );
                }
                TPP_XLSX_Writer::download( $result['filename'], $result['content'] );
        }

        /** پشتیبان کامل JSON — انتخاب محتوا با پارامترهای with_history/with_activity/with_sms_log */
        public function backup( WP_REST_Request $request ) {
                $flags = array(
                        'with_history'  => null === $request->get_param( 'with_history' ) ? 1 : (int) $request->get_param( 'with_history' ),
                        'with_activity' => (int) $request->get_param( 'with_activity' ),
                        'with_sms_log'  => (int) $request->get_param( 'with_sms_log' ),
                );
                $json = tpp()->export()->backup( $flags );
                TPP_Zip::download( 'tpp-backup-' . gmdate( 'Ymd-His' ) . '.json', $json, 'application/json; charset=utf-8' );
        }

        /** اطلاعات پشتیبان (تعداد/حجم تقریبی/محدودیت‌های PHP) برای نمایش قبل از عملیات */
        public function backup_info( WP_REST_Request $request ) {
                return self::ok( tpp()->export()->backup_info() );
        }

        /** بازیابی مستقیم (فایل‌های کوچک) — نتیجه شامل خلاصه شمارش/خطاها است */
        public function restore( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                $json = '';
                if ( isset( $body['json'] ) && is_string( $body['json'] ) ) {
                        $json = $body['json'];
                } elseif ( ! empty( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $json = (string) file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.Security.NonceVerification
                }
                if ( '' === $json ) {
                        return self::err( 'tpp_no_backup', 'فایل پشتیبان ارسال نشده است.', 400 );
                }
                $result = tpp()->export()->restore( $json );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        /* ---------- آپلود تکه‌ای پشتیبان (فایل‌های بزرگ — بدون محدودیت آپلود سرور) ---------- */

        /** مسیر فایل موقت نشست بازیابی */
        private static function restore_part_path( $token ) {
                $uploads = wp_upload_dir();
                if ( empty( $uploads['basedir'] ) ) {
                        return null;
                }
                return rtrim( $uploads['basedir'], '/\\' ) . '/tpp-restore-' . $token . '.part';
        }

        /** پاک‌سازی فایل‌های موکیت قدیمی (بیش از ۲ ساعت) */
        private static function cleanup_restore_parts() {
                $uploads = wp_upload_dir();
                if ( empty( $uploads['basedir'] ) ) {
                        return;
                }
                $files = (array) glob( rtrim( $uploads['basedir'], '/\\' ) . '/tpp-restore-*.part' );
                foreach ( $files as $f ) {
                        if ( is_file( $f ) && @filemtime( $f ) < time() - 2 * HOUR_IN_SECONDS ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                                @unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        }
                }
        }

        private static function restore_meta( WP_REST_Request $request ) {
                $body  = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $token = isset( $body['token'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) $body['token'] ) : '';
                if ( strlen( (string) $token ) < 16 ) {
                        return array( null, new WP_Error( 'tpp_bad_token', 'شناسه نشست بازیابی نامعتبر است.', array( 'status' => 400 ) ) );
                }
                $meta = get_transient( 'tpp_restore_' . $token );
                if ( ! is_array( $meta ) || empty( $meta['path'] ) ) {
                        return array( null, new WP_Error( 'tpp_restore_expired', 'نشست بازیابی یافت نشد یا منقضی شده است — دوباره شروع کنید.', array( 'status' => 400 ) ) );
                }
                return array( array( $token, $meta ), null );
        }

        /** تبدیل WP_Error داخلی به پاسخ خطای REST */
        private static function err_from( $err ) {
                $status = 400;
                if ( is_wp_error( $err ) ) {
                        $data = $err->get_error_data();
                        if ( is_array( $data ) && isset( $data['status'] ) ) {
                                $status = (int) $data['status'];
                        }
                        return self::err( $err->get_error_code(), $err->get_error_message(), $status );
                }
                return self::err( 'tpp_error', 'خطای نامشخص.', 500 );
        }

        /** شروع آپلود تکه‌ای — {size, name} → {token, chunk_size} */
        public function restore_begin( WP_REST_Request $request ) {
                $body  = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $size = (int) ( $body['size'] ?? 0 );
                $name = isset( $body['name'] ) ? sanitize_file_name( (string) $body['name'] ) : 'backup.json';
                if ( $size <= 0 ) {
                        return self::err( 'tpp_bad_size', 'حجم فایل پشتیبان نامعتبر است.', 400 );
                }
                if ( $size > 512 * MB_IN_BYTES ) {
                        return self::err( 'tpp_too_big', 'فایل پشتیبان بزرگ‌تر از حد مجاز است (۵۱۲ مگابایت).', 400 );
                }
                $token = wp_generate_password( 32, false );
                $path  = self::restore_part_path( $token );
                if ( ! $path || false === @file_put_contents( $path, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        return self::err( 'tpp_no_tmp', 'محل ذخیره موقت (پوشه uploads) قابل نوشتن نیست — دسترسی‌های پوشه را بررسی کنید.', 500 );
                }
                set_transient( 'tpp_restore_' . $token, array(
                        'size'     => $size,
                        'name'     => $name,
                        'path'     => $path,
                        'chunks'   => 0,
                        'received' => 0,
                        'user_id'  => get_current_user_id(),
                ), 6 * HOUR_IN_SECONDS );
                self::cleanup_restore_parts();
                return self::ok( array( 'token' => $token, 'chunk_size' => 300000, 'size' => $size ) );
        }

        /** یک تکه — {token, index, data} → {received, chunks} */
        public function restore_chunk( WP_REST_Request $request ) {
                list( $pair, $err ) = self::restore_meta( $request );
                if ( $err ) {
                        return self::err_from( $err );
                }
                list( $token, $meta ) = $pair;
                $body  = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $index = (int) ( $body['index'] ?? -1 );
                $data  = isset( $body['data'] ) ? (string) $body['data'] : '';
                if ( strlen( $data ) > 2 * MB_IN_BYTES ) {
                        return self::err( 'tpp_big_chunk', 'اندازه تکه بیش از حد مجاز است (۲ مگابایت).', 400 );
                }
                if ( $index !== (int) $meta['chunks'] ) {
                        return self::err( 'tpp_restore_order', 'ترتیب تکه‌ها نامعتبر است (منتظر تکه ' . ( (int) $meta['chunks'] + 1 ) . ' بود).', 400 );
                }
                if ( false === @file_put_contents( $meta['path'], $data, FILE_APPEND ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        return self::err( 'tpp_write_failed', 'نوشتن تکه در محل موقت ناموفق بود.', 500 );
                }
                $meta['chunks']   = (int) $meta['chunks'] + 1;
                $meta['received'] = (int) $meta['received'] + strlen( $data );
                set_transient( 'tpp_restore_' . $token, $meta, 6 * HOUR_IN_SECONDS );
                return self::ok( array( 'received' => $meta['received'], 'chunks' => $meta['chunks'] ) );
        }

        /** پایان آپلود → اجرای بازیابی + خلاصه نتیجه — {token} */
        public function restore_finish( WP_REST_Request $request ) {
                list( $pair, $err ) = self::restore_meta( $request );
                if ( $err ) {
                        return self::err_from( $err );
                }
                list( $token, $meta ) = $pair;
                $content = (string) @file_get_contents( $meta['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                @unlink( $meta['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                delete_transient( 'tpp_restore_' . $token );
                if ( false === $content || '' === $content ) {
                        return self::err( 'tpp_empty_upload', 'محتوای فایل پشتیبان دریافت نشد — دوباره تلاش کنید.', 400 );
                }
                $result = tpp()->export()->restore( $content );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        /** لغو نشست آپلود تکه‌ای — {token} */
        public function restore_cancel( WP_REST_Request $request ) {
                list( $pair, $err ) = self::restore_meta( $request );
                if ( $err ) {
                        return self::ok( array( 'status' => 'nothing' ) ); // نشستی نبود — بی‌خطر
                }
                list( $token, $meta ) = $pair;
                @unlink( $meta['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                delete_transient( 'tpp_restore_' . $token );
                return self::ok( array( 'status' => 'cancelled' ) );
        }

        /* ---------- ۱.۱۵.۰ — پشتیبان‌های ذخیره‌شده روی سرور (پوشه افزونه) ---------- */

        /** نام فایل درخواستی + اعتبارسنجی — array(path, filename) یا WP_Error */
        private static function stored_backup_file( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $filename = isset( $body['filename'] ) ? (string) $body['filename'] : (string) $request->get_param( 'filename' );
                $filename = basename( $filename ); // هیچ مسیری پذیرفته نمی‌شود
                $path     = TPP_Backup::item_path( $filename );
                if ( ! $path ) {
                        return new WP_Error( 'tpp_backup_not_found', 'پشتیبانی با این نام روی سرور یافت نشد.', array( 'status' => 404 ) );
                }
                return array( $path, $filename );
        }

        /** فهرست پشتیبان‌های ذخیره‌شده + وضعیت محل ذخیره */
        public function backup_stored_list( WP_REST_Request $request ) {
                return self::ok( array(
                        'info'  => TPP_Backup::info(),
                        'items' => TPP_Backup::items(),
                ) );
        }

        /** گرفتن پشتیبان تازه و ذخیره روی سرور (دستی) */
        public function backup_stored_create( WP_REST_Request $request ) {
                try {
                        $meta = TPP_Backup::create( 'manual', get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_backup_error', 'خطا در پشتیبان‌گیری: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $meta ) ) {
                        return self::err( $meta->get_error_code(), $meta->get_error_message(), 500 );
                }
                return self::ok( $meta, 201 );
        }

        /** ۱.۲۱.۰ — بروزآوری دیتابیس: پشتیبان خودکار + انتقال ستون‌های یتیم به «توضیحات متفرقه» + حذف ستون‌ها */
        public function tools_db_update( WP_REST_Request $request ) {
                try {
                        // پشتیبان کامل خودکار پیش از هر تغییر ساختاری — مثل جریان اصلاح اعداد
                        $backup = TPP_Backup::create( 'pre_db_update', get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_backup_error', 'پشتیبان‌گیری خودکار ناموفق بود: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $backup ) ) {
                        return self::err( $backup->get_error_code(), 'پشتیبان‌گیری خودکار ناموفق بود: ' . $backup->get_error_message(), 500 );
                }
                try {
                        $report = TPP_Fields::db_update_run( get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_db_update_error', 'خطا در بروزآوری دیتابیس: ' . $e->getMessage(), 500 );
                }
                $report['backup'] = $backup;
                $report['orphan_after'] = TPP_Fields::orphan_columns();
                return self::ok( $report );
        }

        /** ۱.۱۸.۰ — اصلاح اعداد فارسی/عربی به انگلیسی در همه فیلدها (با پشتیبان خودکار پیش از اجرا) */
        public function numbers_fix( WP_REST_Request $request ) {
                $dry_run = ! empty( $request->get_param( 'dry_run' ) );
                try {
                        $result = TPP_Numfix::run( $dry_run, get_current_user_id() );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_numfix_error', 'خطا در اصلاح اعداد: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 500 );
                }
                return self::ok( $result );
        }

        /** بازگردانی یک‌کلیکی از پشتیبان ذخیره‌شده — {filename} */
        public function backup_stored_restore( WP_REST_Request $request ) {
                $found = self::stored_backup_file( $request );
                if ( is_wp_error( $found ) ) {
                        return self::err_from( $found );
                }
                list( , $filename ) = $found;
                try {
                        $result = TPP_Backup::restore_item( $filename );
                } catch ( Throwable $e ) {
                        return self::err( 'tpp_restore_error', 'خطا در بازیابی: ' . $e->getMessage(), 500 );
                }
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        /** حذف یک پشتیبان ذخیره‌شده — {filename} */
        public function backup_stored_delete( WP_REST_Request $request ) {
                $found = self::stored_backup_file( $request );
                if ( is_wp_error( $found ) ) {
                        return self::err_from( $found );
                }
                list( , $filename ) = $found;
                $result = TPP_Backup::delete_item( $filename );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result );
        }

        /** دانلود یک پشتیبان ذخیره‌شده — ?filename= */
        public function backup_stored_download( WP_REST_Request $request ) {
                $found = self::stored_backup_file( $request );
                if ( is_wp_error( $found ) ) {
                        return self::err_from( $found );
                }
                list( , $filename ) = $found;
                $err = TPP_Backup::download_item( $filename );
                if ( is_wp_error( $err ) ) {
                        return self::err( $err->get_error_code(), $err->get_error_message(), 404 );
                }
                return self::ok( array( 'status' => 'done' ) ); // هرگز اجرا نمی‌شود — download خروجی مستقیم دارد
        }

        /* -------------------- هندلرها: فیلدها -------------------- */

        public function fields_list( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                if ( ! TPP_Capabilities::user_can( $user_id, 'tpp_manage_fields' ) ) {
                        // کاربر عادی: فقط فیلدهای قابل مشاهده خودش
                        $visible = TPP_Capabilities::visible_fields( $user_id );
                        $out = array();
                        foreach ( TPP_Fields::all() as $f ) {
                                if ( empty( $visible[ $f['slug'] ] ) ) {
                                        continue;
                                }
                                $out[] = array_intersect_key( $f, array_flip( array( 'id', 'group_key', 'slug', 'label', 'field_type', 'is_required', 'is_searchable', 'is_sensitive', 'options', 'sort_order' ) ) );
                        }
                        return self::ok( $out );
                }
                return self::ok( array_map( function( $f ) {
                        return array_intersect_key( $f, array_flip( array( 'id', 'group_key', 'slug', 'label', 'field_type', 'is_required', 'is_searchable', 'is_sensitive', 'options', 'sort_order' ) ) );
                }, TPP_Fields::all() ) );
        }

        public function fields_add( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = TPP_Fields::add( $body );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( $result, 201 );
        }

        public function fields_update( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = TPP_Fields::update( (int) $request['id'], $body );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'updated' ) );
        }

        public function fields_delete( WP_REST_Request $request ) {
                $archive = (int) $request->get_param( 'archive' );
                $result  = TPP_Fields::delete( (int) $request['id'], empty( $archive ) );
                if ( is_wp_error( $result ) ) {
                        return self::err( $result->get_error_code(), $result->get_error_message(), 400 );
                }
                return self::ok( array( 'status' => 'deleted' ) );
        }

        public function fields_reorder( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                $group = (string) ( $body['group'] ?? '' );
                $order = (array) ( $body['order'] ?? array() );
                if ( ! in_array( $group, array( 'address', 'service' ), true ) || empty( $order ) ) {
                        return self::err( 'tpp_bad_args', 'گروه یا ترتیب نامعتبر است.', 400 );
                }
                TPP_Fields::reorder( $group, $order );
                return self::ok( array( 'status' => 'reordered' ) );
        }

        /* -------------------- هندلرها: نقش‌ها -------------------- */

        public function roles_list() {
                return self::ok( TPP_Capabilities::roles_list() );
        }

        public function roles_add( WP_REST_Request $request ) {
                $body  = (array) $request->get_json_params();
                $label = trim( (string) ( $body['label'] ?? '' ) );
                $slug  = sanitize_key( (string) ( $body['slug'] ?? '' ) );
                if ( '' === $label ) {
                        return self::err( 'tpp_bad_label', 'نام نقش الزامی است.', 400 );
                }
                if ( '' === $slug ) {
                        $slug = 'tpp_' . substr( md5( $label . microtime() ), 0, 8 );
                }
                if ( 0 !== strpos( $slug, 'tpp_' ) ) {
                        $slug = 'tpp_' . $slug;
                }
                if ( get_role( $slug ) ) {
                        return self::err( 'tpp_duplicate', 'نقشی با این نام‌کد وجود دارد.', 400 );
                }
                add_role( $slug, $label, array( 'read' => true, 'tpp_view' => true ) );
                $caps  = (array) ( $body['caps'] ?? array() );
                $fields_map = (array) ( $body['fields'] ?? array() );
                TPP_Capabilities::set_role_caps( $slug, $caps, $fields_map );
                return self::ok( array( 'slug' => $slug, 'label' => $label ), 201 );
        }

        public function roles_update( WP_REST_Request $request ) {
                $slug = sanitize_key( (string) $request['slug'] );
                $role = get_role( $slug );
                if ( ! $role || 0 !== strpos( $slug, 'tpp_' ) ) {
                        return self::err( 'tpp_not_found', 'نقش یافت نشد.', 404 );
                }
                $body = (array) $request->get_json_params();
                $caps = (array) ( $body['caps'] ?? array() );
                $fields_map = (array) ( $body['fields'] ?? array() );
                TPP_Capabilities::set_role_caps( $slug, $caps, $fields_map );
                // به‌روزرسانی برچسب
                if ( ! empty( $body['label'] ) ) {
                        remove_role( $slug );
                        add_role( $slug, sanitize_text_field( (string) $body['label'] ), array( 'read' => true, 'tpp_view' => true ) );
                        TPP_Capabilities::set_role_caps( $slug, $caps, $fields_map );
                }
                return self::ok( array( 'status' => 'updated' ) );
        }

        public function roles_delete( WP_REST_Request $request ) {
                $slug = sanitize_key( (string) $request['slug'] );
                if ( in_array( $slug, array( 'tpp_manager', 'tpp_installer', 'tpp_operator', 'tpp_reporter' ), true ) ) {
                        return self::err( 'tpp_system_role', 'نقش‌های پیش‌فرض قابل حذف نیستند (فقط قابل ویرایش).', 400 );
                }
                if ( ! get_role( $slug ) ) {
                        return self::err( 'tpp_not_found', 'نقش یافت نشد.', 404 );
                }
                remove_role( $slug );
                $all = (array) get_option( TPP_Capabilities::CAPS_OPTION, array() );
                unset( $all[ $slug ] );
                update_option( TPP_Capabilities::CAPS_OPTION, $all, false );
                return self::ok( array( 'status' => 'deleted' ) );
        }

        /* -------------------- هندلرها: تنظیمات و آمار -------------------- */

        public function settings_get() {
                $s = tpp()->settings();
                return self::ok( array(
                        'session_mode'       => $s->get( 'session_mode' ),
                        'session_days'       => (int) $s->get( 'session_days' ),
                        'rows_per_page'      => (int) $s->get( 'rows_per_page' ),
                        'import_max_rows'    => (int) $s->get( 'import_max_rows' ),
                        'import_max_size_mb' => (int) $s->get( 'import_max_size_mb' ),
                        'default_match_key'  => $s->get( 'default_match_key' ),
                        'offline_cache_size' => (int) $s->get( 'offline_cache_size' ),
                        'delete_on_uninstall'=> (int) $s->get( 'delete_on_uninstall' ),
                        'heartbeat_minutes'  => (int) $s->get( 'heartbeat_minutes' ),
                        /* ۱.۱۰.۰ — تاریخچه */
                        'history_daily'      => (int) $s->get( 'history_daily', 1 ),
                        'history_days'       => (int) $s->get( 'history_days', 0 ),
                        'view_history_days'  => (int) $s->get( 'view_history_days', 180 ),
                        'search_history_days'=> (int) $s->get( 'search_history_days', 180 ),
                        'search_dedupe_seconds' => (int) $s->get( 'search_dedupe_seconds', 15 ),
                        'sms_api_key'        => (string) $s->get( 'sms_api_key' ),
                        'sms_line_number'    => (string) $s->get( 'sms_line_number' ),
                        'sms_copy_template'  => (string) $s->get( 'sms_copy_template' ),
                        'sms_copy_default'   => TPP_Settings::default_copy_template(),
                        /* ۱.۱۵.۰ — پشتیبان خودکار ایمپورت */
                        'import_auto_backup' => (int) $s->get( 'import_auto_backup', 1 ),
                        'auto_backup_keep'   => (int) $s->get( 'auto_backup_keep', 10 ),
                        /* ۱.۲۱.۰ — تایید خودکار اقدامات نصاب‌ها (۰ = غیرفعال) */
                        'review_auto_days'   => (int) $s->get( 'review_auto_days', 7 ),
                ) );
        }

        public function settings_update( WP_REST_Request $request ) {
                $body = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $all = tpp()->settings()->update( $body );
                do_action( 'tpp_settings_updated', $all );
                return self::ok( array( 'status' => 'updated', 'settings' => $all ) );
        }

        public function stats() {
                return self::ok( tpp()->services()->stats() );
        }

        /* -------------------- هندلرها: پنل پیامک (SMS.ir) -------------------- */

        /** وضعیت پنل پیامک + موجودی باقیمانده */
        public function sms_status( WP_REST_Request $request ) {
                $sms = tpp()->sms();
                if ( ! $sms->is_configured() ) {
                        return self::ok( array( 'configured' => false, 'credit' => null, 'line' => '', 'message' => 'کلید API تنظیم نشده است.' ) );
                }
                $fresh  = (int) $request->get_param( 'fresh' );
                $credit = $sms->credit( ! empty( $fresh ) );
                if ( is_wp_error( $credit ) ) {
                        return self::err( $credit->get_error_code(), $credit->get_error_message(), 502 );
                }
                return self::ok( array(
                        'configured' => true,
                        'credit'     => $credit,
                        'line'       => $sms->line_number(),
                        'message'    => 'اتصال موفق — موجودی پیامک‌ها دریافت شد.',
                ) );
        }

        /** ارسال پیامک برای یک سرویس */
        public function sms_send( WP_REST_Request $request ) {
                $body        = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $service_id  = (int) ( $body['service_id'] ?? 0 );
                $template_id = (int) ( $body['template_id'] ?? 0 );
                $mobile      = (string) ( $body['mobile'] ?? '' );
                $text        = (string) ( $body['text'] ?? '' );
                $result = tpp()->sms()->send_for_service( $service_id, $template_id, $mobile, $text, get_current_user_id() );
                if ( is_wp_error( $result ) ) {
                        $status = 502;
                        $data   = $result->get_error_data();
                        if ( is_array( $data ) && isset( $data['status'] ) ) {
                                $status = (int) $data['status'];
                        }
                        return self::err( $result->get_error_code(), $result->get_error_message(), $status );
                }
                do_action( 'tpp_sms_sent', $result, $service_id, get_current_user_id() );
                return self::ok( $result );
        }

        /** فهرست قالب‌های پیامک (برای انتخاب هنگام ارسال) */
        public function sms_templates_list() {
                return self::ok( tpp()->sms()->templates() );
        }

        public function sms_templates_add( WP_REST_Request $request ) {
                $body   = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = tpp()->sms()->save_template( $body );
                if ( is_wp_error( $result ) ) {
                        $data   = $result->get_error_data();
                        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
                        return self::err( $result->get_error_code(), $result->get_error_message(), $status );
                }
                return self::ok( $result, 201 );
        }

        public function sms_template_update( WP_REST_Request $request ) {
                $body   = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $result = tpp()->sms()->save_template( $body, (int) $request['id'] );
                if ( is_wp_error( $result ) ) {
                        $data   = $result->get_error_data();
                        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
                        return self::err( $result->get_error_code(), $result->get_error_message(), $status );
                }
                return self::ok( array( 'status' => 'updated' ) );
        }

        public function sms_template_delete( WP_REST_Request $request ) {
                tpp()->sms()->delete_template( (int) $request['id'] );
                return self::ok( array( 'status' => 'deleted' ) );
        }

        /** گزارش آخرین ارسال‌های پیامک */
        public function sms_log_list( WP_REST_Request $request ) {
                $per = (int) $request->get_param( 'per_page' );
                return self::ok( tpp()->sms()->log( $per ? $per : 50 ) );
        }

        /* -------------------- هندلرها: مرکز API (۱.۱۱.۰) -------------------- */

        /** فهرست کاربران دارای دسترسی افزونه (نام/نقش tpp) — برای اتصال افزونه‌های دیگر */
        public function users_list( WP_REST_Request $request ) {
                $q = trim( (string) $request->get_param( 'q' ) );
                $out = array();
                foreach ( get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ), 'number' => 500 ) ) as $u ) {
                        if ( ! tpp()->auth()->user_has_tpp_access( (int) $u->ID ) ) {
                                continue;
                        }
                        $name = $u->display_name . ' (' . $u->user_login . ')';
                        if ( '' !== $q && false === mb_stripos( $name, $q ) ) {
                                continue;
                        }
                        $tpp_roles = array();
                        foreach ( (array) get_userdata( (int) $u->ID )->roles as $r ) {
                                if ( 0 === strpos( (string) $r, 'tpp_' ) ) {
                                        $tpp_roles[] = (string) $r;
                                }
                        }
                        $out[] = array(
                                'id'       => (int) $u->ID,
                                'name'     => $u->display_name,
                                'login'    => $u->user_login,
                                'tpp_roles'=> $tpp_roles,
                                'is_manager' => TPP_Capabilities::is_manager( (int) $u->ID ),
                        );
                }
                return self::ok( array( 'total' => count( $out ), 'users' => $out ) );
        }

        /** فهرست توکن‌های API کاربر فعلی (بدون مقادیر خام) — مدیر می‌تواند user_id بدهد */
        public function api_tokens_list( WP_REST_Request $request ) {
                $user_id = get_current_user_id();
                $asked   = (int) $request->get_param( 'user_id' );
                if ( $asked && $asked !== $user_id && ! current_user_can( 'manage_options' ) ) {
                        return self::err( 'tpp_forbidden', 'فقط مدیر کل می‌تواند توکن‌های کاربر دیگر را ببیند.', 403 );
                }
                $target = $asked ? $asked : $user_id;
                return self::ok( array( 'user_id' => (int) $target, 'tokens' => tpp()->auth()->tokens_of( $target ) ) );
        }

        /** صدور توکن API جدید — {label} → مقدار خام فقط یک‌بار برگردانده می‌شود */
        public function api_tokens_issue( WP_REST_Request $request ) {
                $body  = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $label = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';
                if ( '' === $label ) {
                        $label = 'API — ' . TPP_Date::now();
                }
                $token = tpp()->auth()->issue_token( get_current_user_id(), $label );
                return self::ok( array(
                        'status' => 'issued',
                        'token'  => $token, // فقط همین یک‌بار نمایش داده می‌شود — ذخیره کنید
                        'label'  => $label,
                        'hint'   => 'این مقدار را همان‌جا ذخیره کنید؛ سرور فقط هش آن را نگه می‌دارد و دیگر قابل مشاهته نیست.',
                ), 201 );
        }

        /** ابطال یک توکن با اندیس — {idx} (یا ?idx=) — مدیر می‌تواند {user_id} بدهد */
        public function api_tokens_revoke( WP_REST_Request $request ) {
                $body  = (array) $request->get_json_params();
                if ( empty( $body ) ) {
                        $body = $request->get_params();
                }
                $idx = isset( $body['idx'] ) ? (int) $body['idx'] : (int) $request->get_param( 'idx' );
                if ( $idx < 0 ) {
                        return self::err( 'tpp_bad_idx', 'اندیس توکن نامعتبر است.', 400 );
                }
                $user_id = get_current_user_id();
                $asked   = isset( $body['user_id'] ) ? (int) $body['user_id'] : (int) $request->get_param( 'user_id' );
                if ( $asked && $asked !== $user_id && ! current_user_can( 'manage_options' ) ) {
                        return self::err( 'tpp_forbidden', 'فقط مدیر کل می‌تواند توکن کاربر دیگر را ابطال کند.', 403 );
                }
                $target = $asked ? $asked : $user_id;
                $ok = tpp()->auth()->revoke_by_idx( $target, $idx );
                if ( ! $ok ) {
                        return self::err( 'tpp_not_found', 'توکنی با این اندیس یافت نشد.', 404 );
                }
                return self::ok( array( 'status' => 'revoked' ) );
        }

        /**
         * کاتالوگ مستندات API — منبع یگانه برای مرکز API اپ و فایل API-DOCUMENTATION.md.
         * هر مسیر: m=متد، p=مسیر، t=عنوان، d=توضیح، perm=سطح دسترسی، args=[نام/نوع/توضیح]، body=نمونه بدنه،
         * فقط مهم‌ها اینجا مستند می‌شوند؛ فهرست کامل الگوها از route_table می‌آید.
         */
        public static function docs_catalog() {
                $base_pretty = rest_url( self::NS . '/' );
                $base_ugly   = add_query_arg( array( 'rest_route' => '/' . self::NS . '/' ), home_url( '/' ) );
                $ajax        = admin_url( 'admin-ajax.php?action=tpp_api' );

                $ep = static function ( $m, $p, $t, $d, $perm, $args = array(), $body = null ) {
                        return array( 'm' => $m, 'p' => $p, 't' => $t, 'd' => $d, 'perm' => $perm, 'args' => $args, 'body' => $body );
                };
                $arg = static function ( $name, $type, $desc ) {
                        return array( 'name' => $name, 'type' => $type, 'desc' => $desc );
                };

                $groups = array(

                        array( 'title' => 'احراز هویت و نشست', 'items' => array(
                                $ep( 'GET', 'ping', 'وضعیت افزونه', 'بررسی زنده بودن API + نسخه + آدرس ورود. در حالت ورود، nonce تازه هم برمی‌گرداند.', 'عمومی' ),
                                $ep( 'POST', 'login', 'ورود و دریافت توکن', 'ورود با نام کاربری/رمز وردپرس → توکن X-TPP-Token برای همه فراخوانی‌های بعدی.', 'عمومی', array(), array( 'username' => 'admin', 'password' => '•••' ) ),
                                $ep( 'GET', 'bootstrap', 'راه‌اندازی اپ', 'کاربر جاری + قابلیت‌ها + اسکیمای فیلدهای قابل مشاهده + تنظیمات + آمار — همه در یک فراخوانی.', 'کاربر افزونه' ),
                                $ep( 'POST', 'logout', 'خروج', 'ابطال توکن فعلی؛ با all=1 همه دستگاه‌ها؛ با wp=1 خروج از وردپرس.', 'کاربر افزونه', array(), array( 'all' => 1 ) ),
                        ) ),

                        array( 'title' => 'سرویس‌ها و آدرس‌ها', 'items' => array(
                                $ep( 'GET', 'search', 'جستجوی سراسری', 'جستجو در همه فیلدهای قابل جستجو (سرویس+آدرس) با فیلترهای فیلد، صفحه‌بندی، مرتب‌سازی، بازه زمانی ویرایش و فیلتر وضعیت پیشرفت دایری. فقط فیلدهای مجاز کاربر برگردانده می‌شود و عبارت/فیلتر در گزارش فعالیت ثبت می‌شود.', 'مشاهده سرویس‌ها', array(
                                        $arg( 'query', 'string', 'عبارت جستجو (LIKE همه فیلدهای قابل جستجو)' ),
                                        $arg( 'filters', 'json', 'شیء فیلتر فیلد‌ها {f_center_name: "مرکز الف"}' ),
                                        $arg( 'page / per_page', 'int', 'صفحه‌بندی (پیش‌فرض ۲۵)' ),
                                        $arg( 'sort / order', 'string', 'updated|created|phone — ASC|DESC' ),
                                        $arg( 'group', 'int', 'گروه‌بندی بر اساس آدرس (۱/۰)' ),
                                        $arg( 'upd_from / upd_to', 'date', 'بازه زمانی ویرایش YYYY-MM-DD' ),
                                        $arg( 'progress_status', 'string', 'وضعیت دایری: none|progress|done|fail|fail_los|fail_phone|fail_internet|fail_other' ),
                                        $arg( 'progress_step', 'string', 'کلید مرحله خاص مثل fat یا inet_omc' ),
                                        $arg( 'progress_step_state', 'string', 'done = انجام‌شده / todo = انجام‌نشده (پیش‌فرض done)' ),
                                        $arg( 'category', 'int', '۱.۱۹.۰ — شناسه دسته‌بندی پروژه (فیلتر دقیق)' ),
                                        $arg( 'tags', 'string', '۱.۱۹.۰ — شناسه تگ‌ها با کاما — سرویسی که هرکدام را دارد' ),
                                ) ),
                                $ep( 'POST', 'services', 'ثبت سرویس', 'ایجاد سرویس + تطبیق/ایجاد آدرس با کلید نرمال‌شده؛ op_id برای تکرارناپذیری؛ progress = مراحل دایری و خرابی (۱.۱۲.۰).', 'ثبت سرویس', array(), array( 'address' => array( 'f_postal_code' => '1111111111' ), 'service' => array( 'f_phone' => '9100000000' ), 'progress' => array( 'steps' => array( 'infra', 'fat' ), 'failure' => '' ), 'op_id' => 'uniq-1' ) ),
                                $ep( 'GET', 'services/{id}', 'مشخصات سرویس', 'کلید فیلد + progress (خلاصه مراحل دایری/خرابی) + تاریخچه + سرویس‌های همان آدرس + بازدیدهای اخیر؛ بازدید در گزارش فعالیت ثبت می‌شود.', 'مشاهده سرویس‌ها' ),
                                $ep( 'PUT', 'services/{id}', 'ویرایش سرویس', 'ویرایش با base_version برای تشخیص تعارض همزمانی؛ op_id اختیاری؛ progress = مراحل دایری و خرابی (۱.۱۲.۰) — تغییرات در تاریخچه/گزارش فعالیت ثبت می‌شود.', 'ویرایش سرویس', array(), array( 'service' => array( 'f_owner_name' => 'نام جدید' ), 'progress' => array( 'steps' => array( 'infra', 'fat', 'drop' ), 'failure' => 'los' ), 'base_version' => 3 ) ),
                                $ep( 'DELETE', 'services/{id}', 'حذف سرویس', 'حذف + تاریخچه آبشاری؛ op_id اختیاری.', 'حذف سرویس', array(), array( 'op_id' => 'uniq-2' ) ),
                                $ep( 'GET', 'addresses/{id}', 'آدرس و سرویس‌هایش', 'اطلاعات آدرس + همه سرویس‌های آن + تایم‌لاین.', 'مشاهده سرویس‌ها' ),
                                $ep( 'GET', 'addresses/suggest', 'پیشنهاد آدرس', '?query= برای autocomplete آدرس در فرم ثبت.', 'مشاهده سرویس‌ها', array( $arg( 'query', 'string', 'حداقل ۲ نویسه' ) ) ),
                                $ep( 'GET', 'values', 'مقادیر یکتای فیلد', 'مقادیر متمایز یک فیلد برای کشویی‌های فیلتر (با جستجوی q).', 'مشاهده سرویس‌ها', array( $arg( 'field', 'string', 'slug فیلد مثل f_center_name' ), $arg( 'q', 'string', 'فیلتر LIKE' ), $arg( 'limit', 'int', 'پیش‌فرض ۱۰۰' ) ) ),
                        ) ),

                        array( 'title' => 'پیشرفت دایری سرویس (۱.۱۲.۰)', 'items' => array(
                                $ep( 'POST/PUT', 'services + progress', 'ثبت/ویرایش پیشرفت دایری', 'در بدنه ثبت (POST services) و ویرایش (PUT services/{id}) کلید progress ارسال می‌شود؛ ۱۶ کلید مرحله: infra, fat, drop, fusion_fat, atb, patch, modem, registered, ready, sip_info, inet_config, inet_omc, inet_connected, sip_config, sip_omc, phone_connected. خرابی: los (اینترنت+تلفن قطع) / phone (قطع تلفن) / internet (قطع اینترنت) / other (سایر — شرح در فیلد توضیحات). ۱.۱۳.۰ — منطق آبشاری: مراحل وابسته‌اند؛ تیک مرحله N همه مراحل قبل از N را خودکار تیک می‌زند «مگر مراحل ردشده» که بعد از تیک‌خوردن، تیکشان توسط کاربر برداشته شده (progress.skipped یا برداشتن تیک با وجود مرحله بعدی تیک‌دار → progress_excluded). ۱.۱۴.۰ — چند خرابی همزمان: progress.failures آرایه کلیدها (مثلاً ["internet","other"])؛ failure تکی قدیمی هم پذیرفته می‌شود. خروجی GET services/{id} و search کلید progress را با steps/failures/failures_labels/failure/failure_label/done/total/pct/status/last_label/excluded (مراحل ردشده) برمی‌گرداند. تغییرات پیشرفت با برچسب فارسی مراحل در تاریخچه و گزارش فعالیت ثبت می‌شوند.', 'ثبت/ویرایش سرویس', array(
                                        $arg( 'progress.steps', 'array', 'کلیدهای مراحل انجام‌شده (به هر ترتیبی — سرور مرتب/اعتبارسنجی/آبشاری می‌کند)' ),
                                        $arg( 'progress.skipped', 'array', 'کلیدهای مراحل ردشده توسط کاربر — آبشار از آن‌ها می‌پرد (اختیاری)' ),
                                        $arg( 'progress.failures', 'array', '۱.۱۴.۰ — آرایه خرابی‌ها: ["los","internet","phone","other"] — آرایه خالی = رفع همه خرابی‌ها' ),
                                        $arg( 'progress.failure', 'string', 'legacy — los | phone | internet | other | خالی (رفع خرابی)' ),
                                ), array( 'progress' => array( 'steps' => array( 'infra', 'fat', 'drop' ), 'skipped' => array(), 'failures' => array( 'internet', 'other' ) ) ) ),
                                $ep( 'POST', 'services/bulk', 'تغییر گروهی/تکی پیشرفت و وضعیت', 'اعمال یک‌باره پیشرفت دایری + خرابی + فیلدهای سرویس روی فهرستی از سرویس‌ها (ids). mode: up_to = تنظیم تا مرحله (آبشاری — همه مراحل قبل خودکار تیک می‌خورند)، add/remove = افزودن/حذف مراحل خاص، clear = پاک‌کردن، خالی = فقط خرابی/فیلد. skipped_policy: keep (مراحل ردشده توسط کاربر حفظ شوند — پیش‌فرض) / reset (بدون استثنا). ۱.۱۴.۰ — failures: آرایه خرابی‌ها (null = بدون تغییر؛ آرایه خالی = رفع همه)؛ failure تکی قدیمی هم پذیرفته می‌شود. service: {slug: value} فیلدهای سرویس (مثل f_mobile یا f_misc_notes). هر تغییر در تاریخچه و گزارش فعالیت با علامت «تغییر گروهی» ثبت می‌شود؛ dry_run = محاسبه بدون ذخیره. دسترسی: قابلیت «ویرایش سریع» — به‌طور پیش‌فرض فقط مدیر کل سایت؛ برای نقش‌ها از «نقش‌ها و دسترسی‌ها» قابل اعطاست.', 'ویرایش سریع (⚡/🚀) — فقط مدیر کل به‌طور پیش‌فرض', array(
                                        $arg( 'ids', 'array', 'شناسه سرویس‌ها (حداکثر ۵۰۰۰)' ),
                                        $arg( 'progress.mode', 'string', 'up_to | add | remove | clear | خالی' ),
                                        $arg( 'progress.step', 'string', 'کلید مرحله برای up_to یا all = همه ۱۶ مرحله' ),
                                        $arg( 'progress.steps', 'array', 'کلیدها برای add/remove' ),
                                        $arg( 'progress.skipped_policy', 'string', 'keep (پیش‌فرض) | reset' ),
                                        $arg( 'progress.failures', 'array', '۱.۱۴.۰ — آرایه خرابی‌ها؛ null=بدون تغییر | [] = رفع همه | ["los","other"]' ),
                                        $arg( 'progress.failure', 'string', 'legacy — null=بدون تغییر | خالی=رفع | los|phone|internet|other' ),
                                        $arg( 'service', 'object', 'فیلدهای سرویس مثل {"f_mobile": "۰۹۱۲…", "f_misc_notes": "…"}' ),
                                        $arg( 'dry_run', 'bool', 'true = فقط پیش‌نمایش بدون ذخیره' ),
                                ), array( 'ids' => array( 12, 45, 78 ), 'progress' => array( 'mode' => 'up_to', 'step' => 'inet_connected', 'skipped_policy' => 'keep', 'failures' => array( 'internet' ) ), 'service' => array( 'f_misc_notes' => 'یادداشت گروهی' ) ) ),
                        ) ),

                        array( 'title' => 'بررسی موارد تکراری (مدیر کل)', 'items' => array(
                                $ep( 'GET', 'duplicates', 'گروه‌های تکراری + تشخیص تناقض', 'سرویس‌های با مقدار یکسان در فیلدهای شناسایی؛ هر گروه: فیلدهای مشترک، فیلدهای متناقض (دلایل تناقض)، فیلدهای مکمل، وضعیت clean/conflict و سرویس اصلی پیشنهادی + خلاصه کلی.', 'مدیر کل', array(
                                        $arg( 'fields', 'string', 'فیلدهای شناسایی با کاما (پیش‌فرض: تلفن/شماره مجازی/سریال مودم)' ),
                                        $arg( 'include_dismissed', 'int', '۱ = گروه‌های علامت‌خورده هم بیایند' ),
                                ) ),
                                $ep( 'POST', 'duplicates/merge', 'ادغام گروه', 'ادغام اعضا در سرویس اصلی؛ فیلدهای خالی پر می‌شود و تاریخچه‌ها منتقل؛ pick = {slug: id} مقدار برندهٔ هر فیلد متناقض را تعیین می‌کند.', 'مدیر کل', array(), array( 'primary_id' => 12, 'ids' => array( 12, 45, 78 ), 'pick' => array( 'f_phone' => 45 ) ) ),
                                $ep( 'POST', 'duplicates/merge_safe', 'ادغام همه گروه‌های بدون تناقض', 'گروه‌هایی که هیچ فیلد متناقضی ندارند یک‌جا و بی‌خطر ادغام می‌شوند؛ keys اختیاری برای محدود کردن.', 'مدیر کل', array(), array( 'keys' => array( '12_45' ) ) ),
                                $ep( 'POST', 'duplicates/dismiss', 'علامت «باقی می‌ماند»', 'گروه از فهرست بررسی خارج می‌شود.', 'مدیر کل', array(), array( 'ids' => array( 12, 45 ) ) ),
                                $ep( 'POST', 'duplicates/restore', 'بازگردانی علامت‌خورده‌ها', 'key خاص یا all.', 'مدیر کل', array(), array( 'key' => 'all' ) ),
                        ) ),

                        array( 'title' => 'تاریخچه تغییرات', 'items' => array(
                                $ep( 'GET', 'history', 'گزارش تاریخچه', 'رکوردهای تجمیعی روزانه با فیلتر کاربر/عملیات/آدرس/تعارض؛ بدون cap مربوطه فقط تاریخچه خود کاربر.', 'مشاهده تاریخچه', array(
                                        $arg( 'user_id / action / entity / address_id / conflict', 'mixed', 'فیلترها' ),
                                        $arg( 'page / per_page', 'int', 'صفحه‌بندی' ),
                                ) ),
                                $ep( 'POST', 'history/{id}/restore', 'بازگردانی وضعیت', 'اعمال مقادیر ابتدای همان روز.', 'ویرایش سرویس' ),
                                $ep( 'POST', 'history/delete', 'حذف رکوردهای تاریخچه', 'حذف تکی/گروهی با {ids:[…]} — فقط مدیر.', 'حذف تاریخچه', array(), array( 'ids' => array( 1, 2, 3 ) ) ),
                        ) ),

                        array( 'title' => 'گزارش فعالیت', 'items' => array(
                                $ep( 'GET', 'activity/log', 'فید یکپارچه فعالیت', 'همه فعالیت‌ها (ایجاد/ویرایش/حذف/ادغام + بازدید + جستجو + پیامک) در یک فید مرتب با فیلتر کاربر/نوع/عملیات/سرویس/متن/بازه تاریخ.', 'گزارش فعالیت', array(
                                        $arg( 'type', 'string', 'change|view|search|sms|all' ),
                                        $arg( 'action', 'string', 'create|update|delete|merge|restore|view|search|sms' ),
                                        $arg( 'user_id / service_id / q', 'mixed', 'فیلتر کاربر/سرویس/متن' ),
                                        $arg( 'from / to', 'date', 'بازه YYYY-MM-DD' ),
                                        $arg( 'page / per_page', 'int', 'صفحه‌بندی' ),
                                ) ),
                                $ep( 'GET', 'activity/stats', 'آمار فعالیت', 'شمار امروز/۷روز/۳۰روز + کاربران برتر + سرویس‌های پربازدید + عبارات پرتکرار + پنجره تجمیع جستجو.', 'گزارش فعالیت' ),
                                $ep( 'GET', 'activity/views', 'بازدیدها', 'تاریخچه بازدید سرویس‌ها (ادغام ۱۵ دقیقه‌ای پیوسته).', 'گزارش فعالیت' ),
                                $ep( 'GET', 'activity/searches', 'جستجوها', 'تاریخچه جستجو؛ جستجوهای در حال تایپ در پنجره search_dedupe_seconds یک رکورد می‌شوند.', 'گزارش فعالیت' ),
                                $ep( 'GET', 'activity/export', 'خروجی CSV', 'دانلود CSV همان فید یکپارچه با همان فیلترها.', 'گزارش فعالیت' ),
                        ) ),

                        array( 'title' => 'گزارش کار (۱.۱۴.۰ / ۱.۱۹.۰)', 'items' => array(
                                $ep( 'GET', 'workreport', 'گزارش کار روز / بازه‌ای', 'گزارش روزانه اقدامات یک کاربر برای ارائه به مدیران — قلم‌های مرتب با شماره ترتیب. بدون user_id گزارش خود کاربر جاری؛ با user_id (فقط دارندگان قابلیت گزارش فعالیت) گزارش کاربر دیگر. ۱.۱۹.۰: با from/to (هر دو YYYY-MM-DD) گزارش بازه‌ای n-روزه/هفتگی/ماهانه با گروه‌بندی روزانه برمی‌گردد؛ پاسخ تک‌روز شامل feed (فعالیت‌های همان روز بدون جستجو + آدرس کامل/بلوک/پلاک/واحد/شماره مجازی سرویس)، activity_days (روزهای دارای فعالیت ماه برای هایلایت تقویم)، retention (مدت نگهداشت تاریخچه فعالیت برای اخطار) و actions (فهرست اقدامات آماده) است.', 'کاربر افزونه', array(
                                        $arg( 'date', 'date', 'روز گزارش YYYY-MM-DD (پیش‌فرض: امروز)' ),
                                        $arg( 'from / to', 'date', '۱.۱۹.۰ — بازه گزارش بازه‌ای (هر دو با هم)' ),
                                        $arg( 'user_id', 'int', 'شناسه کاربر — فقط برای مدیران' ),
                                ) ),
                                $ep( 'POST', 'workreport', 'افزودن قلم (دستی/API)', 'افزودن قلم به گزارش روز جاری کاربر. با service_id + prefix متن قلم طبق قالب ساده‌شده ۱.۱۹.۰ ساخته می‌شود: «{اقدام} (آدرس کامل، نام خیابان یا بلوک، شماره پلاک، شماره واحد)، دایری سرویس تا مرحله (X)، مراحل باقیمانده بعدی از مرحله (Y)، خرابی اعلام‌شده: Z» (بدون شمارش مراحل/درصد). بدون سرویس، content خام ثبت می‌شود.', 'کاربر افزونه', array(
                                        $arg( 'date', 'date', 'روز گزارش (پیش‌فرض امروز)' ),
                                        $arg( 'content', 'string', 'متن قلم (وقتی service_id ارسال نشود)' ),
                                        $arg( 'service_id', 'int', 'شناسه سرویس مرتبط (اختیاری)' ),
                                        $arg( 'prefix', 'string', 'عنوان اقدام مثل «رفع مشکل» / «تحویل سرویس» / متن دلخواه' ),
                                ), array( 'date' => '2026-06-12', 'service_id' => 12, 'prefix' => 'رفع مشکل' ) ),
                                $ep( 'POST', 'workreport/from_activity', 'افزودن از تاریخچه فعالیت', 'تبدیل یک ردیف فعالیت روز (بازدید یا تغییر سرویس) به قلم گزارش با قالب استاندارد: ردیف view → «رفع مشکل …»، ردیف change با action=create → «تحویل سرویس …»، سایر تغییرها → «رفع مشکل …».', 'کاربر افزونه', array(
                                        $arg( 'src', 'string', 'view | change' ),
                                        $arg( 'row_id', 'int', 'شناسه رکورد همان جدول (بازدید/تاریخچه)' ),
                                        $arg( 'date', 'date', 'روز گزارش (پیش‌فرض امروز)' ),
                                ), array( 'src' => 'change', 'row_id' => 345, 'date' => '2026-06-12' ) ),
                                $ep( 'POST', 'workreport/line', 'پیش‌نمایش متن قلم', 'ساخت متن قالب برای یک سرویس بدون ثبت — برای پیش‌نمایش قبل از افزودن.', 'کاربر افزونه', array(
                                        $arg( 'service_id', 'int', 'شناسه سرویس' ),
                                        $arg( 'prefix', 'string', 'عنوان اقدام' ),
                                ), array( 'service_id' => 12, 'prefix' => 'تحویل سرویس' ) ),
                                $ep( 'GET', 'workreport/days', 'روزهای دارای گزارش کار (۱.۲۱.۰)', '?from&to[&user_id] — فقط تاریخ + تعداد اقلام گزارش هر روز (بدون اقلام) — منبع تقویم مجزای «روزهای دارای گزارش کار» در گزارش کار.', 'کاربر افزونه', array( $arg( 'from / to', 'date', 'بازه YYYY-MM-DD' ) ) ),
                                $ep( 'PUT', 'workreport/{id}', 'ویرایش قلم', 'ویرایش متن/سرویس یک قلم — فقط مالک قلم.', 'کاربر افزونه', array(), array( 'content' => 'متن جدید' ) ),
                                $ep( 'DELETE', 'workreport/{id}', 'حذف قلم', 'حذف قلم از گزارش روز — فقط مالک قلم.', 'کاربر افزونه' ),
                        ) ),

                        array( 'title' => 'دسته‌بندی پروژه‌ها و تگ‌ها (۱.۱۹.۰)', 'items' => array(
                                $ep( 'GET', 'categories', 'فهرست دسته‌بندی‌ها و تگ‌ها', 'بدون پارامتر: همه دسته‌بندی‌ها و تگ‌های سیستمی + تعداد استفاده — برای فرم ثبت/ویرایش سرویس و فیلترهای جستجو. ۱.۲۱.۰ — با kind=category|tag (+q جستجو + page + per_page): {items, total, page, per_page} برای کامبوباکس آجاکسی فرم سرویس و فهرست صفحه‌بندی‌شده «دسته‌بندی پروژه‌ها».', 'کاربر افزونه', array( $arg( 'kind', 'string', 'بدون پارامتر = شکل کامل قدیمی | category | tag' ), $arg( 'q', 'string', 'جستجوی بخشی از عنوان' ), $arg( 'page / per_page', 'int', 'صفحه‌بندی (per_page حداکثر ۱۰۰)' ) ) ),
                                $ep( 'POST', 'categories', 'افزودن', '{kind: category|tag, label} — دسته/تگ جدید.', 'دسته‌بندی پروژه‌ها (پیش‌فرض فقط مدیر کل)', array(), array( 'kind' => 'category', 'label' => 'پروژه سازمانی' ) ),
                                $ep( 'PUT', 'categories/{id}', 'ویرایش', '{label, sort_order}.', 'دسته‌بندی پروژه‌ها' ),
                                $ep( 'DELETE', 'categories/{id}', 'حذف', 'دسته در حال استفاده حذف نمی‌شود؛ تگ از سرویس‌ها جدا و حذف می‌شود.', 'دسته‌بندی پروژه‌ها' ),
                                $ep( 'GET', 'activity/days', 'روزهای دارای فعالیت', '?from&to[&user_id] — روزهایی که کاربر فعالیت (تغییر/بازدید/جستجو/پیامک) ثبت کرده؛ برای هایلایت تقویم گزارش کار.', 'کاربر افزونه', array( $arg( 'from / to', 'date', 'بازه YYYY-MM-DD' ) ) ),
                        ) ),

                        array( 'title' => 'بازبینی (۱.۲۰.۰)', 'items' => array(
                                $ep( 'GET', 'review/queue', 'صف سرویس‌های ارجاعی', 'سرویس‌هایی که با دسته‌بندی پیش‌فرض «ثبت جهت بازبینی و ویرایش یا تأیید مدیریت» ثبت شده‌اند — همراه آدرس کامل/بلوک/پلاک/واحد، شماره مجازی، ثبت‌کننده، وضعیت دایری و فهرست دسته‌ها/تگ‌ها برای تعیین دسته. هر سرویسی که دسته مناسب ندارد می‌تواند با این دسته به بازبینی ارجاع شود.', 'قابلیت «بازبینی سرویس‌های ارجاعی» (پیش‌فرض فقط مدیر کل؛ از نقش‌ها قابل اعطا)' ),
                                $ep( 'POST', 'review/assign', 'تعیین دسته سرویس ارجاعی', '{service_id, category, tags} — بازبین دسته‌بندی/تگ درست را تعیین می‌کند؛ سرویس از صف خارج می‌شود و در تاریخچه ثبت می‌شود.', 'بازبینی سرویس‌های ارجاعی', array(), array( 'service_id' => 12, 'category' => 5, 'tags' => array( 2, 7 ) ) ),
                                $ep( 'GET', 'review/changes', 'سجل اقدامات نصاب‌ها', 'همه ثبت/ویرایش/حذف‌های کاربران غیرمدیر (نصاب/اپراتور ثبت/…) بر حسب روز، با خلاصه تغییرات و وضعیت بازبینی (pending/kept/reverted). فیلتر: date یا from&to + user_id + status.', 'قابلیت «بازبینی اقدامات نصاب‌ها» (پیش‌فرض فقط مدیر کل)', array(
                                        $arg( 'date / from & to', 'date', 'یک روز یا بازه YYYY-MM-DD' ),
                                        $arg( 'user_id', 'int', 'فیلتر کاربر (۰ = همه)' ),
                                        $arg( 'status', 'string', 'pending | kept | reverted | خالی = همه' ),
                                ) ),
                                $ep( 'POST', 'review/keep', 'نگه‌داشتن تغییر', '{id} — علامت «بازبینی شد/نگه داشته شد»؛ تغییر باقی می‌ماند (پیش‌فرض همه تغییرات می‌مانند).', 'بازبینی اقدامات نصاب‌ها', array(), array( 'id' => 101 ) ),
                                $ep( 'POST', 'review/revert', 'بازگردانی به حالت قبل', '{id} — وضعیت دقیق قبل از تغییر بازمی‌گردد: ثبت → سرویس حذف می‌شود؛ ویرایش → ستون‌های سرویس/آدرس عیناً بازنویسی؛ حذف → سرویس با همان شناسه بازساز. اگر سرویس بعد از تغییر دوباره ویرایش شده باشد بازگردانی انجام نمی‌شود (محافظ تغییرات جدیدتر).', 'بازبینی اقدامات نصاب‌ها', array(), array( 'id' => 101 ) ),
                                $ep( 'POST', 'tools/db-update', 'بروزآوری دیتابیس (۱.۲۱.۰)', 'ستون‌های یتیم (بدون فیلد فعال) هر دو جدول services/addresses پیدا می‌شود، محتوایشان قالب‌بندی‌شده («🔹 عنوان: مقدار») به فیلد «توضیحات متفرقه» سرویس‌ها منتقل و ستون‌ها کامل حذف می‌شود. پیش از اجرا پشتیبان کامل خودکار روی سرور گرفته می‌شود (پاسخ شامل متادیتای پشتیبان و ستون‌های باقی‌مانده).', 'تنظیمات (مدیر کل)' ),
                        ) ),

                        array( 'title' => 'ایمپورت اکسل (۳ مرحله‌ای)', 'items' => array(
                                $ep( 'POST', 'import/preview', 'مرحله ۱ — آپلود و نگاشت', 'فایل xlsx multipart → سرستون‌ها + پیش‌نمایش + پیشنهاد نگاشت ستون‌ها.', 'ایمپورت', array(), null ),
                                $ep( 'POST', 'import/analyze', 'مرحله ۲ — تحلیل تشابه', 'ردیف‌های مشابه با داده‌های موجود (کلید تطبیق) + وضعیت هر ردیف.', 'ایمپورت', array(), array( 'session_id' => '…', 'match_key' => 'f_phone' ) ),
                                $ep( 'POST', 'import/commit', 'مرحله ۳ — ثبت نهایی', 'ثبت ردیف‌ها با تصمیم هر ردیف (ایجاد/ادغام/رد).', 'ایمپورت', array(), array( 'session_id' => '…', 'rows' => array() ) ),
                        ) ),

                        array( 'title' => 'خروجی‌ها و پشتیبان', 'items' => array(
                                $ep( 'GET', 'export/xlsx', 'خروجی اکسل', 'نتیجه جستجو/فیلتر فعلی (همان پارامترهای search — شامل progress_status/progress_step). ۱.۱۲.۰: ستون‌های «وضعیت دایری»، «آخرین مرحله دایری» و «خرابی اعلام‌شده» به خروجی اضافه شد.', 'مشاهده یا خروجی' ),
                                $ep( 'GET', 'export/pdf', 'خروجی PDF بومی', 'PDF با فونت فارسی تعبیه‌شده + ستون‌های پیشرفت دایری (۱.۱۲.۰).', 'مشاهده یا خروجی' ),
                                $ep( 'GET', 'export/print', 'صفحه چاپ', 'HTML آماده چاپ مرورگر + ستون‌های پیشرفت دایری (۱.۱۲.۰).', 'مشاهده یا خروجی' ),
                                $ep( 'GET', 'template', 'فایل نمونه اکسل', 'قالب خالی با ستون‌های فعلی.', 'کاربر افزونه' ),
                                $ep( 'GET', 'backup', 'پشتیبان JSON', '?with_history=1&with_activity=1&with_sms_log=1 — قابل بازیابی روی هر سرور/دامنه.', 'تنظیمات' ),
                                $ep( 'GET', 'backup/info', 'اطلاعات پشتیبان', 'تعداد رکورد هر جدول + حجم تقریبی + محدودیت‌های PHP.', 'تنظیمات' ),
                                $ep( 'GET', 'backup/sql', 'پشتیبان SQL قابل‌حمل', 'دستورات SQL با {{PFX}} — مستقل از پیشوند جدول.', 'تنظیمات' ),
                                $ep( 'GET', 'backup/zip', 'پشتیبان ZIP کامل', 'دیتابیس + تاریخچه + فایل‌های افزونه + راهنمای بازیابی.', 'تنظیمات' ),
                                $ep( 'POST', 'restore', 'بازیابی مستقیم', 'فایل JSON (بدنه json یا فایل file) — گزارش شمارش/خطاها.', 'تنظیمات' ),
                                $ep( 'POST', 'restore/begin', 'آپلود تکه‌ای — شروع', 'فایل بزرگ بدون محدودیت upload_max_filesize → {token, chunk_size}.', 'تنظیمات', array(), array( 'size' => 5242880, 'name' => 'backup.json' ) ),
                                $ep( 'POST', 'restore/chunk', 'آپلود تکه‌ای — تکه', '{token, index, data} به‌ترتیب.', 'تنظیمات', array(), array( 'token' => '…', 'index' => 0, 'data' => '…' ) ),
                                $ep( 'POST', 'restore/finish', 'آپلود تکه‌ای — پایان', 'سرهم‌کردن + اجرای بازیابی + خلاصه نتیجه.', 'تنظیمات', array(), array( 'token' => '…' ) ),
                                $ep( 'POST', 'restore/cancel', 'لغو نشست بازیابی', 'پاک‌سازی فایل‌های موقت.', 'تنظیمات', array(), array( 'token' => '…' ) ),
                                /* ۱.۱۵.۰ — پشتیبان‌های ذخیره‌شده روی سرور (پوشه افزونه) */
                                $ep( 'GET', 'backup/list', 'فهرست پشتیبان‌های سرور', 'همه پشتیبان‌های ذخیره‌شده (خودکارِ قبل از ایمپورت + دستی) + وضعیت محل ذخیره (پوشه افزونه/uploads).', 'تنظیمات' ),
                                $ep( 'POST', 'backup/stored', 'پشتیبان روی سرور — ساخت', 'گرفتن پشتیبان کامل تازه و ذخیره روی سرور؛ خروجی متادیتای فایل.', 'تنظیمات', array(), array( 'context' => 'manual' ) ),
                                $ep( 'POST', 'backup/stored/restore', 'بازگردانی یک‌کلیکی', 'بازیابی کامل از فایل پشتیبان ذخیره‌شده روی سرور — بدون آپلود فایل.', 'تنظیمات', array(), array( 'filename' => 'tpp-backup-20250921-101500-ab12cd34.json' ) ),
                                $ep( 'POST', 'backup/stored/delete', 'حذف پشتیبان سرور', 'حذف فایل پشتیبان و متادیتای آن.', 'تنظیمات', array(), array( 'filename' => 'tpp-backup-….json' ) ),
                                $ep( 'GET', 'backup/stored/download', 'دانلود پشتیبان سرور', '?filename= — همان فایل JSON پشتیبان برای نگهداری بیرونی.', 'تنظیمات', array( $arg( 'filename', 'string', 'نام فایل از backup/list' ) ) ),
                                /* ۱.۱۸.۰ — اصلاح اعداد */
                                $ep( 'POST', 'numbers/fix', 'اصلاح اعداد فارسی/عربی → انگلیسی', 'تبدیل ارقام ۰-۹ و ٠-٩ به 0-9 در همه فیلدهای سرویس/آدرس + متن گزارش‌های کار؛ پیش از اجرا پشتیبان کامل خودکار گرفته می‌شود و در پاسخ نام فایل آن می‌آید. ?dry_run=true فقط می‌شمارد.', 'تنظیمات', array( $arg( 'dry_run', 'boolean', 'فقط شمارش بدون تغییر (پیش‌نمایش)' ) ), array( 'dry_run' => false ) ),
                        ) ),

                        array( 'title' => 'مدیریت ساختار و تنظیمات', 'items' => array(
                                $ep( 'GET', 'fields', 'فهرست فیلدها', 'بدون دسترسی مدیریت فقط فیلدهای قابل مشاهده کاربر.', 'کاربر افزونه' ),
                                $ep( 'POST', 'fields', 'افزودن فیلد', 'ستون واقعی جدید + بایگانی خودکار هنگام حذف.', 'مدیریت فیلدها', array(), array( 'group' => 'service', 'label' => 'نام فیلد', 'field_type' => 'text', 'is_searchable' => 1 ) ),
                                $ep( 'PUT', 'fields/{id}', 'ویرایش فیلد', '', 'مدیریت فیلدها' ),
                                $ep( 'DELETE', 'fields/{id}', 'حذف فیلد', '?archive=1 مقادیر بایگانی شوند.', 'مدیریت فیلدها' ),
                                $ep( 'POST', 'fields/reorder', 'جابه‌جایی فیلدها', '{group, order:[ids]} — ترتیب فرم‌ها/جدول‌ها.', 'مدیریت فیلدها', array(), array( 'group' => 'service', 'order' => array( 3, 1, 2 ) ) ),
                                $ep( 'GET', 'roles', 'فهرست نقش‌ها', 'نقش‌های tpp_ با قابلیت‌ها و فیلدهای مجاز.', 'مدیریت نقش‌ها' ),
                                $ep( 'POST', 'roles', 'افزودن نقش', '', 'مدیریت نقش‌ها', array(), array( 'label' => 'نقش جدید', 'caps' => array( 'tpp_view_services' => 1 ), 'fields' => array() ) ),
                                $ep( 'PUT', 'roles/{slug}', 'ویرایش نقش', '', 'مدیریت نقش‌ها' ),
                                $ep( 'DELETE', 'roles/{slug}', 'حذف نقش', 'نقش‌های پیش‌فرض فقط ویرایش می‌شوند.', 'مدیریت نقش‌ها' ),
                                $ep( 'GET', 'users', 'کاربران افزونه', 'کاربران دارای دسترسی + نقش‌های tpp آن‌ها — برای اتصال افزونه‌های دیگر.', 'مدیریت نقش‌ها', array( $arg( 'q', 'string', 'جستجو در نام' ) ) ),
                                $ep( 'GET', 'settings', 'خواندن تنظیمات', 'همه تنظیمات (جستجو/تاریخچه/پیامک/…).', 'تنظیمات' ),
                                $ep( 'PUT', 'settings', 'ذخیره تنظیمات', 'کلیدهای مجاز sanitize می‌شوند.', 'تنظیمات', array(), array( 'rows_per_page' => 25, 'search_dedupe_seconds' => 15 ) ),
                                $ep( 'GET', 'stats', 'آمار کلی', 'شمار سرویس/آدرس/کاربران فعال.', 'مشاهده سرویس‌ها' ),
                        ) ),

                        array( 'title' => 'پیامک (SMS.ir)', 'items' => array(
                                $ep( 'GET', 'sms/status', 'وضعیت پنل', 'اتصال + موجودی باقیمانده (?fresh=1 بدون کش).', 'ارسال پیامک' ),
                                $ep( 'POST', 'sms/send', 'ارسال پیامک', 'برای سرویس با قالب یا شماره/متن دلخواه.', 'ارسال پیامک', array(), array( 'service_id' => 12, 'template_id' => 2 ) ),
                                $ep( 'GET', 'sms/templates', 'قالب‌ها', 'فهرست قالب‌های پیامک.', 'ارسال پیامک' ),
                                $ep( 'POST', 'sms/templates', 'قالب جدید', '', 'تنظیمات', array(), array( 'title' => 'خوش‌آمد', 'body' => '{{f_owner_name}} عزیز…' ) ),
                                $ep( 'PUT', 'sms/templates/{id}', 'ویرایش قالب', '', 'تنظیمات' ),
                                $ep( 'DELETE', 'sms/templates/{id}', 'حذف قالب', '', 'تنظیمات' ),
                                $ep( 'GET', 'sms/log', 'گزارش ارسال‌ها', 'آخرین ارسال‌ها با وضعیت.', 'تنظیمات' ),
                        ) ),

                        array( 'title' => 'مرکز API و توکن‌ها', 'items' => array(
                                $ep( 'GET', 'api/tokens', 'توکن‌های من', 'فهرست دستگاه‌ها/توکن‌های کاربر فعلی (بدون مقدار خام)؛ مدیر می‌تواند ?user_id= بدهد.', 'کاربر افزونه' ),
                                $ep( 'POST', 'api/tokens', 'صدور توکن API', 'برای اتصال افزونه‌ها/اسکریپت‌های دیگر؛ مقدار خام فقط یک‌بار برمی‌گردد.', 'کاربر افزونه', array(), array( 'label' => 'اتصال افزونه فروش' ) ),
                                $ep( 'DELETE', 'api/tokens', 'ابطال توکن', '{idx} از فهرست توکن‌های من؛ مدیر {user_id} هم می‌دهد.', 'کاربر افزونه', array(), array( 'idx' => 2 ) ),
                                $ep( 'GET', 'api/docs', 'مستندات زنده', 'همین کاتالوگ به‌صورت JSON برای رندر در ابزارهای دیگر؛ ?format=md نسخه Markdown دانلود می‌کند.', 'کاربر افزونه' ),
                        ) ),

                        array( 'title' => 'همگام‌سازی آفلاین', 'items' => array(
                                $ep( 'POST', 'sync', 'دسته عملیات آفلاین', 'صف عملیات ثبت/ویرایش/حذف با op_id یکتا (idempotent) + داده تازه برای کش — هسته کار آفلاین PWA.', 'کاربر افزونه', array(), array( 'ops' => array( array( 'op_id' => 'uniq-3', 'kind' => 'service.create', 'payload' => array() ) ) ) ),
                        ) ),
                );

                return array(
                        'version'    => TPP_VERSION,
                        'base'       => array(
                                'pretty' => $base_pretty,
                                'ugly'    => $base_ugly,
                                'ajax'    => $ajax,
                        ),
                        'auth'       => array(
                                array( 'title' => 'توکن اختصاصی (پیشنهادی — همه‌جا کار می‌کند)', 'desc' => 'هدر X-TPP-Token: <token> — از login یا api/tokens بگیرید. حتی بدون کوکی/nonce معتبر است.', 'example' => 'curl -H "X-TPP-Token: tpp_…" ' . $base_pretty . 'services/12' ),
                                array( 'title' => 'کوکی وردپرس + nonce (داخل افزونه‌های PHP خود سایت)', 'desc' => 'برای درخواست‌های سمت سرور از خود وردپرس، کوکی‌های کاربر + هدر X-WP-Nonce: <nonce> را بفرستید (wp_create_nonce(\'wp_rest\')).', 'example' => 'curl -H "X-WP-Nonce: abc123" --cookie "wordpress_logged_in_…" ' . $base_pretty . 'search?query=تست' ),
                                array( 'title' => 'پشتیبان admin-ajax (وقتی REST توسط افزونه امنیتی مسدود است)', 'desc' => 'admin-ajax.php?action=tpp_api&route=<مسیر>&method=<GET|POST|PUT|DELETE> — بدنه JSON در پارامتر body یا args؛ توکن در هدر یا پارامتر token.', 'example' => 'curl -H "X-TPP-Token: tpp_…" "' . $ajax . '&route=services/12&method=GET"' ),
                        ),
                        'notes'      => array(
                                'همه پاسخ‌ها JSON هستند؛ خطاها به‌صورت {code, message, data:{status}}.',
                                'دسترسی هر مسیر با نقش/قابلیت کاربر بررسی می‌شود و فیلدهای پنهان نقش او از خروجی حذف می‌شوند.',
                                'عملیات‌های نوشتاری با op_id یکتا تکرارناپذیرند (idempotent) — برای اتصال سیستم‌های بی‌ثبات امن است.',
                                'PHP API داخلی هم داریم: تابع tpp() — مستندات کامل در DEVELOPERS.md.',
                                '۱.۱۸.۰ — همه زمان‌های ثبت‌شده (created_at/updated_at/…) به وقت تهران (Asia/Tehran) هستند و نمایش اپ شمسی است؛ فیلترهای تاریخ سرور میلادی YYYY-MM-DD می‌گیرند.',
                        ),
                        'groups'     => $groups,
                );
        }

        /** مستندات API — ?format=md → دانلود Markdown تولیدشده از همان کاتالوگ (همیشه همگام) */
        public function api_docs( WP_REST_Request $request ) {
                $format = sanitize_key( (string) $request->get_param( 'format' ) );
                $cat    = self::docs_catalog();
                if ( 'md' === $format ) {
                        $md = self::docs_markdown( $cat );
                        $name = 'API-DOCUMENTATION.md';
                        nocache_headers();
                        header( 'Content-Type: text/markdown; charset=utf-8' );
                        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
                        echo $md; // phpcs:ignore WordPress.Security.EscapeOutput
                        exit;
                }
                return self::ok( $cat );
        }

        /** تبدیل کاتالوگ به Markdown (همان متنی که فایل API-DOCUMENTATION.md پلاگین از آن ساخته می‌شود) */
        public static function docs_markdown( $cat ) {
                $md  = "# مستندات REST API — سرویس‌های TPP (نسخه {$cat['version']})\n\n";
                $md .= "تمام امکانات افزونه از طریق REST API در دسترس است. این سند از همان منبعی تولید می‌شود که «مرکز API» داخل برنامه نمایش می‌دهد — همیشه همگام.\n\n";
                $md .= "- آدرس پایه (پیوندهای یکتا روشن): `{$cat['base']['pretty']}`\n";
                $md .= "- آدرس جایگزین (پیوندهای یکتا خاموش): `{$cat['base']['ugly']}`\n";
                $md .= "- پشتیبان admin-ajax: `{$cat['base']['ajax']}`\n\n";
                $md .= "## احراز هویت\n\n";
                foreach ( $cat['auth'] as $a ) {
                        $md .= "### {$a['title']}\n\n{$a['desc']}\n\n```bash\n{$a['example']}\n```\n\n";
                }
                $md .= "## نکته‌های کلی\n\n";
                foreach ( $cat['notes'] as $n ) {
                        $md .= "- {$n}\n";
                }
                $md .= "\n";
                foreach ( $cat['groups'] as $g ) {
                        $md .= "## {$g['title']}\n\n";
                        $md .= "| متد | مسیر | عنوان | دسترسی | توضیح |\n|---|---|---|---|---|\n";
                        foreach ( $g['items'] as $it ) {
                                $d = str_replace( '|', '\\|', (string) $it['d'] );
                                $md .= "| `{$it['m']}` | `{$it['p']}` | {$it['t']} | {$it['perm']} | {$d} |\n";
                        }
                        $md .= "\n";
                        foreach ( $g['items'] as $it ) {
                                $md .= "### {$it['m']} `{$it['p']}` — {$it['t']}\n\n{$it['d']}\n\n";
                                $md .= "دسترسی لازم: **{$it['perm']}**\n\n";
                                if ( ! empty( $it['args'] ) ) {
                                        $md .= "پارامترها:\n\n| نام | نوع | توضیح |\n|---|---|---|\n";
                                        foreach ( $it['args'] as $a ) {
                                                $desc = str_replace( '|', '\\|', (string) $a['desc'] );
                                                $md .= "| `{$a['name']}` | {$a['type']} | {$desc} |\n";
                                        }
                                        $md .= "\n";
                                }
                                if ( ! empty( $it['body'] ) ) {
                                        $md .= "نمونه بدنه:\n\n```json\n" . wp_json_encode( $it['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n```\n\n";
                                }
                        }
                }
                $md .= "## خطاها\n\nهمه خطاها با ساختار `{\"code\": \"…\", \"message\": \"…\", \"data\": {\"status\": 4xx}}` برمی‌گردند؛ کدهای رایج: `tpp_forbidden` (۴۰۳)، `tpp_unauthorized` (۴۰۱)، `tpp_not_found` (۴۰۴)، `tpp_rest_no_route` (۴۰۴)، `tpp_server_error` (۵۰۰).\n\n";
                $md .= "## اتصال از PHP (داخل افزونه‌های دیگر)\n\nعلاوه بر REST، PHP API داخلی هم هست — `tpp()->services()->search( array( 'query' => 'کدپستی' ) )`. فهرست کامل در `DEVELOPERS.md` پوشه افزونه.\n\n";
                $md .= "```php\n// نمونه فراخوانی REST از PHP با توکن\n\$r = wp_remote_get( '" . $cat['base']['pretty'] . "search?query=تست', array(\n  'headers' => array( 'X-TPP-Token' => \$tpp_token ),\n) );\n\$data = json_decode( wp_remote_retrieve_body( \$r ), true );\n```\n";
                return $md;
        }
}
