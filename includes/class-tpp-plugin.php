<?php
/**
 * ظرف اصلی افزونه (Container) — نقطه دسترسی همه ماژول‌ها برای توسعه‌دهندگان.
 *
 * استفاده در افزونه‌های دیگر:
 *   tpp()->services()->search( array( 'query' => 'کدپستی' ) );
 *   tpp()->fields()->all( 'service' );
 *   tpp()->user_can( 'tpp_edit_services', $user_id );
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class TPP_Plugin {

        private static $instance = null;

        private $modules = array();

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {}

        public function boot() {
                $this->modules['settings']  = new TPP_Settings();
                $this->modules['auth']      = new TPP_Auth();
                $this->modules['rest']      = new TPP_Rest();
                $this->modules['admin']     = new TPP_Admin();
                $this->modules['shortcode'] = new TPP_Shortcode();

                // سرویس‌های داده‌ای
                $this->modules['services'] = new TPP_Services();
                $this->modules['sync']     = new TPP_Sync();
                $this->modules['import']   = new TPP_Import();
                $this->modules['export']   = new TPP_Export();
                $this->modules['sms']      = new TPP_SMS();

                // ارتقای ساختار در صورت نیاز
                add_action( 'init', array( 'TPP_Install', 'maybe_upgrade' ), 5 );
                // ۱.۱۰.۰ — گزارش فعالیت: پاک‌سازی دوره‌ای (کرون روزانه + بازبینی ساعتی سبک در بوت)
                add_action( 'tpp_daily_cleanup', array( 'TPP_Activity', 'cron_cleanup' ) );
                add_action( 'init', array( 'TPP_Activity', 'maybe_cleanup' ), 99 );

                do_action( 'tpp_booted', $this );
        }

        public function settings()  { return $this->modules['settings']; }
        public function auth()      { return $this->modules['auth']; }
        public function rest()      { return $this->modules['rest']; }
        public function admin()     { return $this->modules['admin']; }
        public function shortcode() { return $this->modules['shortcode']; }
        public function services()  { return $this->modules['services']; }
        public function sync()      { return $this->modules['sync']; }
        public function import()    { return $this->modules['import']; }
        public function export()    { return $this->modules['export']; }
        public function sms()       { return $this->modules['sms']; }

        public function fields()   { return 'TPP_Fields'; }   // کلاس ایستا
        public function history()  { return 'TPP_History'; }  // کلاس ایستا
        public function activity() { return 'TPP_Activity'; } // کلاس ایستا
        public function caps()     { return 'TPP_Capabilities'; } // کلاس ایستا
        public function progress() { return 'TPP_Progress'; } // کلاس ایستا — ۱.۱۲.۰
        public function backup()   { return 'TPP_Backup'; }  // کلاس ایستا — ۱.۱۵.۰
        public function categories() { return 'TPP_Categories'; } // کلاس ایستا — ۱.۱۹.۰
        public function review()    { return 'TPP_Review'; }    // کلاس ایستا — 1.20.0

        /** میان‌بر بررسی دسترسی */
        public function user_can( $cap, $user_id = null ) {
                $user_id = $user_id ? (int) $user_id : get_current_user_id();
                return TPP_Capabilities::user_can( $user_id, $cap );
        }

        /** فیلدهای قابل مشاهده یک کاربر */
        public function visible_fields( $user_id = null ) {
                $user_id = $user_id ? (int) $user_id : get_current_user_id();
                return TPP_Capabilities::visible_fields( $user_id );
        }
}
