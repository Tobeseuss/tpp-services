<?php
/**
 * بازبینی (۱.۲۰.۰) — دو بخش:
 *
 * ۱) «سرویس‌های ارجاعی» — صف سرویس‌هایی که با دسته‌بندی پیش‌فرض
 *    «ثبت جهت بازبینی و ویرایش یا تأیید مدیریت» ثبت شده‌اند (درخواست کاربر):
 *    اگر کاربر دسته‌بندی مناسب پیدا نکرد، سرویس را با همین دسته ارجاع می‌دهد تا
 *    دارندگان قابلیت tpp_review_queue (اپراتور ثبت / مدیر کل / گزارش‌گیر — بر حسب
 *    دسترسی تعریف‌شده در «نقش‌ها و دسترسی‌ها») دسته‌بندی درست را تعیین کنند.
 *
 * ۲) «بازبینی اقدامات نصاب‌ها» — همه تغییرات و ثبت‌های جدید کاربران غیرمدیر
 *    (نصاب / اپراتور ثبت و هر نقش سفارشی بدون دسترسی مدیریتی) با snapshot کامل
 *    قبل/بعد در جدول installer_changes ذخیره می‌شود و بر حسب روز نمایش داده می‌شود.
 *    مدیر (دارنده قابلیت tpp_review_installer) هر تغییر را «نگه‌داشتن» یا
 *    «بازگردانی به حالت قبل» می‌کند. پیش‌فرض: همه تغییرات باقی می‌مانند مگر آنکه
 *    صریحاً بازگردانی شوند (درخواست کاربر).
 *
 * ذخیره‌سازی — جدول installer_changes:
 *   id / user_id / user_name (نام کاربر در لحظه ثبت — مقاوم به حذف کاربر) /
 *   service_id / action (create|update|delete) / source (online|import|bulk|offline|api|…) /
 *   before_json {service:{…}, address:{…}|null} / after_json /
 *   summary (متن تغییرات برای نمایش) / status (pending|kept|reverted) /
 *   reviewed_by / reviewed_at / created_at (وقت تهران)
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Review {

        const REVIEW_LABEL = 'ثبت جهت بازبینی و ویرایش یا تأیید مدیریت';

        /* ---------------------------------------------------------------------
         * ثبت تغییر (هوک‌های داخلی TPP_Services)
         * ------------------------------------------------------------------- */

        /** آیا کاربر «سطح نصاب» است و تغییراتش باید ردیابی شود؟
         *  تعریف: کاربرِ دارای دسترسی ثبت/ویرایش سرویس که مدیر نیست (مدیر کل سایت و
         *  مدیر سرویس‌ها و هر نقش دارای tpp_manage_roles معاف‌اند — تغییرات مدیران
         *  در تاریخچه رسمی موجود است و نیازی به بازبینی جداگانه ندارد). */
        public static function should_track( $user_id ) {
                $user_id = (int) $user_id;
                if ( $user_id <= 0 ) {
                        return false;
                }
                if ( TPP_Capabilities::is_manager( $user_id ) ) {
                        return false;
                }
                $caps = TPP_Capabilities::user_caps( $user_id );
                return ! empty( $caps['tpp_create_services'] ) || ! empty( $caps['tpp_edit_services'] ) || ! empty( $caps['tpp_delete_services'] );
        }

        /** عکس‌العمل عملیات‌های خودِ بازبینی نباید دوباره ردیابی شود */
        private static function skip_source( $source ) {
                return in_array( (string) $source, array( 'review', 'revert' ), true );
        }

        /** snapshot کامل یک سرویس + آدرسش (برای before/after) */
        private static function snapshot( $service_row ) {
                if ( ! is_array( $service_row ) || empty( $service_row['id'] ) ) {
                        return null;
                }
                $out = array( 'service' => $service_row, 'address' => null );
                if ( ! empty( $service_row['address_id'] ) ) {
                        $addr = tpp()->services()->get_address( (int) $service_row['address_id'] );
                        if ( $addr ) {
                                $out['address'] = $addr;
                        }
                }
                return $out;
        }

        /** جدول موجود است؟ (کش ایستا — اگر مهاجرت هنوز اجرا نشده باشد ثبت سرویس هرگز fatal نمی‌شود) */
        private static $table_ok = null;
        private static function table_ready() {
                if ( null === self::$table_ok ) {
                        $t = TPP_DB::table( 'installer_changes' );
                        self::$table_ok = ( $t && TPP_DB::get_var( "SHOW TABLES LIKE %s", array( $t ) ) ) ? 1 : 0;
                }
                return self::$table_ok;
        }

        /** ثبت رویداد در installer_changes — خروجی: id رکورد یا false */
        private static function log( $user_id, $service_id, $action, $source, $before, $after, $summary ) {
                $user_id = (int) $user_id;
                if ( ! self::should_track( $user_id ) ) {
                        return false;
                }
                if ( ! self::table_ready() ) {
                        return false; // جدول هنوز ساخته نشده — ثبت سرویس نباید بشکند
                }
                $user = get_userdata( $user_id );
                $enc  = static function ( $snap ) {
                        return $snap ? wp_json_encode( $snap, JSON_UNESCAPED_UNICODE ) : null;
                };
                $row = array(
                        'user_id'     => $user_id,
                        'user_name'   => $user ? $user->display_name : ( 'کاربر #' . $user_id ),
                        'service_id'  => (int) $service_id,
                        'action'      => substr( sanitize_key( (string) $action ), 0, 20 ),
                        'source'      => substr( sanitize_key( (string) $source ), 0, 20 ),
                        'before_json' => $enc( $before ),
                        'after_json'  => $enc( $after ),
                        'summary'     => mb_substr( trim( (string) $summary ), 0, 500 ),
                        'status'      => 'pending',
                        'created_at'  => TPP_Date::now(),
                );
                return TPP_DB::insert( 'installer_changes', $row );
        }

        /** ثبت سرویس جدید (بعد از insert موفق) */
        public static function track_create( $service_id, $user_id, $source, $changes ) {
                if ( self::skip_source( $source ) ) {
                        return false;
                }
                $after = self::snapshot( tpp()->services()->get( (int) $service_id ) );
                return self::log( $user_id, $service_id, 'create', $source, null, $after, self::changes_text( $changes, 'create' ) );
        }

        /** ویرایش سرویس — $before_service/$before_address ردیف‌های قبل از تغییر */
        public static function track_update( $before_service, $before_address, $service_id, $user_id, $source, $changes ) {
                if ( self::skip_source( $source ) ) {
                        return false;
                }
                $before = array( 'service' => $before_service, 'address' => $before_address );
                $after  = self::snapshot( tpp()->services()->get( (int) $service_id ) );
                return self::log( $user_id, $service_id, 'update', $source, $before, $after, self::changes_text( $changes, 'update' ) );
        }

        /** حذف سرویس — snapshot کامل برای بازگردانی احتمالی */
        public static function track_delete( $before_service, $before_address, $service_id, $user_id, $source ) {
                if ( self::skip_source( $source ) ) {
                        return false;
                }
                $before = array( 'service' => $before_service, 'address' => $before_address );
                return self::log( $user_id, $service_id, 'delete', $source, $before, null, 'حذف سرویس' );
        }

        /* ---------------------------------------------------------------------
         * متن خلاصه تغییرات (برچسب‌دار — مثل تاریخچه)
         * ------------------------------------------------------------------- */

        /** برچسب فارسی کلیدهای تغییر */
        private static function change_label( $key ) {
                static $special = array(
                        '_progress_steps'  => 'پیشرفت دایری',
                        '_progress_failure' => 'خرابی اعلام‌شده',
                        '_category'        => 'دسته‌بندی پروژه',
                        '_tags'            => 'تگ‌ها',
                        '_address_moved'   => 'انتقال به آدرس دیگر',
                        '_bulk_edit'       => 'نوع ویرایش',
                );
                if ( isset( $special[ $key ] ) ) {
                        return $special[ $key ];
                }
                foreach ( TPP_Fields::all() as $f ) {
                        if ( $f['slug'] === $key ) {
                                return (string) $f['label'];
                        }
                }
                return $key;
        }

        /** متن «برچسب: قدیم → جدید» برای آرایه changes تاریخچه */
        public static function changes_text( $changes, $action = 'update' ) {
                if ( 'create' === $action ) {
                        return 'ثبت سرویس جدید';
                }
                if ( ! is_array( $changes ) || ! $changes ) {
                        return 'ویرایش سرویس';
                }
                $parts = array();
                foreach ( array_slice( $changes, 0, 6, true ) as $key => $ch ) {
                        $old = is_array( $ch ) ? (string) ( $ch['old'] ?? '' ) : '';
                        $new = is_array( $ch ) ? (string) ( $ch['new'] ?? '' ) : '';
                        $lbl = self::change_label( $key );
                        if ( '' !== $old && '' !== $new ) {
                                $parts[] = $lbl . ': ' . $old . ' ← ' . $new;
                        } elseif ( '' !== $new ) {
                                $parts[] = $lbl . ': ' . $new;
                        } else {
                                $parts[] = $lbl . ' حذف شد';
                        }
                }
                $more = count( $changes ) - count( $parts );
                if ( $more > 0 ) {
                        $parts[] = '… و ' . $more . ' تغییر دیگر';
                }
                return implode( '؛ ', $parts );
        }

        /* ---------------------------------------------------------------------
         * صف سرویس‌های ارجاعی (دسته «ثبت جهت بازبینی»)
         * ------------------------------------------------------------------- */

        /** فهرست سرویس‌های در انتظار بازبینی (دسته ارجاعی) — غنی‌شده با آدرس/ثبت‌کننده */
        public static function queue() {
                $cat_id = TPP_Categories::review_category_id();
                if ( $cat_id <= 0 ) {
                        return array( 'category_id' => 0, 'items' => array(), 'total' => 0 );
                }
                $st = TPP_DB::table( 'services' );
                $rows = TPP_DB::get_results(
                        "SELECT * FROM {$st} WHERE category_id = %d ORDER BY updated_at DESC, id DESC LIMIT 200",
                        array( $cat_id )
                );
                $items = array();
                foreach ( (array) $rows as $s ) {
                        $addr = ! empty( $s['address_id'] ) ? tpp()->services()->get_address( (int) $s['address_id'] ) : null;
                        $creator = ! empty( $s['created_by'] ) ? get_userdata( (int) $s['created_by'] ) : null;
                        $items[] = array(
                                'id'             => (int) $s['id'],
                                'virtual_number' => (string) ( $s['f_virtual_number'] ?? '' ),
                                'full_address'   => $addr ? (string) ( $addr['f_full_address'] ?? '' ) : '',
                                'block'          => $addr ? (string) ( $addr['f_block'] ?? '' ) : '',
                                'plate'          => $addr ? (string) ( $addr['f_plate'] ?? '' ) : '',
                                'unit'           => $addr ? (string) ( $addr['f_unit'] ?? '' ) : '',
                                'created_by'     => $creator ? $creator->display_name : '',
                                'created_at'     => (string) ( $s['created_at'] ?? '' ),
                                'updated_at'     => (string) ( $s['updated_at'] ?? '' ),
                                'progress'       => TPP_Progress::summary( $s ),
                                'category'       => TPP_Categories::shape( $s ),
                        );
                }
                return array(
                        'category_id' => $cat_id,
                        'category_label' => TPP_Categories::label_of( $cat_id ),
                        'items'       => $items,
                        'total'       => count( $items ),
                );
        }

        /**
         * تعیین دسته‌بندی/تگ سرویس ارجاعی توسط بازبین (قابلیت tpp_review_queue).
         * دسته «ثبت جهت بازبینی» هم مجاز است (ارجاع مجدد به صف).
         * خروجی: نتیجه services()->update() با source='review' (تاریخچه ثبت می‌شود).
         */
        public static function assign( $service_id, $category, $tags, $user_id ) {
                $service_id = (int) $service_id;
                $user_id    = (int) $user_id;
                $service = tpp()->services()->get( $service_id );
                if ( ! $service ) {
                        return new WP_Error( 'tpp_not_found', 'سرویس یافت نشد.' );
                }
                if ( ! TPP_Categories::any_category_defined() ) {
                        return new WP_Error( 'tpp_no_categories', 'هنوز دسته‌بندی‌ای تعریف نشده است.' );
                }
                $data = array();
                if ( null !== $category && '' !== trim( (string) $category ) ) {
                        $data['category'] = $category;
                }
                if ( null !== $tags ) {
                        $data['tags'] = $tags;
                }
                if ( ! $data ) {
                        return new WP_Error( 'tpp_no_change', 'دسته‌بندی یا تگی ارسال نشده است.' );
                }
                // source='review' → در ردیابی نصاب‌ها ثبت نمی‌شود (عملیات خودِ بازبینی است)
                return tpp()->services()->update( $service_id, $data, array( 'user_id' => $user_id, 'source' => 'review' ) );
        }

        /* ---------------------------------------------------------------------
         * فهرست تغییرات نصاب‌ها (بر حسب روز)
         * ------------------------------------------------------------------- */

        /**
         * $args: date (یک روز) یا from/to (بازه)، user_id (۰=همه)، status (''|pending|kept|reverted)
         * خروجی: {from, to, days: [{date, rows: []}], total, pending_total, users: []}
         */
        public static function changes( $args = array() ) {
                $table = TPP_DB::table( 'installer_changes' );
                $from = trim( (string) ( $args['from'] ?? '' ) );
                $to   = trim( (string) ( $args['to'] ?? '' ) );
                $date = trim( (string) ( $args['date'] ?? '' ) );
                if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                        $from = $date;
                        $to   = $date;
                }
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
                        $from = TPP_Date::today();
                        $to   = $from;
                }
                if ( strcmp( $from, $to ) > 0 ) {
                        $tmp = $from; $from = $to; $to = $tmp;
                }
                $where  = ' WHERE created_at >= %s AND created_at < %s';
                $params = array( $from . ' 00:00:00', TPP_Date::iso_add_days( $to, 1 ) . ' 00:00:00' );
                $user_id = (int) ( $args['user_id'] ?? 0 );
                if ( $user_id > 0 ) {
                        $where .= ' AND user_id = %d';
                        $params[] = $user_id;
                }
                $status = sanitize_key( (string) ( $args['status'] ?? '' ) );
                if ( in_array( $status, array( 'pending', 'kept', 'reverted' ), true ) ) {
                        $where .= ' AND status = %s';
                        $params[] = $status;
                }
                $rows = TPP_DB::get_results(
                        "SELECT * FROM {$table}{$where} ORDER BY created_at DESC, id DESC LIMIT 400",
                        $params
                );

                // غنی‌سازی: اطلاعات سرویس موجود (آدرس/شماره مجازی) + شمارنده‌ها
                $svc_ids = array();
                foreach ( (array) $rows as $r ) {
                        if ( (int) $r['service_id'] > 0 ) {
                                $svc_ids[ (int) $r['service_id'] ] = true;
                        }
                }
                $services = array();
                if ( $svc_ids ) {
                        $st = TPP_DB::table( 'services' );
                        $in = implode( ',', array_map( 'intval', array_keys( $svc_ids ) ) );
                        foreach ( (array) TPP_DB::get_results( "SELECT id, address_id, f_virtual_number, updated_at, version FROM {$st} WHERE id IN ({$in})" ) as $s ) {
                                $services[ (int) $s['id'] ] = $s;
                        }
                }

                $days = array();
                $index = array();
                $pending_total = 0;
                foreach ( (array) $rows as $r ) {
                        $day = substr( (string) $r['created_at'], 0, 10 );
                        if ( ! isset( $index[ $day ] ) ) {
                                $index[ $day ] = count( $days );
                                $days[] = array( 'date' => $day, 'rows' => array() );
                        }
                        if ( 'pending' === (string) $r['status'] ) {
                                $pending_total++;
                        }
                        $svc = isset( $services[ (int) $r['service_id'] ] ) ? $services[ (int) $r['service_id'] ] : null;
                        $addr = null;
                        if ( $svc && ! empty( $svc['address_id'] ) ) {
                                $addr = tpp()->services()->get_address( (int) $svc['address_id'] );
                        }
                        $days[ $index[ $day ] ]['rows'][] = array(
                                'id'          => (int) $r['id'],
                                'user_id'     => (int) $r['user_id'],
                                'user_name'   => (string) $r['user_name'],
                                'service_id'  => (int) $r['service_id'],
                                'service_virtual' => $svc ? (string) ( $svc['f_virtual_number'] ?? '' ) : '',
                                'service_addr'=> $addr ? trim( implode( '، ', array_filter( array(
                                        (string) ( $addr['f_full_address'] ?? '' ),
                                        (string) ( $addr['f_block'] ?? '' ),
                                        $addr['f_plate'] ? 'پلاک ' . $addr['f_plate'] : '',
                                        $addr['f_unit'] ? 'واحد ' . $addr['f_unit'] : '',
                                ) ) ) ) : '',
                                'exists'      => (bool) $svc,
                                'action'      => (string) $r['action'],
                                'source'      => (string) $r['source'],
                                'summary'     => (string) ( $r['summary'] ?? '' ),
                                'status'      => (string) $r['status'],
                                'reviewed_by' => (int) $r['reviewed_by'] > 0 ? self::user_name( (int) $r['reviewed_by'] ) : ( ( 'kept' === (string) $r['status'] && ! empty( $r['reviewed_at'] ) ) ? 'تایید خودکار سیستم' : '' ),
                                'reviewed_at' => (string) ( $r['reviewed_at'] ?? '' ),
                                'created_at'  => (string) $r['created_at'],
                        );
                }

                return array(
                        'from' => $from,
                        'to'   => $to,
                        'days' => $days,
                        'total'=> count( $rows ),
                        'pending_total' => $pending_total,
                        'users' => self::changed_users(),
                );
        }

        /** فهرست کاربرانِ دارای تغییر ردیابی‌شده (برای فیلتر) */
        public static function changed_users() {
                $table = TPP_DB::table( 'installer_changes' );
                $rows = TPP_DB::get_results( "SELECT user_id, MAX(user_name) AS user_name, COUNT(*) AS cnt FROM {$table} GROUP BY user_id ORDER BY cnt DESC LIMIT 100" );
                $out = array();
                foreach ( (array) $rows as $r ) {
                        $out[] = array( 'id' => (int) $r['user_id'], 'name' => (string) $r['user_name'], 'count' => (int) $r['cnt'] );
                }
                return $out;
        }

        private static function user_name( $user_id ) {
                $u = get_userdata( (int) $user_id );
                return $u ? $u->display_name : ( 'کاربر #' . (int) $user_id );
        }

        /** یک رکورد خام */
        public static function get_change( $id ) {
                return TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'installer_changes' ) . " WHERE id = %d", array( (int) $id ) );
        }

        /* ---------------------------------------------------------------------
         * تصمیم مدیر: نگه‌داشتن / بازگردانی
         * ------------------------------------------------------------------- */

        /** «نگه داشتن» — فقط علامت بازبینی‌شده (تغییر در داده اعمال نمی‌شود) */
        public static function keep( $id, $user_id ) {
                $row = self::get_change( $id );
                if ( ! $row ) {
                        return new WP_Error( 'tpp_not_found', 'رکورد تغییر یافت نشد.' );
                }
                if ( 'reverted' === (string) $row['status'] ) {
                        return new WP_Error( 'tpp_already_reverted', 'این تغییر قبلاً بازگردانی شده است.' );
                }
                TPP_DB::update( 'installer_changes', array(
                        'status'      => 'kept',
                        'reviewed_by' => (int) $user_id,
                        'reviewed_at' => TPP_Date::now(),
                ), array( 'id' => (int) $id ) );
                return true;
        }

        /**
         * «بازگردانی به حالت قبل» — وضعیت دقیق قبل از تغییر بازمی‌گردد:
         *   create  → سرویس حذف می‌شود (مثل حذف عادی: تاریخچه‌اش هم آبشاری پاک می‌شود)
         *   update  → ستون‌های سرویس/آدرس از before_json عیناً بازنویسی می‌شوند
         *   delete  → ردیف سرویس با همان شناسه/مقادیر دوباره درج می‌شود
         * اگر سرویس بعد از این تغییر دوباره تغییر کرده باشد (updated_at/version نمی‌خواند)
         * بازگردانی انجام نمی‌شود تا تغییرات جدیدتر از بین نرود.
         */
        public static function revert( $id, $user_id ) {
                $row = self::get_change( $id );
                if ( ! $row ) {
                        return new WP_Error( 'tpp_not_found', 'رکورد تغییر یافت نشد.' );
                }
                if ( 'reverted' === (string) $row['status'] ) {
                        return new WP_Error( 'tpp_already_reverted', 'این تغییر قبلاً بازگردانی شده است.' );
                }
                $action = (string) $row['action'];
                $before = json_decode( (string) $row['before_json'], true );
                $after  = json_decode( (string) $row['after_json'], true );
                $service_id = (int) $row['service_id'];

                if ( 'create' === $action ) {
                        // بازگردانی ثبت = حذف سرویس (وضعیت «قبل از ثبت» = وجود نداشتن)
                        $service = tpp()->services()->get( $service_id );
                        if ( ! $service ) {
                                // سرویس از قبل حذف شده — فقط علامت بخور
                                return self::mark_reverted( $id, $user_id, 'سرویس از قبل حذف شده بود' );
                        }
                        $del = tpp()->services()->delete( $service_id, array( 'user_id' => $user_id, 'source' => 'revert' ) );
                        if ( is_wp_error( $del ) ) {
                                return $del;
                        }
                        return self::mark_reverted( $id, $user_id, 'سرویس ثبت‌شده حذف شد (بازگشت به وضعیت قبل از ثبت)' );
                }

                if ( 'update' === $action ) {
                        $service = tpp()->services()->get( $service_id );
                        if ( ! $service ) {
                                return new WP_Error( 'tpp_gone', 'سرویس یافت نشد — ممکن است بعداً حذف شده باشد.' );
                        }
                        // محافظ تغییرات جدیدتر: بازگردانی نباید کاری را که بعد از آن شده از بین ببرد
                        $after_service = is_array( $after ) && isset( $after['service'] ) ? $after['service'] : array();
                        $same = isset( $after_service['updated_at'], $service['updated_at'] )
                                && (string) $after_service['updated_at'] === (string) $service['updated_at']
                                && (int) ( $after_service['version'] ?? 0 ) === (int) $service['version'];
                        if ( ! $same ) {
                                return new WP_Error( 'tpp_newer_changes', 'این سرویس پس از این تغییر دوباره ویرایش شده است — برای محافظت از تغییرات جدیدتر، بازگردانی انجام نشد. ابتدا تغییر جدیدتر را بازگردانی کنید.' );
                        }
                        if ( ! is_array( $before ) || empty( $before['service'] ) ) {
                                return new WP_Error( 'tpp_bad_snapshot', ' snapshot وضعیت قبل موجود نیست.' );
                        }
                        $cols = self::restorable_columns( 'services', $before['service'] );
                        TPP_DB::update( 'services', $cols, array( 'id' => $service_id ) );
                        // آدرس هم اگر در snapshot بود بازگردانی شود
                        if ( ! empty( $before['address'] ) && ! empty( $before['service']['address_id'] ) ) {
                                $addr_id = (int) $before['service']['address_id'];
                                if ( tpp()->services()->get_address( $addr_id ) ) {
                                        TPP_DB::update( 'addresses', self::restorable_columns( 'addresses', $before['address'] ), array( 'id' => $addr_id ) );
                                }
                        }
                        self::record_restore_history( $service_id, (int) $before['service']['address_id'], $user_id, (string) $row['summary'] );
                        return self::mark_reverted( $id, $user_id, 'ستون‌های سرویس/آدرس به مقادیر قبل بازگردانی شد' );
                }

                if ( 'delete' === $action ) {
                        if ( tpp()->services()->get( $service_id ) ) {
                                return new WP_Error( 'tpp_exists', 'این سرویس از قبل موجود است — بازگردانی حذف ممکن نیست.' );
                        }
                        if ( ! is_array( $before ) || empty( $before['service'] ) ) {
                                return new WP_Error( 'tpp_bad_snapshot', 'snapshot وضعیت قبل موجود نیست.' );
                        }
                        $cols = self::restorable_columns( 'services', $before['service'] );
                        $cols['id'] = $service_id; // همان شناسه قبلی (پیوندهای خارجی معتبر می‌مانند)
                        TPP_DB::insert( 'services', $cols );
                        // آدرس اگر حذف/گم شده بود بازگردانی شود (نباید — حذف سرویس آدرس را حذف نمی‌کند)
                        if ( ! empty( $before['service']['address_id'] ) ) {
                                $addr_id = (int) $before['service']['address_id'];
                                if ( ! tpp()->services()->get_address( $addr_id ) && ! empty( $before['address'] ) ) {
                                        $acols = self::restorable_columns( 'addresses', $before['address'] );
                                        $acols['id'] = $addr_id;
                                        TPP_DB::insert( 'addresses', $acols );
                                }
                        }
                        self::record_restore_history( $service_id, (int) ( $before['service']['address_id'] ?? 0 ), $user_id, 'بازگردانی سرویس حذف‌شده' );
                        return self::mark_reverted( $id, $user_id, 'سرویس حذف‌شده با همان شناسه بازگردانی شد (تاریخچه قدیمی آن قابل بازیابی نیست)' );
                }

                return new WP_Error( 'tpp_bad_action', 'نوع عملیات نامعتبر است.' );
        }

        /** ستون‌های قابل بازنویسی از snapshot (بدون id) — فقط ستون‌های واقعی جدول */
        private static function restorable_columns( $table, $snapshot_row ) {
                $real = self::table_columns( $table );
                $out = array();
                foreach ( (array) $snapshot_row as $col => $val ) {
                        if ( 'id' === $col || ! isset( $real[ $col ] ) ) {
                                continue;
                        }
                        $out[ $col ] = $val;
                }
                return $out;
        }

        /** فهرست ستون‌های واقعی جدول (کش ایستا) — MySQL «Field» / سازگار با محیط‌های تست */
        private static function table_columns( $table ) {
                static $cache = array();
                $name = TPP_DB::table( $table );
                if ( ! $name ) {
                        return array();
                }
                if ( ! isset( $cache[ $table ] ) ) {
                        $cols = array();
                        foreach ( (array) TPP_DB::get_results( "SHOW COLUMNS FROM {$name}" ) as $c ) {
                                $col = isset( $c['Field'] ) ? (string) $c['Field'] : ( isset( $c['name'] ) ? (string) $c['name'] : '' );
                                if ( '' !== $col ) {
                                        $cols[ $col ] = true;
                                }
                        }
                        $cache[ $table ] = $cols;
                }
                return $cache[ $table ];
        }

        /** ثبت رویداد «بازگردانی» در تاریخچه رسمی سرویس */
        private static function record_restore_history( $service_id, $address_id, $user_id, $text ) {
                TPP_History::record( array(
                        'entity' => 'service', 'entity_id' => (int) $service_id, 'address_id' => (int) $address_id,
                        'user_id' => (int) $user_id, 'action' => 'restore', 'source' => 'review',
                        'changes' => array( '_revert' => array( 'old' => null, 'new' => 'بازگردانی به حالت قبل — ' . $text ) ),
                        'no_aggregate' => true,
                ) );
        }

        private static function mark_reverted( $id, $user_id, $note ) {
                TPP_DB::update( 'installer_changes', array(
                        'status'      => 'reverted',
                        'reviewed_by' => (int) $user_id,
                        'reviewed_at' => TPP_Date::now(),
                        'summary'     => mb_substr( ( (string) TPP_Review::get_change( $id )['summary'] ) . ' — بازگردانی شد', 0, 500 ),
                ), array( 'id' => (int) $id ) );
                return array( 'status' => 'reverted', 'id' => (int) $id, 'note' => $note );
        }

        /* ---------------------------------------------------------------------
         * نصب — جدول
         * ------------------------------------------------------------------- */

        /** DDL جدول installer_changes (در TPP_Install::create_tables) */
        public static function table_sql() {
                $charset = '';
                if ( ! TPP_DB::is_external() ) {
                        global $wpdb;
                        $charset = $wpdb->get_charset_collate();
                } else {
                        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
                }
                return 'CREATE TABLE ' . TPP_DB::table( 'installer_changes' ) . " (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        user_name VARCHAR(190) NULL,
                        service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        action VARCHAR(20) NOT NULL DEFAULT 'update',
                        source VARCHAR(20) NOT NULL DEFAULT '',
                        before_json LONGTEXT NULL,
                        after_json LONGTEXT NULL,
                        summary TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        reviewed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        reviewed_at DATETIME NULL,
                        created_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY user_day (user_id, created_at),
                        KEY service_id (service_id),
                        KEY status_day (status, created_at),
                        KEY created_day (created_at)
                ) " . $charset . ';';
        }

        /** اطمینان از وجود جدول در نصب‌های موجود (dbDelta در ارتقا همیشه جدول نمی‌سازد) */
        public static function ensure_table() {
                $table = TPP_DB::table( 'installer_changes' );
                if ( ! $table ) {
                        return;
                }
                $exists = TPP_DB::get_var( "SHOW TABLES LIKE %s", array( $table ) );
                if ( ! $exists ) {
                        TPP_DB::query( self::table_sql() );
                }
        }

        /** پاک‌سازی قدیمی‌ها همراه با حذف خودکار تاریخچه (همان n روز تنظیمات؛ ۰ = نامحدود) */
        public static function cleanup( $days ) {
                $table = TPP_DB::table( 'installer_changes' );
                if ( ! $table ) {
                        return 0;
                }
                $days = (int) $days;
                if ( $days <= 0 ) {
                        return 0; // نامحدود
                }
                $cutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - $days * DAY_IN_SECONDS );
                $total  = 0;
                for ( $i = 0; $i < 100; $i++ ) {
                        $deleted = TPP_DB::query( "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", array( $cutoff ) );
                        if ( ! is_numeric( $deleted ) || (int) $deleted <= 0 ) {
                                break;
                        }
                        $total += (int) $deleted;
                        if ( (int) $deleted < 5000 ) {
                                break;
                        }
                }
                return $total;
        }

        /* ---------------------------------------------------------------------
         * ۱.۲۱.۰ — تایید خودکار اقدامات نصاب‌ها پس از n روز (تنظیمات)
         * ------------------------------------------------------------------- */

        /**
         * تغییرات pending قدیمی‌تر از «review_auto_days» روز به‌صورت خودکار «تایید نهایی» (kept) می‌شوند.
         *  • سرویس‌هایی که دسته‌بندی‌شان همان دسته پیش‌فرض «ثبت جهت بازبینی…» است مستثنا هستند
         *    (تا زمانی که بازبین تکلیف‌شان را روشن نکرده، در صف می‌مانند — درخواست کاربر)
         *  • ۰ در تنظیمات = تایید خودکار غیرفعال
         * اجرا: کرون روزانه tpp_daily_cleanup (هوک در TPP_Plugin::boot) + شمارش خروجی برای لاگ.
         */
        public static function auto_approve_expired() {
                $table = TPP_DB::table( 'installer_changes' );
                if ( ! $table || ! self::table_ready() ) {
                        return 0;
                }
                $days = (int) tpp()->settings()->get( 'review_auto_days', 7 );
                if ( $days <= 0 ) {
                        return 0; // غیرفعال
                }
                $cutoff = gmdate( 'Y-m-d H:i:s', TPP_Date::ts() - $days * DAY_IN_SECONDS );
                $st     = TPP_DB::table( 'services' );
                $review_cat = TPP_Categories::review_category_id();

                // تغییرات pending قدیمی + دسته فعلی سرویس‌شان (برای استثنا)
                $rows = TPP_DB::get_results(
                        "SELECT c.id, c.service_id, s.category_id AS cur_cat
                         FROM {$table} c
                         LEFT JOIN {$st} s ON s.id = c.service_id
                         WHERE c.status = 'pending' AND c.created_at < %s
                         ORDER BY c.id ASC LIMIT 5000",
                        array( $cutoff )
                );

                $approved = 0;
                foreach ( (array) $rows as $r ) {
                        // استثنا: سرویس موجود با دسته بازبینی → تا بازبینی دستی در صف می‌ماند
                        if ( $review_cat > 0 && (int) ( $r['cur_cat'] ?? 0 ) === $review_cat ) {
                                continue;
                        }
                        TPP_DB::update( 'installer_changes', array(
                                'status'      => 'kept',
                                'reviewed_by' => 0, // ۰ = تایید خودکار سیستم
                                'reviewed_at' => TPP_Date::now(),
                        ), array( 'id' => (int) $r['id'] ) );
                        $approved++;
                }
                return $approved;
        }
}
