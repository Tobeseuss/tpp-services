<?php
/**
 * منطق سرویس‌ها و آدرس‌ها — ثبت، ویرایش، حذف، تطبیق آدرس و جستجوی سراسری.
 * هر آدرس می‌تواند چند سرویس داشته باشد (رابطه address_id).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Services {

        /** نرمال‌سازی متن فارسی برای مقایسه (ی/ک عربی، فاصله‌ها، نیم‌فاصله) */
        public static function normalize( $text ) {
                $t = (string) $text;
                $t = str_replace(
                        array( 'ي', 'ك', 'ة', 'أ', 'إ', 'آ', '\u200c', "\xE2\x80\x8C", "\xC2\xA0" ),
                        array( 'ی', 'ک', 'ه', 'ا', 'ا', 'ا', ' ', ' ', ' ' ),
                        $t
                );
                $t = preg_replace( '/\s+/u', ' ', $t );
                return trim( $t );
        }

        /* ---------------------------------------------------------------------
         * آدرس‌ها
         * ------------------------------------------------------------------- */

        /** تبدیل ارقام فارسی/عربی به لاتین (برای مقایسه کد پستی و کد ملی) */
        public static function latin_digits( $text ) {
                return strtr( (string) $text, array(
                        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                ) );
        }

        /**
         * یافتن یا ساخت آدرس بر اساس فیلدهای آدرس.
         * ۱.۱۰.۰ — پارامتر $collected: اگر ارسال شود، رویداد تاریخچه آدرس جداگانه ثبت نمی‌شود
         * و تغییرات برای ادغام در رکورد تاریخچه سرویس برگردانده می‌شود.
         */
        public function find_or_create_address( $address_data, $user_id, $source = 'online', $force_new = false, &$collected = null ) {
                $fields = TPP_Fields::all( 'address' );
                $values = array();
                foreach ( $fields as $f ) {
                        $slug        = $f['slug'];
                        $values[ $slug ] = TPP_Fields::validate_value( $f, $address_data[ $slug ] ?? '' );
                }

                if ( ! $force_new ) {
                        // ۱) تطبیق با کد پستی (دقیق — با تحمل ارقام فارسی/لاتین)
                        if ( ! empty( $values['f_postal_code'] ) ) {
                                $postal = self::latin_digits( $values['f_postal_code'] );
                                $found = TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'addresses' ) . " WHERE f_postal_code = %s LIMIT 1", array( $values['f_postal_code'] ) );
                                if ( ! $found && $postal !== $values['f_postal_code'] ) {
                                        $found = TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'addresses' ) . " WHERE f_postal_code = %s LIMIT 1", array( $postal ) );
                                }
                                if ( $found ) {
                                        return (int) $found['id'];
                                }
                        }

                        // ۲) تطبیق با کلید آدرس (آدرس کامل + بلوک + پلاک + واحد به‌صورت نرمال‌شده)
                        $key_parts = array();
                        foreach ( $fields as $f ) {
                                $slug = $f['slug'];
                                if ( in_array( $slug, array( 'f_full_address', 'f_block', 'f_plate', 'f_unit' ), true ) && '' !== $values[ $slug ] ) {
                                        $key_parts[ $slug ] = self::normalize( $values[ $slug ] );
                                }
                        }
                        if ( ! empty( $key_parts['f_full_address'] ) ) {
                                $candidates = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'addresses' ) . " WHERE f_full_address = %s LIMIT 50", array( $values['f_full_address'] ) );
                                foreach ( (array) $candidates as $cand ) {
                                        $match = true;
                                        foreach ( $key_parts as $slug => $norm ) {
                                                if ( self::normalize( $cand[ $slug ] ?? '' ) !== $norm ) {
                                                        $match = false;
                                                        break;
                                                }
                                        }
                                        if ( $match ) {
                                                return (int) $cand['id'];
                                        }
                                }
                        }
                }

                // ۳) ساخت آدرس جدید
                $now    = TPP_Date::now();
                $insert = array(
                        'created_by' => (int) $user_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'version'    => 1,
                );
                foreach ( $values as $slug => $val ) {
                        $insert[ $slug ] = $val;
                }
                $address_id = TPP_DB::insert( 'addresses', $insert );
                if ( ! $address_id ) {
                        return new WP_Error( 'tpp_address_failed', 'ثبت آدرس ناموفق بود.' );
                }
                $changes = array();
                foreach ( $values as $slug => $val ) {
                        if ( '' !== $val ) {
                                $changes[ $slug ] = array( 'old' => null, 'new' => $val );
                        }
                }
                if ( is_array( $collected ) ) {
                        // ۱.۱۰.۰ — رویداد آدرس در رکورد سرویس ادغم می‌شود (بدون رکورد جداگانه)
                        $collected = array( 'action' => 'create', 'changes' => $changes );
                } else {
                        TPP_History::record( array(
                                'entity' => 'address', 'entity_id' => $address_id, 'address_id' => $address_id,
                                'revision' => 1, 'user_id' => $user_id, 'action' => 'create', 'source' => $source, 'changes' => $changes,
                        ) );
                }
                return $address_id;
        }

        /**
         * ویرایش مستقیم فیلدهای آدرس (به‌همراه تاریخچه).
         * ۱.۱۰.۰ — پارامتر $collected: اگر ارسال شود، رکورد تاریخچه جداگانه ثبت نمی‌شود
         * و تغییرات برای ادغام در رکورد سرویس برگردانده می‌شود.
         */
        public function update_address_fields( $address_id, $new_values, $user_id, $source = 'online', &$collected = null ) {
                $address = TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'addresses' ) . " WHERE id = %d", array( (int) $address_id ) );
                if ( ! $address ) {
                        return new WP_Error( 'tpp_address_not_found', 'آدرس یافت نشد.' );
                }
                $changes = array();
                $data    = array();
                foreach ( TPP_Fields::all( 'address' ) as $f ) {
                        $slug = $f['slug'];
                        if ( ! array_key_exists( $slug, $new_values ) ) {
                                continue;
                        }
                        $val = TPP_Fields::validate_value( $f, $new_values[ $slug ] );
                        if ( (string) ( $address[ $slug ] ?? '' ) !== (string) $val ) {
                                $changes[ $slug ] = array( 'old' => $address[ $slug ], 'new' => $val );
                                $data[ $slug ]    = $val;
                        }
                }
                if ( empty( $data ) ) {
                        return array( 'status' => 'nochange' );
                }
                $version = (int) $address['version'] + 1;
                $data['updated_at'] = TPP_Date::now();
                $data['version']    = $version;
                TPP_DB::update( 'addresses', $data, array( 'id' => (int) $address_id ) );
                if ( is_array( $collected ) ) {
                        // ۱.۱۰.۰ — رویداد آدرس در رکورد سرویس ادغام می‌شود (بدون رکورد جداگانه)
                        $collected = array( 'action' => 'update', 'changes' => $changes );
                } else {
                        TPP_History::record( array(
                                'entity' => 'address', 'entity_id' => (int) $address_id, 'address_id' => (int) $address_id,
                                'revision' => $version, 'user_id' => $user_id, 'action' => 'update', 'source' => $source, 'changes' => $changes,
                        ) );
                }
                return array( 'status' => 'updated', 'version' => $version, 'changes' => $changes );
        }

        public function get_address( $address_id ) {
                return TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'addresses' ) . " WHERE id = %d", array( (int) $address_id ) );
        }

        public function address_services( $address_id ) {
                return TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'services' ) . " WHERE address_id = %d ORDER BY id ASC", array( (int) $address_id ) );
        }

        /* ---------------------------------------------------------------------
         * سرویس‌ها — CRUD
         * ------------------------------------------------------------------- */

        /**
         * ثبت سرویس جدید
         * $data = ['address_id' => ?, 'address' => [slug=>val], 'service' => [slug=>val]]
         * $args = ['user_id' => ?, 'source' => 'online|offline|import|api', 'op_id' => ?]
         */
        public function create( $data, $args = array() ) {
                $user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
                $source  = sanitize_key( (string) ( $args['source'] ?? 'online' ) );
                if ( ! empty( $args['op_id'] ) ) {
                        $existing = TPP_DB::get_row( "SELECT result FROM " . TPP_DB::table( 'op_log' ) . " WHERE op_id = %s", array( (string) $args['op_id'] ) );
                        if ( $existing ) {
                                $decoded = json_decode( $existing['result'], true );
                                return is_array( $decoded ) ? $decoded : array( 'status' => 'duplicate' );
                        }
                }

                $visible = TPP_Capabilities::visible_fields( $user_id );
                $service_values = array();
                $changes        = array();
                $skip_required  = ( 'import' === $source ) || ! empty( $args['skip_required'] );

                // اعتبارسنجی فیلدهای الزامی (فقط فیلدهای قابل مشاهده کاربر) — ایمپورت معاف است (داده قدیمی ممکن است ناقص باشد)
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        $slug = $f['slug'];
                        if ( isset( $data['service'][ $slug ] ) ) {
                                $val = TPP_Fields::validate_value( $f, $data['service'][ $slug ] );
                                if ( empty( $visible[ $slug ] ) ) {
                                        continue; // کاربر اجازه نوشتن این فیلد را ندارد
                                }
                                $service_values[ $slug ] = $val;
                        }
                }
                if ( ! $skip_required ) {
                        foreach ( TPP_Fields::all( 'service' ) as $f ) {
                                if ( $f['is_required'] && ! empty( $visible[ $f['slug'] ] ) ) {
                                        $val = $service_values[ $f['slug'] ] ?? '';
                                        if ( '' === $val || null === $val ) {
                                                return new WP_Error( 'tpp_required', 'فیلد الزامی «' . $f['label'] . '» خالی است.' );
                                        }
                                }
                        }
                        // فیلدهای الزامی آدرس (مثل آدرس کامل و نام مرکز) — فقط وقتی داده آدرس ارسال شده
                        if ( ! empty( $data['address'] ) && is_array( $data['address'] ) ) {
                                $addr_err = $this->check_required_address( $data['address'], $visible );
                                if ( is_wp_error( $addr_err ) ) {
                                        return $addr_err;
                                }
                        }
                }

                // ۱.۱۹.۰ — دسته‌بندی/تگ سرویس (فیلد اجباری دسته وقتی دسته‌بندی‌ای تعریف شده)
                $cat_changes = array();
                $cat_data = TPP_Categories::apply_to_payload( $data, null, $cat_changes );
                if ( is_wp_error( $cat_data ) ) {
                        return $cat_data;
                }
                if ( ! $skip_required && TPP_Categories::any_category_defined() ) {
                        $cat_id = isset( $cat_data['category_id'] ) ? (int) $cat_data['category_id'] : 0;
                        if ( $cat_id <= 0 ) {
                                return new WP_Error( 'tpp_required', 'فیلد الزامی «دسته‌بندی پروژه» خالی است — از فهرست دسته‌بندی‌های تعریف‌شده انتخاب کنید.' );
                        }
                }

                // آدرس — ۱.۱۰.۰: رویداد تاریخچه آدرس در همان رکورد سرویس ادغام می‌شود (بدون رکورد جداگانه)
                $addr_event = array();
                if ( ! empty( $data['address_id'] ) ) {
                        $address_id = (int) $data['address_id'];
                        if ( ! $this->get_address( $address_id ) ) {
                                return new WP_Error( 'tpp_address_not_found', 'آدرس انتخاب‌شده یافت نشد.' );
                        }
                        // اگر فیلدهای آدرس هم ارسال شده باشند، روی همان آدرس اعمال می‌شود
                        if ( ! empty( $data['address'] ) && is_array( $data['address'] ) ) {
                                $addr_result = $this->update_address_fields( $address_id, $data['address'], $user_id, $source, $addr_event );
                                if ( is_wp_error( $addr_result ) ) {
                                        return $addr_result;
                                }
                        }
                } else {
                        $address_id = $this->find_or_create_address( (array) ( $data['address'] ?? array() ), $user_id, $source, ! empty( $data['force_new_address'] ), $addr_event );
                        if ( is_wp_error( $address_id ) ) {
                                return $address_id;
                        }
                }

                $now    = TPP_Date::now();
                $insert = array(
                        'address_id' => $address_id,
                        'created_by' => $user_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'version'    => 1,
                );
                foreach ( $service_values as $slug => $val ) {
                        $insert[ $slug ] = $val;
                }
                // ۱.۱۹.۰ — ستون‌های دسته‌بندی/تگ
                foreach ( $cat_data as $col => $val ) {
                        $insert[ $col ] = $val;
                }
                // ۱.۱۲.۰/۱.۱۳.۰/۱.۱۴.۰ — پیشرفت دایری سرویس (۱۶ مرحله + خرابی‌های چندتایی + منطق آبشاری مراحل وابسته)
                $progress_changes = array();
                if ( array_key_exists( 'progress', $data ) && is_array( $data['progress'] ) ) {
                        $prog = TPP_Progress::apply( $data['progress'], null );
                        $insert['progress_steps']      = $prog['steps'];
                        $insert['progress_done']       = $prog['done'];
                        $insert['progress_failures']   = $prog['failures']; // ۱.۱۴.۰ — آرایه خرابی‌ها
                        $insert['progress_failure']    = $prog['failure'];  // اولین خرابی (سازگاری)
                        $insert['progress_excluded']   = $prog['excluded'];
                        $insert['progress_updated_at'] = $now;
                        if ( $prog['done'] > 0 ) {
                                $progress_changes['_progress_steps'] = array(
                                        'old' => null,
                                        'new' => '+' . $prog['done'] . ' مرحله: ' . implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $prog['steps_array'] ) ),
                                );
                        }
                        if ( ! empty( $prog['failures_array'] ) ) {
                                $progress_changes['_progress_failure'] = array(
                                        'old' => null,
                                        'new' => TPP_Progress::failures_labels_text( $prog['failures_array'] ), // ۱.۱۴.۰ — چند خرابی
                                );
                        }
                }
                $service_id = TPP_DB::insert( 'services', $insert );
                if ( ! $service_id ) {
                        return new WP_Error( 'tpp_insert_failed', 'ثبت سرویس ناموفق بود.' );
                }
                foreach ( $service_values as $slug => $val ) {
                        if ( '' !== $val ) {
                                $changes[ $slug ] = array( 'old' => null, 'new' => $val );
                        }
                }
                TPP_History::record( array(
                        'entity' => 'service', 'entity_id' => $service_id, 'address_id' => $address_id,
                        'revision' => 1, 'user_id' => $user_id, 'action' => 'create', 'source' => $source, 'changes' => array_merge( $changes, $progress_changes, $cat_changes ),
                        'addr_changes' => $addr_event ? $addr_event['changes'] : null,
                        'addr_action'  => $addr_event ? $addr_event['action'] : null,
                ) );

                // ۱.۲۰.۰ — سجل بازبینی اقدامات نصاب‌ها (کاربران غیرمدیر)
                TPP_Review::track_create( $service_id, $user_id, $source, array_merge( $changes, $progress_changes, $cat_changes ) );

                $result = array( 'status' => 'created', 'id' => (int) $service_id, 'address_id' => (int) $address_id, 'version' => 1 );
                if ( ! empty( $args['op_id'] ) ) {
                        $this->log_op( $args['op_id'], $user_id, $result );
                }
                do_action( 'tpp_service_created', (int) $service_id, $data, $args );
                return $result;
        }

        public function get( $id ) {
                return TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'services' ) . " WHERE id = %d", array( (int) $id ) );
        }

        /**
         * بررسی فیلدهای الزامی آدرس (آدرس کامل، نام مرکز و هر فیلد اجباری دیگر)
         * فقط فیلدهایی که هم ارسال شده‌اند و برای کاربر قابل مشاهده‌اند بررسی می‌شوند.
         */
        private function check_required_address( $address_data, $visible ) {
                foreach ( TPP_Fields::all( 'address' ) as $f ) {
                        if ( ! $f['is_required'] || empty( $visible[ $f['slug'] ] ) ) {
                                continue;
                        }
                        if ( ! array_key_exists( $f['slug'], (array) $address_data ) ) {
                                continue;
                        }
                        $val = TPP_Fields::validate_value( $f, $address_data[ $f['slug'] ] );
                        if ( '' === $val || null === $val ) {
                                return new WP_Error( 'tpp_required', 'فیلد الزامی «' . $f['label'] . '» خالی است.' );
                        }
                }
                return true;
        }

        /**
         * ویرایش سرویس
         * $data = ['service' => [slug=>val], 'address' => [slug=>val], 'address_id' => ?]
         * $args = ['user_id','source','base_version','base_address_version','op_id','force']
         *
         * تعارض: اگر base_version با نسخه فعلی نخواند، تغییر همچنان به‌عنوان آخرین بازبینی ثبت
         * می‌شود اما با پرچم is_conflict تا در تاریخچه و اعلان‌ها مشخص باشد (سیاست انتخاب‌شده).
         */
        public function update( $id, $data, $args = array() ) {
                $user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
                $source  = sanitize_key( (string) ( $args['source'] ?? 'online' ) );
                if ( ! empty( $args['op_id'] ) ) {
                        $existing = TPP_DB::get_row( "SELECT result FROM " . TPP_DB::table( 'op_log' ) . " WHERE op_id = %s", array( (string) $args['op_id'] ) );
                        if ( $existing ) {
                                $decoded = json_decode( $existing['result'], true );
                                return is_array( $decoded ) ? $decoded : array( 'status' => 'duplicate' );
                        }
                }

                $service = $this->get( $id );
                if ( ! $service ) {
                        return new WP_Error( 'tpp_not_found', 'سرویس یافت نشد.' );
                }
                $visible = TPP_Capabilities::visible_fields( $user_id );

                // فیلدهای سرویس — فقط فیلدهای ارسالی و قابل مشاهده
                $changes = array();
                $sdata   = array();
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        $slug = $f['slug'];
                        if ( isset( $data['service'][ $slug ] ) && ! empty( $visible[ $slug ] ) ) {
                                $val = TPP_Fields::validate_value( $f, $data['service'][ $slug ] );
                                if ( (string) ( $service[ $slug ] ?? '' ) !== (string) $val ) {
                                        $changes[ $slug ] = array( 'old' => $service[ $slug ], 'new' => $val );
                                        $sdata[ $slug ]   = $val;
                                }
                        }
                }

                $address_id = (int) $service['address_id'];
                $address    = $this->get_address( $address_id );

                // فیلدهای آدرس — ۱.۱۰.۰: رویداد آدرس در رکورد سرویس ادغام می‌شود (یک رکورد به‌جای دو)
                $address_changed = false;
                $addr_event      = array();
                if ( ! empty( $data['address'] ) && is_array( $data['address'] ) && $address ) {
                        // فیلدهای الزامی آدرس (مثل آدرس کامل و نام مرکز) — ایمپورت معاف است
                        if ( 'import' !== $source && empty( $args['skip_required'] ) ) {
                                $addr_err = $this->check_required_address( $data['address'], $visible );
                                if ( is_wp_error( $addr_err ) ) {
                                        return $addr_err;
                                }
                        }
                        $addr_result = $this->update_address_fields( $address_id, $data['address'], $user_id, $source, $addr_event );
                        if ( is_wp_error( $addr_result ) ) {
                                return $addr_result;
                        }
                        if ( 'updated' === ( $addr_result['status'] ?? '' ) ) {
                                $address_changed = true;
                        }
                }
                // انتقال سرویس به آدرس دیگر
                if ( ! empty( $data['address_id'] ) && (int) $data['address_id'] !== $address_id ) {
                        $new_address = $this->get_address( (int) $data['address_id'] );
                        if ( ! $new_address ) {
                                return new WP_Error( 'tpp_address_not_found', 'آدرس جدید یافت نشد.' );
                        }
                        $sdata['address_id'] = (int) $data['address_id'];
                        $address_id          = (int) $data['address_id'];
                        $address_changed     = true;
                        $changes['_address_moved'] = array( 'old' => (int) $service['address_id'], 'new' => $address_id );
                }

                // ۱.۱۹.۰ — دسته‌بندی/تگ: فقط وقتی در payload آمده اعمال می‌شود
                $cat_changes = array();
                $cat_data = TPP_Categories::apply_to_payload( $data, $service, $cat_changes );
                if ( is_wp_error( $cat_data ) ) {
                        return $cat_data;
                }
                if ( ( ! isset( $data['category'] ) || null === $data['category'] ) && ( ! isset( $data['category_id'] ) ) ) {
                        // فیلد دسته ارسال نشده — دسته فعلی حفظ می‌شود
                } elseif ( 'import' !== $source && empty( $args['skip_required'] ) && TPP_Categories::any_category_defined() ) {
                        // دسته ارسال شده: نتیجه نهایی نباید خالی باشد (فیلد اجباری در فرم مشاهده/ویرایش)
                        $effective = isset( $cat_data['category_id'] ) ? (int) $cat_data['category_id'] : (int) ( $service['category_id'] ?? 0 );
                        if ( $effective <= 0 ) {
                                return new WP_Error( 'tpp_required', 'فیلد الزامی «دسته‌بندی پروژه» خالی است — از فهرست دسته‌بندی‌های تعریف‌شده انتخاب کنید.' );
                        }
                }
                foreach ( $cat_data as $col => $val ) {
                        $sdata[ $col ] = $val;
                }
                foreach ( $cat_changes as $k => $ch ) {
                        $changes[ $k ] = $ch;
                }

                // ۱.۱۲.۰/۱.۱۳.۰/۱.۱۴.۰ — پیشرفت دایری: فیلد progress اگر ارسال شده باشد با مقدار فعلی مقایسه و ثبت می‌شود
                // (فقط کاربرانی که اجازه ویرایش دارند می‌توانند تغییرش بدهند — همان قید سایر فیلدها)
                // ۱.۱۳.۰ — منطق آبشاری: تیک مرحله N همه مراحل قبل را خودکار تیک می‌زند (مگر ردشده‌ها)
                // ۱.۱۴.۰ — خرابی‌ها آرایه‌اند و می‌توان چند خرابی را همزمان اعلام کرد
                $progress_changed = false;
                if ( array_key_exists( 'progress', $data ) && is_array( $data['progress'] ) ) {
                        $prog       = TPP_Progress::apply( $data['progress'], $service );
                        $old        = TPP_Progress::steps_of( $service );
                        $new        = $prog['steps_array'];
                        $old_excl   = TPP_Progress::excluded_of( $service );
                        $new_excl   = $prog['excluded_array'];
                        $old_fails  = TPP_Progress::failures_of( $service );
                        $new_fails  = $prog['failures_array'];
                        if ( $old !== $new || $old_excl !== $new_excl ) {
                                $text = TPP_Progress::steps_change_text( $old, $new, $old_excl, $new_excl );
                                if ( '' !== $text ) {
                                        $changes['_progress_steps'] = array( 'old' => $old ? count( $old ) . ' مرحله' : null, 'new' => $text );
                                }
                                $sdata['progress_steps']      = $prog['steps'];
                                $sdata['progress_done']       = $prog['done'];
                                $sdata['progress_excluded']   = $prog['excluded'];
                                $progress_changed = true;
                        }
                        if ( $old_fails !== $new_fails ) {
                                $changes['_progress_failure'] = array(
                                        'old' => $old_fails ? TPP_Progress::failures_labels_text( $old_fails ) : null,
                                        'new' => $new_fails ? TPP_Progress::failures_labels_text( $new_fails ) : 'حل شد / حذف خرابی',
                                );
                                $sdata['progress_failures'] = $prog['failures']; // ۱.۱۴.۰ — JSON آرایه
                                $sdata['progress_failure']  = $prog['failure'];  // اولین خرابی (سازگاری)
                                $progress_changed = true;
                        }
                        if ( $progress_changed ) {
                                $sdata['progress_updated_at'] = TPP_Date::now();
                        }
                }

                if ( empty( $sdata ) && ! $address_changed ) {
                        $result = array( 'status' => 'nochange', 'id' => (int) $id, 'version' => (int) $service['version'] );
                        if ( ! empty( $args['op_id'] ) ) {
                                $this->log_op( $args['op_id'], $user_id, $result );
                        }
                        return $result;
                }

                // تشخیص تعارض نسخه
                $is_conflict = 0;
                $base_version = (int) ( $args['base_version'] ?? 0 );
                $current_version = (int) $service['version'];
                if ( $base_version > 0 && $base_version !== $current_version && empty( $args['force'] ) ) {
                        $is_conflict = 1; // ثبت می‌شود + علامت تعارض (سیاست تأییدشده)
                }

                $new_version = $current_version + 1;
                $sdata['updated_at'] = TPP_Date::now();
                $sdata['version']    = $new_version;
                TPP_DB::update( 'services', $sdata, array( 'id' => (int) $id ) );

                TPP_History::record( array(
                        'entity' => 'service', 'entity_id' => (int) $id, 'address_id' => $address_id,
                        'revision' => $new_version, 'user_id' => $user_id,
                        'action' => ( 'import' === $source ) ? 'import' : 'update',
                        'source' => $source, 'changes' => $changes, 'is_conflict' => $is_conflict,
                        'addr_changes' => $addr_event ? $addr_event['changes'] : null,
                        'addr_action'  => $addr_event ? $addr_event['action'] : null,
                ) );

                // ۱.۲۰.۰ — سجل بازبینی اقدامات نصاب‌ها ($service/$address = وضعیت قبل از تغییر)
                TPP_Review::track_update( $service, $address, (int) $id, $user_id, $source, array_merge( $changes, $addr_event ? (array) $addr_event['changes'] : array() ) );

                $result = array(
                        'status' => 'updated', 'id' => (int) $id, 'address_id' => (int) $address_id,
                        'version' => $new_version, 'conflict' => (bool) $is_conflict,
                );
                if ( ! empty( $args['op_id'] ) ) {
                        $this->log_op( $args['op_id'], $user_id, $result );
                }
                do_action( 'tpp_service_updated', (int) $id, $changes, $args );
                return $result;
        }

        /** حذف سرویس — تاریخچه آن سرویس هم به‌صورت آبشاری حذف می‌شود (درخواست کاربر) */
        public function delete( $id, $args = array() ) {
                $user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
                $source  = sanitize_key( (string) ( $args['source'] ?? 'online' ) );
                $service = $this->get( $id );
                if ( ! $service ) {
                        return new WP_Error( 'tpp_not_found', 'سرویس یافت نشد.' );
                }
                // ۱.۲۰.۰ — وضعیت قبل از حذف برای سجل بازبینی (کاربران غیرمدیر)
                $before_address = ! empty( $service['address_id'] ) ? $this->get_address( (int) $service['address_id'] ) : null;
                if ( ! empty( $args['op_id'] ) ) {
                        $existing = TPP_DB::get_row( "SELECT result FROM " . TPP_DB::table( 'op_log' ) . " WHERE op_id = %s", array( (string) $args['op_id'] ) );
                        if ( $existing ) {
                                $decoded = json_decode( $existing['result'], true );
                                return is_array( $decoded ) ? $decoded : array( 'status' => 'duplicate' );
                        }
                }

                TPP_DB::delete( 'services', array( 'id' => (int) $id ) );
                // حذف آبشاری تاریخچه این سرویس
                $hist_deleted = TPP_History::delete_for_service( (int) $id );
                // ۱.۲۰.۰ — سجل بازبینی: حذف توسط کاربر غیرمدیر با snapshot کامل
                TPP_Review::track_delete( $service, $before_address, (int) $id, $user_id, $source );
                $result = array( 'status' => 'deleted', 'id' => (int) $id, 'history_deleted' => (int) $hist_deleted );
                if ( ! empty( $args['op_id'] ) ) {
                        $this->log_op( $args['op_id'], $user_id, $result );
                }
                do_action( 'tpp_service_deleted', (int) $id, $args );
                return $result;
        }

        /**
         * ۱.۱۳.۰ — تغییر گروهی/تکی «پیشرفت دایری + خرابی + فیلدهای سرویس» روی چند سرویس.
         *
         * $opts:
         *   mode           => '' (بدون تغییر) | 'up_to' (تنظیم تا مرحله) | 'add' | 'remove' | 'clear'
         *   step           => کلید مرحله برای up_to (یا 'all' = همه ۱۶ مرحله)
         *   steps          => آرایه کلیدها برای add/remove
         *   skipped_policy => 'keep' (پیش‌فرض — مراحل ردشده توسط کاربر حفظ و پرش می‌شوند) | 'reset'
         *   failure        => null (بدون تغییر) | '' (رفع خرابی) | los|phone|internet|other
         *   service        => array(slug => value) — فیلدهای سرویس (مثل آخرین وضعیت اینترنت/تلفن)
         *   dry_run        => فقط محاسبه؛ ذخیره نمی‌شود
         * $args: user_id, source ('bulk'|'offline'), op_id
         *
         * هر سرویس تغییرکرده: به‌روزرسانی + رکورد تاریخچه (action=update, source=bulk) با علامت
         * «تغییر گروهی» — در گزارش فعالیت‌ها همان قالب ویرایش پیشرفت دایری دیده می‌شود.
         */
        public function apply_bulk( array $ids, array $opts, $args = array() ) {
                $user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
                $source  = sanitize_key( (string) ( $args['source'] ?? 'bulk' ) );
                if ( ! in_array( $source, array( 'bulk', 'offline' ), true ) ) {
                        $source = 'bulk';
                }
                $dry_run = ! empty( $opts['dry_run'] );

                // ۱.۱۳.۰ — تکرارناپذیری: op_id قبلاً اعمال شده → همان نتیجه بدون اعمال مجدد
                // (مثل create/update/delete — برای همگام‌سازی آفلاین که پاسخ ممکن است گم شود)
                if ( ! empty( $args['op_id'] ) && ! $dry_run ) {
                        $existing = TPP_DB::get_row( "SELECT result FROM " . TPP_DB::table( 'op_log' ) . " WHERE op_id = %s", array( (string) $args['op_id'] ) );
                        if ( $existing ) {
                                $decoded = json_decode( $existing['result'], true );
                                return is_array( $decoded ) ? $decoded : array( 'status' => 'duplicate' );
                        }
                }

                // ---- اعتبارسنجی گزینه‌ها ----
                $mode = sanitize_key( (string) ( $opts['mode'] ?? '' ) );
                if ( ! in_array( $mode, array( '', 'up_to', 'add', 'remove', 'clear' ), true ) ) {
                        return new WP_Error( 'tpp_bad_bulk_mode', 'نوع عملیات گروهی نامعتبر است.' );
                }
                $step  = sanitize_key( (string) ( $opts['step'] ?? '' ) );
                $steps = array();
                foreach ( (array) ( $opts['steps'] ?? array() ) as $s ) {
                        $s = sanitize_key( (string) $s );
                        if ( array_key_exists( $s, TPP_Progress::steps() ) ) {
                                $steps[] = $s;
                        }
                }
                if ( 'up_to' === $mode && 'all' !== $step && ! array_key_exists( $step, TPP_Progress::steps() ) ) {
                        return new WP_Error( 'tpp_bad_bulk_step', 'مرحله دایری نامعتبر است.' );
                }
                if ( ( 'add' === $mode || 'remove' === $mode ) && empty( $steps ) ) {
                        return new WP_Error( 'tpp_bad_bulk_steps', 'فهرست مراحل خالی است.' );
                }
                $reset_skips = ( 'reset' === sanitize_key( (string) ( $opts['skipped_policy'] ?? 'keep' ) ) );
                // ۱.۱۴.۰ — خرابی‌ها آرایه‌اند: failures (چندتایی) یا failure (legacy تکی)؛ null = بدون تغییر
                $failures = null;
                if ( array_key_exists( 'failures', $opts ) && null !== $opts['failures'] ) {
                        $failures = TPP_Progress::parse_failure_list( $opts['failures'] );
                } elseif ( array_key_exists( 'failure', $opts ) && null !== $opts['failure'] ) {
                        $one = sanitize_key( (string) $opts['failure'] );
                        if ( '' !== $one && ! array_key_exists( $one, TPP_Progress::failures() ) ) {
                                return new WP_Error( 'tpp_bad_bulk_failure', 'وضعیت خرابی نامعتبر است.' );
                        }
                        $failures = '' === $one ? array() : array( $one );
                }

                // ---- فیلدهای سرویس (اختیاری) — فقط فیلدهای قابل مشاهده کاربر ----
                $svc_values = array();
                if ( ! empty( $opts['service'] ) && is_array( $opts['service'] ) ) {
                        $visible = TPP_Capabilities::visible_fields( $user_id );
                        foreach ( TPP_Fields::all( 'service' ) as $f ) {
                                $slug = $f['slug'];
                                if ( array_key_exists( $slug, $opts['service'] ) && ! empty( $visible[ $slug ] ) ) {
                                        $svc_values[ $slug ] = TPP_Fields::validate_value( $f, $opts['service'][ $slug ] );
                                }
                        }
                        if ( empty( $svc_values ) ) {
                                return new WP_Error( 'tpp_bad_bulk_fields', 'فیلد سرویس ارسالی معتبر یا قابل مشاهده نیست.' );
                        }
                }

                $keys = TPP_Progress::step_keys();
                $results = array();
                $summary = array( 'total' => 0, 'applied' => 0, 'unchanged' => 0, 'not_found' => 0, 'error' => 0 );

                $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
                $summary['total'] = count( $ids );
                if ( count( $ids ) > 5000 ) {
                        return new WP_Error( 'tpp_bulk_too_many', 'حداکثر ۵۰۰۰ سرویس در هر عملیات گروهی (برای اجرای مطمئن، دسته‌های کوچک‌تر بفرستید).' );
                }

                foreach ( $ids as $id ) {
                        if ( $id <= 0 ) {
                                $summary['error']++;
                                $results[] = array( 'id' => $id, 'status' => 'error', 'message' => 'شناسه نامعتبر.' );
                                continue;
                        }
                        $service = $this->get( $id );
                        if ( ! $service ) {
                                $summary['not_found']++;
                                $results[] = array( 'id' => $id, 'status' => 'not_found', 'message' => 'سرویس یافت نشد.' );
                                continue;
                        }

                        // ---- محاسبه پیشرفت جدید بر اساس mode ----
                        $cur   = TPP_Progress::steps_of( $service );
                        $cur_e = TPP_Progress::excluded_of( $service );
                        $input = array( 'skipped' => array(), 'reset_skips' => false );
                        switch ( $mode ) {
                                case 'up_to':
                                        $input['steps'] = ( 'all' === $step ) ? $keys : array_slice( $keys, 0, array_search( $step, $keys, true ) + 1 );
                                        $input['reset_skips'] = $reset_skips;
                                        $input['skipped'] = $reset_skips ? array() : $cur_e;
                                        // ۱.۱۳.۰ — با سیاست keep: مراحل ردشده از فهرست هدف حذف می‌شوند تا
                                        // در apply() به‌عنوان «تیک دوباره توسط کاربر» تلقی نشوند و ردشده بمانند
                                        if ( ! $reset_skips && ! empty( $cur_e ) ) {
                                                $input['steps'] = array_values( array_diff( $input['steps'], $cur_e ) );
                                        }
                                        break;
                                case 'add':
                                        $input['steps'] = array_values( array_unique( array_merge( $cur, $steps ) ) );
                                        $input['skipped'] = $reset_skips ? array() : array_diff( $cur_e, $input['steps'] );
                                        break;
                                case 'remove':
                                        $input['steps'] = array_values( array_diff( $cur, $steps ) );
                                        $input['skipped'] = $reset_skips ? array() : $cur_e;
                                        break;
                                case 'clear':
                                        $input['steps'] = array();
                                        $input['skipped'] = array();
                                        $input['reset_skips'] = true; // پاک‌کردن پیشرفت = شروع تازه
                                        break;
                                default:
                                        $input['steps'] = $cur; // بدون تغییر پیشرفت — فقط خرابی/فیلدها
                                        $input['skipped'] = $cur_e;
                        }
                        if ( null !== $failures ) {
                                $input['failures'] = $failures; // ۱.۱۴.۰ — فهرست جدید خرابی‌ها (آرایه خالی = رفع همه)
                        } else {
                                $input['failures'] = TPP_Progress::failures_of( $service ); // بدون تغییر
                        }

                        $prog = TPP_Progress::apply( $input, $service );

                        // ---- تغییر فیلدهای سرویس ----
                        $changes = array();
                        $sdata   = array();
                        foreach ( $svc_values as $slug => $val ) {
                                if ( (string) ( $service[ $slug ] ?? '' ) !== (string) $val ) {
                                        $changes[ $slug ] = array( 'old' => $service[ $slug ] ?? '', 'new' => $val );
                                        $sdata[ $slug ]   = $val;
                                }
                        }

                        // ---- تغییر پیشرفت/خرابی ----
                        $new      = $prog['steps_array'];
                        $new_excl = $prog['excluded_array'];
                        $old_fails = TPP_Progress::failures_of( $service );
                        $new_fails = $prog['failures_array'];
                        $progress_changed = false;
                        if ( $cur !== $new || $cur_e !== $new_excl ) {
                                $text = TPP_Progress::steps_change_text( $cur, $new, $cur_e, $new_excl );
                                if ( '' !== $text ) {
                                        $changes['_progress_steps'] = array( 'old' => $cur ? count( $cur ) . ' مرحله' : null, 'new' => $text );
                                }
                                $sdata['progress_steps']    = $prog['steps'];
                                $sdata['progress_done']     = $prog['done'];
                                $sdata['progress_excluded'] = $prog['excluded'];
                                $progress_changed = true;
                        }
                        if ( $old_fails !== $new_fails ) {
                                $changes['_progress_failure'] = array(
                                        'old' => $old_fails ? TPP_Progress::failures_labels_text( $old_fails ) : null,
                                        'new' => $new_fails ? TPP_Progress::failures_labels_text( $new_fails ) : 'حل شد / حذف خرابی',
                                );
                                $sdata['progress_failures'] = $prog['failures']; // ۱.۱۴.۰ — JSON آرایه
                                $sdata['progress_failure']  = $prog['failure'];  // اولین خرابی (سازگاری)
                                $progress_changed = true;
                        }

                        if ( empty( $changes ) ) {
                                $summary['unchanged']++;
                                $results[] = array(
                                        'id' => $id, 'status' => 'unchanged',
                                        'progress' => TPP_Progress::summary( $service ),
                                );
                                continue;
                        }

                        $entry = array(
                                'id' => $id, 'status' => 'applied',
                                'progress' => TPP_Progress::summary(
                                        array_merge( is_array( $service ) ? $service : array(), array(
                                                'progress_steps' => $prog['steps'],
                                                'progress_done'  => $prog['done'],
                                                'progress_failures' => $prog['failures'],
                                                'progress_failure' => $prog['failure'],
                                                'progress_excluded' => $prog['excluded'],
                                        ) )
                                ),
                        );

                        if ( $dry_run ) {
                                $entry['dry_run'] = true;
                                $entry['changes'] = $changes;
                                $results[] = $entry;
                                $summary['applied']++;
                                continue;
                        }

                        if ( $progress_changed ) {
                                $sdata['progress_updated_at'] = TPP_Date::now();
                        }
                        $new_version = (int) $service['version'] + 1;
                        $sdata['updated_at'] = TPP_Date::now();
                        $sdata['version']    = $new_version;
                        TPP_DB::update( 'services', $sdata, array( 'id' => $id ) );

                        // علامت «تغییر گروهی» برای گزارش فعالیت‌ها
                        $changes['_bulk_edit'] = array( 'old' => null, 'new' => 'تغییر گروهی پیشرفت/وضعیت' );
                        TPP_History::record( array(
                                'entity' => 'service', 'entity_id' => $id, 'address_id' => (int) $service['address_id'],
                                'revision' => $new_version, 'user_id' => $user_id,
                                'action' => 'update', 'source' => $source, 'changes' => $changes,
                        ) );

                        // ۱.۲۰.۰ — سجل بازبینی اقدامات نصاب‌ها (تغییر گروهی)
                        TPP_Review::track_update( $service, ! empty( $service['address_id'] ) ? $this->get_address( (int) $service['address_id'] ) : null, $id, $user_id, $source, $changes );

                        $summary['applied']++;
                        do_action( 'tpp_service_updated', $id, $changes, $args );
                        $results[] = $entry;
                }

                $out = array( 'status' => 'done', 'results' => $results, 'summary' => $summary );
                if ( ! empty( $args['op_id'] ) && ! $dry_run ) {
                        $this->log_op( $args['op_id'], $user_id, array(
                                'status' => 'done', 'summary' => $summary,
                        ) );
                }
                return $out;
        }

        private function log_op( $op_id, $user_id, $result ) {
                TPP_DB::insert( 'op_log', array(
                        'op_id'   => substr( (string) $op_id, 0, 64 ),
                        'user_id' => (int) $user_id,
                        'result'  => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ),
                        'created_at' => TPP_Date::now(),
                ) );
        }

        /* ---------------------------------------------------------------------
         * جستجو
         * ------------------------------------------------------------------- */

        /**
         * جستجوی سراسری.
         * $args: query (متن آزاد), filters ([slug=>value]), page, per_page, group (گروه‌بندی بر اساس آدرس),
         *        user_id (برای اعمال دسترسی فیلد), sort (updated|unit|block|postal|address), order (ASC|DESC)
         */
        public function search( $args = array(), $user_id = 0 ) {
                $user_id = $user_id ? (int) $user_id : get_current_user_id();
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $query   = trim( (string) ( $args['query'] ?? '' ) );
                $filters = (array) ( $args['filters'] ?? array() );
                $page    = max( 1, (int) ( $args['page'] ?? 1 ) );
                $per     = min( 500, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
                $group   = ! empty( $args['group'] );

                $sfields = array(); // فیلدهای قابل جستجوی سرویس
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( $f['is_searchable'] && ! empty( $visible[ $f['slug'] ] ) ) {
                                $sfields[] = $f['slug'];
                        }
                }
                $afields = array();
                foreach ( TPP_Fields::all( 'address' ) as $f ) {
                        if ( $f['is_searchable'] && ! empty( $visible[ $f['slug'] ] ) ) {
                                $afields[] = $f['slug'];
                        }
                }

                $st = TPP_DB::table( 'services' );
                $at = TPP_DB::table( 'addresses' );
                $where  = ' WHERE 1=1';
                $params = array();

                // متن آزاد — LIKE روی همه فیلدهای قابل جستجو (سرویس + آدرس)
                if ( '' !== $query ) {
                        $like    = '%' . TPP_DB::esc_like( $query ) . '%';
                        $ors     = array();
                        foreach ( $sfields as $slug ) {
                                $ors[]   = "s.{$slug} LIKE %s";
                                $params[] = $like;
                        }
                        foreach ( $afields as $slug ) {
                                $ors[]   = "a.{$slug} LIKE %s";
                                $params[] = $like;
                        }
                        if ( $ors ) {
                                $where .= ' AND ( ' . implode( ' OR ', $ors ) . ' )';
                        }
                }

                // بازه زمانی ویرایش (زمان آخرین بروزرسانی سرویس) — upd_from/upd_to به تاریخ میلادی YYYY-MM-DD
                $upd = $this->updated_range( $args );
                if ( $upd ) {
                        $where   .= ' AND s.updated_at >= %s AND s.updated_at <= %s';
                        $params[] = $upd[0];
                        $params[] = $upd[1];
                }

                // ۱.۱۲.۰ — فیلتر وضعیت پیشرفت دایری (none|progress|done|fail|fail_los|…)
                $psw = TPP_Progress::status_where( (string) ( $args['progress_status'] ?? '' ), 's' );
                if ( $psw ) {
                        $where   .= $psw[0];
                        $params   = array_merge( $params, $psw[1] );
                }
                // ۱.۱۲.۰ — فیلتر مرحله خاص دایری (انجام‌شده/انجام‌نشده)
                $stw = TPP_Progress::step_where( (string) ( $args['progress_step'] ?? '' ), (string) ( $args['progress_step_state'] ?? '' ), 's' );
                if ( $stw ) {
                        $where   .= $stw[0];
                        $params   = array_merge( $params, $stw[1] );
                }

                // ۱.۱۹.۰ — فیلتر دسته‌بندی/تگ (دسته = دقیق؛ تگ‌ها = هرکدام)
                $cw = TPP_Categories::filter_where( $args, 's' );
                if ( $cw ) {
                        $where   .= $cw[0];
                        $params   = array_merge( $params, $cw[1] );
                }

                // فیلترهای اختصاصی هر فیلد
                foreach ( $filters as $slug => $value ) {
                        $value = trim( (string) $value );
                        if ( '' === $value || ! preg_match( '/^[a-z0-9_]{1,64}$/', $slug ) ) {
                                continue;
                        }
                        $is_service = in_array( $slug, $sfields, true );
                        $is_address = in_array( $slug, $afields, true );
                        if ( ! $is_service && ! $is_address ) {
                                continue; // فیلد ناشناخته یا غیرقابل جستجو یا پنهان
                        }
                        $alias   = $is_service ? 's' : 'a';
                        $like    = '%' . TPP_DB::esc_like( $value ) . '%';
                        $where  .= " AND {$alias}.{$slug} LIKE %s";
                        $params[] = $like;
                }

                if ( $group ) {
                        return $this->search_grouped( $query, $where, $params, $page, $per, $visible, $this->sort_clause( $args, 'a', true ) );
                }

                $total   = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} s LEFT JOIN {$at} a ON s.address_id = a.id {$where}", $params );
                $offset  = ( $page - 1 ) * $per;
                $orderby = $this->sort_clause( $args, 'a' );
                $sql     = "SELECT s.* FROM {$st} s LEFT JOIN {$at} a ON s.address_id = a.id {$where} {$orderby} LIMIT %d OFFSET %d";
                $rows    = TPP_DB::get_results( $sql, array_merge( $params, array( $per, $offset ) ) );

                $out = array();
                foreach ( (array) $rows as $row ) {
                        $out[] = $this->shape_row( $row, $visible );
                }
                return array( 'total' => $total, 'page' => $page, 'per_page' => $per, 'rows' => $out );
        }

        /**
         * بازه زمانی ویرایش (آخرین بروزرسانی سرویس) از آرگومان‌های جستجو.
         * ورودی: upd_from / upd_to با قالب YYYY-MM-DD (میلادی) — هر کدام اختیاری.
         * فقط from درج شده → از همان روز به بعد؛ فقط to → تا همان روز؛ هر دو یک روز → همان روز خاص.
         * خروجی: [از 00:00:00, تا 23:59:59] یا null (بدون فیلتر). تاریخ‌های نامعتبر نادیده گرفته می‌شوند
         * و اگر ترتیب برعکس باشد بی‌صدا جابه‌جا می‌شوند تا نتیجه همیشه معنادار باشد.
         */
        private function updated_range( $args ) {
                $from = trim( (string) ( $args['upd_from'] ?? '' ) );
                $to   = trim( (string) ( $args['upd_to'] ?? '' ) );
                $valid = function ( $d ) {
                        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
                                return '';
                        }
                        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                                return '';
                        }
                        return $d;
                };
                $from = $valid( $from );
                $to   = $valid( $to );
                if ( '' === $from && '' === $to ) {
                        return null;
                }
                if ( '' === $from ) {
                        $from = '1000-01-01';
                }
                if ( '' === $to ) {
                        $to = '9999-12-31';
                }
                if ( strcmp( $from, $to ) > 0 ) {
                        $tmp = $from; $from = $to; $to = $tmp; // ترتیب برعکس → جابه‌جا
                }
                return array( $from . ' 00:00:00', $to . ' 23:59:59' );
        }

        /**
         * ساخت رشته ORDER BY از پارامترهای sort/order — فقط کلیدهای مجاز و فقط ستون‌های موجود.
         * sort: updated (آخرین ویرایش)، unit (شماره واحد)، block (نام بلوک/خیابان)، postal (کد پستی)، address (آدرس — بدون حساسیت به بزرگی/کوچکی حروف)
         */
        private function sort_clause( $args, $addr_alias = 'a', $grouped = false ) {
                $sort  = sanitize_key( (string) ( $args['sort'] ?? 'updated' ) );
                $order = ( 'ASC' === strtoupper( (string) ( $args['order'] ?? '' ) ) ) ? 'ASC' : 'DESC';
                // در حالت گروه‌بندی، کوئری DISTINCT روی آدرس‌هاست → فقط ستون‌های a.* قابل ارجاع‌اند
                $tie = $grouped ? "{$addr_alias}.id DESC" : 's.id DESC';

                $addr_slugs = array();
                foreach ( TPP_Fields::all( 'address' ) as $f ) {
                        $addr_slugs[] = $f['slug'];
                }

                switch ( $sort ) {
                        case 'updated':
                                // آخرین ویرایش — جهت ASC/DESC پشتیبانی می‌شود (رفع نادیده‌گرفته‌شدن ASC)
                                return $grouped
                                        ? " ORDER BY {$addr_alias}.updated_at {$order}, {$addr_alias}.id {$order}"
                                        : " ORDER BY s.updated_at {$order}, s.id {$order}";
                        case 'unit':
                                if ( in_array( 'f_unit', $addr_slugs, true ) ) {
                                        // واحد و کد پستی عددی‌گونه‌اند: اول مقدار عددی، بعد متنی (تا «واحد ۱۰» بعد از «واحد ۹» بیاید)
                                        return " ORDER BY ({$addr_alias}.f_unit + 0) {$order}, {$addr_alias}.f_unit {$order}, {$tie}";
                                }
                                break;
                        case 'block':
                                if ( in_array( 'f_block', $addr_slugs, true ) ) {
                                        return " ORDER BY {$addr_alias}.f_block {$order}, {$addr_alias}.f_full_address {$order}, {$tie}";
                                }
                                break;
                        case 'postal':
                                if ( in_array( 'f_postal_code', $addr_slugs, true ) ) {
                                        return " ORDER BY ({$addr_alias}.f_postal_code + 0) {$order}, {$addr_alias}.f_postal_code {$order}, {$tie}";
                                }
                                break;
                        case 'address':
                                if ( in_array( 'f_full_address', $addr_slugs, true ) ) {
                                        // LOWER() → مرتب‌سازی بدون حساسیت به بزرگی/کوچکی حروف
                                        return " ORDER BY LOWER({$addr_alias}.f_full_address) {$order}, {$addr_alias}.f_block {$order}, {$tie}";
                                }
                                break;
                }
                if ( $grouped ) {
                        return " ORDER BY {$addr_alias}.updated_at DESC, {$addr_alias}.id DESC";
                }
                return " ORDER BY s.updated_at DESC, s.id DESC";
        }

        /** جستجوی گروه‌بندی‌شده بر اساس آدرس (هر آدرس یک‌بار + سرویس‌هایش) */
        private function search_grouped( $query, $where, $params, $page, $per, $visible, $orderby = '' ) {
                $st = TPP_DB::table( 'services' );
                $at = TPP_DB::table( 'addresses' );

                // آدرس‌های مطبق: یا فیلد خودش مطابق است یا سرویسی از آن مطابق است
                $total = (int) TPP_DB::get_var(
                        "SELECT COUNT(DISTINCT a.id) FROM {$at} a LEFT JOIN {$st} s ON s.address_id = a.id {$where}",
                        $params
                );
                $offset = ( $page - 1 ) * $per;
                $orderby = $orderby ? $orderby : " ORDER BY a.updated_at DESC, a.id DESC";
                $sql = "SELECT DISTINCT a.* FROM {$at} a LEFT JOIN {$st} s ON s.address_id = a.id {$where}
                                {$orderby} LIMIT %d OFFSET %d";
                $addresses = TPP_DB::get_results( $sql, array_merge( $params, array( $per, $offset ) ) );

                $groups = array();
                foreach ( (array) $addresses as $addr ) {
                        $services = $this->address_services( (int) $addr['id'] );
                        $shaped   = array();
                        foreach ( $services as $svc ) {
                                $shaped[] = $this->shape_row( $svc, $visible );
                        }
                        $groups[] = array(
                                'address'  => $this->shape_address( $addr, $visible ),
                                'services' => $shaped,
                                'count'    => count( $shaped ),
                        );
                }
                return array( 'total' => $total, 'page' => $page, 'per_page' => $per, 'grouped' => $groups );
        }

        /** شکل‌دهی ردیف سرویس برای خروجی JSON (فیلدهای پنهان حذف می‌شوند) */
        public function shape_row( $row, $visible = null ) {
                if ( null === $visible ) {
                        $visible = TPP_Capabilities::visible_fields( get_current_user_id() );
                }
                $address = $this->get_address( (int) $row['address_id'] );
                $out = array(
                        'id'         => (int) $row['id'],
                        'address_id' => (int) $row['address_id'],
                        'version'    => (int) $row['version'],
                        'created_by' => (int) $row['created_by'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                        'address'    => $address ? $this->shape_address( $address, $visible ) : null,
                );
                $creator = get_userdata( (int) $row['created_by'] );
                $out['created_by_name'] = $creator ? $creator->display_name : '—';
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        $slug = $f['slug'];
                        if ( ! empty( $visible[ $slug ] ) ) {
                                $out[ $slug ] = (string) ( $row[ $slug ] ?? '' );
                        }
                }
                // ۱.۱۲.۰ — خلاصه پیشرفت دایری (مراحل + خرابی + درصد) برای همه کاربران
                $out['progress'] = TPP_Progress::summary( $row );
                // ۱.۱۹.۰ — دسته‌بندی/تگ سرویس (داده سیستمی — تابع دسترسی فیلد نیست)
                $out = array_merge( $out, TPP_Categories::shape( $row ) );
                return $out;
        }

        public function shape_address( $address, $visible = null ) {
                if ( null === $visible ) {
                        $visible = TPP_Capabilities::visible_fields( get_current_user_id() );
                }
                $out = array(
                        'id'         => (int) $address['id'],
                        'version'    => (int) $address['version'],
                        'created_at' => $address['created_at'],
                        'updated_at' => $address['updated_at'],
                );
                foreach ( TPP_Fields::all( 'address' ) as $f ) {
                        $slug = $f['slug'];
                        if ( ! empty( $visible[ $slug ] ) ) {
                                $out[ $slug ] = (string) ( $address[ $slug ] ?? '' );
                        }
                }
                return $out;
        }

        /** آمار داشبورد */
        public function stats() {
                return array(
                        'services'   => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'services' ) ),
                        'addresses'  => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'addresses' ) ),
                        'changes'    => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'history' ) ),
                        'today'      => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'history' ) . " WHERE DATE(changed_at) = %s", array( TPP_Date::today() ) ),
                        'conflicts'  => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'history' ) . " WHERE is_conflict = 1" ),
                        'last_change'=> TPP_DB::get_var( "SELECT changed_at FROM " . TPP_DB::table( 'history' ) . " ORDER BY id DESC LIMIT 1" ),
                        // ۱.۱۲.۰ — آمار پیشرفت دایری برای داشبورد
                        'progress'   => TPP_Progress::stats(),
                );
        }

        /** پیشنهاد آدرس هنگام تایپ (برای فرم ثبت سرویس) */
        public function suggest_addresses( $query, $limit = 8 ) {
                $like = '%' . TPP_DB::esc_like( $query ) . '%';
                $at   = TPP_DB::table( 'addresses' );
                $ors    = array();
                $params = array();
                foreach ( TPP_Fields::all( 'address' ) as $f ) {
                        if ( $f['is_searchable'] ) {
                                $ors[]   = $f['slug'] . " LIKE %s";
                                $params[] = $like;
                        }
                }
                if ( empty( $ors ) ) {
                        return array();
                }
                $sql = "SELECT * FROM {$at} WHERE " . implode( ' OR ', $ors ) . " ORDER BY id DESC LIMIT %d";
                $params[] = (int) $limit;
                $rows = TPP_DB::get_results( $sql, $params );
                $out  = array();
                $user_id = get_current_user_id();
                $visible = TPP_Capabilities::visible_fields( $user_id );
                foreach ( (array) $rows as $r ) {
                        $out[] = $this->shape_address( $r, $visible );
                }
                return $out;
        }

        /* ---------------------------------------------------------------------
         * کشویی جستجوی اجاکسی (۱.۹.۰) — مقادیر موجود هر فیلد برای فیلترها
         * ------------------------------------------------------------------- */

        /**
         * مقادیر یکتای یک فیلد برای کشویی فیلترها (جستجوی اجاکسی).
         * مقادیر خالی/بی‌ارزش (''، '0'، '-') و مقدار AUTO حذف می‌شوند؛ خروجی مرتب‌شده صعودی.
         * فیلد باید قابل جستجو و برای کاربر قابل مشاهده باشد (فیلدهای حساس/پنهان قابل جستجو نیستند).
         */
        public function distinct_values( $slug, $query = '', $limit = 100, $user_id = 0 ) {
                $user_id = $user_id ? (int) $user_id : get_current_user_id();
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $field   = null;
                $table   = null;
                foreach ( TPP_Fields::all() as $f ) {
                        if ( $f['slug'] === $slug ) {
                                $field = $f;
                                $table = ( 'address' === $f['group_key'] ) ? 'addresses' : 'services';
                                break;
                        }
                }
                if ( ! $field || ! $field['is_searchable'] || empty( $visible[ $slug ] ) ) {
                        return new WP_Error( 'tpp_bad_field', 'فیلد نامعتبر است یا قابل جستجو/مشاهده نیست.', array( 'status' => 400 ) );
                }
                $limit = max( 1, min( 500, (int) $limit ) );
                $t     = TPP_DB::table( $table );
                $q     = trim( (string) $query );
                // TRIM داخل SQL تا «تهران» و « تهران » یکی حساب نشوند؛ NOT IN برای مقادیر بی‌ارزش
                $base = "SELECT DISTINCT TRIM({$slug}) AS v FROM {$t}
                          WHERE {$slug} IS NOT NULL AND TRIM({$slug}) <> '' AND UPPER(TRIM({$slug})) NOT IN ('0','-','AUTO')";
                if ( '' !== $q ) {
                        $like = '%' . TPP_DB::esc_like( $q ) . '%';
                        $rows = TPP_DB::get_results( $base . " AND {$slug} LIKE %s ORDER BY v ASC LIMIT %d", array( $like, $limit ) );
                } else {
                        $rows = TPP_DB::get_results( $base . " ORDER BY v ASC LIMIT %d", array( $limit ) );
                }
                $out = array();
                foreach ( (array) $rows as $r ) {
                        $v = trim( (string) ( $r['v'] ?? '' ) );
                        if ( '' !== $v ) {
                                $out[] = $v;
                        }
                }
                return $out;
        }

        /* ---------------------------------------------------------------------
         * بررسی موارد تکراری (۱.۹.۰) — فقط مدیر کل (دسترسی در لایه REST)
         * ------------------------------------------------------------------- */

        /** فیلدهای سرویسِ قابل مقایسه (کشویی انتخاب فیلدهای تطبیق در صفحه تکراری‌ها) */
        public function duplicate_compare_fields() {
                $out = array();
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( $f['is_searchable'] ) {
                                $out[] = array( 'slug' => $f['slug'], 'label' => $f['label'] );
                        }
                }
                return $out;
        }

        /** فیلدهای پیش‌فرض شناسایی تکراری — شماره تلفن، شماره مجازی، سریال مودم (هرچه موجود) */
        public static function default_duplicate_fields() {
                $want = array( 'f_phone', 'f_virtual_number', 'f_modem_serial' );
                $out  = array();
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( $f['is_searchable'] && in_array( $f['slug'], $want, true ) ) {
                                $out[] = $f['slug'];
                        }
                }
                return $out;
        }

        /** مقدار بی‌ارزش برای مقایسه تکراری؟ (خالی، صفر، خط‌تیره، AUTO) */
        private static function trivial_dup_value( $value ) {
                $v = strtoupper( trim( (string) $value ) );
                return '' === $v || '0' === $v || '-' === $v || 'AUTO' === $v;
        }

        /** نرمال‌سازی مقدار برای مقایسه تکراری: ی/ک عربی، ارقام فارسی/عربی، فاصله‌ها، کوچک‌سازی */
        private static function dup_key( $value ) {
                $v = self::latin_digits( self::normalize( (string) $value ) );
                return mb_strtolower( $v, 'UTF-8' );
        }

        /** کلید گروه (شناسه سرویس‌ها به‌صورت مرتب‌شده) برای علامت‌گذاری «باقی می‌ماند به حالت فعلی» */
        private static function dup_group_key( array $ids ) {
                $ids = array_map( 'intval', $ids );
                sort( $ids );
                return implode( '_', $ids );
        }

        /** گروه‌های علامت‌خورده «باقی می‌ماند به حالت فعلی» */
        public function duplicate_dismissed_keys() {
                $keys = get_option( 'tpp_dup_dismissed', array() );
                return is_array( $keys ) ? array_values( array_unique( array_map( 'strval', $keys ) ) ) : array();
        }

        /** علامت‌گذاری گروه به‌عنوان «بررسی‌شده — باقی می‌ماند به حالت فعلی» */
        public function dismiss_duplicate_group( array $ids ) {
                $key  = self::dup_group_key( $ids );
                $keys = $this->duplicate_dismissed_keys();
                if ( ! in_array( $key, $keys, true ) ) {
                        $keys[] = $key;
                        update_option( 'tpp_dup_dismissed', $keys, false );
                }
                return $key;
        }

        /** بازگردانی گروه/گروه‌های علامت‌خورده (کلید خاص یا 'all') */
        public function restore_duplicate_groups( $key = 'all' ) {
                $keys = $this->duplicate_dismissed_keys();
                if ( 'all' === $key ) {
                        update_option( 'tpp_dup_dismissed', array(), false );
                        return count( $keys );
                }
                $key  = (string) $key;
                $keys = array_values( array_filter( $keys, static function ( $k ) use ( $key ) {
                        return $k !== $key;
                } ) );
                update_option( 'tpp_dup_dismissed', $keys, false );
                return 1;
        }

        /**
         * یافتن گروه‌های سرویس تکراری — سرویس‌هایی که در فیلدهای شناسایی مقدار یکسان دارند.
         * گروه‌های هم‌پوشان با union-find ادغه می‌شوند (مثلاً تلفن یکسان + سریال مودم یکسان → یک گروه).
         * $args: fields (رشته slugs با کاما — پیش‌فرض: تلفن/شماره مجازی/سریال مودم)، include_dismissed
         */
        public function find_duplicates( $args = array() ) {
                $user_id = get_current_user_id();

                // فیلدهای مقایسه (اعتبارسنجی: باید فیلد سرویسِ قابل جستجو باشد)
                $valid   = array();
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( $f['is_searchable'] ) {
                                $valid[ $f['slug'] ] = $f;
                        }
                }
                $requested = array();
                if ( ! empty( $args['fields'] ) && is_string( $args['fields'] ) ) {
                        foreach ( explode( ',', (string) $args['fields'] ) as $slug ) {
                                $slug = trim( $slug );
                                if ( isset( $valid[ $slug ] ) ) {
                                        $requested[] = $slug;
                                }
                        }
                }
                $fields = $requested ? $requested : self::default_duplicate_fields();
                if ( empty( $fields ) ) {
                        return array( 'total' => 0, 'groups' => array(), 'fields' => array(), 'selected' => array(), 'dismissed_count' => 0 );
                }

                // همه سرویس‌ها + آدرس‌شان (مدیر کل → همه فیلدها قابل مشاهده)
                $st = TPP_DB::table( 'services' );
                $at = TPP_DB::table( 'addresses' );
                $rows = TPP_DB::get_results( "SELECT s.*, a.id AS _addr_pk FROM {$st} s LEFT JOIN {$at} a ON s.address_id = a.id" );
                $services = array();
                foreach ( (array) $rows as $row ) {
                        $services[ (int) $row['id'] ] = $row;
                }
                if ( count( $services ) < 2 ) {
                        return array( 'total' => 0, 'groups' => array(), 'fields' => $this->duplicate_compare_fields(), 'selected' => $fields, 'dismissed_count' => 0 );
                }

                // union-find روی شناسه سرویس‌ها
                $parent = array();
                foreach ( array_keys( $services ) as $id ) {
                        $parent[ $id ] = $id;
                }
                $find = static function ( $x ) use ( &$parent ) {
                        while ( $parent[ $x ] !== $x ) {
                                $parent[ $x ] = $parent[ $parent[ $x ] ];
                                $x = $parent[ $x ];
                        }
                        return $x;
                };
                $union = static function ( $a, $b ) use ( &$parent, $find ) {
                        $ra = $find( $a );
                        $rb = $find( $b );
                        if ( $ra !== $rb ) {
                                $parent[ $rb ] = $ra; // ریشه کوچک‌تر (قدیمی‌تر) باقی بماند
                        }
                };

                foreach ( $fields as $slug ) {
                        $by_value = array();
                        foreach ( $services as $id => $row ) {
                                $val = (string) ( $row[ $slug ] ?? '' );
                                if ( self::trivial_dup_value( $val ) ) {
                                        continue;
                                }
                                $by_value[ self::dup_key( $val ) ][] = $id;
                        }
                        foreach ( $by_value as $ids ) {
                                if ( count( $ids ) < 2 ) {
                                        continue;
                                }
                                for ( $i = 1; $i < count( $ids ); $i++ ) {
                                        $union( $ids[0], $ids[ $i ] );
                                }
                        }
                }

                // خوشه‌ها
                $clusters = array();
                foreach ( $parent as $id => $root ) {
                        $clusters[ $find( $id ) ][] = $id;
                }

                $dismissed      = $this->duplicate_dismissed_keys();
                $dismissed_set  = array_flip( $dismissed );
                $include_dismissed = ! empty( $args['include_dismissed'] );
                $dismissed_count = 0;

                $labels = array();
                foreach ( TPP_Fields::all() as $f ) {
                        $labels[ $f['slug'] ] = $f['label'];
                }

                $groups = array();
                $summary = array(
                        'total_groups'      => 0,
                        'conflict_groups'   => 0,
                        'clean_groups'      => 0,
                        'services_involved' => 0,
                        'clean_services'    => 0,
                        'merge_savings'     => 0,
                );
                foreach ( $clusters as $root => $ids ) {
                        if ( count( $ids ) < 2 ) {
                                continue;
                        }
                        sort( $ids );
                        $key = self::dup_group_key( $ids );
                        $is_dismissed = isset( $dismissed_set[ $key ] );
                        if ( $is_dismissed ) {
                                $dismissed_count++;
                        }
                        if ( $is_dismissed && ! $include_dismissed ) {
                                continue;
                        }

                        // آدرس اعضا یک‌بار (به‌جای فراخوانی تکراری get_address برای هر فیلد)
                        $addr_of = array();
                        foreach ( $ids as $id ) {
                                $addr_of[ $id ] = $this->get_address( (int) $services[ $id ]['address_id'] );
                        }

                        /*
                         * دسته‌بندی فیلدها (۱.۱۱.۰ — تشخیص تناقض):
                         *  - shared        : در همه اعضا مقدار معنادارِ یکسان است (تشابه)
                         *  - conflicts     : در حداقل دو عضو مقدار معنادارِ «متفاوت» است (تناقض)
                         *  - complementary : در برخی اعضا پر و در بقیه خالی است (مکمل — ادغام بی‌ضرر)
                         */
                        $shared = array();
                        $conflicts = array();
                        $complementary = array();
                        $filled_count = array();
                        $all_fields = array_merge( TPP_Fields::all( 'service' ), TPP_Fields::all( 'address' ) );
                        foreach ( $all_fields as $f ) {
                                $slug = $f['slug'];
                                $is_addr = ( 'address' === $f['group_key'] );
                                $vals = array();
                                foreach ( $ids as $id ) {
                                        $row = $services[ $id ];
                                        if ( $is_addr ) {
                                                $addr = $addr_of[ $id ];
                                                $val  = $addr ? (string) ( $addr[ $slug ] ?? '' ) : '';
                                        } else {
                                                $val = (string) ( $row[ $slug ] ?? '' );
                                        }
                                        $vals[ $id ] = trim( (string) $val );
                                        if ( ! self::trivial_dup_value( $val ) ) {
                                                $filled_count[ $id ] = ( $filled_count[ $id ] ?? 0 ) + 1;
                                        }
                                }
                                $nontrivial = array_filter( $vals, static function ( $v ) {
                                        return ! self::trivial_dup_value( $v );
                                } );
                                $norm = array();
                                foreach ( $nontrivial as $id => $v ) {
                                        $norm[ $id ] = self::dup_key( $v );
                                }
                                $unique_norm = array_values( array_unique( $norm ) );
                                if ( count( $unique_norm ) === 1 && count( $norm ) === count( $ids ) ) {
                                        // همه اعضا مقدار یکسان معنادار دارند
                                        $shared[] = array( 'slug' => $slug, 'label' => $labels[ $slug ] ?? $slug, 'value' => reset( $vals ) );
                                } elseif ( count( $unique_norm ) >= 2 ) {
                                        // حداقل دو مقدار متفاوت → تناقض
                                        $conflicts[] = array(
                                                'slug'   => $slug,
                                                'label'  => $labels[ $slug ] ?? $slug,
                                                'values' => $vals,
                                        );
                                } elseif ( count( $norm ) >= 1 && count( $norm ) < count( $ids ) ) {
                                        // بعضی پر و بعضی خالی → مکمل
                                        $filled = array();
                                        foreach ( $norm as $id => $nv ) {
                                                $filled[] = (int) $id;
                                        }
                                        $complementary[] = array( 'slug' => $slug, 'label' => $labels[ $slug ] ?? $slug, 'filled_in' => $filled, 'value' => $vals[ $filled[0] ] );
                                }
                        }

                        // پیشنهاد سرویس اصلی: غنی‌ترین رکورد (بیشترین فیلد پر) — تساوی → قدیمی‌ترین
                        $recommended = $ids[0];
                        $best = -1;
                        foreach ( $ids as $id ) {
                                $cnt = (int) ( $filled_count[ $id ] ?? 0 );
                                $older = strtotime( (string) $services[ $id ]['created_at'] ) < strtotime( (string) $services[ $recommended ]['created_at'] );
                                if ( $cnt > $best || ( $cnt === $best && $older ) ) {
                                        $best = $cnt;
                                        $recommended = $id;
                                }
                        }

                        $status = empty( $conflicts ) ? 'clean' : 'conflict';
                        $summary['total_groups']++;
                        $summary['services_involved'] += count( $ids );
                        if ( 'clean' === $status ) {
                                $summary['clean_groups']++;
                                $summary['clean_services'] += count( $ids );
                                $summary['merge_savings'] += count( $ids ) - 1;
                        } else {
                                $summary['conflict_groups']++;
                        }

                        // مشخصات هر سرویس (مقادیر فیلدهای مقایسه + مشترک‌ها + متناقض‌ها برای جدول تطبیق)
                        $show_slugs = array_values( array_unique( array_merge(
                                $fields,
                                array_map( static function ( $s ) { return $s['slug']; }, $shared ),
                                array_map( static function ( $c ) { return $c['slug']; }, $conflicts )
                        ) ) );
                        $addr_slugs = array();
                        foreach ( TPP_Fields::all( 'address' ) as $af ) {
                                $addr_slugs[ $af['slug'] ] = true;
                        }
                        $members = array();
                        foreach ( $ids as $id ) {
                                $row  = $services[ $id ];
                                $addr = $addr_of[ $id ];
                                $m    = array(
                                        'id'          => $id,
                                        'address_id'  => (int) $row['address_id'],
                                        'created_at'  => $row['created_at'],
                                        'updated_at'  => $row['updated_at'],
                                        'fields'      => array(),
                                );
                                $creator = get_userdata( (int) $row['created_by'] );
                                $m['created_by_name'] = $creator ? $creator->display_name : '—';
                                foreach ( $show_slugs as $slug ) {
                                        $m['fields'][ $slug ] = isset( $addr_slugs[ $slug ] ) && $addr ? (string) ( $addr[ $slug ] ?? '' ) : (string) ( $row[ $slug ] ?? '' );
                                }
                                $members[] = $m;
                        }

                        $groups[] = array(
                                'key'       => $key,
                                'ids'       => $ids,
                                'dismissed' => $is_dismissed,
                                'shared'    => $shared,
                                'conflicts' => $conflicts,
                                'complementary' => $complementary,
                                'status'    => $status,
                                'recommended_primary' => $recommended,
                                'services'  => $members,
                        );
                }

                return array(
                        'total'           => count( $groups ),
                        'groups'          => $groups,
                        'summary'         => $summary,
                        'fields'          => $this->duplicate_compare_fields(),
                        'selected'        => $fields,
                        'dismissed_count' => $dismissed_count,
                );
        }

        /**
         * ادغام گروهی همه گروه‌های «بدون تناقض» (۱.۱۱.۰).
         * گروه بدون تناقض = فیلدهای پر اعضا یا یکسان‌اند یا مکمل یکدیگر → ادغام بدون از دست رفتن هیچ داده‌ای.
         * $keys: کلیدهای گروه‌های خاص؛ خالی = همه گروه‌های بدون تناقض.
         */
        public function merge_safe_duplicates( $keys = array(), $args = array() ) {
                $user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
                $find    = $this->find_duplicates( array( 'include_dismissed' => 0 ) );
                $wanted  = is_array( $keys ) ? array_flip( array_map( 'strval', $keys ) ) : array();

                $merged_groups = 0;
                $merged_services = 0;
                $filled_total = 0;
                $errors = array();
                foreach ( (array) $find['groups'] as $g ) {
                        if ( 'clean' !== (string) ( $g['status'] ?? '' ) ) {
                                continue;
                        }
                        if ( ! empty( $wanted ) && ! isset( $wanted[ (string) $g['key'] ] ) ) {
                                continue;
                        }
                        $primary = (int) ( $g['recommended_primary'] ?: $g['ids'][0] );
                        $result  = $this->merge_duplicates( $primary, $g['ids'], array( 'user_id' => $user_id ) );
                        if ( is_wp_error( $result ) ) {
                                $errors[] = array( 'key' => $g['key'], 'message' => $result->get_error_message() );
                                continue;
                        }
                        $merged_groups++;
                        $merged_services += count( $result['merged_ids'] );
                        $filled_total   += count( $result['filled_fields'] );
                }

                return array(
                        'status'          => 'done',
                        'merged_groups'   => $merged_groups,
                        'merged_services' => $merged_services,
                        'filled_fields'   => $filled_total,
                        'errors'          => $errors,
                );
        }

        /**
         * ادغام سرویس‌های تکراری در یک سرویس اصلی (۱.۹.۰).
         * فیلدهای خالیِ سرویس اصلی با اولین مقدار غیرخالی از سرویس‌های دیگر پر می‌شود؛
         * تاریخچه سرویس‌های ادغام‌شده به سرویس اصلی منتقل می‌شود (حذف نمی‌شود)؛
         * خود سرویس‌های دیگر حذف می‌شوند و در تاریخچه اصلی یک رکورد «ادغام» ثبت می‌شود.
         *
         * ۱.۱۱.۰ — $args['pick']: نگاشت slug → شناسه سرویس مبدأ؛ برای فیلدهای متناقض، مقدار آن سرویس
         * حتی بر مقدار موجود سرویس اصلی برنده می‌شود (حل تعارض فیلدی هنگام ادغام).
         */
        public function merge_duplicates( $primary_id, array $ids, $args = array() ) {
                $user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
                $pick    = isset( $args['pick'] ) && is_array( $args['pick'] ) ? $args['pick'] : array();
                $primary_id = (int) $primary_id;
                $primary = $this->get( $primary_id );
                if ( ! $primary ) {
                        return new WP_Error( 'tpp_not_found', 'سرویس اصلی یافت نشد.', array( 'status' => 404 ) );
                }
                $others = array();
                foreach ( $ids as $id ) {
                        $id = (int) $id;
                        if ( $id === $primary_id ) {
                                continue;
                        }
                        $svc = $this->get( $id );
                        if ( ! $svc ) {
                                return new WP_Error( 'tpp_not_found', 'سرویس تکراری #' . $id . ' یافت نشد.', array( 'status' => 404 ) );
                        }
                        $others[ $id ] = $svc;
                }
                if ( empty( $others ) ) {
                        return new WP_Error( 'tpp_nothing_to_merge', 'سرویسی برای ادغام انتخاب نشده است.', array( 'status' => 400 ) );
                }

                $changes = array();
                $sdata   = array();

                // ۰) حل تعارض فیلدی (pick): مقدار انتخاب‌شده هر فیلد حتی بر مقدار موجودِ سرویس اصلی برتری دارد
                $picked_labels = array();
                if ( ! empty( $pick ) ) {
                        foreach ( TPP_Fields::all( 'service' ) as $f ) {
                                $slug = $f['slug'];
                                if ( ! isset( $pick[ $slug ] ) ) {
                                        continue;
                                }
                                $src_id = (int) $pick[ $slug ];
                                $src    = ( $src_id === $primary_id ) ? $primary : ( $others[ $src_id ] ?? null );
                                if ( ! $src ) {
                                        continue; // شناسه انتخابی معتبر نیست
                                }
                                $ov = trim( (string) ( $src[ $slug ] ?? '' ) );
                                if ( self::trivial_dup_value( $ov ) ) {
                                        continue; // مقدار انتخابی خالی است
                                }
                                $val = TPP_Fields::validate_value( $f, $ov );
                                $pv  = trim( (string) ( $primary[ $slug ] ?? '' ) );
                                if ( '' !== $val && self::dup_key( $val ) !== self::dup_key( $pv ) ) {
                                        $changes[ $slug ] = array( 'old' => $primary[ $slug ], 'new' => $val );
                                        $sdata[ $slug ]   = $val;
                                        $picked_labels[]  = $slug;
                                }
                        }
                }

                // ۱) پر کردن فیلدهای خالی سرویس اصلی از اولین مقدار غیرخالی بقیه
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        $slug = $f['slug'];
                        if ( isset( $sdata[ $slug ] ) ) {
                                continue; // با pick مقدار همین فیلد تعیین شده است
                        }
                        $pv   = trim( (string) ( $primary[ $slug ] ?? '' ) );
                        if ( ! self::trivial_dup_value( $pv ) ) {
                                continue; // مقدار موجود است — دست نمی‌زنیم
                        }
                        foreach ( $others as $oid => $svc ) {
                                $ov = trim( (string) ( $svc[ $slug ] ?? '' ) );
                                if ( ! self::trivial_dup_value( $ov ) ) {
                                        $val = TPP_Fields::validate_value( $f, $ov );
                                        if ( '' !== $val ) {
                                                $changes[ $slug ] = array( 'old' => $primary[ $slug ], 'new' => $val );
                                                $sdata[ $slug ]   = $val;
                                        }
                                        break;
                                }
                        }
                }

                // ۲) پر کردن فیلدهای خالی آدرس اصلی از آدرس سرویس‌های دیگر (اختیاری و ایمن)
                $primary_addr = $this->get_address( (int) $primary['address_id'] );
                $addr_fill    = array();
                if ( $primary_addr ) {
                        foreach ( TPP_Fields::all( 'address' ) as $f ) {
                                $slug = $f['slug'];
                                $pv   = trim( (string) ( $primary_addr[ $slug ] ?? '' ) );
                                if ( '' !== $pv ) {
                                        continue;
                                }
                                foreach ( $others as $oid => $svc ) {
                                        $oaddr = $this->get_address( (int) $svc['address_id'] );
                                        $ov    = $oaddr ? trim( (string) ( $oaddr[ $slug ] ?? '' ) ) : '';
                                        if ( '' !== $ov ) {
                                                $addr_fill[ $slug ] = $ov;
                                                break;
                                        }
                                }
                        }
                }

                // ۳) اعمال روی سرویس اصلی
                $new_version = (int) $primary['version'] + 1;
                if ( ! empty( $sdata ) ) {
                        $sdata['updated_at'] = TPP_Date::now();
                        $sdata['version']    = $new_version;
                        TPP_DB::update( 'services', $sdata, array( 'id' => $primary_id ) );
                }
                if ( ! empty( $addr_fill ) && $primary_addr ) {
                        $this->update_address_fields( (int) $primary['address_id'], $addr_fill, $user_id, 'merge' );
                }

                $merged_ids = array_keys( $others );
                $changes['_merged_from'] = array( 'old' => null, 'new' => implode( ', ', array_map( static function ( $i ) {
                        return '#' . $i;
                }, $merged_ids ) ) );

                // ۴) انتقال تاریخچه سرویس‌های دیگر به سرویس اصلی + حذف خودشان
                $ht = TPP_DB::table( 'history' );
                foreach ( $merged_ids as $oid ) {
                        TPP_DB::query(
                                "UPDATE {$ht} SET entity_id = %d, address_id = %d WHERE entity = 'service' AND entity_id = %d",
                                array( $primary_id, (int) $primary['address_id'], (int) $oid )
                        );
                        TPP_DB::delete( 'services', array( 'id' => (int) $oid ) );
                }

                // ۵) رکورد تاریخچه ادغام روی سرویس اصلی (همیشه — حتی بدون پر شدن فیلدی)
                TPP_History::record( array(
                        'entity' => 'service', 'entity_id' => $primary_id, 'address_id' => (int) $primary['address_id'],
                        'revision' => $new_version, 'user_id' => $user_id,
                        'action' => 'update', 'source' => 'merge', 'changes' => $changes,
                ) );

                do_action( 'tpp_duplicates_merged', $primary_id, $merged_ids, $changes );

                return array(
                        'status'       => 'merged',
                        'primary_id'   => $primary_id,
                        'merged_ids'   => $merged_ids,
                        'filled_fields'=> array_keys( $sdata ),
                        'picked_fields'=> $picked_labels,
                        'version'      => $new_version,
                );
        }
}
