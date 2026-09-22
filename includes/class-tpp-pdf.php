<?php
/**
 * موتور تولید PDF خالص PHP — بدون هیچ وابستگی خارجی (TCPDF/FPDF/mPDF لازم نیست).
 *
 * قابلیت‌ها:
 *  - تعبیه فونت TrueType فارسی (CIDFontType2 + Identity-H) — دو وزن: معمولی و ضخیم
 *  - شکل‌دهی حروف فارسی/عربی (اتصال اولیه/میانی/پایانی/جدا + لیگاتور لام-الف)
 *  - ترتیب‌دهی دوجهته (RTL با جزایر LTR برای اعداد/انگلیسی) + قرینه‌سازی پرانتزها
 *  - جدول راست‌چین با ستون‌های اولویت‌دار، شکست خط در سلول، تکرار سرستون، ردیف‌های یک‌درمیان
 *  - سرصفحه (عنوان/سایت/تاریخ/تهیه‌کننده) و پاصفحه شماره صفحه در همه صفحات
 *
 * فونت: زیرمجموعه FreeSerif (پروانه GNU FreeFont — GPL با استثنای تعبیه فونت)
 * در includes/fonts/ در کنار همین فایل قرار دارد.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_PDF {

        /* ==================== جدول اشکال حروف (Presentation Forms) ==================== */

        /** cp => [isolated, final, initial, medial, نوع اتصال D(دوجهته)/R(فقط راست)/U(بدون)] */
        private static $JOIN = array(
                0x0621 => array( 0xFE80, 0, 0, 0, 'U' ),      // ء
                0x0622 => array( 0xFE81, 0xFE82, 0, 0, 'R' ),  // آ
                0x0623 => array( 0xFE83, 0xFE84, 0, 0, 'R' ),  // أ
                0x0624 => array( 0xFE85, 0xFE86, 0, 0, 'R' ),  // ؤ
                0x0625 => array( 0xFE87, 0xFE88, 0, 0, 'R' ),  // إ
                0x0626 => array( 0xFE89, 0xFE8A, 0xFE8B, 0xFE8C, 'D' ), // ئ
                0x0627 => array( 0xFE8D, 0xFE8E, 0, 0, 'R' ),  // ا
                0x0628 => array( 0xFE8F, 0xFE90, 0xFE91, 0xFE92, 'D' ), // ب
                0x0629 => array( 0xFE93, 0xFE94, 0, 0, 'R' ),  // ة
                0x062A => array( 0xFE95, 0xFE96, 0xFE97, 0xFE98, 'D' ), // ت
                0x062B => array( 0xFE99, 0xFE9A, 0xFE9B, 0xFE9C, 'D' ), // ث
                0x062C => array( 0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0, 'D' ), // ج
                0x062D => array( 0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4, 'D' ), // ح
                0x062E => array( 0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8, 'D' ), // خ
                0x062F => array( 0xFEA9, 0xFEAA, 0, 0, 'R' ),  // د
                0x0630 => array( 0xFEAB, 0xFEAC, 0, 0, 'R' ),  // ذ
                0x0631 => array( 0xFEAD, 0xFEAE, 0, 0, 'R' ),  // ر
                0x0632 => array( 0xFEAF, 0xFEB0, 0, 0, 'R' ),  // ز
                0x0633 => array( 0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4, 'D' ), // س
                0x0634 => array( 0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8, 'D' ), // ش
                0x0635 => array( 0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC, 'D' ), // ص
                0x0636 => array( 0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0, 'D' ), // ض
                0x0637 => array( 0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4, 'D' ), // ط
                0x0638 => array( 0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8, 'D' ), // ظ
                0x0639 => array( 0xFEC9, 0xFECA, 0xFECB, 0xFECC, 'D' ), // ع
                0x063A => array( 0xFECD, 0xFECE, 0xFECF, 0xFED0, 'D' ), // غ
                0x0641 => array( 0xFED1, 0xFED2, 0xFED3, 0xFED4, 'D' ), // ف
                0x0642 => array( 0xFED5, 0xFED6, 0xFED7, 0xFED8, 'D' ), // ق
                0x0643 => array( 0xFED9, 0xFEDA, 0xFEDB, 0xFEDC, 'D' ), // ك
                0x0644 => array( 0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0, 'D' ), // ل
                0x0645 => array( 0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4, 'D' ), // م
                0x0646 => array( 0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8, 'D' ), // ن
                0x0647 => array( 0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC, 'D' ), // ه
                0x0648 => array( 0xFEED, 0xFEEE, 0, 0, 'R' ),  // و
                0x0649 => array( 0xFEEF, 0xFEF0, 0, 0, 'R' ),  // ى
                0x064A => array( 0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4, 'D' ), // ي
                0x067E => array( 0xFB56, 0xFB57, 0xFB58, 0xFB59, 'D' ), // پ
                0x0686 => array( 0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D, 'D' ), // چ
                0x0698 => array( 0xFB8A, 0xFB8B, 0, 0, 'R' ),  // ژ
                0x06A9 => array( 0xFB8E, 0xFB8F, 0xFB90, 0xFB91, 'D' ), // ک
                0x06AF => array( 0xFB92, 0xFB93, 0xFB94, 0xFB95, 'D' ), // گ
                0x06CC => array( 0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF, 'D' ), // ی
                0x06C0 => array( 0xFB64, 0xFB65, 0xFB66, 0xFB67, 'R' ), // ۀ
                0x06C1 => array( 0xFB6C, 0xFB6D, 0xFB6E, 0xFB6F, 'D' ), // ہ
                0x06D2 => array( 0xFBAE, 0xFBAF, 0, 0, 'R' ),  // ے
                0x06D5 => array( 0xFE89 - 0x62, 0, 0, 0, 'U' ),// جای نگه‌دار (استفاده نمی‌شود)
        );

        /** لیگاتور لام + الف: کلید لام، مقدار [isolated, final] */
        private static $LAM_ALEF = array(
                0x0622 => array( 0xFEF5, 0xFEF6 ),
                0x0623 => array( 0xFEF7, 0xFEF8 ),
                0x0625 => array( 0xFEF9, 0xFEFA ),
                0x0627 => array( 0xFEFB, 0xFEFC ),
        );

        /** کاراکترهای شفاف (حرکت‌ها) — روی اتصال تأثیر ندارند */
        private static function is_transparent( $cp ) {
                return ( $cp >= 0x064B && $cp <= 0x0652 ) || 0x0670 === $cp || 0x0640 === $cp;
        }

        /* ==================== وضعیت سند ==================== */

        private $pages = array();       // آرایه محتوای هر صفحه
        private $cur = '';              // محتوای صفحه جاری
        private $w = 842;               // عرض صفحه (pt) — A4 افقی
        private $h = 595;               // ارتفاع صفحه
        private $marginX = 24;
        private $marginTop = 58;
        private $marginBottom = 34;
        private $y = null; // null تا اولین add_page صفحه خالی نسازد
        private $title = '';
        private $meta = array();        // خط‌های اطلاعات سرصفحه
        private $headerTitleSize = 13;
        private $fontSize = 8.5;
        private $headerFontSize = 9;
        private $lineH = 13;
        private $footerText = '';
        private static $fontCache = array();

        /** رنگ‌ها [r,g,b] 0..1 */
        private $colHeadBg = array( 0.15, 0.33, 0.50 );
        private $colHeadText = array( 1, 1, 1 );
        private $colBorder = array( 0.72, 0.76, 0.81 );
        private $colText = array( 0.11, 0.14, 0.18 );
        private $colZebra = array( 0.945, 0.958, 0.973 );

        public function __construct( $orientation = 'L' ) {
                if ( 'P' === $orientation ) {
                        $this->w = 595;
                        $this->h = 842;
                }
                $this->add_page();
        }

        public function set_title( $title, $meta = array() ) {
                $this->title = (string) $title;
                $this->meta  = (array) $meta;
        }

        public function set_footer( $text ) {
                $this->footerText = (string) $text;
        }

        /* ==================== بارگذاری و تجزیه فونت TTF ==================== */

        /** بارگذاری فونت با کش ایستا */
        private static function font( $bold ) {
                $key = $bold ? 'B' : 'R';
                if ( isset( self::$fontCache[ $key ] ) ) {
                        return self::$fontCache[ $key ];
                }
                $path = TPP_PLUGIN_DIR . 'includes/fonts/tpp-fa-' . ( $bold ? 'bold' : 'regular' ) . '.ttf';
                if ( ! is_readable( $path ) ) {
                        $path = TPP_PLUGIN_DIR . 'includes/fonts/tpp-fa-regular.ttf';
                }
                $data = @file_get_contents( $path );
                if ( false === $data || strlen( $data ) < 500 ) {
                        return null;
                }
                $f = array(
                        'data'  => $data,
                        'bold'  => $bold,
                        'glyphs'=> array(), // cp => gid
                        'widths'=> array(), // gid => عرض (units)
                        'upem'  => 1000,
                        'bbox'  => array( 0, 0, 1000, 1000 ),
                        'ascent'=> 800,
                        'descent' => -200,
                        'numGlyphs' => 1,
                );

                $n = strlen( $data );
                $tableCount = self::u16( $data, 4 );
                $tables = array();
                for ( $i = 0; $i < $tableCount; $i++ ) {
                        $off = 12 + $i * 16;
                        if ( $off + 16 > $n ) {
                                break;
                        }
                        $tag = substr( $data, $off, 4 );
                        $tables[ $tag ] = self::u32( $data, $off + 8 );
                }

                // head: unitsPerEm(18) bbox(36..44) indexToLocFormat(50)
                if ( isset( $tables['head'] ) ) {
                        $o = $tables['head'];
                        $f['upem'] = self::u16( $data, $o + 18 ) ?: 1000;
                        $f['bbox'] = array(
                                self::s16( $data, $o + 36 ), self::s16( $data, $o + 38 ),
                                self::s16( $data, $o + 40 ), self::s16( $data, $o + 42 ),
                        );
                }
                // maxp: numGlyphs(4)
                if ( isset( $tables['maxp'] ) ) {
                        $f['numGlyphs'] = self::u16( $data, $tables['maxp'] + 4 );
                }
                // hhea: numberOfHMetrics(34), ascent(4), descent(6)
                $numH = 1;
                if ( isset( $tables['hhea'] ) ) {
                        $o = $tables['hhea'];
                        $numH = self::u16( $data, $o + 34 );
                        $f['ascent']  = self::s16( $data, $o + 4 );
                        $f['descent'] = self::s16( $data, $o + 6 );
                }
                // hmtx
                if ( isset( $tables['hmtx'] ) ) {
                        $o = $tables['hmtx'];
                        $last = 0;
                        for ( $i = 0; $i < $f['numGlyphs'] && $i < 65536; $i++ ) {
                                if ( $i < $numH ) {
                                        $last = self::u16( $data, $o + $i * 4 );
                                }
                                $f['widths'][ $i ] = $last;
                        }
                }
                // cmap — جستجوی زیرجدول (3,1) یا (0,x) با فرمت ۴
                if ( isset( $tables['cmap'] ) ) {
                        $o = $tables['cmap'];
                        $subs = self::u16( $data, $o + 2 );
                        $chosen = 0;
                        for ( $i = 0; $i < $subs; $i++ ) {
                                $rec = $o + 4 + $i * 8;
                                $plat = self::u16( $data, $rec );
                                $enc  = self::u16( $data, $rec + 2 );
                                $off  = self::u32( $data, $rec + 4 );
                                $fmt  = self::u16( $data, $o + $off );
                                if ( 4 === $fmt && ( ( 3 === $plat && 1 === $enc ) || ( 0 === $plat ) ) ) {
                                        $chosen = $o + $off;
                                        break;
                                }
                        }
                        if ( $chosen ) {
                                $f['glyphs'] = self::parse_cmap4( $data, $chosen );
                        }
                }

                self::$fontCache[ $key ] = $f;
                return $f;
        }

        private static function parse_cmap4( $data, $off ) {
                $map = array();
                $segCount = self::u16( $data, $off + 6 ) / 2;
                $endBase  = $off + 14;
                $startBase = $endBase + $segCount * 2 + 2;
                $deltaBase = $startBase + $segCount * 2;
                $rangeBase = $deltaBase + $segCount * 2;
                for ( $i = 0; $i < $segCount; $i++ ) {
                        $end   = self::u16( $data, $endBase + $i * 2 );
                        $start = self::u16( $data, $startBase + $i * 2 );
                        $delta = self::s16( $data, $deltaBase + $i * 2 );
                        $rangeOff = self::u16( $data, $rangeBase + $i * 2 );
                        if ( $start > $end || $start > 0xFFFF ) {
                                continue;
                        }
                        for ( $cp = $start; $cp <= $end; $cp++ ) {
                                if ( 0xFFFF === $cp ) {
                                        break;
                                }
                                if ( 0 === $rangeOff ) {
                                        $gid = ( $cp + $delta ) & 0xFFFF;
                                } else {
                                        $idx = $rangeBase + $i * 2 + $rangeOff + ( $cp - $start ) * 2;
                                        $gid = self::u16( $data, $idx );
                                        if ( $gid ) {
                                                $gid = ( $gid + $delta ) & 0xFFFF;
                                        }
                                }
                                if ( $gid ) {
                                        $map[ $cp ] = $gid;
                                }
                        }
                }
                return $map;
        }

        private static function u16( $d, $o ) {
                return ( ord( $d[ $o ] ) << 8 ) | ord( $d[ $o + 1 ] );
        }

        /** کدپوینت یونی‌کد از یک کاراکتر UTF-8 */
        private static function ord_utf8( $ch ) {
                $b0 = ord( $ch[0] );
                if ( $b0 < 0x80 ) {
                        return $b0;
                }
                $l = strlen( $ch );
                if ( ( $b0 & 0xE0 ) === 0xC0 && $l >= 2 ) {
                        return ( ( $b0 & 0x1F ) << 6 ) | ( ord( $ch[1] ) & 0x3F );
                }
                if ( ( $b0 & 0xF0 ) === 0xE0 && $l >= 3 ) {
                        return ( ( $b0 & 0x0F ) << 12 ) | ( ( ord( $ch[1] ) & 0x3F ) << 6 ) | ( ord( $ch[2] ) & 0x3F );
                }
                if ( ( $b0 & 0xF8 ) === 0xF0 && $l >= 4 ) {
                        return ( ( $b0 & 0x07 ) << 18 ) | ( ( ord( $ch[1] ) & 0x3F ) << 12 ) | ( ( ord( $ch[2] ) & 0x3F ) << 6 ) | ( ord( $ch[3] ) & 0x3F );
                }
                return 0x3F;
        }
        private static function s16( $d, $o ) {
                $v = self::u16( $d, $o );
                return $v >= 0x8000 ? $v - 0x10000 : $v;
        }
        private static function u32( $d, $o ) {
                return ( ord( $d[ $o ] ) << 24 ) | ( ord( $d[ $o + 1 ] ) << 16 ) | ( ord( $d[ $o + 2 ] ) << 8 ) | ord( $d[ $o + 3 ] );
        }

        /* ==================== شکل‌دهی و دوجهته‌سازی ==================== */

        /**
         * متن منطقی → آرایه گلیف‌های نمایشی (چپ‌به‌راست برای چاپ در PDF).
         * هر عنصر: ['cp'=>کدپوینت نهایی بعد از شکل‌دهی، 'rtl'=>bool]
         */
        public static function shape( $text ) {
                $chars = array();
                foreach ( (array) preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
                        $chars[] = self::ord_utf8( $ch );
                }

                $n = count( $chars );
                $out = array();

                $i = 0;
                while ( $i < $n ) {
                        $cp = $chars[ $i ];

                        // نویسه‌های صفر-عرض: روی اتصال اثر دارند ولی چاپ نمی‌شوند
                        if ( 0x200C === $cp || 0x200D === $cp || 0x200E === $cp || 0x200F === $cp ) {
                                $out[] = array( 'cp' => $cp, 'rtl' => true, 'zw' => true );
                                $i++;
                                continue;
                        }

                        // لیگاتور لام + الف
                        if ( 0x0644 === $cp ) {
                                $j = $i + 1;
                                while ( $j < $n && self::is_transparent( $chars[ $j ] ) ) {
                                        $j++;
                                }
                                if ( $j < $n && isset( self::$LAM_ALEF[ $chars[ $j ] ] ) ) {
                                        $prevJoin = self::prev_joins( $chars, $i );
                                        $forms = self::$LAM_ALEF[ $chars[ $j ] ];
                                        $out[] = array( 'cp' => $prevJoin ? $forms[1] : $forms[0], 'rtl' => true );
                                        $i = $j + 1;
                                        continue;
                                }
                        }

                        if ( isset( self::$JOIN[ $cp ] ) ) {
                                $entry = self::$JOIN[ $cp ];
                                $prevJoin = self::prev_joins( $chars, $i );
                                $nextJoin = self::next_joinable( $chars, $i );
                                $form = 0;
                                if ( $prevJoin && $nextJoin && $entry[3] ) {
                                        $form = 3;
                                } elseif ( $prevJoin && $entry[1] ) {
                                        $form = 1;
                                } elseif ( $nextJoin && $entry[2] ) {
                                        $form = 2;
                                }
                                $glyph = $entry[ $form ] ? $entry[ $form ] : $entry[0];
                                $out[] = array( 'cp' => $glyph, 'rtl' => true );
                                $i++;
                                continue;
                        }

                        $out[] = array( 'cp' => $cp, 'rtl' => self::is_rtl_cp( $cp ) );
                        $i++;
                }

                return self::bidi( $out );
        }

        /** آیا حرف قبلی (پس از رد کردن شفاف‌ها/نویسه‌های صفر-عرض قطع‌کننده) به بعد وصل می‌شود؟ */
        private static function prev_joins( $chars, $i ) {
                for ( $j = $i - 1; $j >= 0; $j-- ) {
                        $cp = $chars[ $j ];
                        if ( self::is_transparent( $cp ) ) {
                                continue;
                        }
                        if ( 0x200C === $cp || 0x200D === $cp ) {
                                return 0x200D === $cp; // ZWJ وصل می‌کند، ZWNJ قطع می‌کند
                        }
                        // فقط حروف دوجهته (D) به حرف بعدی وصل می‌شوند؛ حروف R فقط از قبل اتصال می‌گیرند
                        return isset( self::$JOIN[ $cp ] ) && 'D' === self::$JOIN[ $cp ][4];
                }
                return false;
        }

        /** آیا حرف بعدی (پس از رد کردن شفاف‌ها) قابلیت اتصال به قبل (دوجهته) دارد؟ */
        private static function next_joinable( $chars, $i ) {
                $n = count( $chars );
                for ( $j = $i + 1; $j < $n; $j++ ) {
                        $cp = $chars[ $j ];
                        if ( self::is_transparent( $cp ) ) {
                                continue;
                        }
                        if ( 0x200C === $cp || 0x200D === $cp ) {
                                return false;
                        }
                        // حرف بعد باید بتواند از راست اتصال بپذیرد (دوجهته D یا راست‌اتصال R)
                        return isset( self::$JOIN[ $cp ] ) && 'U' !== self::$JOIN[ $cp ][4];
                }
                return false;
        }

        private static function is_rtl_cp( $cp ) {
                return ( $cp >= 0x0590 && $cp <= 0x08FF ) || ( $cp >= 0xFB50 && $cp <= 0xFEFF );
        }

        /** آیا نویسه بخشی از جزیره چپ‌به‌راست است؟ (اعداد لاتین/فارسی/عربی و حروف لاتین) */
        private static function is_ltr_cp( $cp ) {
                return ( $cp >= 0x30 && $cp <= 0x39 )
                        || ( $cp >= 0x41 && $cp <= 0x5A )
                        || ( $cp >= 0x61 && $cp <= 0x7A )
                        || ( $cp >= 0x0660 && $cp <= 0x0669 )
                        || ( $cp >= 0x06F0 && $cp <= 0x06F9 );
        }

        /** جداکننده‌های قابل‌قبول داخل جزیره LTR (بین دو نویسه LTR) */
        private static function is_ltr_sep( $cp ) {
                return in_array( $cp, array( 0x2E, 0x2D, 0x2F, 0x3A, 0x5F, 0x40, 0x23, 0x25, 0x2B, 0x26, 0x7E, 0x28, 0x29, 0x24 ), true ) || 0x20 === $cp;
        }

        /** قرینه پرانتز/براکت پس از معکوس‌سازی */
        private static function mirror( $cp ) {
                static $m = array( 0x28 => 0x29, 0x29 => 0x28, 0x5B => 0x5D, 0x5D => 0x5B, 0x7B => 0x7D, 0x7D => 0x7B, 0x3C => 0x3E, 0x3E => 0x3C );
                return isset( $m[ $cp ] ) ? $m[ $cp ] : $cp;
        }

        /**
         * دوجهته‌سازی ساده: معکوس کامل + باز‌معکوس جزایر LTR (اعداد و لاتین)
         */
        private static function bidi( array $glyphs ) {
                // ۱) معکوس کامل (رشته RTL است)
                $rev = array_reverse( $glyphs );

                // ۲) باز‌معکوس جزایر LTR: توالی‌های [LTR]([جداکننده][LTR])*
                $res = array();
                $count = count( $rev );
                for ( $i = 0; $i < $count; $i++ ) {
                        $cp = $rev[ $i ]['cp'];
                        if ( self::is_ltr_cp( $cp ) ) {
                                // پیشروی تا پایان جزیره
                                $j = $i;
                                $island = array( $rev[ $i ] );
                                $k = $i + 1;
                                while ( $k < $count && self::is_ltr_cp( $rev[ $k ]['cp'] ) ) {
                                        $island[] = $rev[ $k ];
                                        $k++;
                                }
                                // ادامه جزیره با زنجیره جداکننده‌ها (تا ۴) که به نویسه LTR ختم شوند
                                // مثال: «IP: 192.168.1.1» یا «TD 123» — دونقطه/فاصله بین دو جزء LTR
                                while ( $k < $count ) {
                                        $m = $k;
                                        while ( $m < $count && $m < $k + 4 && self::is_ltr_sep( $rev[ $m ]['cp'] ) ) {
                                                $m++;
                                        }
                                        if ( $m > $k && $m < $count && self::is_ltr_cp( $rev[ $m ]['cp'] ) ) {
                                                for ( $z = $k; $z <= $m; $z++ ) {
                                                        $island[] = $rev[ $z ];
                                                }
                                                $k = $m + 1;
                                                while ( $k < $count && self::is_ltr_cp( $rev[ $k ]['cp'] ) ) {
                                                        $island[] = $rev[ $k ];
                                                        $k++;
                                                }
                                        } else {
                                                break;
                                        }
                                }
                                // جزیره را به ترتیب منطقی (معکوسِ معکوس) برگردان
                                foreach ( array_reverse( $island ) as $g ) {
                                        $res[] = $g;
                                }
                                $i = $k - 1;
                                continue;
                        }
                        // پرانتزها قرینه شوند
                        $rev[ $i ]['cp'] = self::mirror( $cp );
                        $res[] = $rev[ $i ];
                }

                // ۳) حذف نویسه‌های صفر-عرض (ZWNJ و...)
                $out = array();
                foreach ( $res as $g ) {
                        if ( ! empty( $g['zw'] ) ) {
                                continue;
                        }
                        $out[] = $g;
                }
                return $out;
        }

        /* ==================== اندازه‌گیری و رسم متن ==================== */

        /** عرض متن (pt) با فونت و اندازه داده‌شده */
        public function text_width( $text, $size = null, $bold = false ) {
                $size = $size ? $size : $this->fontSize;
                $f = self::font( $bold );
                if ( ! $f ) {
                        return strlen( (string) $text ) * $size * 0.5;
                }
                $glyphs = self::shape( $text );
                $units = 0;
                foreach ( $glyphs as $g ) {
                        $gid = isset( $f['glyphs'][ $g['cp'] ] ) ? $f['glyphs'][ $g['cp'] ] : 0;
                        $units += isset( $f['widths'][ $gid ] ) ? $f['widths'][ $gid ] : $f['widths'][0];
                }
                return $units * $size / $f['upem'];
        }

        /**
         * چاپ متن در مختصات (x,y = خط مبنای Baseline).
         * $align: 'R' (پایان متن در x) | 'L' (شروع متن در x) | 'C' (وسط در x)
         */
        public function text( $x, $y, $text, $size = null, $bold = false, $color = null, $align = 'R' ) {
                $size = $size ? $size : $this->fontSize;
                $f = self::font( $bold );
                if ( ! $f ) {
                        return 0;
                }
                $glyphs = self::shape( $text );
                $hex = '';
                $units = 0;
                foreach ( $glyphs as $g ) {
                        $gid = isset( $f['glyphs'][ $g['cp'] ] ) ? $f['glyphs'][ $g['cp'] ] : ( isset( $f['glyphs'][0x3F] ) ? $f['glyphs'][0x3F] : 0 );
                        $hex .= sprintf( '%04X', $gid );
                        $units += isset( $f['widths'][ $gid ] ) ? $f['widths'][ $gid ] : $f['widths'][0];
                }
                $w = $units * $size / $f['upem'];
                if ( 'R' === $align ) {
                        $tx = $x - $w;
                } elseif ( 'C' === $align ) {
                        $tx = $x - $w / 2;
                } else {
                        $tx = $x;
                }
                $c = $color ? $color : $this->colText;
                $this->cur .= sprintf( "%.3F %.3F %.3F rg\n", $c[0], $c[1], $c[2] );
                $this->cur .= sprintf( "BT /%s %.2F Tf %.3F %.3F Td <%s> Tj ET\n", $bold ? 'F2' : 'F1', $size, $tx, $y, $hex );
                return $w;
        }

        /* ==================== عناصر گرافیکی ==================== */

        public function rect( $x, $y, $w, $h, $fill = null, $stroke = null ) {
                if ( $fill ) {
                        $this->cur .= sprintf( "%.3F %.3F %.3F rg\n", $fill[0], $fill[1], $fill[2] );
                }
                if ( $stroke ) {
                        $this->cur .= sprintf( "%.3F %.3F %.3F RG\n", $stroke[0], $stroke[1], $stroke[2] );
                }
                $this->cur .= sprintf( "%.2F %.2F %.2F %.2F re %s\n", $x, $y, $w, $h, ( $fill && $stroke ) ? 'B' : ( $fill ? 'f' : 'S' ) );
        }

        public function line( $x1, $y1, $x2, $y2, $color = null, $width = 0.5 ) {
                $c = $color ? $color : $this->colBorder;
                $this->cur .= sprintf( "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $c[0], $c[1], $c[2], $width, $x1, $y1, $x2, $y2 );
        }

        /* ==================== صفحه‌بندی ==================== */

        public function add_page() {
                if ( null !== $this->y ) {
                        $this->pages[] = $this->cur;
                }
                $this->cur = '';
                $this->y = $this->h - $this->marginTop;
                $this->page_header();
        }

        private function page_header() {
                if ( '' === $this->title ) {
                        return;
                }
                $top = $this->h - 40;
                $this->rect( $this->marginX, $top, $this->w - 2 * $this->marginX, 30, $this->colHeadBg );
                $this->line( $this->marginX, $top - 2, $this->w - $this->marginX, $top - 2, $this->colHeadBg, 2 );
                $this->text( $this->w - $this->marginX - 10, $top + 10, $this->title, $this->headerTitleSize, true, $this->colHeadText, 'R' );
                $meta = implode( '   |   ', array_filter( $this->meta ) );
                if ( '' !== $meta ) {
                        $this->text( $this->marginX + 10, $top + 10, $meta, 7.5, false, array( 0.9, 0.94, 1 ), 'L' );
                }
                $this->y = $top - 14;
        }

        /* ==================== جدول ==================== */

        /**
         * رسم جدول راست‌چین.
         * $headers: آرایه برچسب ستون‌ها (به‌ترتیب از راست)
         * $rows: آرایه‌ای از آرایه‌های مقادیر (به‌ترتیب از راست)
         * $opts: ['font_size'=>, 'max_lines'=>حداکثر خط هر سلول]
         */
        public function table( array $headers, array $rows, array $opts = array() ) {
                $fs = isset( $opts['font_size'] ) ? (float) $opts['font_size'] : $this->fontSize;
                $hfs = isset( $opts['header_font_size'] ) ? (float) $opts['header_font_size'] : $this->headerFontSize;
                $maxLines = isset( $opts['max_lines'] ) ? max( 1, (int) $opts['max_lines'] ) : 3;
                $lineH = $fs * 1.55;
                $hLineH = $hfs * 1.6;
                $pad = 3;
                $avail = $this->w - 2 * $this->marginX;

                $n = max( 1, count( $headers ) );
                // عرض‌ها: بر اساس بیشینه عرض محتوا (سقف‌دار) سپس نرمال‌سازی
                $widths = array();
                for ( $c = 0; $c < $n; $c++ ) {
                        $w = $this->text_width( (string) $headers[ $c ], $hfs, true ) + 2 * $pad + 4;
                        $sample = 0;
                        $cnt = 0;
                        foreach ( $rows as $r ) {
                                $val = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
                                $sample = max( $sample, $this->text_width( $val, $fs ) );
                                if ( ++$cnt > 200 ) {
                                        break; // نمونه‌گیری ۲۰۰ ردیف اول برای سرعت
                                }
                        }
                        $widths[ $c ] = min( 240, max( $w, min( $sample + 2 * $pad + 2, 220 ) ) );
                }
                $total = array_sum( $widths );
                if ( $total > $avail ) {
                        $scale = $avail / $total;
                        foreach ( $widths as $c => $w ) {
                                $widths[ $c ] = $w * $scale;
                        }
                } elseif ( $total < $avail ) {
                        // توزیع فضای خالی بین ستون‌ها
                        $extra = ( $avail - $total ) / $n;
                        foreach ( $widths as $c => $w ) {
                                $widths[ $c ] = $w + $extra;
                        }
                }

                $draw_header = function () use ( $headers, $widths, $hfs, $hLineH, $pad, $n ) {
                        $rowH = $hLineH + 2 * $pad;
                        if ( $this->y - $rowH < $this->marginBottom ) {
                                $this->add_page();
                        }
                        $fullW = $this->w - 2 * $this->marginX;
                        $xRight = $this->w - $this->marginX;
                        $this->rect( $this->marginX, $this->y - $rowH, $fullW, $rowH, $this->colHeadBg );
                        $x = $xRight;
                        for ( $c = 0; $c < $n; $c++ ) {
                                $cw = $widths[ $c ];
                                $this->text( $x - $pad, $this->y - $pad - $hfs * 0.85, (string) $headers[ $c ], $hfs, true, $this->colHeadText, 'R' );
                                $x -= $cw;
                        }
                        $this->y -= $rowH;
                };

                $draw_header();

                $idx = 0;
                foreach ( $rows as $r ) {
                        // شکست خط هر سلول
                        $cellLines = array();
                        for ( $c = 0; $c < $n; $c++ ) {
                                $val = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
                                $lines = $this->wrap( $val, $widths[ $c ] - 2 * $pad, $fs );
                                if ( count( $lines ) > $maxLines ) {
                                        $lines = array_slice( $lines, 0, $maxLines );
                                        $last = $lines[ $maxLines - 1 ];
                                        while ( $this->text_width( $last . '…', $fs ) > $widths[ $c ] - 2 * $pad && mb_strlen( $last ) > 1 ) {
                                                $last = mb_substr( $last, 0, -1 );
                                        }
                                        $lines[ $maxLines - 1 ] = $last . '…';
                                }
                                $cellLines[] = $lines;
                        }
                        $maxC = 0;
                        foreach ( $cellLines as $lines ) {
                                $maxC = max( $maxC, count( $lines ) );
                        }
                        $rowH = max( $lineH + 2 * $pad, $maxC * $lineH + 2 * $pad );

                        // صفحه جدید + تکرار سرستون
                        if ( $this->y - $rowH < $this->marginBottom ) {
                                $this->add_page();
                                $draw_header();
                        }

                        $xRight = $this->w - $this->marginX;
                        $x = $xRight;
                        $zebra = ( 0 === $idx % 2 );
                        for ( $c = 0; $c < $n; $c++ ) {
                                $cw = $widths[ $c ];
                                $cellX = $x - $cw;
                                if ( $zebra ) {
                                        $this->rect( $cellX, $this->y - $rowH, $cw, $rowH, $this->colZebra );
                                }
                                $this->rect( $cellX, $this->y - $rowH, $cw, $rowH, null, $this->colBorder );
                                $lines = $cellLines[ $c ];
                                $lineCount = count( $lines );
                                $blockH = $lineCount * $lineH;
                                $baseY = $this->y - $pad - ( $rowH - 2 * $pad - $blockH ) / 2 - $fs * 0.82;
                                foreach ( $lines as $li => $ln ) {
                                        $this->text( $x - $pad, $baseY - $li * $lineH, $ln, $fs, false, $this->colText, 'R' );
                                }
                                $x -= $cw;
                        }
                        $this->y -= $rowH;
                        $idx++;
                }
        }

        /** شکست خط بر اساس عرض — از کلمات */
        public function wrap( $text, $maxWidth, $size ) {
                $text = trim( (string) $text );
                if ( '' === $text ) {
                        return array( '' );
                }
                // ۱.۹.۳: سطرهای جدید صریحِ کاربر حفظ می‌شوند — هر سطر جداگانه شکسته می‌شود
                $out = array();
                foreach ( preg_split( '/\n/u', $text ) as $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) {
                                $out[] = '';
                                continue;
                        }
                        foreach ( $this->wrap_single( $line, $maxWidth, $size ) as $seg ) {
                                $out[] = $seg;
                        }
                }
                return $out ? $out : array( '' );
        }

        /** شکستن یک سطر بدون سطر جدید — بر اساس عرض ستون */
        private function wrap_single( $text, $maxWidth, $size ) {
                $text = trim( (string) $text );
                if ( '' === $text ) {
                        return array( '' );
                }
                $words = preg_split( '/\s+/u', $text );
                $lines = array();
                $cur = '';
                foreach ( $words as $w ) {
                        $try = '' === $cur ? $w : $cur . ' ' . $w;
                        if ( $this->text_width( $try, $size ) <= $maxWidth ) {
                                $cur = $try;
                        } else {
                                if ( '' !== $cur ) {
                                        $lines[] = $cur;
                                }
                                // کلمه خودش بلندتر از ستون → برش هجایی
                                while ( $this->text_width( $w, $size ) > $maxWidth && mb_strlen( $w ) > 2 ) {
                                        $cut = $w;
                                        while ( mb_strlen( $cut ) > 1 && $this->text_width( $cut . '…', $size ) > $maxWidth ) {
                                                $cut = mb_substr( $cut, 0, -1 );
                                        }
                                        $lines[] = $cut;
                                        $w = mb_substr( $w, mb_strlen( $cut ) );
                                }
                                $cur = $w;
                        }
                }
                if ( '' !== $cur ) {
                        $lines[] = $cur;
                }
                return $lines ? $lines : array( '' );
        }

        /* ==================== ساخت خروجی PDF ==================== */

        public function output() {
                $this->pages[] = $this->cur;
                $this->cur = '';
                $total = count( $this->pages );

                $fr = self::font( false );
                $fb = self::font( true );
                if ( ! $fr ) {
                        // بدون فونت — خروجی حداقلی (نباید رخ دهد)
                        $this->pages = array( '' );
                        $total = 1;
                }

                $objects = array(); // شماره شیء => محتوا (بدون "n 0 obj")
                $nextObj = 1;

                // 1: Catalog
                $catalogObj = $nextObj++;
                // 2: Pages (بعداً پر می‌شود)
                $pagesObj = $nextObj++;
                // فونت‌ها: هر فونت ۴ شیء (Type0, CIDFont, Descriptor, FontFile2)
                $fontObjs = array();
                foreach ( array( array( false, 'F1', $fr ), array( true, 'F2', $fb ) ) as $def ) {
                        list( $bold, $tag, $font ) = $def;
                        if ( ! $font ) {
                                continue;
                        }
                        $type0 = $nextObj++;
                        $cid = $nextObj++;
                        $desc = $nextObj++;
                        $file = $nextObj++;
                        $fontObjs[ $tag ] = compact( 'type0', 'cid', 'desc', 'file' ) + array( 'font' => $font );
                }

                // صفحات
                $pageIds = array();
                for ( $p = 0; $p < $total; $p++ ) {
                        $contentId = $nextObj++;
                        $pageId = $nextObj++;
                        $pageIds[] = array( 'page' => $pageId, 'content' => $contentId );
                }

                // ساخت محتوای هر صفحه (پاصفحه + شماره صفحه)
                $fontTagFirst = isset( $fontObjs['F1'] ) ? 'F1' : '';
                foreach ( $pageIds as $i => $pd ) {
                        $stream = $this->pages[ $i ];
                        // پاصفحه
                        $fy = 16;
                        $footLeft = '';
                        if ( '' !== $this->footerText ) {
                                $footLeft = $this->footerText;
                        }
                        $foot = sprintf( "%.3F %.3F %.3F rg\n", 0.45, 0.48, 0.53 );
                        $foot .= sprintf( "BT /%s 7.5 Tf %.3F %.3F Td <%s> Tj ET\n", $fontTagFirst, $this->w - $this->marginX, $fy, $this->hex_text( 'صفحه ' . self::fa_num( $i + 1 ) . ' از ' . self::fa_num( $total ), false ) );
                        $gl = $this->hex_text( $footLeft, false );
                        if ( $gl ) {
                                $foot .= sprintf( "BT /%s 7.5 Tf %.3F %.3F Td <%s> Tj ET\n", $fontTagFirst, $this->marginX, $fy, $gl );
                        }
                        $stream .= $foot;

                        $dict = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . 'endstream';
                        $objects[ $pd['content'] ] = $dict;
                }

                // شیء صفحات
                $kids = '';
                foreach ( $pageIds as $pd ) {
                        $kids .= $pd['page'] . ' 0 R ';
                }
                $objects[ $pagesObj ] = "<< /Type /Pages /Kids [ $kids] /Count $total >>";

                // اشیای صفحه
                foreach ( $pageIds as $pd ) {
                        $fontsDict = '';
                        foreach ( $fontObjs as $tag => $fo ) {
                                $fontsDict .= " /$tag " . $fo['type0'] . ' 0 R';
                        }
                        $objects[ $pd['page'] ] = sprintf(
                                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources << /Font << %s >> >> /Contents %d 0 R >>",
                                $pagesObj,
                                number_format( $this->w, 2, '.', '' ),
                                number_format( $this->h, 2, '.', '' ),
                                trim( $fontsDict ),
                                $pd['content']
                        );
                }

                // اشیای فونت
                foreach ( $fontObjs as $tag => $fo ) {
                        $font = $fo['font'];
                        $base = 'TPPFA' . ( 'F2' === $tag ? 'B' : 'R' );
                        // بازه‌های پیوسته گلیف‌ها برای آرایه /W
                        $gids = array_values( array_unique( array_values( $font['glyphs'] ) ) );
                        sort( $gids );
                        $prev = -2;
                        $start = 0;
                        $wRuns = array();
                        foreach ( $gids as $gid ) {
                                if ( $gid !== $prev + 1 ) {
                                        if ( $prev >= 0 ) {
                                                $wRuns[] = array( $start, $prev );
                                        }
                                        $start = $gid;
                                }
                                $prev = $gid;
                        }
                        if ( $prev >= 0 ) {
                                $wRuns[] = array( $start, $prev );
                        }
                        $wArr = array();
                        foreach ( $wRuns as $run ) {
                                $wArr[] = $run[0] . ' [';
                                for ( $g = $run[0]; $g <= $run[1]; $g++ ) {
                                        $wArr[] = isset( $font['widths'][ $g ] ) ? $font['widths'][ $g ] : 0;
                                }
                                $wArr[] = ']';
                        }
                        $wStr = implode( ' ', $wArr );

                        $objects[ $fo['type0'] ] = sprintf(
                                "<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] >>",
                                $base, $fo['cid']
                        );
                        $objects[ $fo['cid'] ] = sprintf(
                                "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W [%s] /CIDToGIDMap /Identity >>",
                                $base, $fo['desc'], $wStr
                        );
                        $fd = $font['data'];
                        // نکته حیاتی: FlateDecode در PDF فرمت zlib (RFC 1950) می‌خواهد؛ gzdeflate فقط raw deflate است.
                        $compressed = function_exists( 'gzcompress' ) ? gzcompress( $fd, 6 ) : false;
                        if ( false !== $compressed ) {
                                $fileStream = '<< /Length ' . strlen( $compressed ) . " /Filter /FlateDecode /Length1 " . strlen( $fd ) . " >>\nstream\n" . $compressed . 'endstream';
                        } else {
                                $fileStream = '<< /Length ' . strlen( $fd ) . ' /Length1 ' . strlen( $fd ) . " >>\nstream\n" . $fd . 'endstream';
                        }
                        $objects[ $fo['file'] ] = $fileStream;
                        $objects[ $fo['desc'] ] = sprintf(
                                "<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>",
                                $base,
                                $font['bbox'][0], $font['bbox'][1], $font['bbox'][2], $font['bbox'][3],
                                $font['ascent'], $font['descent'], (int) ( $font['ascent'] * 0.7 ),
                                $fo['file']
                        );
                }

                $objects[ $catalogObj ] = "<< /Type /Catalog /Pages $pagesObj 0 R >>";

                // سرآیند PDF + اطلاعات سند
                $infoObj = 0;
                if ( '' !== $this->title ) {
                        $infoObj = $nextObj++;
                        $objects[ $infoObj ] = '<< /Title ' . self::pdf_string( $this->title ) . ' /Producer (TPP Services) /Creator (TPP Services) >>';
                }
                if ( $infoObj ) {
                        $objects[ $catalogObj ] = "<< /Type /Catalog /Pages $pagesObj 0 R /Info $infoObj 0 R >>";
                }

                // سریال‌سازی
                ksort( $objects );
                $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
                $offsets = array();
                foreach ( $objects as $num => $body ) {
                        $offsets[ $num ] = strlen( $pdf );
                        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
                }
                $xrefPos = strlen( $pdf );
                $maxObj = $nextObj - 1;
                $pdf .= 'xref' . "\n" . '0 ' . ( $maxObj + 1 ) . "\n";
                $pdf .= "0000000000 65535 f \n";
                for ( $i = 1; $i <= $maxObj; $i++ ) {
                        if ( isset( $offsets[ $i ] ) ) {
                                $pdf .= sprintf( '%010d 00000 n ' . "\n", $offsets[ $i ] );
                        } else {
                                $pdf .= "0000000000 65535 f \n";
                        }
                }
                $root = $infoObj ? $catalogObj : $catalogObj;
                $pdf .= "trailer\n<< /Size " . ( $maxObj + 1 ) . " /Root $root 0 R" . ( $infoObj ? " /Info $infoObj 0 R" : '' ) . " >>\nstartxref\n$xrefPos\n%%EOF";
                return $pdf;
        }

        /** متن → هگز گلیف (برای پاصفحه که مستقیم ساخته می‌شود) */
        private function hex_text( $text, $bold = false ) {
                $f = self::font( $bold );
                if ( ! $f ) {
                        return '';
                }
                $hex = '';
                foreach ( self::shape( $text ) as $g ) {
                        $gid = isset( $f['glyphs'][ $g['cp'] ] ) ? $f['glyphs'][ $g['cp'] ] : 0;
                        $hex .= sprintf( '%04X', $gid );
                }
                return $hex;
        }

        /** تبدیل ارقام لاتین به فارسی */
        public static function fa_num( $s ) {
                return str_replace(
                        array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
                        array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
                        (string) $s
                );
        }

        /** رشته PDF (UTF-16BE با BOM) */
        private static function pdf_string( $s ) {
                $utf16 = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( (string) $s, 'UTF-16BE', 'UTF-8' ) : (string) $s;
                return '(' . str_replace( array( '(', ')', '\\' ), array( '\\(', '\\)', '\\\\' ), $utf16 ) . ')';
        }

        /** ارسال PDF به مرورگر */
        public static function download( $filename, $content ) {
                TPP_Zip::download( $filename, $content, 'application/pdf' );
        }
}
