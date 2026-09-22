<?php
/**
 * تنظیمات افزونه — ذخیره در option با نام tpp_settings
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Settings {

        const OPTION = 'tpp_settings';

        private $cache = null;

        /** مقادیر پیش‌فرض تنظیمات */
        public static function defaults() {
                return array(
                        'session_mode'         => 'unlimited', // unlimited | days
                        'session_days'         => 3650,        // مدت اعتبار نشست هنگام حالت نامحدود (کوکی ۱۰ ساله)
                        'rows_per_page'        => 25,          // ردیف در هر صفحه در اپ
                        'import_max_rows'      => 20000,       // سقف ردیف‌های ایمپورت
                        'import_max_size_mb'   => 10,          // سقف حجم فایل ایمپورت
                        'default_match_key'    => 'f_phone',   // کلید تشخیص سرویس تکراری (فیلد سرویس)
                        'offline_cache_size'   => 5000,        // سقف رکوردهای کش آفلاین
                        'delete_on_uninstall'  => 0,           // حذف کامل داده‌ها هنگام حذف افزونه
                        'heartbeat_minutes'    => 5,           // بازه پینگ حفظ نشست
                        /* ۱.۱۰.۰ — تاریخچه */
                        'history_daily'        => 1,           // تجمیع روزانه تاریخچه: همه تغییرات یک سرویس/آدرس در هر روز در یک رکورد
                        'history_days'         => 0,           // مدت نگهداری تاریخچه تغییرات (روز — ۰ = نامحدود)
                        'view_history_days'    => 180,         // مدت نگهداری تاریخچه بازدید سرویس‌ها (روز — ۰ = نامحدود)
                        'search_history_days'  => 180,         // مدت نگهداری تاریخچه جستجو (روز — ۰ = نامحدود)
                        /* ۱.۱۱.۰ — پنجره ادغام جستجوهای در حال تایپ (ثانیه؛ ۰ = هر جستجو جداگانه) */
                        'search_dedupe_seconds'=> 15,
                        'sms_api_key'          => '',          // کلید API پنل پیامک sms.ir
                        'sms_line_number'      => '',          // شماره خط ارسال (اختیاری — برای ارسال گروهی)
                        'sms_copy_template'    => '',          // قالب متن «کپی مشخصات برای OMC»
                        /* ۱.۱۵.۰ — پشتیبان خودکار قبل از ایمپورت گروهی (ذخیره روی سرور) */
                        'import_auto_backup'   => 1,           // پشتیبان کامل خودکار قبل از هر ثبت گروهی
                        'auto_backup_keep'     => 10,          // تعداد نسخه‌های نگهداری‌شده روی سرور (۰ = نامحدود)
                        /* ۱.۲۱.۰ — تایید خودکار اقدامات نصاب‌ها پس از n روز (۰ = غیرفعال)؛
                         * سرویس‌های با دسته «ثبت جهت بازبینی…» از آن مستثنا هستند */
                        'review_auto_days'     => 7,
                );
        }

        /**
         * قالب پیش‌فرض کپی مشخصات (OMC) — الگوی استاندارد ارسال به OMC (درخواست کاربر، ۱.۹.۰).
         * جای‌نگهدار ناموجود (فیلد حذف‌شده) هنگام رندر به متن خالی تبدیل می‌شود.
         */
        public static function default_copy_template() {
                return "بلوک {{f_block}} واحد{{f_unit}}\n\n"
                        . "دایری سیپ\n\n"
                        . "{{f_modem_serial}}\n\n"
                        . "{{f_virtual_number}}\n\n"
                        . "{{f_phone}}\n\n"
                        . "{{f_sip_pass}}\n\n"
                        . "{{f_sip_ip}}\n\n"
                        . "sbc: {{f_sbc}}\n\n"
                        . "{{f_full_address}}\n\n"
                        . "{{f_center_name}}";
        }

        public function all() {
                if ( null === $this->cache ) {
                        $saved          = get_option( self::OPTION, array() );
                        $this->cache    = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
                }
                return $this->cache;
        }

        public function get( $key, $default = null ) {
                $all = $this->all();
                if ( isset( $all[ $key ] ) && '' !== $all[ $key ] ) {
                        return $all[ $key ];
                }
                return null === $default ? ( isset( self::defaults()[ $key ] ) ? self::defaults()[ $key ] : null ) : $default;
        }

        public function update( array $new ) {
                $clean = $this->sanitize( $new );
                $all   = wp_parse_args( $clean, $this->all() );
                update_option( self::OPTION, $all, false );
                $this->cache = $all;
                return $all;
        }

        /** فقط کلیدهای مجاز قابل ذخیره‌اند */
        private function sanitize( array $in ) {
                $out = array();
                if ( isset( $in['session_mode'] ) ) {
                        $out['session_mode'] = in_array( $in['session_mode'], array( 'unlimited', 'days' ), true ) ? $in['session_mode'] : 'unlimited';
                }
                foreach ( array( 'session_days', 'rows_per_page', 'import_max_rows', 'import_max_size_mb', 'offline_cache_size', 'heartbeat_minutes' ) as $k ) {
                        if ( isset( $in[ $k ] ) ) {
                                $out[ $k ] = max( 1, (int) $in[ $k ] );
                        }
                }
                if ( array_key_exists( 'session_days', $out ) ) {
                        $out['session_days'] = min( max( 1, (int) $out['session_days'] ), 3650 );
                }
                if ( isset( $in['default_match_key'] ) ) {
                        $out['default_match_key'] = preg_match( '/^[a-z0-9_]{1,64}$/', (string) $in['default_match_key'] ) ? $in['default_match_key'] : 'f_phone';
                }
                if ( isset( $in['delete_on_uninstall'] ) ) {
                        $out['delete_on_uninstall'] = $in['delete_on_uninstall'] ? 1 : 0;
                }
                /* ۱.۱۰.۰ — تاریخچه: تجمیع روزانه و مدت نگهداری */
                if ( isset( $in['history_daily'] ) ) {
                        $out['history_daily'] = $in['history_daily'] ? 1 : 0;
                }
                foreach ( array( 'history_days', 'view_history_days', 'search_history_days' ) as $k ) {
                        if ( isset( $in[ $k ] ) ) {
                                $out[ $k ] = max( 0, (int) $in[ $k ] ); // ۰ = نامحدود
                        }
                }
                /* ۱.۱۱.۰ — پنجره ادغام جستجوهای در حال تایپ */
                if ( isset( $in['search_dedupe_seconds'] ) ) {
                        $out['search_dedupe_seconds'] = min( 120, max( 0, (int) $in['search_dedupe_seconds'] ) );
                }
                /* ۱.۱۵.۰ — پشتیبان خودکار قبل از ایمپورت */
                if ( isset( $in['import_auto_backup'] ) ) {
                        $out['import_auto_backup'] = $in['import_auto_backup'] ? 1 : 0;
                }
                if ( isset( $in['auto_backup_keep'] ) ) {
                        $out['auto_backup_keep'] = min( 100, max( 0, (int) $in['auto_backup_keep'] ) );
                }
                /* ۱.۲۱.۰ — تایید خودکار اقدامات نصاب‌ها (۰ = غیرفعال) */
                if ( isset( $in['review_auto_days'] ) ) {
                        $out['review_auto_days'] = min( 3650, max( 0, (int) $in['review_auto_days'] ) );
                }
                if ( array_key_exists( 'sms_api_key', $in ) ) {
                        $out['sms_api_key'] = trim( sanitize_text_field( (string) $in['sms_api_key'] ) );
                }
                if ( array_key_exists( 'sms_line_number', $in ) ) {
                        $out['sms_line_number'] = preg_replace( '/[^0-9]/', '', (string) $in['sms_line_number'] );
                }
                if ( array_key_exists( 'sms_copy_template', $in ) ) {
                    $tpl = (string) $in['sms_copy_template'];
                    $tpl = preg_replace( '/<\/?script.*?>/i', '', $tpl );
                    $out['sms_copy_template'] = mb_substr( trim( $tpl ), 0, 5000 );
                }
                return $out;
        }
}
