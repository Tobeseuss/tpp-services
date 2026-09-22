<?php
/**
 * مدیریت فیلدهای داینامیک — هر فیلد یک ستون واقعی در جدول مربوطه است.
 * افزودن/حذف فیلد = افزودن/حذف ستون (ALTER TABLE)؛ بنابراین جستجوی SQL روی همه فیلدها سریع و سراسری است.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Fields {

        private static $cache = null;

        /** انواع فیلد پشتیبانی‌شده */
        public static function types() {
                return array(
                        'text'     => 'متن کوتاه',
                        'textarea' => 'متن بلند',
                        'number'   => 'عدد',
                        'tel'      => 'شماره تلفن',
                        'email'    => 'ایمیل',
                        'date'     => 'تاریخ (متنی)',
                        'select'   => 'لیست کشویی',
                        'checkbox' => 'چک‌باکس (بله/خیر)',
                );
        }

        /** همه فیلدها (با کش داخلی) — اگر group داده شود فقط همان گروه */
        public static function all( $group = null ) {
                if ( null === self::$cache ) {
                        $rows        = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'fields' ) . " ORDER BY group_key ASC, sort_order ASC, id ASC" );
                        self::$cache = array();
                        foreach ( (array) $rows as $r ) {
                                $r['id']            = (int) $r['id'];
                                $r['is_required']   = (int) $r['is_required'];
                                $r['is_searchable'] = (int) $r['is_searchable'];
                                $r['is_sensitive']  = (int) $r['is_sensitive'];
                                $r['sort_order']    = (int) $r['sort_order'];
                                $r['options']       = $r['options'] ? json_decode( $r['options'], true ) : array();
                                self::$cache[]      = $r;
                        }
                }
                if ( null === $group ) {
                        return self::$cache;
                }
                $out = array();
                foreach ( self::$cache as $f ) {
                        if ( $f['group_key'] === $group ) {
                                $out[] = $f;
                        }
                }
                return $out;
        }

        public static function get( $slug ) {
                foreach ( self::all() as $f ) {
                        if ( $f['slug'] === $slug ) {
                                return $f;
                        }
                }
                return null;
        }

        /** نام ستون‌های یک گروه — برچسب => ستون برای خروجی اکسل */
        public static function labels_map( $group ) {
                $map = array();
                foreach ( self::all( $group ) as $f ) {
                        $map[ $f['label'] ] = $f['slug'];
                }
                return $map;
        }

        /** افزودن فیلد جدید (ستون جدید) */
        public static function add( $args ) {
                global $wpdb;
                $label  = trim( (string) ( $args['label'] ?? '' ) );
                $group  = ( 'address' === ( $args['group'] ?? '' ) ) ? 'address' : 'service';
                $type   = (string) ( $args['field_type'] ?? 'text' );
                if ( ! isset( self::types()[ $type ] ) ) {
                        return new WP_Error( 'tpp_bad_type', 'نوع فیلد نامعتبر است.' );
                }
                if ( '' === $label ) {
                        return new WP_Error( 'tpp_bad_label', 'عنوان فیلد الزامی است.' );
                }

                // slug: از ورودی لاتین یا خودکار
                $slug = (string) ( $args['slug'] ?? '' );
                $slug = sanitize_key( $slug );
                if ( '' === $slug ) {
                        $slug = 'f_field_' . uniqid();
                }
                if ( preg_match( '/^(id|address_id|created_by|created_at|updated_at|version)$/', $slug ) ) {
                        return new WP_Error( 'tpp_reserved', 'این نام اختصاصی است؛ نام دیگری انتخاب کنید.' );
                }
                if ( self::get( $slug ) ) {
                        return new WP_Error( 'tpp_duplicate', 'فیلدی با این نام‌کد وجود دارد.' );
                }

                $options = array();
                if ( 'select' === $type && ! empty( $args['options'] ) && is_array( $args['options'] ) ) {
                        foreach ( $args['options'] as $opt ) {
                                $opt = trim( (string) $opt );
                                if ( '' !== $opt ) {
                                        $options[] = $opt;
                                }
                        }
                        if ( empty( $options ) ) {
                                return new WP_Error( 'tpp_bad_options', 'برای لیست کشویی حداقل یک گزینه لازم است.' );
                        }
                }

                $table  = ( 'address' === $group ) ? TPP_DB::table( 'addresses' ) : TPP_DB::table( 'services' );
                $column = ( 'textarea' === $type ) ? 'TEXT NULL' : 'VARCHAR(500) NULL DEFAULT NULL';
                if ( ! self::ensure_column( $table, $slug, $column ) ) {
                        return new WP_Error( 'tpp_column_exists', 'ستونی با این نام‌کد در جدول موجود است.' );
                }

                $sort = (int) TPP_DB::get_var( "SELECT MAX(sort_order) FROM " . TPP_DB::table( 'fields' ) . " WHERE group_key = %s", array( $group ) );
                $id   = TPP_DB::insert( 'fields', array(
                        'group_key'     => $group,
                        'slug'          => $slug,
                        'label'         => $label,
                        'field_type'    => $type,
                        'is_required'   => empty( $args['is_required'] ) ? 0 : 1,
                        'is_searchable' => isset( $args['is_searchable'] ) ? ( $args['is_searchable'] ? 1 : 0 ) : ( 'textarea' === $type ? 0 : 1 ),
                        'is_sensitive'  => empty( $args['is_sensitive'] ) ? 0 : 1,
                        'options'       => $options ? wp_json_encode( $options ) : null,
                        'sort_order'    => $sort + 1,
                        'created_at'    => TPP_Date::now(),
                ) );

                if ( ! $id ) {
                        TPP_DB::query( "ALTER TABLE {$table} DROP COLUMN {$slug}" ); // بازگشت
                        return new WP_Error( 'tpp_insert_failed', 'ثبت تعریف فیلد ناموفق بود.' );
                }
                self::flush_cache();
                return array( 'id' => $id, 'slug' => $slug );
        }

        /** ویرایش فیلد (عنوان/گزینه‌ها/پرچم‌ها — نوع و نام‌کد تغییر نمی‌کند) */
        public static function update( $id, $args ) {
                $field = null;
                foreach ( self::all() as $f ) {
                        if ( (int) $f['id'] === (int) $id ) {
                                $field = $f;
                                break;
                        }
                }
                if ( ! $field ) {
                        return new WP_Error( 'tpp_not_found', 'فیلد یافت نشد.' );
                }
                $data = array();
                if ( isset( $args['label'] ) && '' !== trim( (string) $args['label'] ) ) {
                        $data['label'] = trim( (string) $args['label'] );
                }
                if ( isset( $args['is_required'] ) ) {
                        $data['is_required'] = $args['is_required'] ? 1 : 0;
                }
                if ( isset( $args['is_searchable'] ) ) {
                        $data['is_searchable'] = $args['is_searchable'] ? 1 : 0;
                }
                if ( isset( $args['is_sensitive'] ) ) {
                        $data['is_sensitive'] = $args['is_sensitive'] ? 1 : 0;
                }
                if ( 'select' === $field['field_type'] && isset( $args['options'] ) && is_array( $args['options'] ) ) {
                        $options = array();
                        foreach ( $args['options'] as $opt ) {
                                $opt = trim( (string) $opt );
                                if ( '' !== $opt ) {
                                        $options[] = $opt;
                                }
                        }
                        $data['options'] = $options ? wp_json_encode( $options ) : null;
                }
                if ( empty( $data ) ) {
                        return true;
                }
                TPP_DB::update( 'fields', $data, array( 'id' => (int) $id ) );
                self::flush_cache();
                return true;
        }

        /** ترتیب جدید فیلدهای یک گروه */
        public static function reorder( $group, array $ordered_ids ) {
                $i = 1;
                foreach ( $ordered_ids as $fid ) {
                        TPP_DB::update( 'fields', array( 'sort_order' => $i ), array( 'id' => (int) $fid, 'group_key' => $group ) );
                        $i++;
                }
                self::flush_cache();
                return true;
        }

        /**
         * حذف فیلد — ابتدا مقادیر فعلی ستون در جدول بایگانی field_archives ذخیره می‌شود
         * (پس از حذف هم امکان بازیابی داده‌ها وجود دارد و تاریخچه تغییرات هم دست‌نخورده می‌ماند).
         */
        public static function delete( $id, $archive = true ) {
                $field = null;
                foreach ( self::all() as $f ) {
                        if ( (int) $f['id'] === (int) $id ) {
                                $field = $f;
                                break;
                        }
                }
                if ( ! $field ) {
                        return new WP_Error( 'tpp_not_found', 'فیلد یافت نشد.' );
                }
                $table = ( 'address' === $field['group_key'] ) ? TPP_DB::table( 'addresses' ) : TPP_DB::table( 'services' );
                $slug  = $field['slug'];

                if ( $archive ) {
                        $rows = TPP_DB::get_results( "SELECT id, {$slug} AS val FROM {$table} WHERE {$slug} IS NOT NULL AND {$slug} <> ''" );
                        TPP_DB::insert( 'field_archives', array(
                                'field_def' => wp_json_encode( $field ),
                                'values'    => wp_json_encode( $rows ),
                                'archived_at' => TPP_Date::now(),
                        ) );
                }

                TPP_DB::delete( 'fields', array( 'id' => (int) $id ) );
                TPP_DB::query( "ALTER TABLE {$table} DROP COLUMN {$slug}" );
                self::flush_cache();
                return true;
        }

        /** فیلدهای پیش‌فرض افزونه (۲۸ فیلد) — فیلدهای «اطلاعات اصلی» به‌صورت پیش‌فرض اجباری هستند */
        public static function seed_defaults() {
                if ( ! empty( self::all() ) ) {
                        self::seed_missing_fields(); // نصب‌های قبلی: فقط فیلدهای جدیدِ نسخه فعلی را اضافه کن
                        return;
                }
                $defaults = array(
                        // group, slug, label, type, required, searchable, sensitive, after
                        array( 'address', 'f_full_address',   'آدرس کامل',              'text', 1, 1, 0, '' ),
                        array( 'address', 'f_block',          'نام بلوک یا خیابان',     'text', 0, 1, 0, '' ),
                        array( 'address', 'f_plate',          'شماره پلاک',             'text', 0, 1, 0, '' ),
                        array( 'address', 'f_unit',           'شماره واحد',             'text', 0, 1, 0, '' ),
                        array( 'address', 'f_postal_code',    'کد پستی',                'text', 0, 1, 0, '' ),
                        array( 'address', 'f_center_name',    'نام مرکز',               'text', 1, 1, 0, 'f_postal_code' ),
                        // ۱.۸.۰ — ترتیب کانونی مطابق فرم ثبت سرویس: ابتدا ۱۱ فیلد «اطلاعات اصلی»، سپس «سایر اطلاعات»
                        array( 'service', 'f_modem_model',    'مدل مودم',               'text', 1, 1, 0, '' ),
                        array( 'service', 'f_modem_serial',   'سریال مودم',             'text', 1, 1, 0, '' ),
                        array( 'service', 'f_virtual_number', 'شماره مجازی',            'tel',  1, 1, 0, '' ),
                        array( 'service', 'f_internet_pass',  'پسورد اینترنت',          'text', 1, 0, 0, '' ),
                        array( 'service', 'f_phone',          'شماره تلفن ثابت',        'tel',  1, 1, 0, '' ),
                        array( 'service', 'f_sip_pass',       'Sip Pass',               'text', 1, 0, 0, '' ),
                        array( 'service', 'f_sip_ip',         'Sip IP',                 'text', 1, 0, 0, '' ),
                        // سه فیلد زیر فقط در حالت «آیپی استاتیک» اجباری‌اند (در فرم اعمال می‌شود)
                        array( 'service', 'f_subnet',         'Subnet Mask',            'text', 0, 0, 0, '' ),
                        array( 'service', 'f_gateway',        'Gateway',                'text', 0, 0, 0, '' ),
                        array( 'service', 'f_sbc',            'SBC',                    'text', 0, 0, 0, '' ),
                        array( 'service', 'f_standby_proxy',  'StandBy Proxy',          'text', 0, 0, 0, '' ),
                        // — سایر اطلاعات —
                        array( 'service', 'f_owner_name',     'نام و نام خانوادگی',     'text', 0, 1, 0, '' ),
                        array( 'service', 'f_mobile',         'شماره موبایل',           'tel',  0, 1, 0, 'f_phone' ),
                        array( 'service', 'f_national_id_line',   'کد ملی مالک خط',     'text', 0, 1, 0, 'f_mobile' ),
                        array( 'service', 'f_national_id_service', 'کد ملی مالک سرویس', 'text', 0, 1, 0, 'f_national_id_line' ),
                        array( 'service', 'f_description',    'توضیحات',                'textarea', 0, 0, 0, '' ),
                        // ۱.۲۱.۰ — به‌جای شش فیلد حذف‌شده (وضعیت اینترنت/تلفن + وای‌فای‌ها)؛ ستون‌های یتیم با «بروزآوری دیتابیس» به همین فیلد منتقل و حذف می‌شوند
                        array( 'service', 'f_misc_notes',     'توضیحات متفرقه',         'textarea', 0, 1, 0, 'f_description' ),
                );
                $order = array( 'address' => 0, 'service' => 0 );
                foreach ( $defaults as $d ) {
                        list( $group, $slug, $label, $type, $req, $search, $sens ) = $d;
                        $table  = ( 'address' === $group ) ? TPP_DB::table( 'addresses' ) : TPP_DB::table( 'services' );
                        $column = ( 'textarea' === $type ) ? 'TEXT NULL' : 'VARCHAR(500) NULL DEFAULT NULL';
                        self::ensure_column( $table, $slug, $column );
                        $order[ $group ]++;
                        TPP_DB::insert( 'fields', array(
                                'group_key'     => $group,
                                'slug'          => $slug,
                                'label'         => $label,
                                'field_type'    => $type,
                                'is_required'   => $req,
                                'is_searchable' => $search,
                                'is_sensitive'  => $sens,
                                'options'       => null,
                                'sort_order'    => $order[ $group ],
                                'created_at'    => TPP_Date::now(),
                        ) );
                }
                // ایندکس کد پستی برای تطبیق سریع آدرس
                self::ensure_index( 'addresses', 'f_postal_code' );
                self::flush_cache();
        }

        /**
         * افزودن فیلدهای پیش‌فرضِ موجود نبودن به نصب‌های قدیمی — یک‌بار برای هر فیلد.
         * اگر کاربر فیلد پیش‌فرضی را عمداً حذف کرده باشد، با گزینه skip-deleted دیگر خودکار اضافه نمی‌شود.
         */
        public static function seed_missing_fields() {
                $missing = array(
                        array( 'service', 'f_mobile', 'شماره موبایل', 'tel', 0, 1, 0, 'f_phone' ),
                        array( 'service', 'f_national_id_line', 'کد ملی مالک خط', 'text', 0, 1, 0, 'f_mobile' ),
                        array( 'service', 'f_national_id_service', 'کد ملی مالک سرویس', 'text', 0, 1, 0, 'f_national_id_line' ),
                        array( 'address', 'f_center_name', 'نام مرکز', 'text', 0, 1, 0, 'f_postal_code' ),
                        // ۱.۲۱.۰ — فیلد جدید «توضیحات متفرقه» برای نصب‌های قبلی (جایگزین شش فیلد حذف‌شده)
                        array( 'service', 'f_misc_notes', 'توضیحات متفرقه', 'textarea', 0, 1, 0, 'f_description' ),
                );
                $skip = (array) get_option( 'tpp_seed_skip', array() );
                foreach ( $missing as $d ) {
                        list( $group, $slug, $label, $type, $req, $search, $sens, $after ) = $d;
                        if ( self::get( $slug ) || in_array( $slug, $skip, true ) ) {
                                continue;
                        }
                        // اگر ستون فیزیکی موجود است ولی تعریفش حذف شده، بازسازی نکن (کاربر خودش حذف کرده)
                        $table  = ( 'address' === $group ) ? TPP_DB::table( 'addresses' ) : TPP_DB::table( 'services' );
                        $column = ( 'textarea' === $type ) ? 'TEXT NULL' : 'VARCHAR(500) NULL DEFAULT NULL';
                        $col_exists = TPP_DB::get_row( "SHOW COLUMNS FROM {$table} LIKE %s", array( $slug ) );
                        if ( $col_exists ) {
                                $skip[] = $slug;
                                continue;
                        }
                        self::ensure_column( $table, $slug, $column );

                        // جای‌گذاری بعد از فیلد مرجع (یا در انتها)
                        $after_order = 0;
                        if ( $after && ( $ref = self::get( $after ) ) ) {
                                $after_order = (int) $ref['sort_order'];
                                TPP_DB::query( "UPDATE " . TPP_DB::table( 'fields' ) . " SET sort_order = sort_order + 1 WHERE group_key = %s AND sort_order > %d", array( $group, $after_order ) );
                        } else {
                                $after_order = (int) TPP_DB::get_var( "SELECT MAX(sort_order) FROM " . TPP_DB::table( 'fields' ) . " WHERE group_key = %s", array( $group ) );
                        }
                        TPP_DB::insert( 'fields', array(
                                'group_key'     => $group,
                                'slug'          => $slug,
                                'label'         => $label,
                                'field_type'    => $type,
                                'is_required'   => $req,
                                'is_searchable' => $search,
                                'is_sensitive'  => $sens,
                                'options'       => null,
                                'sort_order'    => $after_order + 1,
                                'created_at'    => TPP_Date::now(),
                        ) );
                        self::flush_cache();
                }
                if ( $skip !== (array) get_option( 'tpp_seed_skip', array() ) ) {
                        update_option( 'tpp_seed_skip', array_values( array_unique( $skip ) ), false );
                }
        }

        /**
         * نسخه ۱.۶.۰ — پرچم‌های پیش‌فرض فیلدها مطابق درخواست کاربر:
         *  • اجباری: آدرس کامل و نام مرکز (آدرس) + مدل مودم، سریال مودم، شماره مجازی، پسورد اینترنت،
         *    شماره تلفن ثابت، Sip Pass و Sip IP (سرویس)
         *  • Subnet/Gateway/SBC فقط در حالت آیپی استاتیک اجباری‌اند (در فرم اعمال می‌شود) و StandBy Proxy اختیاری است
         *  • پاک‌سازی میراث نسخه‌های قدیمی: نام و نام خانوادگی اجباری نباشد؛ پسورد اینترنت و Sip Pass حساس نباشند
         * فقط فیلدهای پیش‌فرض با برچسب پیش‌فرض تغییر می‌کنند (سفارشی‌سازی‌های مدیر دست‌نخورده می‌مانند).
         */
        public static function apply_default_flags_v160() {
                $required = array(
                        'f_full_address'  => 1, 'f_center_name' => 1,
                        'f_modem_model'   => 1, 'f_modem_serial' => 1, 'f_virtual_number' => 1,
                        'f_internet_pass' => 1, 'f_phone' => 1, 'f_sip_pass' => 1, 'f_sip_ip' => 1,
                        // شرطی/اختیاری — پرچم اجباری دیتابیس خاموش می‌ماند (کنترل در فرم است)
                        'f_subnet' => 0, 'f_gateway' => 0, 'f_sbc' => 0, 'f_standby_proxy' => 0,
                        'f_owner_name' => 0,
                );
                $not_sensitive = array( 'f_internet_pass' => 0, 'f_sip_pass' => 0 );
                $changed = false;
                foreach ( $required as $slug => $val ) {
                        $f = self::get( $slug );
                        if ( $f && (int) $f['is_required'] !== $val ) {
                                TPP_DB::update( 'fields', array( 'is_required' => $val ), array( 'slug' => $slug ) );
                                $changed = true;
                        }
                }
                foreach ( $not_sensitive as $slug => $val ) {
                        $f = self::get( $slug );
                        if ( $f && (int) $f['is_sensitive'] !== $val ) {
                                TPP_DB::update( 'fields', array( 'is_sensitive' => $val ), array( 'slug' => $slug ) );
                                $changed = true;
                        }
                }
                // برچسب «شماره تلفن» → «شماره تلفن ثابت» (فقط اگر هنوز برچسب پیش‌فرض قدیمی است)
                $f = self::get( 'f_phone' );
                if ( $f && 'شماره تلفن' === trim( (string) $f['label'] ) ) {
                        TPP_DB::update( 'fields', array( 'label' => 'شماره تلفن ثابت' ), array( 'slug' => 'f_phone' ) );
                        $changed = true;
                }
                if ( $changed ) {
                        self::flush_cache();
                }
        }

        /**
         * ترتیب مرجع فیلدها — دقیقاً مطابق فرم ثبت/ویرایش سرویس (۱.۸.۰):
         *   ۱) «اطلاعات اصلی»: ۱۱ فیلد سرویس به ترتیب تعیین‌شده توسط کاربر
         *   ۲) «اطلاعات آدرس»: فیلدهای گروه آدرس (به ترتیب sort_order)
         *   ۳) «سایر اطلاعات»: بقیه فیلدهای سرویس (به ترتیب sort_order)
         * این ترتیب مرجعِ همه ستون‌بندی‌ها است: فایل نمونه اکسل، خروجی اکسل/PDF/چاپ،
         * فهرست فیلدهای مرحله نگاشت ایمپورت و بازنویسی sort_order (مهاجرت/بازگردانی پشتیبان).
         */
        public static function form_order() {
                $main_slugs = array(
                        'f_modem_model', 'f_modem_serial', 'f_virtual_number', 'f_internet_pass',
                        'f_phone', 'f_sip_pass', 'f_sip_ip', 'f_subnet', 'f_gateway', 'f_sbc', 'f_standby_proxy',
                );
                $service = self::all( 'service' );
                $out     = array();
                foreach ( $main_slugs as $slug ) {
                        foreach ( $service as $f ) {
                                if ( $f['slug'] === $slug ) {
                                        $out[] = $f;
                                        break;
                                }
                        }
                }
                foreach ( self::all( 'address' ) as $f ) {
                        $out[] = $f;
                }
                foreach ( $service as $f ) {
                        if ( ! in_array( $f['slug'], $main_slugs, true ) ) {
                                $out[] = $f;
                        }
                }
                return $out;
        }

        /**
         * ۱.۸.۰ — بازنویسی sort_order فیلدها مطابق ترتیب فرم ثبت سرویس.
         * گروه سرویس: ۱۱ فیلد اصلی = ۱..۱۱، سپس بقیه فیلدهای سرویس با حفظ ترتیب نسبی فعلی.
         * گروه آدرس: شماره‌گذاری مجدد ۱..n با حفظ ترتیب نسبی.
         * فیلدهای حذف‌شده نادیده گرفته می‌شوند؛ ستون فیزیکی و داده‌ها دست‌نخورده می‌مانند.
         */
        public static function apply_canonical_order_v180() {
                $main_slugs = array(
                        'f_modem_model', 'f_modem_serial', 'f_virtual_number', 'f_internet_pass',
                        'f_phone', 'f_sip_pass', 'f_sip_ip', 'f_subnet', 'f_gateway', 'f_sbc', 'f_standby_proxy',
                );
                $changed = false;

                // گروه سرویس: ابتدا ۱۱ فیلد اصلی به ترتیب مرجع، سپس بقیه با ترتیب نسبی فعلی
                $svc = self::all( 'service' );
                $ordered = array();
                foreach ( $main_slugs as $slug ) {
                        foreach ( $svc as $f ) {
                                if ( $f['slug'] === $slug ) {
                                        $ordered[] = $f;
                                        break;
                                }
                        }
                }
                foreach ( $svc as $f ) {
                        if ( ! in_array( $f['slug'], $main_slugs, true ) ) {
                                $ordered[] = $f;
                        }
                }
                $i = 0;
                foreach ( $ordered as $f ) {
                        $i++;
                        if ( (int) $f['sort_order'] !== $i ) {
                                TPP_DB::update( 'fields', array( 'sort_order' => $i ), array( 'id' => (int) $f['id'] ) );
                                $changed = true;
                        }
                }

                // گروه آدرس: شماره‌گذاری مجدد فشرده
                $i = 0;
                foreach ( self::all( 'address' ) as $f ) {
                        $i++;
                        if ( (int) $f['sort_order'] !== $i ) {
                                TPP_DB::update( 'fields', array( 'sort_order' => $i ), array( 'id' => (int) $f['id'] ) );
                                $changed = true;
                        }
                }

                if ( $changed ) {
                        self::flush_cache();
                }
                return $changed;
        }

        /** افزودن ایندکس در صورت نبود */
        public static function ensure_index( $table_name, $column, $index_name = '' ) {
                $table = TPP_DB::table( $table_name );
                $index_name = '' !== $index_name ? $index_name : 'idx_' . $column;
                $exists = TPP_DB::get_row( "SHOW INDEX FROM {$table} WHERE Key_name = %s", array( $index_name ) );
                if ( ! $exists ) {
                        $col_exists = TPP_DB::get_row( "SHOW COLUMNS FROM {$table} LIKE %s", array( $column ) );
                        if ( $col_exists ) {
                                TPP_DB::query( "ALTER TABLE {$table} ADD INDEX {$index_name} ({$column}(20))" );
                        }
                }
        }

        /** افزودن ستون در صورت نبود */
        public static function ensure_column( $table, $column, $definition ) {
                $exists = TPP_DB::get_row( "SHOW COLUMNS FROM {$table} LIKE %s", array( $column ) );
                if ( ! $exists ) {
                        TPP_DB::query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
                        return true;
                }
                return false;
        }

        /** پاک‌سازی کش استاتیک بعد از تغییرات */
        public static function flush_cache() {
                self::$cache = null;
        }

        /**
         * پاک‌سازی مقدار متنی با حفظ سطرهای جدید.
         * ۱.۹.۳: فرم اپ همه فیلدهای متنی را چندخطی (textarea) رندر می‌کند؛
         * قبلاً sanitize_text_field سطرهای کاربر را حذف/فرو می‌ریخت و متن چندخطی
         * پس از ذخیره به یک خط پیوسته تبدیل می‌شد. این متد سطرها را حفظ می‌کند.
         */
        public static function sanitize_multiline( $value ) {
                $value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
                $value = sanitize_textarea_field( $value ); // تگ‌ها گرفته می‌شوند، سطرها حفظ می‌شوند
                $value = preg_replace( '/[ \t]+$/mu', '', $value ); // فضای اضافی انتهای هر سطر
                $value = preg_replace( '/\n{3,}/u', "\n\n", $value ); // حداکثر یک سطر خالی پشت‌سرهم
                return trim( $value );
        }

        /** اعتبارسنجی مقدار بر اساس نوع فیلد */
        public static function validate_value( $field, $value ) {
                $type  = $field['field_type'];
                // ۱.۹.۳: سطرهای جدید در همه فیلدهای متنی حفظ می‌شوند (فرم چندخطی است)
                $value = self::sanitize_multiline( (string) $value );
                if ( 'checkbox' === $type ) {
                        return $value ? '1' : '';
                }
                if ( 'select' === $type ) {
                        $options = (array) $field['options'];
                        $single  = trim( preg_replace( '/\s+/', ' ', $value ) ); // گزینه‌ها تک‌خطی‌اند
                        if ( '' !== $single && ! empty( $options ) && ! in_array( $single, $options, true ) ) {
                                return ''; // مقدار خارج از گزینه‌ها
                        }
                        return $single;
                }
                if ( 'email' === $type && '' !== $value ) {
                        // ایمیل ذاتاً تک‌خطی است — برای سازگاری نرمال می‌شود
                        $single = trim( preg_replace( '/\s+/', ' ', $value ) );
                        return is_email( $single ) ? $single : sanitize_email( $single );
                }
                return $value;
        }

        /* =====================================================================
         * ۱.۲۱.۰ — بازنشستگی فیلدهای حذف‌شده + «بروزآوری دیتابیس»
         * ===================================================================== */

        /** برچسب فارسی فیلدهای پیش‌فرضِ بازنشسته‌شده (برای قالب‌بندی انتقال به «توضیحات متفرقه») */
        const RETIRED_LABELS = array(
                'f_internet_status' => 'آخرین وضعیت اینترنت',
                'f_phone_status'    => 'آخرین وضعیت تلفن',
                'f_wifi24_name'     => 'نام وای‌فای ۲.۴ گیگاهرتز',
                'f_wifi24_pass'     => 'رمز وای‌فای ۲.۴ گیگاهرتز',
                'f_wifi5_name'      => 'نام وای‌فای ۵ گیگاهرتز',
                'f_wifi5_pass'      => 'رمز وای‌فای ۵ گیگاهرتز',
        );

        /**
         * مهاجرت ۱.۲۱.۰ — تعریف شش فیلد بازنشسته از tpp_fields حذف می‌شود ولی
         * ستون فیزیکی و داده‌ها سر جایشان می‌مانند (ستون «یتیم») تا مدیر با دکمه
         * «بروزآوری دیتابیس» محتوایشان را به «توضیحات متفرقه» منتقل و ستون‌ها را حذف کند.
         * idempotent: اجرای مجدد کاری نمی‌کند.
         */
        public static function retire_fields_v1210() {
                $skip   = (array) get_option( 'tpp_seed_skip', array() );
                $dirty  = false;
                foreach ( array_keys( self::RETIRED_LABELS ) as $slug ) {
                        $f = self::get( $slug );
                        if ( $f ) {
                                TPP_DB::delete( 'fields', array( 'slug' => $slug ) );
                                $dirty = true;
                        }
                        if ( ! in_array( $slug, $skip, true ) ) {
                                $skip[] = $slug; // دیگر هرگز seed نشود (حتی اگر ستون موجود باشد)
                                $dirty = true;
                        }
                }
                if ( $dirty ) {
                        update_option( 'tpp_seed_skip', array_values( array_unique( $skip ) ), false );
                        self::flush_cache();
                }
        }

        /** ستون‌های ثابت (سیستمی) هر جدول — هرگز جزو فیلدهای داینامیک نیستند */
        private static function protected_columns( $group ) {
                if ( 'address' === $group ) {
                        return array( 'id', 'created_by', 'created_at', 'updated_at', 'version' );
                }
                return array(
                        'id', 'address_id', 'created_by', 'created_at', 'updated_at', 'version',
                        'progress_steps', 'progress_done', 'progress_failure', 'progress_updated_at',
                        'progress_excluded', 'progress_failures', 'category_id', 'service_tags', 'is_conflict',
                );
        }

        /**
         * یافتن ستون‌های یتیم — ستون‌های فیزیکی که دیگر به هیچ فیلد فعال تعریف‌شده تعلق ندارند.
         * خروجی: [ group => [ col => label ] ]
         */
        public static function orphan_columns() {
                $out = array();
                foreach ( array( 'service' => 'services', 'address' => 'addresses' ) as $group => $table_name ) {
                        $table  = TPP_DB::table( $table_name );
                        if ( ! $table ) {
                                continue;
                        }
                        $active = array();
                        foreach ( self::all( $group ) as $f ) {
                                $active[ $f['slug'] ] = (string) $f['label'];
                        }
                        $cols = TPP_DB::get_results( "SHOW COLUMNS FROM {$table}" );
                        foreach ( (array) $cols as $c ) {
                                $col = (string) $c['Field'];
                                if ( in_array( $col, self::protected_columns( $group ), true ) || isset( $active[ $col ] ) ) {
                                        continue;
                                }
                                $label = isset( self::RETIRED_LABELS[ $col ] ) ? self::RETIRED_LABELS[ $col ] : $col;
                                $out[ $group ][ $col ] = $label;
                        }
                }
                return $out;
        }

        /**
         * «بروزآوری دیتابیس» (۱.۲۱.۰ — درخواست کاربر):
         *  ۱) ستون‌های یتیم هر دو جدول services/addresses پیدا می‌شوند
         *  ۲) محتوای غیرخالی هر ستون به‌صورت قالب‌بندی‌شده به «توضیحات متفرقه» همان سرویس اضافه می‌شود
         *     (فیلدهای گروه آدرس از طریق address_id به سرویس‌های همان آدرس می‌رسند)
         *  ۳) پس از انتقال کامل، ستون یتیم به‌طور کامل از دیتابیس حذف می‌شود
         * خروجی: گزارش { backup, services_table, addresses_table, services_updated, dropped }
         */
        public static function db_update_run( $user_id = 0 ) {
                $orphans = self::orphan_columns();
                $report  = array(
                        'services_table'   => array(),
                        'addresses_table'  => array(),
                        'services_updated' => 0,
                        'dropped'          => array(),
                );

                $st = TPP_DB::table( 'services' );
                $at = TPP_DB::table( 'addresses' );
                if ( ! $st ) {
                        return $report;
                }

                // فیلد مقصد — اگر به هر دلیلی موجود نیست بساز (seed آن در ارتقا انجام شده)
                if ( ! self::get( 'f_misc_notes' ) ) {
                        self::ensure_column( $st, 'f_misc_notes', 'TEXT NULL' );
                        $order = (int) TPP_DB::get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM " . TPP_DB::table( 'fields' ) . " WHERE group_key = %s", array( 'service' ) );
                        TPP_DB::insert( 'fields', array(
                                'group_key' => 'service', 'slug' => 'f_misc_notes', 'label' => 'توضیحات متفرقه',
                                'field_type' => 'textarea', 'is_required' => 0, 'is_searchable' => 1, 'is_sensitive' => 0,
                                'options' => null, 'sort_order' => $order, 'created_at' => TPP_Date::now(),
                        ) );
                        self::flush_cache();
                }

                $misc = 'f_misc_notes';

                /* --- جدول سرویس‌ها: انتقال + حذف ستون --- */
                if ( ! empty( $orphans['service'] ) ) {
                        foreach ( $orphans['service'] as $col => $label ) {
                                if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $col ) ) {
                                        continue; // امنیت نام ستون
                                }
                                $rows = TPP_DB::get_results( "SELECT id, `{$col}` AS v FROM {$st} WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''" );
                                $moved = 0;
                                foreach ( (array) $rows as $r ) {
                                        $old  = (string) TPP_DB::get_var( "SELECT {$misc} FROM {$st} WHERE id = %d", array( (int) $r['id'] ) );
                                        $block = '🔹 «' . $label . '»: ' . self::sanitize_multiline( $r['v'] );
                                        $new  = ( '' !== trim( (string) $old ) ) ? ( $old . "\n\n" . $block ) : $block;
                                        TPP_DB::update( 'services', array( $misc => $new ), array( 'id' => (int) $r['id'] ) );
                                        $moved++;
                                }
                                TPP_DB::query( "ALTER TABLE {$st} DROP COLUMN `{$col}`" );
                                $report['services_table'][] = array( 'column' => $col, 'label' => $label, 'rows' => $moved );
                                $report['dropped'][] = 'services.' . $col;
                                $report['services_updated'] += $moved;
                        }
                }

                /* --- جدول آدرس‌ها: انتقال به سرویس‌های متصل (با join) + حذف ستون --- */
                if ( ! empty( $orphans['address'] ) && $at ) {
                        foreach ( $orphans['address'] as $col => $label ) {
                                if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $col ) ) {
                                        continue;
                                }
                                $rows = TPP_DB::get_results( "SELECT s.id AS sid, a.`{$col}` AS v FROM {$at} a INNER JOIN {$st} s ON s.address_id = a.id WHERE a.`{$col}` IS NOT NULL AND a.`{$col}` <> ''" );
                                $moved = 0;
                                foreach ( (array) $rows as $r ) {
                                        $old   = (string) TPP_DB::get_var( "SELECT {$misc} FROM {$st} WHERE id = %d", array( (int) $r['sid'] ) );
                                        $block = '🔹 «' . $label . '» (اطلاعات آدرس): ' . self::sanitize_multiline( $r['v'] );
                                        $new   = ( '' !== trim( (string) $old ) ) ? ( $old . "\n\n" . $block ) : $block;
                                        TPP_DB::update( 'services', array( $misc => $new ), array( 'id' => (int) $r['sid'] ) );
                                        $moved++;
                                }
                                TPP_DB::query( "ALTER TABLE {$at} DROP COLUMN `{$col}`" );
                                $report['addresses_table'][] = array( 'column' => $col, 'label' => $label, 'rows' => $moved );
                                $report['dropped'][] = 'addresses.' . $col;
                                $report['services_updated'] += $moved;
                        }
                }

                // خلاصه اجرا در گزینه برای گزارش «آخرین بروزآوری»
                if ( ! empty( $report['dropped'] ) ) {
                        update_option( 'tpp_last_db_update', array(
                                'at'   => TPP_Date::now(),
                                'by'   => (int) $user_id,
                                'cols' => $report['dropped'],
                                'rows' => $report['services_updated'],
                        ), false );
                }
                return $report;
        }
}
