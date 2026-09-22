<?php
/**
 * پشتیبان‌های ذخیره‌شده روی سرور — ۱.۱۵.۰
 *
 * هدف: قبل از هر ایمپورت گروهی، به‌صورت خودکار یک پشتیبان کامل از داده‌ها گرفته
 * و «در پوشه افزونه» (پوشه backups/ داخل پوشه افزونه) ذخیره می‌شود تا اگر ایمپورت
 * مشکلی ایجاد کرد، مدیر با یک کلیک همان لحظه را بازگردانی کند.
 *
 * ریز طراحی:
 *  - محل ذخیره: TPP_PLUGIN_DIR/backups/ (درخواست کاربر: پوشه افزونه).
 *    اگر پوشه افزونه قابل نوشتن نباشد (برخی میزبان‌ها پوشه افزونه را فقط-خواندنی
 *    می‌کنند)، به‌صورت خودکار به uploads/tpp-backups منتقل می‌شود تا زنجیره
 *    پشتیبان‌گیری هرگز قطع نشود.
 *  - قالب فایل: همان JSON پشتیبان استاندارد افزونه (قابل بازیابی روی هر سرور)
 *    + کلیدهای اطلاعاتی _tpp_stored (زمینه/کاربر/شمارش) که بازیابی نادیده می‌گیرد.
 *  - فایل‌های جانبی *.meta.json: متادیتا برای فهرست سریع بدون بازکردن فایل‌های بزرگ.
 *  - امنیت: نام فایل غیرقابل حدس (توکن تصادفی) + .htaccess (آپاچی) + index.html
 *    برای مسدودسازی دسترسی مستقیم وب به پوشه.
 *  - هرس خودکار: فقط N نسخه آخر نگه داشته می‌شود (تنظیم auto_backup_keep).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Backup {

        const DIR_NAME     = 'backups';
        const FILE_PREFIX  = 'tpp-backup-';
        const META_SUFFIX  = '.meta.json';

        /** @var string|null مسیر جایگزین برای تست‌ها */
        public static $test_dir = null;

        /* -------------------- محل ذخیره -------------------- */

        /** مسیر پوشه پشتیبان‌ها (ایجاد در صورت نبود + جایگزین خودکار) */
        public static function dir() {
                if ( self::$test_dir ) {
                        if ( ! is_dir( self::$test_dir ) ) {
                                @mkdir( self::$test_dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        }
                        return rtrim( self::$test_dir, '/\\' );
                }

                $primary = rtrim( TPP_PLUGIN_DIR, '/\\' ) . '/' . self::DIR_NAME;
                if ( ! is_dir( $primary ) ) {
                        if ( @mkdir( $primary, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                                self::protect_dir( $primary );
                        }
                }
                if ( self::is_writable_dir( $primary ) ) {
                        return $primary;
                }

                // پوشه افزونه قابل نوشتن نیست → پوشه uploads (به‌جای شکست پشتیبان‌گیری)
                if ( function_exists( 'wp_upload_dir' ) ) {
                        $uploads = wp_upload_dir();
                        if ( ! empty( $uploads['basedir'] ) ) {
                                $alt = rtrim( $uploads['basedir'], '/\\' ) . '/tpp-backups';
                                if ( ! is_dir( $alt ) ) {
                                        if ( @mkdir( $alt, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                                                self::protect_dir( $alt );
                                        }
                                }
                                if ( self::is_writable_dir( $alt ) ) {
                                        return $alt;
                                }
                        }
                }
                return $primary; // مقصد قابل نوشتن نیست — create() خطای شفاف می‌دهد
        }

        /** آیا پوشه هست و قابل نوشتن است؟ */
        private static function is_writable_dir( $dir ) {
                return is_dir( $dir ) && is_writable( $dir );
        }

        /** مسدودسازی دسترسی مستقیم وب به پوشه پشتیبان‌ها */
        private static function protect_dir( $dir ) {
                if ( ! is_dir( $dir ) ) {
                        return;
                }
                $ht = $dir . '/.htaccess';
                if ( ! file_exists( $ht ) ) {
                        @file_put_contents( $ht, "# TPP Services — پشتیبان‌ها فقط از داخل افزونه خوانده می‌شوند\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
                $idx = $dir . '/index.html';
                if ( ! file_exists( $idx ) ) {
                        @file_put_contents( $idx, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
        }

        /** برچسب محل ذخیره برای نمایش به کاربر */
        public static function dir_label( $dir = null ) {
                $dir = null === $dir ? self::dir() : $dir;
                $plugin = rtrim( TPP_PLUGIN_DIR, '/\\' ) . '/' . self::DIR_NAME;
                if ( $dir === $plugin ) {
                        return 'پوشه افزونه (' . self::DIR_NAME . '/)';
                }
                return 'پوشه آپلود‌ها (uploads/tpp-backups)';
        }

        /* -------------------- ساخت پشتیبان -------------------- */

        /**
         * گرفتن یک پشتیبان کامل و ذخیره روی سرور.
         *
         * @param string $context زمینه ('import' خودکار | 'manual' دستی)
         * @param int    $user_id کاربر آغازگر
         * @return array|WP_Error متادیتای پشتیبان {filename, created_at, context, user, size, counts, dir_label}
         */
        public static function create( $context = 'manual', $user_id = 0 ) {
                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- پشتیبان دیتابیس بزرگ ممکن است طول بکشد
                }
                $dir = self::dir();
                if ( ! self::is_writable_dir( $dir ) ) {
                        return new WP_Error( 'tpp_backup_dir', sprintf(
                                'پوشه ذخیره پشتیبان روی سرور قابل نوشتن نیست (%s) — دسترسی نوشتن پوشه افزونه (یا wp-content/uploads) را بررسی کنید.',
                                $dir
                        ) );
                }

                $user = $user_id ? get_userdata( (int) $user_id ) : false;
                $counts = array(
                        'fields'    => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'fields' ) ),
                        'addresses' => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'addresses' ) ),
                        'services'  => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'services' ) ),
                        'history'   => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'history' ) ),
                );

                $extra = array(
                        '_tpp_stored' => array(
                                'context'   => (string) $context,
                                'user_id'   => (int) $user_id,
                                'user_name' => $user ? $user->display_name : '',
                                'counts'    => $counts,
                        ),
                );
                // پشتیبان کامل: با تاریخچه (زنجیره بازگردانی کامل) — گزارش‌های فعالیت/پیامک جزو پشتیبان سروری نیستند
                $json = tpp()->export()->backup( array( 'with_history' => 1 ), $extra );
                if ( ! is_string( $json ) || '' === $json ) {
                        return new WP_Error( 'tpp_backup_build', 'ساخت محتوای پشتیبان ناموفق بود.' );
                }

                $filename = self::FILE_PREFIX . gmdate( 'Ymd-His' ) . '-' . self::token() . '.json';
                $path     = $dir . '/' . $filename;
                if ( false === @file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                        return new WP_Error( 'tpp_backup_write', 'نوشتن فایل پشتیبان روی سرور ناموفق بود — دسترسی نوشتن پوشه را بررسی کنید.' );
                }

                $meta = array(
                        'filename'  => $filename,
                        'context'   => (string) $context,
                        'user_id'   => (int) $user_id,
                        'user'      => $user ? $user->display_name : '',
                        'created_at'=> TPP_Date::now(),
                        'created_at_jalali' => TPP_Date::jalali( TPP_Date::now(), true ), // ۱.۱۸.۰ — نمایش شمسی/تهران
                        'created_ts'=> round( microtime( true ), 6 ), // ۱.۱۸.۰ — دقت میکروثانیه (۳ رقم قبلی فقط میلی‌ثانیه بود و دو پشتیبانِ پشت‌سر‌هم می‌توانستند هم‌کلید شوند → ترتیب فهرست flake می‌شد)
                        'size'      => (int) strlen( $json ),
                        'counts'    => $counts,
                        'version'   => defined( 'TPP_VERSION' ) ? TPP_VERSION : '',
                );
                @file_put_contents( $path . self::META_SUFFIX, wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

                self::prune(); // هرس نسخه‌های قدیمی مطابق تنظیمات
                return $meta;
        }

        /** توکن کوتاه غیرقابل حدس برای نام فایل */
        private static function token() {
                if ( function_exists( 'wp_generate_password' ) ) {
                        $t = strtolower( wp_generate_password( 8, false ) );
                        return preg_replace( '/[^a-z0-9]/', '', $t );
                }
                return bin2hex( random_bytes( 4 ) );
        }

        /* -------------------- فهرست/خواندن/حذف -------------------- */

        /** همه پشتیبان‌های ذخیره‌شده (جدید → قدیم) */
        public static function items() {
                $dir = self::dir();
                $out = array();
                foreach ( (array) glob( $dir . '/' . self::FILE_PREFIX . '*.json' ) as $file ) {
                        $name = basename( $file );
                        if ( self::META_SUFFIX === substr( $name, -strlen( self::META_SUFFIX ) ) ) {
                                continue; // فایل متادیتا
                        }
                        $out[] = self::read_meta( $file );
                }
                usort( $out, function ( $a, $b ) {
                        // ۱.۱۸.۰ — مرتب‌سازی با دقت میکروثانیه؛ tiebreak نهایی روی نام فایل (قدیمی‌ها ۳ رقم داشتند)
                        $ta = isset( $a['created_ts'] ) ? (float) $a['created_ts'] : ( isset( $a['created_at'] ) ? strtotime( (string) $a['created_at'] ) : 0 );
                        $tb = isset( $b['created_ts'] ) ? (float) $b['created_ts'] : ( isset( $b['created_at'] ) ? strtotime( (string) $b['created_at'] ) : 0 );
                        if ( $ta === $tb ) {
                                return strcmp( (string) $b['filename'], (string) $a['filename'] );
                        }
                        return $tb <=> $ta;
                } );
                return $out;
        }

        /** متادیتای یک فایل پشتیبان (با ساخت از خود فایل اگر متادیتا نبود) */
        private static function read_meta( $file ) {
                $side = $file . self::META_SUFFIX;
                if ( is_readable( $side ) ) {
                        $meta = json_decode( (string) file_get_contents( $side ), true );
                        if ( is_array( $meta ) && ! empty( $meta['filename'] ) ) {
                                $meta['size'] = isset( $meta['size'] ) ? (int) $meta['size'] : (int) @filesize( $file );
                                return $meta;
                        }
                }
                // متادیتا نبود (مثلاً فایل قدیمی/دستی) — از خود فایل
                $name = basename( $file );
                $meta = array(
                        'filename'   => $name,
                        'context'    => 'manual',
                        'user_id'    => 0,
                        'user'       => '',
                        'created_at' => gmdate( 'Y-m-d H:i:s', (int) @filemtime( $file ) ),
                        'created_ts' => (float) @filemtime( $file ),
                        'size'       => (int) @filesize( $file ),
                        'counts'     => null,
                        'version'    => '',
                );
                @file_put_contents( $side, wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                return $meta;
        }

        /** مسیر امن یک نام فایل — null در صورت نامعتبر بودن */
        public static function item_path( $filename ) {
                $filename = (string) $filename;
                if ( ! preg_match( '/^' . preg_quote( self::FILE_PREFIX, '/' ) . '[A-Za-z0-9\-]+\.json$/', $filename ) ) {
                        return null; // نام فایل نامعتبر (جابجایی مسیر ممنوع)
                }
                $path = self::dir() . '/' . $filename;
                return is_readable( $path ) ? $path : null;
        }

        /** حذف یک پشتیبان ذخیره‌شده + متادیتای آن */
        public static function delete_item( $filename ) {
                $path = self::item_path( $filename );
                if ( ! $path ) {
                        return new WP_Error( 'tpp_backup_not_found', 'پشتیبانی با این نام یافت نشد.' );
                }
                @unlink( $path . self::META_SUFFIX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                return array( 'status' => 'deleted', 'filename' => (string) $filename );
        }

        /**
         * بازگردانی یک‌کلیکی از پشتیبان ذخیره‌شده روی سرور.
         *
         * @return array|WP_Error خلاصه نتیجه بازیابی (همان فرمت restore افزونه)
         */
        public static function restore_item( $filename ) {
                $path = self::item_path( $filename );
                if ( ! $path ) {
                        return new WP_Error( 'tpp_backup_not_found', 'پشتیبانی با این نام یافت نشد.' );
                }
                $json = (string) file_get_contents( $path );
                if ( '' === $json ) {
                        return new WP_Error( 'tpp_backup_read', 'خواندن فایل پشتیبان ناموفق بود.' );
                }
                return tpp()->export()->restore( $json );
        }

        /** دانلود یک پشتیبان ذخیره‌شده (خروجی مستقیم به مرورگر) */
        public static function download_item( $filename ) {
                $path = self::item_path( $filename );
                if ( ! $path ) {
                        return new WP_Error( 'tpp_backup_not_found', 'پشتیبانی با این نام یافت نشد.' );
                }
                $content = (string) file_get_contents( $path );
                TPP_Zip::download( (string) $filename, $content, 'application/json; charset=utf-8' );
        }

        /* -------------------- هرس نسخه‌های قدیمی -------------------- */

        /** نگهداری فقط N نسخه آخر (۰ = نامحدود) — خروجی: تعداد حذف‌شده */
        public static function prune( $keep = null ) {
                if ( null === $keep ) {
                        $keep = (int) tpp()->settings()->get( 'auto_backup_keep', 10 );
                }
                $keep = max( 0, (int) $keep );
                if ( 0 === $keep ) {
                        return 0;
                }
                $items = self::items(); // جدید → قدیم
                $old   = array_slice( $items, $keep );
                $removed = 0;
                foreach ( $old as $meta ) {
                        $path = self::item_path( $meta['filename'] );
                        if ( $path ) {
                                @unlink( $path . self::META_SUFFIX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                                @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                                $removed++;
                        }
                }
                return $removed;
        }

        /* -------------------- اطلاعات برای UI -------------------- */

        /** وضعیت محل ذخیره + تنظیمات مرتبط برای نمایش قبل از عملیات */
        public static function info() {
                $dir = self::dir();
                return array(
                        'dir'        => $dir,
                        'dir_label'  => self::dir_label( $dir ),
                        'writable'   => self::is_writable_dir( $dir ),
                        'auto'       => (int) tpp()->settings()->get( 'import_auto_backup', 1 ) ? 1 : 0,
                        'keep'       => (int) tpp()->settings()->get( 'auto_backup_keep', 10 ),
                        'count'      => count( self::items() ),
                );
        }
}
