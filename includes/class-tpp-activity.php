<?php
/**
 * گزارش فعالیت — ۱.۱۰.۰ / ۱.۱۱.۰
 *  ۱.۱۱.۰ — جستجوهای «در حال تایپ» (آجاکس حرف‌به‌حرف) در پنجره search_dedupe_seconds
 *  در یک رکورد جمع می‌شوند + گزارش یکپارچه همه فعالیت‌ها (unified_log/stats/export_csv).
 *  - تاریخچه بازدید: هر بار که کاربری مشخصات یک سرویس را باز می‌کند ثبت می‌شود
 *    (بازدیدهای پیوسته یک کاربر از یک سرویس در بازه ۱۵ دقیقه در همان رکورد شمرده می‌شود).
 *  - تاریخچه جستجو: عبارت/فیلتر جستجوی هر کاربر + تعداد نتایج
 *    (جستجوی تکراری همان کاربر در بازه ۵ دقیقه در همان رکورد شمرده می‌شود).
 *  - مدت نگهداری هر دو از تنظیمات (view_history_days / search_history_days — ۰ = نامحدود).
 *  - پاک‌سازی: کرون روزانه + بازبینی ساعتیِ سبک در بوت (برای سایت‌های کم‌بازدید که کرون وردپرس اجرا نمی‌شود).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Activity {

        /** بازدیدهای پیوسته یک کاربر از یک سرویس در این بازه (دقیقه) در یک رکورد جمع می‌شود */
        const VIEW_DEDUPE_MIN  = 15;
        /** جستجوی تکراری همان کاربر با همان عبارت/فیلتر در این بازه (دقیقه) در یک رکورد جمع می‌شود */
        const SEARCH_DEDUPE_MIN = 5;

        /* ---------------------------------------------------------------------
         * ثبت
         * ------------------------------------------------------------------- */

        /** ثبت بازدید از یک سرویس (با ادغام بازدیدهای پیوسته) */
        public static function log_view( $user_id, $entity_id, $entity = 'service' ) {
                $user_id   = (int) $user_id;
                $entity_id = (int) $entity_id;
                if ( $user_id <= 0 || $entity_id <= 0 ) {
                        return false;
                }
                $table = TPP_DB::table( 'view_log' );
                $cutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - self::VIEW_DEDUPE_MIN * 60 );
                $recent = TPP_DB::get_row(
                        "SELECT id FROM {$table} WHERE user_id = %d AND entity = %s AND entity_id = %d AND last_at >= %s ORDER BY id DESC LIMIT 1",
                        array( $user_id, $entity, $entity_id, $cutoff )
                );
                $now = TPP_Date::now();
                if ( $recent ) {
                        return TPP_DB::query(
                                "UPDATE {$table} SET views = views + 1, last_at = %s WHERE id = %d",
                                array( $now, (int) $recent['id'] )
                        );
                }
                return TPP_DB::insert( 'view_log', array(
                        'user_id'  => $user_id,
                        'entity'   => ( 'address' === $entity ) ? 'address' : 'service',
                        'entity_id'=> $entity_id,
                        'views'    => 1,
                        'first_at' => $now,
                        'last_at'  => $now,
                ) );
        }

        /**
         * آیا دو عبارت جستجو «در یک جلسه تایپ» هستند؟
         * ادامه‌ی تایپ (پیشوند)، حذف حرف (backspace) یا نزدیکی بالای ۵۰٪ (اصلاح حروف).
         */
        private static function queries_related( $a, $b ) {
                $a = (string) $a;
                $b = (string) $b;
                if ( '' === $a || '' === $b ) {
                        return false;
                }
                if ( $a === $b ) {
                        return true;
                }
                if ( 0 === strpos( $a, $b ) || 0 === strpos( $b, $a ) ) {
                        return true; // تایپ ادامه‌دار یا حذف حروف
                }
                $la = mb_strlen( $a );
                $lb = mb_strlen( $b );
                if ( max( $la, $lb ) > 64 ) {
                        return false; // عبارات بلند → احتمالاً جستجوی واقعاً متفاوت
                }
                $same = similar_text( $a, $b, $pct );
                return $pct >= 50.0;
        }

        /** پنجره ادغام جستجوهای در حال تایپ (ثانیه) از تنظیمات — ۰ = غیرفعال */
        public static function search_dedupe_seconds() {
                $sec = (int) tpp()->settings()->get( 'search_dedupe_seconds', 15 );
                return max( 0, min( 120, $sec ) );
        }

        /** ثبت جستجو (عبارت + فیلترها + تعداد نتایج — با ادغام جستجوی تکراری پیوسته) */
        public static function log_search( $user_id, $query, $filters, $results = 0 ) {
                $user_id = (int) $user_id;
                if ( $user_id <= 0 ) {
                        return false;
                }
                $query   = mb_substr( trim( (string) $query ), 0, 190 );
                $filters = is_array( $filters ) ? $filters : array();
                if ( '' === $query && empty( $filters ) ) {
                        return false; // فهرست ساده بدون جستجو/فیلتر ثبت نمی‌شود
                }
                // کلید یکسان بودن فیلترها (فقط فیلترهای مقداردار، مرتب‌شده)
                $clean_filters = array();
                foreach ( $filters as $k => $v ) {
                        if ( null !== $v && '' !== $v && ! is_array( $v ) ) {
                                $clean_filters[ (string) $k ] = (string) $v;
                        }
                }
                ksort( $clean_filters );
                $filters_json = wp_json_encode( $clean_filters, JSON_UNESCAPED_UNICODE );

                $table  = TPP_DB::table( 'search_log' );
                $now    = TPP_Date::now();

                // ۱) جستجوی تکراری (عبارت + فیلتر یکسان) در ۵ دقیقه → همان رکورد
                $cutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - self::SEARCH_DEDUPE_MIN * 60 );
                $row    = TPP_DB::get_row(
                        "SELECT id FROM {$table} WHERE user_id = %d AND query = %s AND filters = %s AND last_at >= %s ORDER BY id DESC LIMIT 1",
                        array( $user_id, $query, $filters_json, $cutoff )
                );
                if ( $row ) {
                        return TPP_DB::query(
                                "UPDATE {$table} SET searches = searches + 1, results = %d, last_at = %s WHERE id = %d",
                                array( (int) $results, $now, (int) $row['id'] )
                        );
                }

                /*
                 * ۲) ۱.۱۱.۰ — جستجوی «در حال تایپ»: در پنجره چندثانیه‌ای، آخرین رکورد همان کاربر با همان
                 * فیلترها اگر با عبارت جدید در یک جلسه تایپ باشد → همان رکورد با عبارت نهایی به‌روز می‌شود
                 * (جستجوی آجاکسی حرف‌به‌حرف دیگر به‌ازای هر حرف یک رکورد نمی‌سازد).
                 */
                $window = self::search_dedupe_seconds();
                if ( $window > 0 ) {
                        $wcutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - $window );
                        $last    = TPP_DB::get_row(
                                "SELECT id, query FROM {$table} WHERE user_id = %d AND filters = %s AND last_at >= %s ORDER BY id DESC LIMIT 1",
                                array( $user_id, $filters_json, $wcutoff )
                        );
                        if ( $last && self::queries_related( $query, $last['query'] ) ) {
                                return TPP_DB::query(
                                        "UPDATE {$table} SET query = %s, searches = searches + 1, results = %d, last_at = %s WHERE id = %d",
                                        array( $query, (int) $results, $now, (int) $last['id'] )
                                );
                        }
                }

                return TPP_DB::insert( 'search_log', array(
                        'user_id'  => $user_id,
                        'query'    => $query,
                        'filters'  => $filters_json,
                        'results'  => (int) $results,
                        'searches' => 1,
                        'first_at' => $now,
                        'last_at'  => $now,
                ) );
        }

        /* ---------------------------------------------------------------------
         * گزارش‌ها
         * ------------------------------------------------------------------- */

        /** تاریخچه بازدید — $args: user_id, service_id (entity_id), page, per_page */
        public static function views_log( $args = array(), $page = 1, $per_page = 50 ) {
                global $wpdb;
                $table = TPP_DB::table( 'view_log' );
                $where  = ' WHERE entity = %s';
                $params = array( 'service' );
                if ( ! empty( $args['user_id'] ) ) {
                        $where   .= ' AND user_id = %d';
                        $params[] = (int) $args['user_id'];
                }
                if ( ! empty( $args['service_id'] ) ) {
                        $where   .= ' AND entity_id = %d';
                        $params[] = (int) $args['service_id'];
                }
                $total = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$table}{$where}", $params );
                $offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
                $rows = TPP_DB::get_results(
                        "SELECT * FROM {$table}{$where} ORDER BY last_at DESC, id DESC LIMIT %d OFFSET %d",
                        array_merge( $params, array( (int) $per_page, $offset ) )
                );
                return array(
                        'total' => $total,
                        'rows'  => self::decorate_views( $rows ),
                        'users' => self::users_facet( 'view_log' ),
                );
        }

        /** تاریخچه جستجو — $args: user_id, q (عبارت), page, per_page */
        public static function searches_log( $args = array(), $page = 1, $per_page = 50 ) {
                $table = TPP_DB::table( 'search_log' );
                $where  = ' WHERE 1=1';
                $params = array();
                if ( ! empty( $args['user_id'] ) ) {
                        $where   .= ' AND user_id = %d';
                        $params[] = (int) $args['user_id'];
                }
                if ( isset( $args['q'] ) && '' !== trim( (string) $args['q'] ) ) {
                        $where   .= ' AND query LIKE %s';
                        $params[] = '%' . TPP_DB::esc_like( trim( (string) $args['q'] ) ) . '%';
                }
                $total = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$table}{$where}", $params );
                $offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
                $rows = TPP_DB::get_results(
                        "SELECT * FROM {$table}{$where} ORDER BY last_at DESC, id DESC LIMIT %d OFFSET %d",
                        array_merge( $params, array( (int) $per_page, $offset ) )
                );
                foreach ( (array) $rows as &$r ) {
                        $r['filters'] = json_decode( (string) $r['filters'], true );
                }
                unset( $r );
                return array(
                        'total' => $total,
                        'rows'  => self::decorate_views( $rows ),
                        'users' => self::users_facet( 'search_log' ),
                );
        }

        /** آخرین بازدیدهای یک سرویس (برای نمایش در صفحه خود سرویس) */
        public static function recent_views( $entity_id, $limit = 8 ) {
                $table = TPP_DB::table( 'view_log' );
                $rows  = TPP_DB::get_results(
                        "SELECT * FROM {$table} WHERE entity = %s AND entity_id = %d ORDER BY last_at DESC, id DESC LIMIT %d",
                        array( 'service', (int) $entity_id, (int) $limit )
                );
                return self::decorate_views( $rows );
        }

        /** افزودن نام کاربر به ردیف‌های گزارش */
        private static function decorate_views( $rows ) {
                $users = array();
                $out   = array();
                foreach ( (array) $rows as $r ) {
                        $uid = (int) $r['user_id'];
                        if ( ! isset( $users[ $uid ] ) ) {
                                $u = get_userdata( $uid );
                                $users[ $uid ] = $u ? $u->display_name : ( $uid ? ( 'کاربر #' . $uid ) : '—' );
                        }
                        $r['user_name'] = $users[ $uid ];
                        if ( isset( $r['views'] ) ) {
                                $r['views'] = (int) $r['views'];
                        }
                        if ( isset( $r['searches'] ) ) {
                                $r['searches'] = (int) $r['searches'];
                        }
                        if ( isset( $r['results'] ) ) {
                                $r['results'] = (int) $r['results'];
                        }
                        $r['user_id'] = $uid;
                        $out[] = $r;
                }
                return $out;
        }

        /** کاربران حاضر در گزارش (برای فیلتر) */
        private static function users_facet( $table_name ) {
                $table = TPP_DB::table( $table_name );
                $rows  = TPP_DB::get_results( "SELECT user_id, COUNT(*) AS cnt FROM {$table} GROUP BY user_id ORDER BY cnt DESC LIMIT 100" );
                $users = array();
                foreach ( (array) $rows as $r ) {
                        $uid = (int) $r['user_id'];
                        $u   = get_userdata( $uid );
                        $users[] = array(
                                'id'   => $uid,
                                'name' => $u ? $u->display_name : ( 'کاربر #' . $uid ),
                                'count'=> (int) $r['cnt'],
                        );
                }
                return $users;
        }

        /* ---------------------------------------------------------------------
         * گزارش یکپارچه همه فعالیت‌ها (۱.۱۱.۰)
         * تاریخچه تغییرات + بازدید + جستجو + پیامک — در یک فید مرتب بر اساس زمان.
         * $args: user_id, type(change|view|search|sms|all), action, service_id, q, from, to (Y-m-d)
         * ------------------------------------------------------------------- */

        /** شرط‌های مشترک فیلتر + پارامترها برای فید/آمار/خروجی */
        private static function unified_filter( $args, array &$params, $ts_col_prefixes = array() ) {
                $where = ' WHERE 1=1';
                if ( ! empty( $args['user_id'] ) ) {
                        $where   .= ' AND user_id = %d';
                        $params[] = (int) $args['user_id'];
                }
                if ( ! empty( $args['service_id'] ) ) {
                        $where   .= ' AND target_id = %d';
                        $params[] = (int) $args['service_id'];
                }
                if ( ! empty( $args['action'] ) ) {
                        $where   .= ' AND action = %s';
                        $params[] = sanitize_key( (string) $args['action'] );
                }
                if ( isset( $args['q'] ) && '' !== trim( (string) $args['q'] ) ) {
                        $where   .= ' AND title LIKE %s';
                        $params[] = '%' . TPP_DB::esc_like( trim( (string) $args['q'] ) ) . '%';
                }
                if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
                        $where   .= ' AND ts >= %s';
                        $params[] = (string) $args['from'] . ' 00:00:00';
                }
                if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
                        $where   .= ' AND ts <= %s';
                        $params[] = (string) $args['to'] . ' 23:59:59';
                }
                return $where;
        }

        /** ساخت UNION یکپارچه (view ناشناس لازم نیست — کوئری زیرشاخه‌ای) */
        private static function unified_sql( array $args ) {
                $h = TPP_DB::table( 'history' );
                $v = TPP_DB::table( 'view_log' );
                $s = TPP_DB::table( 'search_log' );
                $m = TPP_DB::table( 'sms_log' );

                // عنوان رکورد تغییر: «سرویس #12 — ۳ فیلد» + منبع
                $changes_title = "CONCAT('سرویس #', entity_id, ' — ', GREATEST(event_count, 1), ' رویداد')";
                $changes_extra = "CONCAT('{\"s\":\"', source, '\"}')";

                $parts = array();
                // تغییرات (ایجاد/ویرایش/حذف/ادغام/بازگردانی) — فقط entity سرویس
                $parts[] = "SELECT 'change' AS src, id, user_id, entity_id AS target_id, action, {$changes_title} AS title, changed_at AS ts, {$changes_extra} AS extra FROM {$h} WHERE entity = 'service'";
                // بازدیدها
                $parts[] = "SELECT 'view' AS src, id, user_id, entity_id AS target_id, 'view' AS action, CONCAT('سرویس #', entity_id, ' — ', views, ' بازدید') AS title, last_at AS ts, CONCAT('{\"v\":', views, '}') AS extra FROM {$v} WHERE entity = 'service'";
                // جستجوها — عنوان = خود عبارت
                $parts[] = "SELECT 'search' AS src, id, user_id, 0 AS target_id, 'search' AS action, CONCAT('\"', REPLACE(query, '\"', ''), '\"') AS title, last_at AS ts, CONCAT('{\"n\":', searches, ',\"r\":', results, '}') AS extra FROM {$s}";
                // پیامک‌ها
                $parts[] = "SELECT 'sms' AS src, id, user_id, service_id AS target_id, 'sms' AS action, CONCAT('پیامک به ', mobile) AS title, created_at AS ts, CONCAT('{\"m\":\"', REPLACE(SUBSTRING(COALESCE(message, ''), 1, 80), '\"', ''), '\"}') AS extra FROM {$m}";

                $type = isset( $args['type'] ) ? strtolower( (string) $args['type'] ) : 'all';
                if ( 'all' !== $type && in_array( $type, array( 'change', 'view', 'search', 'sms' ), true ) ) {
                        $keep = array_search( $type, array( 'change', 'view', 'search', 'sms' ), true );
                        $parts = array( $parts[ $keep ] );
                }
                $sql = implode( ' UNION ALL ', $parts );

                $params = array();
                $where  = self::unified_filter( $args, $params );
                return array( "SELECT * FROM ({$sql}) AS u" . $where, $params );
        }

        /** فید یکپارچه همه فعالیت‌ها — مرتب از جدید به قدیم با صفحه‌بندی */
        public static function unified_log( $args = array(), $page = 1, $per_page = 50 ) {
                list( $sql, $params ) = self::unified_sql( $args );
                $total = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM ({$sql}) AS c", $params );
                $offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
                $rows = TPP_DB::get_results(
                        $sql . " ORDER BY ts DESC, id DESC LIMIT %d OFFSET %d",
                        array_merge( $params, array( (int) $per_page, $offset ) )
                );
                foreach ( (array) $rows as &$r ) {
                        $r['extra']   = json_decode( (string) $r['extra'], true );
                        $r['target_id'] = (int) $r['target_id'];
                        $r['id']      = (int) $r['id'];
                        $r['user_id'] = (int) $r['user_id'];
                }
                unset( $r );
                // ۱.۱۲.۰ — رویدادهای تغییرِ حاوی تغییر پیشرفت دایری در عنوان علامت می‌گیرند
                self::annotate_progress_titles( $rows );
                return array(
                        'total' => $total,
                        'rows'  => self::decorate_views( $rows ),
                        'users' => self::users_facet_all(),
                );
        }

        /** علامت «🚀 پیشرفت دایری» روی ردیف‌های تغییری که شامل تغییر مراحل/خرابی سرویس هستند */
        private static function annotate_progress_titles( array &$rows ) {
                $h = TPP_DB::table( 'history' );
                if ( ! $h || empty( $rows ) ) {
                        return;
                }
                $ids = array();
                foreach ( $rows as $r ) {
                        if ( 'change' === (string) $r['src'] ) {
                                $ids[] = (int) $r['id'];
                        }
                }
                if ( ! $ids ) {
                        return;
                }
                $ids_in = implode( ',', array_map( 'intval', $ids ) );
                $hrows  = TPP_DB::get_results( "SELECT id, changes FROM {$h} WHERE id IN ({$ids_in})", ARRAY_A );
                $marked = array();
                foreach ( (array) $hrows as $hr ) {
                        $changes = json_decode( (string) $hr['changes'], true );
                        if ( ! is_array( $changes ) ) {
                                continue;
                        }
                        $has = isset( $changes['_progress_steps'] ) || isset( $changes['_progress_failure'] );
                        if ( ! $has && isset( $changes['fmt'] ) && 2 === (int) $changes['fmt'] && ! empty( $changes['events'] ) && is_array( $changes['events'] ) ) {
                                foreach ( $changes['events'] as $ev ) {
                                        if ( is_array( $ev ) && isset( $ev['c'] ) && is_array( $ev['c'] ) && ( isset( $ev['c']['_progress_steps'] ) || isset( $ev['c']['_progress_failure'] ) ) ) {
                                                $has = true;
                                                break;
                                        }
                                }
                        }
                        if ( $has ) {
                                $marked[ (int) $hr['id'] ] = true;
                        }
                }
                if ( ! $marked ) {
                        return;
                }
                foreach ( $rows as &$r ) {
                        if ( 'change' === (string) $r['src'] && isset( $marked[ (int) $r['id'] ] ) ) {
                                $r['title'] .= ' — 🚀 پیشرفت دایری';
                        }
                }
                unset( $r );
        }

        /** کاربران حاضر در هر سه جدول (برای فیلتر فید یکپارچه) */
        private static function users_facet_all() {
                $h = TPP_DB::table( 'history' );
                $v = TPP_DB::table( 'view_log' );
                $s = TPP_DB::table( 'search_log' );
                $sql = "SELECT user_id, SUM(cnt) AS cnt FROM ("
                        . "SELECT user_id, COUNT(*) AS cnt FROM {$h} GROUP BY user_id"
                        . " UNION ALL SELECT user_id, COUNT(*) AS cnt FROM {$v} GROUP BY user_id"
                        . " UNION ALL SELECT user_id, COUNT(*) AS cnt FROM {$s} GROUP BY user_id"
                        . ") AS t GROUP BY user_id ORDER BY cnt DESC LIMIT 100";
                $users = array();
                foreach ( (array) TPP_DB::get_results( $sql ) as $r ) {
                        $uid = (int) $r['user_id'];
                        $u   = get_userdata( $uid );
                        $users[] = array(
                                'id'    => $uid,
                                'name'  => $u ? $u->display_name : ( 'کاربر #' . $uid ),
                                'count' => (int) $r['cnt'],
                        );
                }
                return $users;
        }

        /** آمار فعالیت برای کارت‌های بالای گزارش: بازه‌ها + کاربران برتر + سرویس‌های پربازدید + عبارات پرتکرار */
        public static function stats() {
                $h = TPP_DB::table( 'history' );
                $v = TPP_DB::table( 'view_log' );
                $s = TPP_DB::table( 'search_log' );

                $ranges = array();
                foreach ( array( 'today' => 0, 'd7' => 6, 'd30' => 29 ) as $label => $days_back ) {
                        $day  = gmdate( 'Y-m-d', TPP_Date::ts() - $days_back * DAY_IN_SECONDS );
                        $from = $day . ' 00:00:00';
                        $ranges[ $label ] = array(
                                'changes'  => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$h} WHERE changed_at >= %s", array( $from ) ),
                                'views'    => (int) TPP_DB::get_var( "SELECT COALESCE(SUM(views), 0) FROM {$v} WHERE last_at >= %s", array( $from ) ),
                                'searches' => (int) TPP_DB::get_var( "SELECT COALESCE(SUM(searches), 0) FROM {$s} WHERE last_at >= %s", array( $from ) ),
                        );
                }

                // برترین کاربران (مجموع اقدامات هر جدول)
                $top_users = array();
                $sql = "SELECT user_id, SUM(cnt) AS cnt FROM ("
                        . "SELECT user_id, COUNT(*) AS cnt FROM {$h} GROUP BY user_id"
                        . " UNION ALL SELECT user_id, SUM(views) AS cnt FROM {$v} GROUP BY user_id"
                        . " UNION ALL SELECT user_id, SUM(searches) AS cnt FROM {$s} GROUP BY user_id"
                        . ") AS t GROUP BY user_id ORDER BY cnt DESC LIMIT 8";
                foreach ( (array) TPP_DB::get_results( $sql ) as $r ) {
                        $uid = (int) $r['user_id'];
                        $u   = get_userdata( $uid );
                        $top_users[] = array( 'id' => $uid, 'name' => $u ? $u->display_name : ( 'کاربر #' . $uid ), 'count' => (int) $r['cnt'] );
                }

                // پربازدیدترین سرویس‌ها
                $top_services = array();
                $rows = TPP_DB::get_results( "SELECT entity_id, SUM(views) AS views, COUNT(*) AS users FROM {$v} WHERE entity = 'service' GROUP BY entity_id ORDER BY views DESC LIMIT 8" );
                foreach ( (array) $rows as $r ) {
                        $top_services[] = array( 'id' => (int) $r['entity_id'], 'views' => (int) $r['views'], 'users' => (int) $r['users'] );
                }

                // پرتکرارترین عبارات جستجو
                $top_queries = array();
                $rows = TPP_DB::get_results( "SELECT query, SUM(searches) AS cnt, MAX(results) AS results FROM {$s} GROUP BY query ORDER BY cnt DESC LIMIT 10" );
                foreach ( (array) $rows as $r ) {
                        $q = trim( (string) $r['query'] );
                        if ( '' !== $q ) {
                                $top_queries[] = array( 'query' => $q, 'count' => (int) $r['cnt'], 'results' => (int) $r['results'] );
                        }
                }

                return array(
                        'ranges'       => $ranges,
                        'top_users'    => $top_users,
                        'top_services' => $top_services,
                        'top_queries'  => $top_queries,
                        'dedupe_sec'   => self::search_dedupe_seconds(),
                );
        }

        /** خروجی CSV فید یکپارچه (با BOM برای اکسل فارسی) — خروجی: متن CSV */
        public static function export_csv( $args = array(), $limit = 5000 ) {
                list( $sql, $params ) = self::unified_sql( $args );
                $rows = TPP_DB::get_results( $sql . " ORDER BY ts DESC, id DESC LIMIT %d", array_merge( $params, array( (int) $limit ) ) );
                self::annotate_progress_titles( $rows ); // ۱.۱۲.۰ — همان علامت پیشرفت دایری

                $action_fa = array(
                        'create' => 'ایجاد', 'update' => 'ویرایش', 'delete' => 'حذف', 'merge' => 'ادغام', 'restore' => 'بازگردانی',
                        'view' => 'بازدید', 'search' => 'جستجو', 'sms' => 'پیامک',
                );
                $src_fa = array( 'change' => 'تغییر', 'view' => 'بازدید', 'search' => 'جستجو', 'sms' => 'پیامک' );

                $out = "\u{FEFF}زمان,نوع,عملیات,کاربر,هدف,شرح\r\n";
                $users = array();
                foreach ( (array) $rows as $r ) {
                        $uid = (int) $r['user_id'];
                        if ( ! isset( $users[ $uid ] ) ) {
                                $u = get_userdata( $uid );
                                $users[ $uid ] = $u ? $u->display_name : ( 'کاربر #' . $uid );
                        }
                        $target = ( 'search' === $r['src'] ) ? '—' : ( 'سرویس #' . (int) $r['target_id'] );
                        $line = array(
                                (string) $r['ts'],
                                $src_fa[ $r['src'] ] ?? $r['src'],
                                $action_fa[ $r['action'] ] ?? $r['action'],
                                $users[ $uid ],
                                $target,
                                (string) $r['title'],
                        );
                        $out .= implode( ',', array_map( static function ( $cell ) {
                                $c = str_replace( '"', '""', (string) $cell );
                                return '"' . $c . '"';
                        }, $line ) ) . "\r\n";
                }
                return $out;
        }

        /* ---------------------------------------------------------------------
         * ۱.۱۹.۰ — فید فعالیت برای «گزارش کار» + روزهای دارای فعالیت + نگهداشت
         * ------------------------------------------------------------------- */

        /**
         * فید فعالیت یک روز کاربر برای بخش گزارش کار (۱.۱۹.۰):
         * تغییرات + بازدید + پیامک همان روز — «بدون جستجوها» (درخواست کاربر: فعالیت‌های جستجو
         * برای افزودن به گزارش کار نمایش داده نشوند). هر ردیفِ قابل افزودن، آدرس کامل سرویس
         * (آدرس کامل/بلوک/پلاک/واحد + شماره مجازی) را هم دارد.
         */
        public static function workreport_feed( $user_id, $date, $page = 1, $per_page = 50 ) {
                $user_id = (int) $user_id;
                $date    = trim( (string) $date );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                        $date = TPP_Date::today();
                }
                $h = TPP_DB::table( 'history' );
                $v = TPP_DB::table( 'view_log' );
                $m = TPP_DB::table( 'sms_log' );

                $changes_title = "CONCAT('سرویس #', entity_id, ' — ', GREATEST(event_count, 1), ' رویداد')";
                $parts = array(
                        "SELECT 'change' AS src, id, user_id, entity_id AS target_id, action, {$changes_title} AS title, changed_at AS ts, '[]' AS extra FROM {$h} WHERE entity = 'service'",
                        "SELECT 'view' AS src, id, user_id, entity_id AS target_id, 'view' AS action, CONCAT('سرویس #', entity_id, ' — ', views, ' بازدید') AS title, last_at AS ts, CONCAT('{\"v\":', views, '}') AS extra FROM {$v} WHERE entity = 'service'",
                        "SELECT 'sms' AS src, id, user_id, service_id AS target_id, 'sms' AS action, CONCAT('پیامک به ', mobile) AS title, created_at AS ts, '[]' AS extra FROM {$m}",
                );
                $sql  = implode( ' UNION ALL ', $parts );
                $from = $date . ' 00:00:00';
                $to   = $date . ' 23:59:59';
                $where = " WHERE user_id = %d AND ts >= %s AND ts <= %s";
                $params = array( $user_id, $from, $to );

                $inner = "SELECT * FROM ({$sql}) AS u" . $where;
                $total = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM ({$inner}) AS c", $params );
                $offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
                $rows = TPP_DB::get_results(
                        $inner . " ORDER BY ts DESC, id DESC LIMIT %d OFFSET %d",
                        array_merge( $params, array( (int) $per_page, $offset ) )
                );
                foreach ( (array) $rows as &$r ) {
                        $r['extra']     = json_decode( (string) $r['extra'], true );
                        $r['target_id'] = (int) $r['target_id'];
                        $r['id']        = (int) $r['id'];
                        $r['user_id']   = (int) $r['user_id'];
                }
                unset( $r );
                self::annotate_progress_titles( $rows );
                self::enrich_service_info( $rows );
                return array(
                        'date'  => $date,
                        'total' => $total,
                        'rows'  => self::decorate_views( $rows ),
                );
        }

        /** افزودن اطلاعات سرویس/آدرس به ردیف‌های فید (batch — یک کوئری برای سرویس‌ها، یکی برای آدرس‌ها) */
        private static function enrich_service_info( array &$rows ) {
                $svc_ids = array();
                foreach ( $rows as $r ) {
                        $tid = (int) $r['target_id'];
                        if ( $tid > 0 && 'search' !== (string) $r['src'] ) {
                                $svc_ids[ $tid ] = true;
                        }
                }
                if ( ! $svc_ids ) {
                        foreach ( $rows as &$r ) {
                                $r['svc'] = null;
                        }
                        unset( $r );
                        return;
                }
                $ids_in = implode( ',', array_map( 'intval', array_keys( $svc_ids ) ) );
                $st = TPP_DB::table( 'services' );
                $services = array();
                foreach ( (array) TPP_DB::get_results( "SELECT * FROM {$st} WHERE id IN ({$ids_in})" ) as $s ) {
                        $services[ (int) $s['id'] ] = $s;
                }
                $addr_ids = array();
                foreach ( $services as $s ) {
                        if ( ! empty( $s['address_id'] ) ) {
                                $addr_ids[ (int) $s['address_id'] ] = true;
                        }
                }
                $addresses = array();
                if ( $addr_ids ) {
                        $at = TPP_DB::table( 'addresses' );
                        $addr_in = implode( ',', array_map( 'intval', array_keys( $addr_ids ) ) );
                        foreach ( (array) TPP_DB::get_results( "SELECT * FROM {$at} WHERE id IN ({$addr_in})" ) as $a ) {
                                $addresses[ (int) $a['id'] ] = $a;
                        }
                }
                foreach ( $rows as &$r ) {
                        $tid = (int) $r['target_id'];
                        if ( $tid <= 0 || ! isset( $services[ $tid ] ) ) {
                                $r['svc'] = null;
                                continue;
                        }
                        $s   = $services[ $tid ];
                        $a   = ! empty( $s['address_id'] ) && isset( $addresses[ (int) $s['address_id'] ] ) ? $addresses[ (int) $s['address_id'] ] : null;
                        $r['svc'] = array(
                                'id'            => $tid,
                                'full_address'  => $a ? (string) ( $a['f_full_address'] ?? '' ) : '',
                                'block'         => $a ? (string) ( $a['f_block'] ?? '' ) : '',
                                'plate'         => $a ? (string) ( $a['f_plate'] ?? '' ) : '',
                                'unit'          => $a ? (string) ( $a['f_unit'] ?? '' ) : '',
                                'virtual_number'=> (string) ( $s['f_virtual_number'] ?? '' ),
                        );
                }
                unset( $r );
        }

        /** روزهایی که کاربر فعالیت (تغییر/بازدید/جستجو/پیامک) ثبت کرده — برای هایلایت تقویم ۱.۱۹.۰ */
        public static function activity_days( $user_id, $from, $to, $limit = 100 ) {
                $user_id = (int) $user_id;
                $from    = trim( (string) $from );
                $to      = trim( (string) $to );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
                        return array();
                }
                $h = TPP_DB::table( 'history' );
                $v = TPP_DB::table( 'view_log' );
                $s = TPP_DB::table( 'search_log' );
                $m = TPP_DB::table( 'sms_log' );
                $sql = "SELECT DISTINCT d AS day FROM ("
                        . "SELECT DATE(changed_at) AS d FROM {$h} WHERE user_id = %d AND changed_at >= %s AND changed_at <= %s"
                        . " UNION SELECT DISTINCT DATE(last_at) FROM {$v} WHERE user_id = %d AND last_at >= %s AND last_at <= %s"
                        . " UNION SELECT DISTINCT DATE(last_at) FROM {$s} WHERE user_id = %d AND last_at >= %s AND last_at <= %s"
                        . " UNION SELECT DISTINCT DATE(created_at) FROM {$m} WHERE user_id = %d AND created_at >= %s AND created_at <= %s"
                        . ") AS t ORDER BY day DESC LIMIT %d";
                $f = $from . ' 00:00:00';
                $t = $to . ' 23:59:59';
                $rows = TPP_DB::get_results( $sql, array(
                        $user_id, $f, $t,
                        $user_id, $f, $t,
                        $user_id, $f, $t,
                        $user_id, $f, $t,
                        (int) $limit,
                ) );
                $out = array();
                foreach ( (array) $rows as $r ) {
                        $out[] = (string) ( $r['day'] ?? '' );
                }
                return array_values( array_filter( $out ) );
        }

        /** اطلاعات نگهداشت تاریخچه فعالیت برای اخطار کاربر (۱.۱۹.۰) — days = کوتاه‌ترین بازه فعال (۰ = نامحدود) */
        public static function retention_info() {
                $settings = tpp()->settings();
                $view   = max( 0, (int) $settings->get( 'view_history_days', 180 ) );
                $search = max( 0, (int) $settings->get( 'search_history_days', 180 ) );
                $hist   = max( 0, (int) $settings->get( 'history_days', 0 ) );
                $active = array_filter( array( $view, $search, $hist ) );
                $days   = $active ? min( $active ) : 0;
                return array(
                        'days'         => $days,
                        'unlimited'    => ( 0 === $days ),
                        'view_days'    => $view,
                        'search_days'  => $search,
                        'history_days' => $hist,
                );
        }

        /* ---------------------------------------------------------------------
         * پاک‌سازی دوره‌ای (مدت نگهداری از تنظیمات)
         * ۱.۱۹.۰ — بهینه‌سازی: حذف تکه‌ای (LIMIT) برای جلوگیری از قفل طولانی جدول‌های
         * بزرگ + بازبینی سبک هر ۱۵ دقیقه (به‌جای ۱ ساعت) + حذف فقط از جدول‌هایی که
         * واقعاً رکورد قدیمی دارند. مرجع حذف همان «زمان انجام فعالیت» است (n روز پس از آن).
         * ------------------------------------------------------------------- */

        /** حذف رکوردهای قدیمی‌تر از مدت نگهداری — خروجی: تعداد حذف‌شده هر بخش */
        public static function cleanup( $only = array() ) {
                $out     = array();
                $settings = tpp()->settings();
                $targets = array(
                        'view_log'   => array( (int) $settings->get( 'view_history_days', 180 ), 'last_at' ),
                        'search_log' => array( (int) $settings->get( 'search_history_days', 180 ), 'last_at' ),
                        'history'    => array( (int) $settings->get( 'history_days', 0 ), 'changed_at' ),
                        // ۱.۲۰.۰ — سجل بازبینی اقدامات نصاب‌ها هم از همان نگهداشت تاریخچه پیروی می‌کند
                        'installer_changes' => array( (int) $settings->get( 'history_days', 0 ), 'created_at' ),
                );
                if ( ! empty( $only ) && is_array( $only ) ) {
                        $targets = array_intersect_key( $targets, array_fill_keys( array_map( 'sanitize_key', array_map( 'strval', $only ) ), true ) );
                }
                foreach ( $targets as $table_name => $spec ) {
                        if ( $spec[0] > 0 ) {
                                $out[ $table_name ] = self::delete_older_than( $table_name, $spec[0], $spec[1] );
                        }
                }
                return $out;
        }

        /** حذف تکه‌ای: دسته‌های ۵۰۰۰تایی تا تخلیه کامل — قفل کوتاه، حافظه-controlled */
        private static function delete_older_than( $table_name, $days, $column ) {
                $table  = TPP_DB::table( $table_name );
                $cutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - $days * DAY_IN_SECONDS );
                $total  = 0;
                // حداکثر ۱۰۰ دسته (۵۰۰٫۰۰۰ ردیف) در هر اجرا — باقیمانده در اجرای بعدی پاک می‌شود
                for ( $i = 0; $i < 100; $i++ ) {
                        $deleted = TPP_DB::query( "DELETE FROM {$table} WHERE {$column} < %s LIMIT 5000", array( $cutoff ) );
                        if ( ! is_numeric( $deleted ) || (int) $deleted <= 0 ) {
                                break;
                        }
                        $total += (int) $deleted;
                        if ( (int) $deleted < 5000 ) {
                                break; // آخرین دسته
                        }
                }
                return $total;
        }

        /** اجرای کرون روزانه */
        public static function cron_cleanup() {
                self::cleanup();
        }

        /**
         * بازبینی سبک در بوت — حداکثر یک‌بار در ۱۵ دقیقه (۱.۱۹.۰ — قبلاً ۱ ساعت؛
         * برای سایت‌هایی که کرون وردپرس به‌موقع اجرا نمی‌شود و رعایت دقیق‌تر «n روز از زمان انجام»).
         * فقط وقتی واقعاً رکورد قدیمی وجود دارد DELETE اجرا می‌شود — و فقط روی همان جدول.
         */
        const CLEANUP_CHECK_EVERY = 900; // ۱۵ دقیقه

        public static function maybe_cleanup() {
                $last = (int) get_option( 'tpp_last_activity_cleanup', 0 );
                if ( $last > TPP_Date::ts() - self::CLEANUP_CHECK_EVERY ) {
                        return;
                }
                update_option( 'tpp_last_activity_cleanup', TPP_Date::ts(), false );
                $settings = tpp()->settings();
                $checks = array(
                        array( 'view_log',   (int) $settings->get( 'view_history_days', 180 ), 'last_at' ),
                        array( 'search_log', (int) $settings->get( 'search_history_days', 180 ), 'last_at' ),
                        array( 'history',    (int) $settings->get( 'history_days', 0 ), 'changed_at' ),
                );
                $stale = array();
                foreach ( $checks as $c ) {
                        if ( $c[1] <= 0 ) {
                                continue;
                        }
                        $table  = TPP_DB::table( $c[0] );
                        $cutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - $c[1] * DAY_IN_SECONDS );
                        $has    = TPP_DB::get_var( "SELECT 1 FROM {$table} WHERE {$c[2]} < %s LIMIT 1", array( $cutoff ) );
                        if ( $has ) {
                                $stale[] = $c[0];
                        }
                }
                if ( $stale ) {
                        self::cleanup( $stale ); // فقط همان جدول‌ها — بدون DELETE بی‌مورد روی بقیه
                }
        }
}
