<?php
/**
 * نویسنده ZIP خالص PHP — بدون وابستگی به افزونه php-zip (ZipArchive).
 *
 * چرا؟ روی برخی میزبان‌ها افزونه zip فعال نیست و ZipArchive باعث خطای مهلک (fatal error) می‌شود؛
 * خروجی آن خطا به‌شکل فایل خراب به کاربر می‌رسید. این کلاس فرمت ZIP را دستی می‌سازد:
 *   - Local File Header + Central Directory + End of Central Directory
 *   - فشرده‌سازی Deflate با gzdeflate (یا ذخیره بدون فشرده‌سازی)
 *   - پشتیبانی CRC32 و نام‌های UTF-8 (فلگ بیت ۱۱)
 * سازگار با Excel، Windows Explorer، unzip، PHP ZipArchive و همه ابزارهای استاندارد.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Zip {

        /** @var array نقشه نام => [داده، متد فشرده‌سازی، زمان DOS] */
        private $files = array();

        /** @var string|null دایرکتوری مرکزی (در حین build) */
        private $cd = '';

        public function __construct() {
                $this->files = array();
        }

        /**
         * افزودن فایل از رشته
         *
         * @param string $name    نام/مسیر داخل آرشیو (با / جدا می‌شود)
         * @param string $data    محتوا
         * @param bool   $deflate فشرده‌سازی (پیش‌فرض فعال؛ برای داده‌های کمتر از ۲۰ بایت بی‌اثر)
         * @param int    $time    Unix timestamp (پیش‌فرض حال)
         */
        public function add_file( $name, $data, $deflate = true, $time = null ) {
                $this->files[] = array(
                        'name'   => str_replace( '\\', '/', (string) $name ),
                        'data'   => (string) $data,
                        'deflate' => $deflate,
                        'time'   => $time ? (int) $time : time(),
                );
        }

        /** افزودن فایل از مسیر دیسک */
        public function add_path( $name, $path, $deflate = true ) {
                if ( ! is_readable( $path ) ) {
                        return false;
                }
                $data = file_get_contents( $path );
                if ( false === $data ) {
                        return false;
                }
                $this->add_file( $name, $data, $deflate, @filemtime( $path ) );
                return true;
        }

        /** افزودن فایل با حفظ ساختار پوشه‌ای یک مسیر */
        public function add_dir( $dir, $prefix = '', $deflate = true ) {
                $dir = rtrim( $dir, '/\\' );
                if ( ! is_dir( $dir ) ) {
                        return 0;
                }
                $count = 0;
                $stack = array( $dir );
                while ( $stack ) {
                        $cur = array_pop( $stack );
                        foreach ( scandir( $cur ) as $entry ) {
                                if ( '.' === $entry || '..' === $entry ) {
                                        continue;
                                }
                                $full = $cur . '/' . $entry;
                                $rel  = $prefix . ltrim( substr( $full, strlen( $dir ) ), '/\\' );
                                if ( is_dir( $full ) ) {
                                        $stack[] = $full;
                                        continue;
                                }
                                if ( $this->add_path( $rel, $full, $deflate ) ) {
                                        $count++;
                                }
                        }
                }
                return $count;
        }

        /** ساخت بایگاری و بازگرداندن محتوای باینری ZIP */
        public function build() {
                $out = '';
                $cd  = '';
                $n   = 0;

                foreach ( $this->files as $f ) {
                        $name = $f['name'];
                        $data = $f['data'];
                        $method = 0; // store
                        $cdata  = $data;
                        if ( $f['deflate'] && function_exists( 'gzdeflate' ) && strlen( $data ) > 32 ) {
                                $def = gzdeflate( $data, 6 );
                                if ( false !== $def && strlen( $def ) < strlen( $data ) ) {
                                        $cdata  = $def;
                                        $method = 8; // deflate
                                }
                        }
                        $crc    = crc32( $data );
                        $usize  = strlen( $data );
                        $csize  = strlen( $cdata );
                        list( $dtime, $ddate ) = self::dos_time( $f['time'] );

                        // فلگ 0x0800 = نام فایل UTF-8
                        $local  = 'PK' . pack( 'vvvvvvVVVvv', 0x0403, 20, 0x0800, $method, $dtime, $ddate, $crc, $csize, $usize, strlen( $name ), 0 );
                        $local .= $name . $cdata;
                        $offset = strlen( $out );
                        $out   .= $local;

                        $cd .= 'PK' . pack( 'vvvvvvvVVVvvvvvVV', 0x0201, 20, 20, 0x0800, $method, $dtime, $ddate, $crc, $csize, $usize, strlen( $name ), 0, 0, 0, 0, 0, $offset );
                        $cd .= $name;
                        $n++;
                }

                // End of Central Directory
                $eocd = 'PK' . pack( 'vvvvvVVv', 0x0605, 0, 0, $n, $n, strlen( $cd ), strlen( $out ), 0 );
                return $out . $cd . $eocd;
        }

        /** تبدیل Unix timestamp به زمان/تاریخ DOS */
        private static function dos_time( $ts ) {
                $ts = $ts ? $ts : time();
                $d = getdate( $ts );
                if ( $d['year'] < 1980 ) {
                        return array( 0, 0x0021 ); // 1980-01-01
                }
                $ddate = ( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'];
                $dtime = ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 );
                return array( $dtime, $ddate );
        }

        /**
         * خروجی مستقیم به مرورگر — با پاک‌سازی کامل بافرهای خروجی
         * (بافرهای فعال مثل ob_gzhandler/zlib.output_compression فایل باینری را خراب می‌کنند)
         */
        public static function download( $filename, $content, $mime ) {
                while ( ob_get_level() > 0 ) {
                        ob_end_clean();
                }
                if ( ! defined( 'DOING_TPP_DOWNLOAD' ) ) {
                        define( 'DOING_TPP_DOWNLOAD', true );
                }
                nocache_headers();
                header( 'Content-Type: ' . $mime );
                header( "Content-Disposition: attachment; filename=\"" . $filename . "\"; filename*=UTF-8''" . rawurlencode( $filename ) );
                header( 'Content-Length: ' . strlen( $content ) );
                header( 'Content-Transfer-Encoding: binary' );
                header( 'X-Content-Type-Options: nosniff' );
                header( 'Cache-Control: no-cache, must-revalidate' );
                echo $content; // phpcs:ignore WordPress.Security.EscapeOutput
                exit;
        }
}

/**
 * خواننده ZIP خالص PHP — خواندن فایل‌های داخل آرشیو بدون افزونه php-zip (ZipArchive).
 *
 * چرا؟ اگر میزبان افزونه zip را نداشته باشد، «new ZipArchive» خطای مهلک می‌دهد و
 * پاسخ HTML/خطای سرور به‌جای JSON به اپ می‌رسد (خطای «Cannot read properties of null» در ایمپورت).
 * این کلاس فرمت ZIP را دستی می‌خواند:
 *   - End of Central Directory → Central Directory → Local File Header → داده فشرده
 *   - روش‌های Stored (0) و Deflate (8)
 *   - مقادیر اندازه/آفست از Central Directory (سازگار با data descriptor)
 */
class TPP_Zip_Reader {

        /** @var string کل بایت‌های فایل */
        private $data = '';

        /** @var array نام => [offset, csize, usize, method] */
        private $entries = array();

        /**
         * باز کردن فایل ZIP.
         *
         * @param string $file مسیر فایل
         * @param string $data محتوای باینری (به‌جای مسیر — اختیاری)
         * @return bool
         */
        public function open( $file, $data = null ) {
                if ( null !== $data ) {
                        $this->data = (string) $data;
                } else {
                        if ( ! is_file( $file ) || ! is_readable( $file ) ) {
                                return false;
                        }
                        $this->data = (string) file_get_contents( $file );
                }
                $len = strlen( $this->data );
                if ( $len < 22 || 'PK' !== substr( $this->data, 0, 2 ) ) {
                        return false;
                }

                // یافتن EOCD از انتها (حداکثر ۶۵۵۵۷ بایت پیمایش — کامنت مجاز ZIP)
                $search = substr( $this->data, -min( $len, 65557 ) );
                $pos = strrpos( $search, "PK\x05\x06" );
                if ( false === $pos ) {
                        return false;
                }
                $pos += max( 0, $len - strlen( $search ) );
                $eocd = unpack( 'vdisk/vcddisk/vdiskentries/vtotal/Vcdsize/Vcdoffset/vcommentlen', substr( $this->data, $pos + 4, 18 ) );
                if ( ! is_array( $eocd ) || empty( $eocd['total'] ) ) {
                        return false;
                }

                // پیمایش Central Directory
                $p     = (int) $eocd['cdoffset'];
                $count = 0;
                $max   = (int) $eocd['total'];
                while ( $count < $max && $p + 46 <= $len ) {
                        if ( "PK\x01\x02" !== substr( $this->data, $p, 4 ) ) {
                                break;
                        }
                        $h = unpack( 'vmadeby/vneeded/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdiskno/viattr/Veattr/Voffset', substr( $this->data, $p + 4, 42 ) );
                        if ( ! is_array( $h ) ) {
                                break;
                        }
                        $name = substr( $this->data, $p + 46, $h['namelen'] );
                        $this->entries[ $name ] = array(
                                'offset' => (int) $h['offset'],
                                'csize'  => (int) $h['csize'],
                                'usize'  => (int) $h['usize'],
                                'method' => (int) $h['method'],
                        );
                        $p += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
                        $count++;
                }
                return ! empty( $this->entries );
        }

        /** آیا فایلی با این نام موجود است؟ */
        public function has( $name ) {
                return isset( $this->entries[ $name ] );
        }

        /** محتوای فایل داخل آرشیو — false در صورت نبود/خرابی */
        public function get( $name ) {
                if ( ! isset( $this->entries[ $name ] ) ) {
                        return false;
                }
                $e   = $this->entries[ $name ];
                $off = $e['offset'];
                if ( $off + 30 > strlen( $this->data ) || "PK\x03\x04" !== substr( $this->data, $off, 4 ) ) {
                        return false;
                }
                $lh = unpack( 'vneeded/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', substr( $this->data, $off + 4, 26 ) );
                if ( ! is_array( $lh ) ) {
                        return false;
                }
                $start = $off + 30 + $lh['namelen'] + $lh['extralen'];
                $csize = $e['csize'] > 0 ? $e['csize'] : $lh['csize'];
                $raw   = substr( $this->data, $start, $csize );

                if ( 0 === $e['method'] ) {
                        return $raw; // Stored
                }
                if ( 8 === $e['method'] ) {
                        $out = @gzinflate( $raw, $e['usize'] > 0 ? $e['usize'] + 1 : 0 );
                        if ( false === $out && $e['usize'] > 0 ) {
                                $out = @gzinflate( $raw ); // تلاش بدون سقف
                        }
                        return false === $out ? false : $out;
                }
                return false; // روش فشرده‌سازی ناشناخته (مثلاً Bzip2)
        }
}
