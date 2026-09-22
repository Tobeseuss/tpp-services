<?php
/**
 * گزارش کار (۱.۱۴.۰) — گزارش روزانه اقدامات هر کاربر برای ارائه به مدیران شرکت.
 * ۱.۱۹.۰ — متن قلم ساده شد (بدون شمارش مراحل/درصد): «{اقدام} (آدرس…) ، دایری سرویس تا مرحله (X) ، مراحل باقیمانده بعدی از مرحله (Y) ، خرابی اعلام‌شده: Z»
 * ۱.۱۹.۰ — کاتالوگ «اقدامات انجام‌شده» + گزارش بازه‌ای n-روزه/هفتگی/ماهانه + فهرست اقدامات برای افزودن دستی
 *
 * هر کاربر برای هر روز یک فهرست از اقلام گزارش دارد (جدول work_reports):
 *   id / user_id / report_date DATE / service_id (اختیاری) / content LONGTEXT / sort / created_at / updated_at
 * اقلام سه راه ورود دارند:
 *   ۱) «افزودن از تاریخچه فعالیت» — ردیف فعالیت روز (بازدید/ویرایش/ایجاد سرویس) با دکمه به گزارش تبدیل می‌شود:
 *      - فعالیت جستجو/مشاهده/ویرایش → «رفع مشکل (آدرس کامل، نام خیابان یا بلوک، شماره پلاک، شماره واحد)، آخرین وضعیت پیشرفت دایری سرویس، اقدامات باقیمانده از پیشرفت دایری سرویس، خرابی اعلام شده»
 *      - فعالیت ایجاد سرویس → «تحویل سرویس (آدرس کامل، …)، آخرین وضعیت پیشرفت دایری سرویس، اقدامات باقیمانده، خرابی اعلام شده»
 *   ۲) «افزودن دستی» — انتخاب سرویس (شناسه یا جستجو) + متن اقدام انجام شده → همان قالب ساخته می‌شود
 *   ۳) API مستقیم (POST workreport) — برای افزونه‌های دیگر
 * اقلام هر روز قابل افزودن/ویرایش/حذف‌اند؛ مدیران می‌توانند گزارش همه کاربران را ببینند (کاربر فقط خودش را).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Workreport {

        /** جدول گزارش کار در TPP_DB::TABLES ثبت شده است (work_reports) */

        /* ---------------------------------------------------------------------
         * اعتبارسنجی ابزارها
         * ------------------------------------------------------------------- */

        /** تاریخ معتبر Y-m-d یا '' (امروز) */
        private static function norm_date( $date ) {
                $date = trim( (string) $date );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                        return '';
                }
                $parts = explode( '-', $date );
                if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
                        return '';
                }
                return $date;
        }

        /** متن قلم گزارش — چندخطی مجاز، محدود به طول معقول */
        private static function norm_content( $content ) {
                $content = sanitize_textarea_field( (string) $content );
                if ( mb_strlen( $content ) > 4000 ) {
                        $content = mb_substr( $content, 0, 4000 );
                }
                return $content;
        }

        /* ---------------------------------------------------------------------
         * ساخت قلم گزارش از سرویس (قالب درخواستی کاربر)
         * ------------------------------------------------------------------- */

        /** بستر آدرس برای متن قلم: «آدرس کامل، نام خیابان یا بلوک، شماره پلاک، شماره واحد» */
        private static function address_line( $address ) {
                $parts = array();
                foreach ( array( 'f_full_address', 'f_block', 'f_plate', 'f_unit' ) as $slug ) {
                        $v = trim( (string) ( $address && isset( $address[ $slug ] ) ? $address[ $slug ] : '' ) );
                        if ( '' !== $v ) {
                                $parts[] = $v;
                        }
                }
                return implode( '، ', $parts );
        }

        /** «دایری سرویس تا مرحله (X)» — بدون شمارش/درصد (درخواست ۱.۱۹.۰: متن مرتب‌تر و ساده‌تر) */
        private static function progress_line( $summary ) {
                if ( ! is_array( $summary ) ) {
                        return '';
                }
                $done  = (int) ( $summary['done'] ?? 0 );
                $total = (int) ( $summary['total'] ?? 0 );
                $last  = (string) ( $summary['last_label'] ?? '' );
                if ( $total > 0 && $done >= $total ) {
                        return 'دایری سرویس کامل شده است';
                }
                if ( $done > 0 && '' !== $last ) {
                        return 'دایری سرویس تا مرحله (' . $last . ')';
                }
                return 'دایری سرویس شروع نشده است';
        }

        /** «مراحل باقیمانده بعدی از مرحله (Y)» — Y = نخستین مرحله انجام‌نشده (به‌جز ردشده‌های کاربر)؛ خالی = چیزی نمانده */
        private static function remaining_line( $summary ) {
                if ( ! is_array( $summary ) ) {
                        return '';
                }
                $steps    = (array) ( $summary['steps'] ?? array() );
                $excluded = (array) ( $summary['excluded'] ?? array() );
                foreach ( array_keys( TPP_Progress::steps() ) as $k ) {
                        if ( ! in_array( $k, $steps, true ) && ! in_array( $k, $excluded, true ) ) {
                                return 'مراحل باقیمانده بعدی از مرحله (' . TPP_Progress::step_label( $k ) . ')';
                        }
                }
                return '';
        }

        /** «خرابی اعلام‌شده» — برچسب خرابی‌های فعلی (۱.۱۴.۰: چندتایی)؛ خالی وقتی خرابی نیست (متن ساده‌تر ۱.۱۹.۰) */
        private static function failure_line( $summary ) {
                if ( ! is_array( $summary ) ) {
                        return '';
                }
                $labels = (array) ( $summary['failures_labels'] ?? array() );
                $labels = array_values( array_filter( $labels ) );
                return $labels ? implode( '، ', $labels ) : '';
        }

        /**
         * متن قلم گزارش برای یک سرویس — قالب ساده‌شده ۱.۱۹.۰ (درخواست کاربر: بدون شمارش مراحل):
         *   «{پیشوند} (آدرس کامل، نام خیابان یا بلوک، شماره پلاک، شماره واحد) ، دایری سرویس تا مرحله (X) ، مراحل باقیمانده بعدی از مرحله (Y) ، خرابی اعلام‌شده: Z»
         * بخش‌های تهی (بدون خرابی / دایری کامل) حذف می‌شوند تا متن کوتاه و مرتب بماند.
         * $prefix: «رفع مشکل» یا «تحویل سرویس» یا متن اقدام انتخابی از فهرست/دلخواه (افزودن دستی)
         * خروجی: رشته یا WP_Error (سرویس یافت نشد)
         */
        public static function build_line( $service_id, $prefix ) {
                $service_id = (int) $service_id;
                $prefix = trim( (string) $prefix );
                if ( '' === $prefix ) {
                        return new WP_Error( 'tpp_wr_prefix', 'عنوان اقدام خالی است.' );
                }
                $row = tpp()->services()->get( $service_id );
                if ( ! $row ) {
                        return new WP_Error( 'tpp_wr_service', 'سرویس یافت نشد.' );
                }
                $address = tpp()->services()->get_address( (int) $row['address_id'] );
                $summary = TPP_Progress::summary( $row );

                $parts = array( $prefix . ' (' . self::address_line( $address ) . ')' );
                $progress = self::progress_line( $summary );
                if ( '' !== $progress ) {
                        $parts[] = $progress;
                }
                $remaining = self::remaining_line( $summary );
                if ( '' !== $remaining ) {
                        $parts[] = $remaining;
                }
                $failure = self::failure_line( $summary );
                if ( '' !== $failure ) {
                        $parts[] = 'خرابی اعلام‌شده: ' . $failure;
                }
                return implode( ' ، ', $parts );
        }

        /** پیشوند قالب بر اساس نوع فعالیت: ایجاد → «تحویل سرویس»، سایر (جستجو/مشاهده/ویرایش) → «رفع مشکل» */
        public static function prefix_for_action( $action ) {
                return ( 'create' === sanitize_key( (string) $action ) ) ? 'تحویل سرویس' : 'رفع مشکل';
        }

        /* ---------------------------------------------------------------------
         * CRUD
         * ------------------------------------------------------------------- */

        /** افزودن قلم به گزارش روز — خروجی: id قلم جدید یا WP_Error */
        public static function add( $user_id, $date, $content, $service_id = 0 ) {
                $user_id = (int) $user_id;
                if ( $user_id <= 0 ) {
                        return new WP_Error( 'tpp_wr_user', 'کاربر نامعتبر است.' );
                }
                $date = self::norm_date( $date );
                if ( '' === $date ) {
                        $date = TPP_Date::today();
                }
                $content = self::norm_content( $content );
                if ( '' === $content ) {
                        return new WP_Error( 'tpp_wr_content', 'متن گزارش خالی است.' );
                }
                $service_id = (int) $service_id;
                $table = TPP_DB::table( 'work_reports' );
                $next = (int) TPP_DB::get_var( "SELECT COALESCE(MAX(sort), 0) + 1 FROM {$table} WHERE user_id = %d AND report_date = %s", array( $user_id, $date ) );
                $now = TPP_Date::now();
                $id = TPP_DB::insert( 'work_reports', array(
                        'user_id'     => $user_id,
                        'report_date' => $date,
                        'service_id'  => $service_id,
                        'content'     => $content,
                        'sort'        => $next,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                ) );
                return $id ? (int) $id : new WP_Error( 'tpp_wr_insert', 'ثبت قلم گزارش ناموفق بود.' );
        }

        /** ویرایش قلم — فقط مالک قلم (خروجی: bool|WP_Error) */
        public static function update_item( $id, $user_id, $content, $service_id = null ) {
                $id = (int) $id;
                $user_id = (int) $user_id;
                $row = self::get_item( $id );
                if ( ! $row || (int) $row['user_id'] !== $user_id ) {
                        return new WP_Error( 'tpp_wr_not_found', 'قلم گزارش یافت نشد یا متعلق به شما نیست.' );
                }
                $data = array( 'updated_at' => TPP_Date::now() );
                if ( null !== $content ) {
                        $content = self::norm_content( $content );
                        if ( '' === $content ) {
                                return new WP_Error( 'tpp_wr_content', 'متن گزارش خالی است.' );
                        }
                        $data['content'] = $content;
                }
                if ( null !== $service_id ) {
                        $data['service_id'] = (int) $service_id;
                }
                return false !== TPP_DB::update( 'work_reports', $data, array( 'id' => $id ) );
        }

        /** حذف قلم — فقط مالک قلم (خروجی: bool|WP_Error) */
        public static function delete_item( $id, $user_id ) {
                $id = (int) $id;
                $user_id = (int) $user_id;
                $row = self::get_item( $id );
                if ( ! $row || (int) $row['user_id'] !== $user_id ) {
                        return new WP_Error( 'tpp_wr_not_found', 'قلم گزارش یافت نشد یا متعلق به شما نیست.' );
                }
                return false !== TPP_DB::delete( 'work_reports', array( 'id' => $id ) );
        }

        /** یک قلم خام */
        public static function get_item( $id ) {
                return TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'work_reports' ) . " WHERE id = %d", array( (int) $id ) );
        }

        /** شکل‌دهی قلم برای JSON — شماره سرویس + متن + زمان‌ها (عمومی — REST هم استفاده می‌کند) */
        public static function shape_item( $r ) {
                return array(
                        'id'          => (int) $r['id'],
                        'user_id'     => (int) $r['user_id'],
                        'report_date' => (string) $r['report_date'],
                        'service_id'  => (int) ( $r['service_id'] ?? 0 ),
                        'content'     => (string) $r['content'],
                        'sort'        => (int) $r['sort'],
                        'created_at'  => (string) ( $r['created_at'] ?? '' ),
                        'updated_at'  => (string) ( $r['updated_at'] ?? '' ),
                );
        }

        /**
         * گزارش یک روز یک کاربر — خروجی: {date, user_id, user_name, items:[]}
         * (مدیران می‌توانند user_id دیگری ببینند؛ کاربر عادی فقط خودش)
         */
        public static function day( $user_id, $date = '' ) {
                $user_id = (int) $user_id;
                $date = self::norm_date( $date );
                if ( '' === $date ) {
                        $date = TPP_Date::today();
                }
                $table = TPP_DB::table( 'work_reports' );
                $rows = TPP_DB::get_results(
                        "SELECT * FROM {$table} WHERE user_id = %d AND report_date = %s ORDER BY sort ASC, id ASC",
                        array( $user_id, $date )
                );
                $user = get_userdata( $user_id );
                return array(
                        'date'      => $date,
                        'user_id'   => $user_id,
                        'user_name' => $user ? $user->display_name : ( 'کاربر #' . $user_id ),
                        'items'     => array_map( array( 'self', 'shape_item' ), (array) $rows ),
                );
        }

        /** فهرست کاربرانی که گزارش ثبت کرده‌اند (برای فیلتر مدیران) + همه دارندگان دسترسی افزونه */
        public static function users_facet() {
                $table = TPP_DB::table( 'work_reports' );
                $rows = TPP_DB::get_results( "SELECT user_id, COUNT(*) AS cnt, COUNT(DISTINCT report_date) AS days FROM {$table} GROUP BY user_id ORDER BY cnt DESC LIMIT 200" );
                $out = array();
                foreach ( (array) $rows as $r ) {
                        $uid = (int) $r['user_id'];
                        $u = get_userdata( $uid );
                        $out[] = array( 'id' => $uid, 'name' => $u ? $u->display_name : ( 'کاربر #' . $uid ), 'count' => (int) $r['cnt'], 'days' => (int) $r['days'] );
                }
                return $out;
        }

        /** روزهایی که کاربر گزارش دارد (برای تقویم/نمای سریع) */
        public static function days_with_reports( $user_id, $limit = 60 ) {
                $table = TPP_DB::table( 'work_reports' );
                $rows = TPP_DB::get_results(
                        "SELECT report_date, COUNT(*) AS cnt FROM {$table} WHERE user_id = %d GROUP BY report_date ORDER BY report_date DESC LIMIT %d",
                        array( (int) $user_id, (int) $limit )
                );
                $out = array();
                foreach ( (array) $rows as $r ) {
                        $out[] = array( 'date' => (string) $r['report_date'], 'count' => (int) $r['cnt'] );
                }
                return $out;
        }

        /**
         * ۱.۱۹.۰ — کاتالوگ «اقدامات انجام‌شده» برای افزودن دستی گزارش کار.
         * گزینه‌ها از دانش مراحل ۱۶گانه دایری + چهار وضعیت خرابی + جریان کار نصاب ساخته شده‌اند.
         * خروجی: فهرست گروه‌ها [{group, items: [عنوان اقدام, …]}]
         */
        public static function actions() {
                return array(
                        array(
                                'group' => 'عمومی',
                                'items' => array( 'تحویل سرویس', 'رفع مشکل', 'بازدید حضوری', 'پیگیری تلفنی' ),
                        ),
                        array(
                                'group' => 'مراحل دایری',
                                'items' => array(
                                        'بررسی زیرساخت و پیوستگی',
                                        'نصب FAT و اسپلیتر',
                                        'دراپ‌کشی',
                                        'فیوژن سمت FAT',
                                        'فیوژن و نصب ATB داخل واحد',
                                        'تأمین پچ‌کورد',
                                        'تأمین و نصب مودم',
                                        'ثبت‌نام سرویس',
                                        'دریافت اطلاعات سیپ‌فون از مرکز',
                                        'انجام تنظیمات اینترنت',
                                        'ارسال اینترنت به OMC (دکمه ZTP)',
                                        'اتصال اینترنت',
                                        'انجام تنظیمات سیپ‌فون',
                                        'ارسال سیپ‌فون به OMC (دکمه ZTP)',
                                        'اتصال تلفن',
                                ),
                        ),
                        array(
                                'group' => 'خرابی',
                                'items' => array( 'اعلام خرابی مشترک', 'رفع خرابی LOS', 'رفع قطع تلفن', 'رفع قطع اینترنت', 'رفع سایر خرابی' ),
                        ),
                );
        }

        /**
         * ۱.۱۹.۰ — گزارش بازه‌ای (n-روزه/هفتگی/ماهانه): همه اقلام بین دو تاریخ، گروه‌بندی روزانه.
         * خروجی: {from, to, user_id, user_name, days: [{date, items:[]}], total_items, day_counts}
         */
        public static function range( $user_id, $from, $to ) {
                $user_id = (int) $user_id;
                $from = self::norm_date( $from );
                $to   = self::norm_date( $to );
                if ( '' === $from || '' === $to ) {
                        return new WP_Error( 'tpp_wr_range', 'بازه تاریخ معتبر نیست.' );
                }
                if ( strcmp( $from, $to ) > 0 ) {
                        $tmp = $from; $from = $to; $to = $tmp;
                }
                $table = TPP_DB::table( 'work_reports' );
                $rows = TPP_DB::get_results(
                        "SELECT * FROM {$table} WHERE user_id = %d AND report_date >= %s AND report_date <= %s ORDER BY report_date ASC, sort ASC, id ASC",
                        array( $user_id, $from, $to )
                );
                $user = get_userdata( $user_id );
                $days = array();
                $index = array();
                $total = 0;
                foreach ( (array) $rows as $r ) {
                        $date = (string) $r['report_date'];
                        if ( ! isset( $index[ $date ] ) ) {
                                $index[ $date ] = count( $days );
                                $days[] = array( 'date' => $date, 'items' => array() );
                        }
                        $days[ $index[ $date ] ]['items'][] = self::shape_item( $r );
                        $total++;
                }
                return array(
                        'from'       => $from,
                        'to'         => $to,
                        'user_id'    => $user_id,
                        'user_name'  => $user ? $user->display_name : ( 'کاربر #' . $user_id ),
                        'days'       => $days,
                        'total_items'=> $total,
                        'day_counts' => array_map( static function ( $d ) {
                                return array( 'date' => $d['date'], 'count' => count( $d['items'] ) );
                        }, $days ),
                );
        }

        /**
         * افزودن قلم از روی ردیف تاریخچه/فعالیت روز:
         * $src: 'view' (بازدید) | 'change' (تغییر) — $row_id: شناسه رکورد همان جدول
         * خط تولیدی طبق قالب build_line با پیشوند create→«تحویل سرویس» و غیر آن→«رفع مشکل»
         */
        public static function add_from_activity( $user_id, $src, $row_id, $date = '' ) {
                $user_id = (int) $user_id;
                $row_id = (int) $row_id;
                $src = ( 'view' === sanitize_key( (string) $src ) ) ? 'view' : 'change';
                $service_id = 0;
                $action = '';
                if ( 'view' === $src ) {
                        $row = TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'view_log' ) . " WHERE id = %d", array( $row_id ) );
                        if ( ! $row || (int) $row['user_id'] !== $user_id || 'service' !== (string) $row['entity'] ) {
                                return new WP_Error( 'tpp_wr_activity', 'ردیف فعالیت بازدید یافت نشد.' );
                        }
                        $service_id = (int) $row['entity_id'];
                        $action = 'view';
                } else {
                        $row = TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'history' ) . " WHERE id = %d", array( $row_id ) );
                        if ( ! $row || (int) $row['user_id'] !== $user_id || 'service' !== (string) $row['entity'] ) {
                                return new WP_Error( 'tpp_wr_activity', 'ردیف تاریخچه یافت نشد.' );
                        }
                        $service_id = (int) $row['entity_id'];
                        $action = sanitize_key( (string) $row['action'] );
                }
                $prefix = self::prefix_for_action( $action );
                $line = self::build_line( $service_id, $prefix );
                if ( is_wp_error( $line ) ) {
                        return $line;
                }
                $id = self::add( $user_id, $date, $line, $service_id );
                if ( is_wp_error( $id ) ) {
                        return $id;
                }
                return self::shape_item( self::get_item( $id ) );
        }

        /* ---------------------------------------------------------------------
         * نصب — جدول
         * ------------------------------------------------------------------- */

        /** DDL جدول work_reports (در TPP_Install::create_tables استفاده می‌شود) */
        public static function table_sql() {
                $charset = '';
                if ( ! TPP_DB::is_external() ) {
                        global $wpdb;
                        $charset = $wpdb->get_charset_collate();
                } else {
                        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
                }
                return 'CREATE TABLE ' . TPP_DB::table( 'work_reports' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        report_date DATE NOT NULL,
                        service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        content LONGTEXT NULL,
                        sort INT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY user_day (user_id, report_date),
                        KEY service_id (service_id)
                ) " . $charset . ';';
        }

        /** اطمینان از وجود جدول در نصب‌های موجود (dbDelta ممکن است هنگام ارتقا جدول جدید نسازد) */
        public static function ensure_table() {
                $table = TPP_DB::table( 'work_reports' );
                if ( ! $table ) {
                        return;
                }
                $exists = TPP_DB::get_var( "SHOW TABLES LIKE %s", array( $table ) );
                if ( ! $exists ) {
                        TPP_DB::query( self::table_sql() );
                }
        }
}
