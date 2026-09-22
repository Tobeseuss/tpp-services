<?php
/**
 * دسته‌بندی پروژه‌ها و تگ‌های سیستمی (۱.۱۹.۰)
 *
 * مدیر کل در بخش «دسته‌بندی پروژه‌ها» فهرست دسته‌بندی‌ها ( انتخابی ) و تگ‌ها
 * ( چندتایی ) را تعریف می‌کند. هر سرویس یک دسته‌بندی اجباری (وقتی دسته‌بندی‌ای
 * تعریف شده باشد) و چند تگ اختیاری می‌گیرد.
 *
 * ذخیره‌سازی:
 *   جدول categories: id / kind ('category'|'tag') / label / sort_order / created_at
 *   جدول services:   category_id BIGINT (شناسه دسته‌بندی — ۰ = بدون دسته)
 *                    service_tags LONGTEXT (JSON آرایه شناسه تگ‌ها [3,7,…])
 *
 * جستجو/فیلتر: category (شناسه دسته) و tags (فهرست شناسه‌ها با کاما — تطبیق «هرکدام»)
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Categories {

        /** انواع تعریف مجاز */
        const KINDS = array( 'category', 'tag' );

        /** عنوان دسته‌بندی ارجاعِ پیش‌فرض (۱.۲۰.۰ — درخواست کاربر) */
        const REVIEW_LABEL = 'ثبت جهت بازبینی و ویرایش یا تأیید مدیریت';

        /** عنوان دسته‌بندی ارجاعِ پیش‌فرض (۱.۲۰.۰ — درخواست کاربر) */

        private static $cache = null;

        /* ---------------------------------------------------------------------
         * تعریف‌ها (CRUD)
         * ------------------------------------------------------------------- */

        /** همه تعریف‌ها (کش داخلی) — اگر kind داده شود فقط همان نوع */
        public static function all( $kind = null ) {
                if ( null === self::$cache ) {
                        $rows        = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'categories' ) . " ORDER BY kind ASC, sort_order ASC, id ASC" );
                        self::$cache = array();
                        foreach ( (array) $rows as $r ) {
                                $r['id']         = (int) $r['id'];
                                $r['sort_order'] = (int) $r['sort_order'];
                                $r['is_review']  = ! empty( $r['is_review'] ) ? 1 : 0;
                                self::$cache[]   = $r;
                        }
                }
                if ( null === $kind ) {
                        return self::$cache;
                }
                $kind = ( 'tag' === $kind ) ? 'tag' : 'category';
                $out  = array();
                foreach ( self::$cache as $c ) {
                        if ( $c['kind'] === $kind ) {
                                $out[] = $c;
                        }
                }
                return $out;
        }

        public static function categories() {
                return self::all( 'category' );
        }

        public static function tags() {
                return self::all( 'tag' );
        }

        /** آیا حداقل یک دسته‌بندی تعریف شده؟ (پیش‌نیاز اجباری‌بودن فیلد) */
        public static function any_category_defined() {
                return count( self::categories() ) > 0;
        }

        public static function get( $id ) {
                foreach ( self::all() as $c ) {
                        if ( $c['id'] === (int) $id ) {
                                return $c;
                        }
                }
                return null;
        }

        public static function label_of( $id ) {
                $c = self::get( $id );
                return $c ? (string) $c['label'] : '';
        }

        /** افزودن تعریف جدید — kind + label الزامی؛ خروجی: id یا WP_Error */
        public static function add( $args ) {
                $kind  = ( isset( $args['kind'] ) && 'tag' === $args['kind'] ) ? 'tag' : 'category';
                $label = trim( (string) ( $args['label'] ?? '' ) );
                if ( '' === $label ) {
                        return new WP_Error( 'tpp_bad_label', 'عنوان الزامی است.' );
                }
                if ( mb_strlen( $label ) > 190 ) {
                        $label = mb_substr( $label, 0, 190 );
                }
                // عنوان تکراری در همان kind مجاز نیست
                foreach ( self::all( $kind ) as $c ) {
                        if ( trim( (string) $c['label'] ) === $label ) {
                                return new WP_Error( 'tpp_duplicate', 'قبلاً با همین عنوان تعریف شده است.' );
                        }
                }
                $sort = (int) TPP_DB::get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM " . TPP_DB::table( 'categories' ) . " WHERE kind = %s", array( $kind ) );
                $id   = TPP_DB::insert( 'categories', array(
                        'kind'       => $kind,
                        'label'      => $label,
                        'sort_order' => $sort,
                        'created_at' => TPP_Date::now(),
                ) );
                self::flush_cache();
                return $id ? (int) $id : new WP_Error( 'tpp_insert_failed', 'ثبت تعریف ناموفق بود.' );
        }

        /** ویرایش (عنوان/ترتیب) — kind تغییر نمی‌کند */
        public static function update( $id, $args ) {
                $item = self::get( $id );
                if ( ! $item ) {
                        return new WP_Error( 'tpp_not_found', 'تعریف یافت نشد.' );
                }
                $data = array();
                if ( isset( $args['label'] ) && '' !== trim( (string) $args['label'] ) ) {
                        $label = mb_substr( trim( (string) $args['label'] ), 0, 190 );
                        foreach ( self::all( $item['kind'] ) as $c ) {
                                if ( (int) $c['id'] !== (int) $id && trim( (string) $c['label'] ) === $label ) {
                                        return new WP_Error( 'tpp_duplicate', 'عنوان تکراری است.' );
                                }
                        }
                        $data['label'] = $label;
                }
                if ( isset( $args['sort_order'] ) ) {
                        $data['sort_order'] = max( 0, (int) $args['sort_order'] );
                }
                if ( empty( $data ) ) {
                        return true;
                }
                TPP_DB::update( 'categories', $data, array( 'id' => (int) $id ) );
                self::flush_cache();
                return true;
        }

        /**
         * حذف تعریف:
         *  - دسته‌بندیِ در حال استفاده حذف نمی‌شود (ابتدا سرویس‌ها باید دسته‌بندی دیگر بگیرند)
         *  - تگ در حال استفاده از همه سرویس‌ها جدا و سپس حذف می‌شود
         */
        public static function delete( $id ) {
                $item = self::get( $id );
                if ( ! $item ) {
                        return new WP_Error( 'tpp_not_found', 'تعریف یافت نشد.' );
                }
                if ( 'category' === $item['kind'] && ! empty( $item['is_review'] ) ) {
                        return new WP_Error( 'tpp_review_cat', 'دسته‌بندی «' . self::REVIEW_LABEL . '» پیش‌فرض سیستم است و قابل حذف نیست — سرویس‌های بدون دسته مناسب با آن به بازبینی ارجاع می‌شوند.' );
                }
                $used = self::usage_count( $id );
                if ( 'category' === $item['kind'] ) {
                        if ( $used > 0 ) {
                                return new WP_Error( 'tpp_in_use', sprintf( 'این دسته‌بندی روی %d سرویس در حال استفاده است — ابتدا دسته‌بندی آن سرویس‌ها را تغییر دهید.', $used ) );
                        }
                        TPP_DB::delete( 'categories', array( 'id' => (int) $id ) );
                        self::flush_cache();
                        return true;
                }
                // تگ: از سرویس‌های دارایِ آن جدا شود
                if ( $used > 0 ) {
                    self::strip_tag_from_services( (int) $id );
                }
                TPP_DB::delete( 'categories', array( 'id' => (int) $id ) );
                self::flush_cache();
                return true;
        }

        /** تعداد سرویس‌های استفاده‌کننده از یک تعریف */
        public static function usage_count( $id ) {
                $id = (int) $id;
                $item = self::get( $id );
                if ( ! $item ) {
                        return 0;
                }
                $st = TPP_DB::table( 'services' );
                if ( 'category' === $item['kind'] ) {
                        return (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} WHERE category_id = %d", array( $id ) );
                }
                $like = '%"' . $id . '"%';
                return (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} WHERE service_tags LIKE %s", array( $like ) );
        }

        /** حذف شناسه یک تگ از JSON همه سرویس‌های دارای آن */
        private static function strip_tag_from_services( $tag_id ) {
                $tag_id = (int) $tag_id;
                $st     = TPP_DB::table( 'services' );
                $like   = '%"' . $tag_id . '"%';
                $rows   = TPP_DB::get_results( "SELECT id, service_tags FROM {$st} WHERE service_tags LIKE %s", array( $like ) );
                foreach ( (array) $rows as $r ) {
                        $ids = self::tags_of_row( $r );
                        $new = array_values( array_diff( $ids, array( $tag_id ) ) );
                        TPP_DB::update( 'services', array( 'service_tags' => self::tags_json( $new ) ), array( 'id' => (int) $r['id'] ) );
                }
        }

        public static function flush_cache() {
                self::$cache = null;
        }

        /* ---------------------------------------------------------------------
         * سمت سرویس: پارس/اعتبارسنجی/شکل‌دهی
         * ------------------------------------------------------------------- */

        /** پارس ورودی تگ‌ها (آرایه / JSON / کاما) → فقط شناسه‌های تگِ موجود، بدون تکرار */
        public static function parse_tags_input( $raw ) {
                if ( is_string( $raw ) ) {
                        $decoded = json_decode( $raw, true );
                        $raw = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                }
                if ( ! is_array( $raw ) ) {
                        return array();
                }
                $valid = array();
                foreach ( self::tags() as $t ) {
                        $valid[ (int) $t['id'] ] = true;
                }
                $out = array();
                foreach ( $raw as $v ) {
                        if ( is_array( $v ) && isset( $v['id'] ) ) {
                                $v = $v['id']; // شکل [{id,label}] از کلاینت
                        }
                        $id = (int) TPP_Date::en_num( (string) $v );
                        if ( $id > 0 && isset( $valid[ $id ] ) && ! in_array( $id, $out, true ) ) {
                                $out[] = $id;
                        }
                }
                return $out;
        }

        /** آرایه شناسه تگ‌های رکورد خام سرویس */
        public static function tags_of_row( $row ) {
                $raw = isset( $row['service_tags'] ) ? (string) $row['service_tags'] : '';
                if ( '' === $raw || 'null' === $raw ) {
                        return array();
                }
                $decoded = json_decode( $raw, true );
                if ( ! is_array( $decoded ) ) {
                        return array();
                }
                $out = array();
                foreach ( $decoded as $v ) {
                        $id = (int) $v;
                        if ( $id > 0 ) {
                                $out[] = $id;
                        }
                }
                return $out;
        }

        /** JSON آرایه شناسه‌ها (برای ستون service_tags) — شناسه‌ها به‌صورت رشته ذخیره می‌شوند
         *  تا الگوی جستجوی LIKE '%"id"%' (همان روش progress_failures) دقیق کار کند و [4] با [14] اشتباه نشود */
        public static function tags_json( array $ids ) {
                $clean = array_values( array_unique( array_map( 'intval', $ids ) ) );
                return wp_json_encode( array_map( 'strval', $clean ), JSON_UNESCAPED_UNICODE );
        }

        /** شکل API یک سرویس: {category: {id,label}|null, tags: [{id,label}]} */
        public static function shape( $row ) {
                $cat_id = (int) ( $row['category_id'] ?? 0 );
                $category = null;
                if ( $cat_id > 0 ) {
                        $label = self::label_of( $cat_id );
                        $category = $label ? array( 'id' => $cat_id, 'label' => $label ) : null;
                }
                $tags = array();
                foreach ( self::tags_of_row( $row ) as $id ) {
                        $label = self::label_of( $id );
                        if ( '' !== $label ) {
                                $tags[] = array( 'id' => (int) $id, 'label' => $label );
                        }
                }
                return array(
                        'category' => $category,
                        'tags'     => $tags,
                );
        }

        /** برچسب‌های تگ‌های یک سرویس به‌صورت متن پیوسته (برای خروجی اکسل/تاریخچه) */
        public static function tags_text( $row, $sep = '، ' ) {
                $labels = array();
                foreach ( self::tags_of_row( $row ) as $id ) {
                        $label = self::label_of( $id );
                        if ( '' !== $label ) {
                                $labels[] = $label;
                        }
                }
                return implode( $sep, $labels );
        }

        /**
         * اعمال category/tags روی داده درج/به‌روزرسانی سرویس.
         * ورودی کلاینت: category (شناسه یا {id}) + tags (آرایه/JSON/کاما).
         * خروجی: array(column => value) برای درج/آپدیت + changes شکل‌دهی‌شده برای تاریخچه.
         */
        public static function apply_to_payload( $data, $old_row = null, &$changes = null ) {
                $out = array();
                if ( array_key_exists( 'category', $data ) && null !== $data['category'] ) {
                        $cat = $data['category'];
                        if ( is_array( $cat ) && isset( $cat['id'] ) ) {
                                $cat = $cat['id'];
                        }
                        $cat_id = ( '' === trim( (string) $cat ) ) ? 0 : (int) TPP_Date::en_num( (string) $cat );
                        if ( $cat_id > 0 && ! self::get( $cat_id ) ) {
                                return new WP_Error( 'tpp_bad_category', 'دسته‌بندی انتخاب‌شده معتبر نیست.' );
                        }
                        $old_cat = $old_row ? (int) ( $old_row['category_id'] ?? 0 ) : 0;
                        if ( $cat_id !== $old_cat ) {
                                $out['category_id'] = $cat_id;
                                if ( null !== $changes ) {
                                        $old_label = $old_cat ? self::label_of( $old_cat ) : '';
                                        $new_label = $cat_id ? self::label_of( $cat_id ) : '';
                                        $changes['_category'] = array( 'old' => $old_label ?: null, 'new' => $new_label ?: null );
                                }
                        }
                }
                if ( array_key_exists( 'tags', $data ) && null !== $data['tags'] ) {
                        $ids  = self::parse_tags_input( $data['tags'] );
                        $old  = $old_row ? self::tags_of_row( $old_row ) : array();
                        if ( $ids !== $old ) {
                                $out['service_tags'] = self::tags_json( $ids );
                                if ( null !== $changes ) {
                                        $changes['_tags'] = array(
                                                'old' => $old ? self::labels_text( $old ) : null,
                                                'new' => $ids ? self::labels_text( $ids ) : null,
                                        );
                                }
                        }
                }
                return $out;
        }

        /** متن برچسب‌های فهرست شناسه */
        public static function labels_text( array $ids, $sep = '، ' ) {
                $labels = array();
                foreach ( $ids as $id ) {
                        $label = self::label_of( (int) $id );
                        if ( '' !== $label ) {
                                $labels[] = $label;
                        }
                }
                return implode( $sep, $labels );
        }

        /**
         * تبدیل متن سلول اکسل (برچسب‌ها با جداکننده کاما/؛) به شناسه‌ها — برای ایمپورت.
         * گزینه‌های ناموجود اختیاری‌اند: $auto_create برچسب جدید می‌سازد (پیش‌فرض).
         * خروجی: array(ids, created_count)
         */
        public static function resolve_labels( $kind, $text, $auto_create = true ) {
                $created = 0;
                $ids     = array();
                $parts   = array_filter( array_map( 'trim', preg_split( '/[,،;؛|]/u', (string) $text ) ) );
                foreach ( $parts as $part ) {
                        if ( '' === $part ) {
                                continue;
                        }
                        $found = 0;
                        foreach ( self::all( $kind ) as $c ) {
                                if ( trim( (string) $c['label'] ) === $part ) {
                                        $found = (int) $c['id'];
                                        break;
                                }
                        }
                        if ( ! $found && $auto_create ) {
                                $new_id = self::add( array( 'kind' => $kind, 'label' => $part ) );
                                if ( ! is_wp_error( $new_id ) ) {
                                        $found   = (int) $new_id;
                                        $created++;
                                }
                        }
                        if ( $found && ! in_array( $found, $ids, true ) ) {
                                $ids[] = $found;
                        }
                }
                return array( $ids, $created );
        }

        /* ---------------------------------------------------------------------
         * فیلتر جستجو — شرط SQL قابل‌حمل
         * ------------------------------------------------------------------- */

        /**
         * $args: category (شناسه دسته) و tags (فهرست شناسه با کاما — تطبیق هرکدام)
         * خروجی: array(where, params) یا null
         */
        public static function filter_where( $args, $alias = 's' ) {
                $where  = '';
                $params = array();
                $cat = isset( $args['category'] ) ? (int) TPP_Date::en_num( (string) $args['category'] ) : 0;
                if ( $cat > 0 ) {
                        $where   .= " AND {$alias}.category_id = %d";
                        $params[] = $cat;
                }
                $tags = isset( $args['tags'] ) ? (string) $args['tags'] : '';
                if ( '' !== trim( $tags ) ) {
                        $ids = array();
                        foreach ( array_filter( array_map( 'trim', explode( ',', $tags ) ) ) as $t ) {
                                $t = (int) TPP_Date::en_num( $t );
                                if ( $t > 0 ) {
                                        $ids[] = $t;
                                }
                        }
                        if ( $ids ) {
                                $ors = array();
                                foreach ( $ids as $t ) {
                                        $ors[]   = "{$alias}.service_tags LIKE %s";
                                        $params[] = '%"' . $t . '"%';
                                }
                                $where .= ' AND ( ' . implode( ' OR ', $ors ) . ' )';
                        }
                }
                return ( '' === $where ) ? null : array( $where, $params );
        }

        /* ---------------------------------------------------------------------
         * نصب — جدول و ستون‌ها
         * ------------------------------------------------------------------- */

        /** DDL جدول categories */
        public static function table_sql() {
                $charset = '';
                if ( ! TPP_DB::is_external() ) {
                        global $wpdb;
                        $charset = $wpdb->get_charset_collate();
                } else {
                        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
                }
                return 'CREATE TABLE ' . TPP_DB::table( 'categories' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        kind VARCHAR(20) NOT NULL DEFAULT 'category',
                        label VARCHAR(190) NOT NULL,
                        is_review TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY kind (kind)
                ) " . $charset . ';';
        }

        /** اطمینان از وجود جدول (dbDelta در ارتقا همیشه جدول جدید نمی‌سازد) */
        public static function ensure_table() {
                $table = TPP_DB::table( 'categories' );
                if ( ! $table ) {
                        return;
                }
                $exists = TPP_DB::get_var( "SHOW TABLES LIKE %s", array( $table ) );
                if ( ! $exists ) {
                        TPP_DB::query( self::table_sql() );
                }
        }

        /** ستون‌های دسته‌بندی روی جدول services (نصب‌های موجود) */
        public static function ensure_columns() {
                $table = TPP_DB::table( 'services' );
                if ( ! $table ) {
                        return;
                }
                TPP_Fields::ensure_column( $table, 'category_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
                TPP_Fields::ensure_column( $table, 'service_tags', 'LONGTEXT NULL' );
                // ایندکس دسته برای فیلتر سریع
                $idx = TPP_DB::get_row( "SHOW INDEX FROM {$table} WHERE Key_name = %s", array( 'idx_category_id' ) );
                if ( ! $idx ) {
                        $col = TPP_DB::get_row( "SHOW COLUMNS FROM {$table} LIKE %s", array( 'category_id' ) );
                        if ( $col ) {
                                TPP_DB::query( "ALTER TABLE {$table} ADD INDEX idx_category_id (category_id)" );
                        }
                }
                // 1.20.0 — review-category flag on the categories table (existing installs)
                self::ensure_review_column();
        }

        /* ---------------------------------------------------------------------
         * 1.20.0 — default referral category «ثبت جهت بازبینی»
         * ------------------------------------------------------------------- */

        /** Create the default review category if missing (idempotent — install/upgrade) */
        public static function seed_review_category() {
                self::ensure_review_column();
                if ( self::review_category_id() > 0 ) {
                        return 0;
                }
                // an existing manually-created row with the same label gets flagged instead
                foreach ( self::all( 'category' ) as $c ) {
                        if ( trim( (string) $c['label'] ) === self::REVIEW_LABEL ) {
                                TPP_DB::update( 'categories', array( 'is_review' => 1 ), array( 'id' => (int) $c['id'] ) );
                                self::flush_cache();
                                return (int) $c['id'];
                        }
                }
                $sort = (int) TPP_DB::get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM " . TPP_DB::table( 'categories' ) . " WHERE kind = %s", array( 'category' ) );
                $id = TPP_DB::insert( 'categories', array(
                        'kind'       => 'category',
                        'label'      => self::REVIEW_LABEL,
                        'is_review'  => 1,
                        'sort_order' => $sort,
                        'created_at' => TPP_Date::now(),
                ) );
                self::flush_cache();
                return $id ? (int) $id : 0;
        }

        /** Id of the default review category (0 = not created yet) */
        public static function review_category_id() {
                foreach ( self::all( 'category' ) as $c ) {
                        if ( ! empty( $c['is_review'] ) ) {
                                return (int) $c['id'];
                        }
                }
                return 0;
        }

        /** Ensure just the is_review column (light — for boot/seed) */
        public static function ensure_review_column() {
                $ct = TPP_DB::table( 'categories' );
                if ( ! $ct ) {
                        return;
                }
                $col = TPP_DB::get_row( "SHOW COLUMNS FROM {$ct} LIKE %s", array( 'is_review' ) );
                if ( ! $col ) {
                        TPP_DB::query( "ALTER TABLE {$ct} ADD COLUMN is_review TINYINT UNSIGNED NOT NULL DEFAULT 0" );
                        self::flush_cache();
                }
        }
}
