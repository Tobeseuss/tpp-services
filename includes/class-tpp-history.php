<?php
/**
 * تاریخچه تغییرات — ۱.۱۰.۰ تجمیع روزانه:
 *  همه تغییرات یک سرویس/آدرس توسط یک کاربر در یک روز، در «یک رکورد» جمع می‌شود
 *  (فرمت changes نسخه ۲: فهرست رویدادهای همان روز با ساعت/عمل/منبع).
 *  تغییرات فیلدهای آدرس که در ذخیره یک سرویس رخ می‌دهند، به‌عنوان رویدادِ همان رکورد سرویس
 *  ثبت می‌شوند (بدون رکورد جداگانه — رفع دوبرابر شدن تاریخچه در ثبت/ایمپورت گروهی).
 *  با حذف کامل سرویس، تاریخچه آن سرویس هم حذف می‌شود (درخواست کاربر)؛
 *  رکوردهای تاریخچه را مدیر می‌تواند تک‌تک یا گروهی حذف کند.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_History {

        /** سقف رویدادها در یک رکورد تجمیع‌شده (بیشتر از این → رکورد جدید همان روز) */
        const MAX_EVENTS = 150;

        /**
         * ثبت یک رکورد تاریخچه
         * $args: entity(service|address), entity_id, address_id, user_id, action(create|update|import|delete|restore|sync),
         *        source(online|offline|import|api|sync), changes(array slug=>[old,new]) — رویداد اصلی (موجودیت رکورد),
         *        addr_changes(array) + addr_action + addr_revision — رویداد فیلدهای آدرسِ همان ذخیره (ادغام در همین رکورد),
         *        is_conflict, changed_at, no_aggregate(bool)
         */
        public static function record( $args ) {
                $entity    = ( 'address' === ( $args['entity'] ?? '' ) ) ? 'address' : 'service';
                $entity_id = (int) ( $args['entity_id'] ?? 0 );
                $user_id   = (int) ( $args['user_id'] ?? get_current_user_id() );
                $action    = sanitize_key( (string) ( $args['action'] ?? 'update' ) );
                $source    = sanitize_key( (string) ( $args['source'] ?? 'online' ) );
                $changes   = (array) ( $args['changes'] ?? array() );
                $now       = TPP_Date::now();

                // رویداد اصلی + رویداد آدرسِ همان ذخیره → یک رکورد
                $events = array();
                if ( ! empty( $changes ) || 'delete' === $action ) {
                        $events[] = array( 'y' => $entity, 'a' => $action, 's' => $source, 'c' => $changes );
                }
                if ( ! empty( $args['addr_changes'] ) && is_array( $args['addr_changes'] ) ) {
                        $events[] = array( 'y' => 'address', 'a' => sanitize_key( (string) ( $args['addr_action'] ?? 'update' ) ), 's' => $source, 'c' => $args['addr_changes'] );
                }

                /* تجمیع روزانه: همه تغییرات یک موجودیت توسط یک کاربر در یک روز → یک رکورد.
                   حذف و تعارض همیشه رکورد جداگانه دارند. */
                $aggregate = (int) tpp()->settings()->get( 'history_daily', 1 )
                        && empty( $args['no_aggregate'] )
                        && 'delete' !== $action
                        && empty( $args['is_conflict'] );

                if ( $aggregate ) {
                        $day = TPP_Date::today();
                        $table = TPP_DB::table( 'history' );
                        $today_row = TPP_DB::get_row(
                                "SELECT * FROM {$table} WHERE entity = %s AND entity_id = %d AND user_id = %d AND agg_day = %s AND is_conflict = 0 ORDER BY id DESC LIMIT 1",
                                array( $entity, $entity_id, $user_id, $day )
                        );
                        if ( $today_row && (int) $today_row['event_count'] < self::MAX_EVENTS ) {
                                $merged = self::row_changes_to_fmt2( $today_row );
                                foreach ( $events as $ev ) {
                                        $ev['t'] = TPP_Date::his();
                                        $merged['events'][] = $ev;
                                }
                                $last = end( $merged['events'] );
                                $ok = TPP_DB::update( 'history', array(
                                        'changes'     => self::encode_changes( $merged ),
                                        'changed_at'  => $now,
                                        'revision'    => (int) ( $args['revision'] ?? ( $last['r'] ?? $today_row['revision'] ) ),
                                        'action'      => $merged['events'][0]['a'],
                                        'source'      => $source, // ۱.۱۰.۰ — منبع آخرین رویداد (مثل merge) در سطح رکورد هم دیده شود
                                        'event_count' => count( $merged['events'] ),
                                ), array( 'id' => (int) $today_row['id'] ) );
                                if ( false !== $ok ) {
                                        return (int) $today_row['id'];
                                }
                        }
                }

                $row = array(
                        'entity'      => $entity,
                        'entity_id'   => $entity_id,
                        'address_id'  => (int) ( $args['address_id'] ?? 0 ),
                        'revision'    => (int) ( $args['revision'] ?? 1 ),
                        'user_id'     => $user_id,
                        'action'      => $action,
                        'source'      => $source,
                        'changes'     => self::encode_changes( array(
                                'fmt'    => 2,
                                'day'    => TPP_Date::today(),
                                'events' => array_map( function ( $ev ) {
                                        $ev['t'] = TPP_Date::his();
                                        return $ev;
                                }, $events ),
                        ) ),
                        'is_conflict' => empty( $args['is_conflict'] ) ? 0 : 1,
                        'changed_at'  => (string) ( $args['changed_at'] ?? $now ),
                        'agg_day'     => TPP_Date::today(),
                        'event_count' => count( $events ),
                );
                return TPP_DB::insert( 'history', $row );
        }

        /** تاریخچه یک موجودیت (سرویس یا آدرس) */
        public static function for_entity( $entity, $entity_id, $limit = 100 ) {
                return self::decorate( TPP_DB::get_results(
                        "SELECT * FROM " . TPP_DB::table( 'history' ) . " WHERE entity = %s AND entity_id = %d ORDER BY id DESC LIMIT %d",
                        array( $entity, (int) $entity_id, (int) $limit )
                ) );
        }

        /** تاریخچه کامل یک آدرس (همه سرویس‌های آن) */
        public static function address_timeline( $address_id, $limit = 200 ) {
                return self::decorate( TPP_DB::get_results(
                        "SELECT * FROM " . TPP_DB::table( 'history' ) . " WHERE address_id = %d ORDER BY id DESC LIMIT %d",
                        array( (int) $address_id, (int) $limit )
                ) );
        }

        /** تاریخچه سراسری با فیلتر */
        public static function global_log( $args = array(), $page = 1, $per_page = 50 ) {
                global $wpdb;
                $where  = ' WHERE 1=1';
                $params = array();
                if ( ! empty( $args['user_id'] ) ) {
                        $where  .= ' AND user_id = %d';
                        $params[] = (int) $args['user_id'];
                }
                if ( ! empty( $args['action'] ) ) {
                        // ۱.۱۰.۰ — رکورد تجمیعی روزانه هر عملی را در خود دارد؛ فیلتر عملیات داخل changes را هم می‌بیند
                        $where  .= ' AND (action = %s OR changes LIKE %s)';
                        $params[] = sanitize_key( (string) $args['action'] );
                        $params[] = '%"a":"' . sanitize_key( (string) $args['action'] ) . '"%';
                }
                if ( ! empty( $args['entity'] ) ) {
                        $where  .= ' AND entity = %s';
                        $params[] = ( 'address' === $args['entity'] ) ? 'address' : 'service';
                }
                if ( ! empty( $args['address_id'] ) ) {
                        $where  .= ' AND address_id = %d';
                        $params[] = (int) $args['address_id'];
                }
                if ( ! empty( $args['conflict'] ) ) {
                        $where .= ' AND is_conflict = 1';
                }
                /* ۲.۱.۰ — فیلتر وضعیت بررسی تعارض: '' = همه، 0 = باز، 1 = بسته‌شده */
                if ( isset( $args['resolved'] ) && '' !== $args['resolved'] && null !== $args['resolved'] ) {
                        self::ensure_resolved_columns();
                        $where  .= ' AND resolved = %d';
                        $params[] = (int) $args['resolved'];
                }
                $table = TPP_DB::table( 'history' );
                $total = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$table}{$where}", $params );
                $offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
                $sql    = "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d";
                $params2 = array_merge( $params, array( (int) $per_page, $offset ) );
                return array(
                        'total' => $total,
                        'rows'  => self::decorate( TPP_DB::get_results( $sql, $params2 ) ),
                );
        }

        /** افزودن اطلاعات کاربر و رمزگذاری مقادیر فیلدهای پنهان */
        private static function decorate( $rows ) {
                $users = array();
                $rows_out = array();
                foreach ( (array) $rows as $r ) {
                        $uid = (int) $r['user_id'];
                        if ( ! isset( $users[ $uid ] ) ) {
                                $u = get_userdata( $uid );
                                $users[ $uid ] = $u ? $u->display_name : '—';
                        }
                        $r['user_name'] = $users[ $uid ];
                        $r['changes']   = json_decode( (string) $r['changes'], true );
                        $r['revision']  = (int) $r['revision'];
                        $r['is_conflict'] = (int) $r['is_conflict'];
                        $r['resolved']    = isset( $r['resolved'] ) ? (int) $r['resolved'] : 0; // ۲.۱.۰
                        $r['resolved_at'] = isset( $r['resolved_at'] ) ? (string) $r['resolved_at'] : '';
                        $r['resolved_by_name'] = '';
                        if ( isset( $r['resolved_by'] ) && (int) $r['resolved_by'] ) {
                                $ru = get_userdata( (int) $r['resolved_by'] );
                                $r['resolved_by_name'] = $ru ? $ru->display_name : '—';
                        }
                        $r['event_count'] = isset( $r['event_count'] ) ? (int) $r['event_count'] : 0;
                        $rows_out[]      = $r;
                }
                return $rows_out;
        }

        /* ==================== تعارض‌های ثبت‌شده (۲.۱.۰) ==================== */

        /**
         * ۲.۱.۰ — ستون‌های وضعیت بررسی تعارض را در نصب‌های قدیمی می‌سازد (idempotent).
         * resolved: 0=باز (نیازمند بررسی) / 1=بسته‌شده توسط مدیرکل
         */
        public static function ensure_resolved_columns() {
                static $done = false;
                if ( $done ) {
                        return true;
                }
                $table = TPP_DB::table( 'history' );
                if ( ! $table ) {
                        return false;
                }
                $names = array();
                foreach ( (array) TPP_DB::get_results( "SHOW COLUMNS FROM {$table}" ) as $c ) {
                        $names[] = (string) ( $c['Field'] ?? '' );
                }
                if ( in_array( 'resolved', $names, true ) ) {
                        $done = true;
                        return true;
                }
                TPP_DB::query( "ALTER TABLE {$table} ADD COLUMN resolved TINYINT(1) NOT NULL DEFAULT 0" );
                TPP_DB::query( "ALTER TABLE {$table} ADD COLUMN resolved_at DATETIME NULL" );
                TPP_DB::query( "ALTER TABLE {$table} ADD COLUMN resolved_by BIGINT UNSIGNED NULL" );
                TPP_DB::query( "ALTER TABLE {$table} ADD KEY conflict_res (is_conflict, resolved)" );
                $done = true;
                return true;
        }

        /** ۲.۱.۰ — بستن تعارض‌ها (تأیید توسط مدیرکل) — تعداد بسته‌شده برمی‌گردد */
        public static function mark_resolved( $ids, $user_id ) {
                self::ensure_resolved_columns();
                $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
                if ( empty( $ids ) ) {
                        return 0;
                }
                $table = TPP_DB::table( 'history' );
                $in    = implode( ',', $ids );
                return (int) TPP_DB::query(
                        "UPDATE {$table} SET resolved = 1, resolved_at = %s, resolved_by = %d WHERE id IN ({$in}) AND is_conflict = 1",
                        array( TPP_Date::now(), (int) $user_id )
                );
        }

        /** ۲.۱.۰ — رکوردهای تعارض برای سرویس‌های مشخص (decorated) — $resolved '' = همه / 0 / 1 */
        public static function conflicts_for_services( $service_ids, $resolved = '' ) {
                $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $service_ids ) ) ) );
                if ( empty( $ids ) ) {
                        return array();
                }
                if ( '' !== $resolved && null !== $resolved ) {
                        self::ensure_resolved_columns();
                }
                $table   = TPP_DB::table( 'history' );
                $in      = implode( ',', $ids );
                $extra   = ( '' !== $resolved && null !== $resolved ) ? " AND resolved = " . ( (int) $resolved ) : '';
                $rows    = TPP_DB::get_results(
                        "SELECT * FROM {$table} WHERE entity = 'service' AND is_conflict = 1{$extra} AND entity_id IN ({$in}) ORDER BY id DESC LIMIT 1000",
                        array()
                );
                return self::decorate( $rows );
        }

        /** ۲.۱.۰ — رکوردهای تعارض با شناسه‌های مشخص (برای حل تعارض) */
        public static function conflicts_for_ids( $ids ) {
                $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
                if ( empty( $ids ) ) {
                        return array();
                }
                self::ensure_resolved_columns();
                $table = TPP_DB::table( 'history' );
                $in    = implode( ',', $ids );
                return self::decorate( TPP_DB::get_results(
                        "SELECT * FROM {$table} WHERE id IN ({$in}) AND is_conflict = 1 ORDER BY id DESC",
                        array()
                ) );
        }

        /** ۲.۱.۰ — شمارش خلاصه تعارض‌ها: کل / باز / بسته‌شده */
        public static function conflict_counts() {
                self::ensure_resolved_columns();
                $table = TPP_DB::table( 'history' );
                $out   = array( 'total' => 0, 'unresolved' => 0, 'resolved' => 0 );
                foreach ( (array) TPP_DB::get_results(
                        "SELECT resolved, COUNT(*) AS n FROM {$table} WHERE is_conflict = 1 GROUP BY resolved",
                        array()
                ) as $r ) {
                        $n = (int) ( $r['n'] ?? 0 );
                        $out['total'] += $n;
                        if ( 0 === (int) ( $r['resolved'] ?? 0 ) ) {
                                $out['unresolved'] += $n;
                        } else {
                                $out['resolved'] += $n;
                        }
                }
                return $out;
        }

        /** ۲.۱.۰ — مقادیر «قبل از تغییر» یک رکورد تعارض (برای بازگردانی) — فیلدهای پنهان حذف می‌شوند */
        public static function old_values_payload( $row ) {
                $changes = is_array( $row['changes'] ?? null ) ? $row['changes'] : json_decode( (string) ( $row['changes'] ?? '' ), true );
                if ( empty( $changes ) || ( 'service' !== ( $row['entity'] ?? '' ) ) ) {
                        return array();
                }
                if ( is_array( $changes ) && isset( $changes['fmt'] ) && 2 === (int) $changes['fmt'] ) {
                        $map = array();
                        foreach ( (array) ( $changes['events'] ?? array() ) as $ev ) {
                                if ( 'address' === ( $ev['y'] ?? '' ) ) {
                                        continue;
                                }
                                if ( 'create' === ( $ev['a'] ?? '' ) ) {
                                        continue;
                                }
                                foreach ( (array) ( $ev['c'] ?? array() ) as $slug => $pair ) {
                                        if ( is_array( $pair ) && array_key_exists( 'old', $pair ) && ! isset( $pair['hidden'] ) ) {
                                                $map[ $slug ] = $pair['old'];
                                        }
                                }
                                if ( ! empty( $map ) ) {
                                        break;
                                }
                        }
                        return $map;
                }
                $payload = array();
                foreach ( (array) $changes as $slug => $pair ) {
                        if ( is_array( $pair ) && array_key_exists( 'old', $pair ) && ! isset( $pair['hidden'] ) ) {
                                $payload[ $slug ] = $pair['old'];
                        } elseif ( is_scalar( $pair ) ) {
                                $payload[ $slug ] = $pair;
                        }
                }
                return $payload;
        }

        /* ==================== فرمت تجمیعی (نسخه ۲) ==================== */

        /** changes یک رکورد را به فرمت ۲ می‌آورد (رویدادهای موجود = رویداد اول) */
        private static function row_changes_to_fmt2( $row ) {
                $decoded = json_decode( (string) $row['changes'], true );
                if ( is_array( $decoded ) && isset( $decoded['fmt'] ) && 2 === (int) $decoded['fmt'] ) {
                        if ( ! is_array( $decoded['events'] ) ) {
                                $decoded['events'] = array();
                        }
                        return $decoded;
                }
                // فرمت قدیم (نقشه تغییرات) → تبدیل به رویداد اولِ همان روز
                $old = is_array( $decoded ) ? $decoded : array();
                $t = '00:00:00';
                if ( ! empty( $row['changed_at'] ) && preg_match( '/\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})/', (string) $row['changed_at'], $m ) ) {
                        $t = $m[1];
                }
                return array(
                        'fmt'    => 2,
                        'day'    => ! empty( $row['agg_day'] ) ? (string) $row['agg_day'] : substr( (string) $row['changed_at'], 0, 10 ),
                        'events' => array( array(
                                't' => $t,
                                'a' => (string) $row['action'],
                                's' => (string) $row['source'],
                                'y' => (string) $row['entity'],
                                'c' => $old,
                        ) ),
                );
        }

        /** رمزگذاری changes با فرمت فشرده */
        private static function encode_changes( $data ) {
                return wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        }

        /**
         * فیلتر نمایش changes بر اساس فیلدهای قابل مشاهده کاربر.
         * مقادیر فیلدهای غیرقابل مشاهده با «••••» جایگزین می‌شوند تا کاربر بداند تغییری رخ داده اما مقدار را نبیند.
         */
        public static function filter_changes( $changes, $visible ) {
                if ( ! is_array( $changes ) ) {
                        return array();
                }
                // فرمت تجمیعی ۲: فیلتر روی changes هر رویداد جداگانه اعمال می‌شود
                if ( isset( $changes['fmt'] ) && 2 === (int) $changes['fmt'] ) {
                        if ( ! is_array( $changes['events'] ) ) {
                                return array();
                        }
                        foreach ( $changes['events'] as &$ev ) {
                                $ev['c'] = self::filter_changes_map( is_array( $ev['c'] ) ? $ev['c'] : array(), $visible );
                        }
                        unset( $ev );
                        return $changes;
                }
                return self::filter_changes_map( $changes, $visible );
        }

        /** فیلتر نقشه تغییرات قدیمی/داخلی */
        private static function filter_changes_map( $changes, $visible ) {
                $out = array();
                foreach ( (array) $changes as $slug => $pair ) {
                        if ( ! is_array( $pair ) ) {
                                continue;
                        }
                        // ۱.۱۲.۰ — کلیدهای داخلی (پیشرفت دایری/انتقال آدرس/ادغام) فیلد واقعی نیستند و نباید مخفی شوند
                        if ( 0 === strpos( (string) $slug, '_' ) ) {
                                $out[ $slug ] = array(
                                        'old' => null === $pair['old'] ? '' : (string) $pair['old'],
                                        'new' => null === $pair['new'] ? '' : (string) $pair['new'],
                                );
                                continue;
                        }
                        if ( empty( $visible[ $slug ] ) ) {
                                $out[ $slug ] = array( 'old' => '••••', 'new' => '••••', 'hidden' => true );
                        } else {
                                $out[ $slug ] = array(
                                        'old' => null === $pair['old'] ? '' : (string) $pair['old'],
                                        'new' => null === $pair['new'] ? '' : (string) $pair['new'],
                                );
                        }
                }
                return $out;
        }

        /** حذف چند رکورد تاریخچه (تکی/گروهی) — فقط با قابلیت tpp_delete_history
         * خروجی: تعداد رکوردهای واقعاً حذف‌شده (برای idempotency و گزارش دقیق) */
        public static function delete_entries( $ids ) {
                $clean = array();
                foreach ( (array) $ids as $id ) {
                        $id = (int) $id;
                        if ( $id > 0 ) {
                                $clean[ $id ] = $id;
                        }
                }
                if ( empty( $clean ) ) {
                        return 0;
                }
                $table = TPP_DB::table( 'history' );
                $ids   = implode( ',', array_map( 'intval', array_values( $clean ) ) );
                // query تعداد رکوردهای واقعاً حذف‌شده را برمی‌گرداند (در MySQL و stub)
                $deleted = TPP_DB::query( "DELETE FROM {$table} WHERE id IN ({$ids})" );
                return is_numeric( $deleted ) ? (int) $deleted : count( $clean );
        }

        /** حذف کامل تاریخچه یک سرویس (هنگام حذف سرویس — آبشاری) */
        public static function delete_for_service( $service_id ) {
                return TPP_DB::query(
                        "DELETE FROM " . TPP_DB::table( 'history' ) . " WHERE entity = %s AND entity_id = %d",
                        array( 'service', (int) $service_id )
                );
        }

        /** ۱.۲۸.۰ — حذف همه رکوردهای تاریخچه یک آدرس (همراه حذف آدرسی که دیگر هیچ سرویسی روی آن نمانده) */
        public static function delete_for_address( $address_id ) {
                return TPP_DB::query(
                        "DELETE FROM " . TPP_DB::table( 'history' ) . " WHERE address_id = %d",
                        array( (int) $address_id )
                );
        }

        /** بازگرداندن مقدار قبلی به‌عنوان بازبینی جدید (مدیر) */
        public static function restore( $history_id, $user_id ) {
                $entry = TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'history' ) . " WHERE id = %d", array( (int) $history_id ) );
                if ( ! $entry ) {
                        return new WP_Error( 'tpp_not_found', 'رکورد تاریخچه یافت نشد.' );
                }
                $changes = json_decode( (string) $entry['changes'], true );
                if ( empty( $changes ) || 'service' !== $entry['entity'] ) {
                        return new WP_Error( 'tpp_not_restorable', 'این رکورد قابل بازگردانی نیست.' );
                }
                // فرمت تجمیعی ۲ → مقدار «قبل از اولین تغییر سرویس در آن روز» (رویداد ثبت خودِ روز نادیده گرفته می‌شود)
                if ( is_array( $changes ) && isset( $changes['fmt'] ) && 2 === (int) $changes['fmt'] ) {
                        $map = array();
                        foreach ( (array) ( $changes['events'] ?? array() ) as $ev ) {
                                if ( 'address' === ( $ev['y'] ?? '' ) ) {
                                        continue; // بازگردانی فقط فیلدهای سرویس
                                }
                                if ( 'create' === ( $ev['a'] ?? '' ) ) {
                                        continue; // ثبتِ همان روز — وضعیت قبل از آن وجود ندارد
                                }
                                foreach ( (array) ( $ev['c'] ?? array() ) as $slug => $pair ) {
                                        if ( is_array( $pair ) && array_key_exists( 'old', $pair ) && ! isset( $pair['hidden'] ) ) {
                                                $map[ $slug ] = $pair['old'];
                                        }
                                }
                                if ( ! empty( $map ) ) {
                                        break; // اولین رویداد ویرایش → وضعیت قبل از تغییرات آن روز
                                }
                        }
                        $changes = $map;
                }
                $payload = array();
                foreach ( (array) $changes as $slug => $pair ) {
                        if ( is_array( $pair ) && array_key_exists( 'old', $pair ) && ! isset( $pair['hidden'] ) ) {
                                $payload[ $slug ] = $pair['old'];
                        } elseif ( is_scalar( $pair ) ) {
                                $payload[ $slug ] = $pair; // فرمت تجمیعی: مقدار مستقیم قدیمی
                        }
                }
                if ( empty( $payload ) ) {
                        return new WP_Error( 'tpp_not_restorable', 'مقداری برای بازگردانی وجود ندارد.' );
                }
                $result = tpp()->services()->update(
                        (int) $entry['entity_id'],
                        array( 'service' => $payload ),
                        array( 'user_id' => $user_id, 'source' => 'api', 'force' => true )
                );
                return $result;
        }
}
