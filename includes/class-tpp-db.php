<?php
/**
 * لایه دسترسی به داده — جدول‌های اختصاصی افزونه (مجزا از جداول اصلی وردپرس)
 *
 * به‌صورت پیش‌فرض جدول‌ها در همان دیتابیس وردپرس با پیشوند اختصاصی tpp_ ساخته می‌شوند.
 * برای دیتابیس کاملاً جداگانه، این ثابت‌ها را در wp-config.php تعریف کنید:
 *   define( 'TPP_DB_NAME', 'my_tpp_db' );
 *   define( 'TPP_DB_USER', 'db_user' );
 *   define( 'TPP_DB_PASSWORD', 'db_pass' );
 *   define( 'TPP_DB_HOST', 'localhost' );
 *   define( 'TPP_DB_PREFIX', 'tpp_' ); // اختیاری
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_DB {

        private static $db = null;

        const TABLES = array( 'fields', 'addresses', 'services', 'history', 'op_log', 'sync_log', 'field_archives', 'sms_templates', 'sms_log', 'view_log', 'search_log', 'work_reports', 'categories', 'installer_changes' ); // ۱.۱۴.۰ — work_reports / ۱.۱۹.۰ — categories / ۱.۲۰.۰ — installer_changes

        /**
         * شیء wpdb — در صورت تعریف ثابت‌ها، اتصال دیتابیس جداگانه
         */
        public static function db() {
                if ( null !== self::$db ) {
                        return self::$db;
                }

                if ( defined( 'TPP_DB_NAME' ) && defined( 'TPP_DB_USER' ) ) {
                        require_once ABSPATH . 'wp-includes/class-wpdb.php';
                        $prefix        = defined( 'TPP_DB_PREFIX' ) ? TPP_DB_PREFIX : 'tpp_';
                        self::$db      = new wpdb( TPP_DB_USER, defined( 'TPP_DB_PASSWORD' ) ? TPP_DB_PASSWORD : '', TPP_DB_NAME, defined( 'TPP_DB_HOST' ) ? TPP_DB_HOST : 'localhost' );
                        self::$db->prefix = $prefix;
                        self::$db->set_prefix( $prefix );
                } else {
                        global $wpdb;
                        self::$db = $wpdb;
                }

                return self::$db;
        }

        /** نام کامل جدول */
        public static function table( $name ) {
                if ( ! in_array( $name, self::TABLES, true ) ) {
                        return null;
                }
                if ( self::is_external() ) {
                        $db = self::db();
                        return $db->prefix . $name; // پیشوند مستقیم (بدون پیشوند وردپرس)
                }
                global $wpdb;
                return $wpdb->prefix . 'tpp_' . $name;
        }

        /** آیا دیتابیس جداگانه است؟ */
        public static function is_external() {
                return defined( 'TPP_DB_NAME' ) && defined( 'TPP_DB_USER' );
        }

        /** اجرای کوئری امن */
        public static function query( $sql, $args = array() ) {
                $db = self::db();
                if ( empty( $args ) ) {
                        return $db->query( $sql );
                }
                return $db->query( $db->prepare( $sql, $args ) );
        }

        public static function get_row( $sql, $args = array() ) {
                $db = self::db();
                return empty( $args ) ? $db->get_row( $sql, ARRAY_A ) : $db->get_row( $db->prepare( $sql, $args ), ARRAY_A );
        }

        public static function get_results( $sql, $args = array() ) {
                $db = self::db();
                return empty( $args ) ? $db->get_results( $sql, ARRAY_A ) : $db->get_results( $db->prepare( $sql, $args ), ARRAY_A );
        }

        public static function get_var( $sql, $args = array() ) {
                $db = self::db();
                return empty( $args ) ? $db->get_var( $sql ) : $db->get_var( $db->prepare( $sql, $args ) );
        }

        public static function insert( $table, $data ) {
                $db   = self::db();
                $full = self::table( $table );
                $ok   = $db->insert( $full, $data );
                return false === $ok ? false : (int) $db->insert_id;
        }

        public static function update( $table, $data, $where ) {
                $db = self::db();
                return $db->update( self::table( $table ), $data, $where );
        }

        public static function delete( $table, $where ) {
                $db = self::db();
                return $db->delete( self::table( $table ), $where );
        }

        /** فرار ایمن مقدار برای LIKE */
        public static function esc_like( $text ) {
                $db = self::db();
                return $db->esc_like( $text );
        }
}
