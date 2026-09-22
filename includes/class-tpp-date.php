<?php
/**
 * TPP_Date — ساعت و تقویم تهران (۱.۱۸.۰)
 *
 * همه‌جای افزونه تاریخ‌ها به وقت تهران (Asia/Tehran) ثبت و به تقویم شمسی نمایش داده می‌شوند.
 * - now()/today()/his()/ts(): جایگزین‌های قطعیِ current_time() — مستقل از تنظیم منطقه‌زمانی وردپرس
 * - to_jalali()/jalali(): تبدیل میلادی→شمسی (الگوریتم jalaali — همان پیاده‌سازی سمت اپ) + قالب نمایش فارسی
 * - fa_num()/en_num(): تبدیل ارقام فارسی/عربی↔انگلیسی
 *
 * نکته زمان‌بندی: ایران از ۲۰۲۲ ساعت تابستانی ندارد؛ افست ثابت +03:30 است و DateTimeZone خودش همین را برمی‌گرداند.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Date {

        const TZ = 'Asia/Tehran';

        /* ============================================================
         * ساعت تهران — جایگزین‌های current_time()
         * ============================================================ */

        /** الان به وقت تهران — «Y-m-d H:i:s» */
        public static function now() {
                return self::fmt( 'Y-m-d H:i:s' );
        }

        /** امروز به وقت تهران — «Y-m-d» */
        public static function today() {
                return self::fmt( 'Y-m-d' );
        }

        /** تاریخ ISO میلادی ± n روز (ریاضی تاریخ خالص — برای محاسبه مرز بازه‌ها؛ ۱.۲۰.۰) */
        public static function iso_add_days( $iso, $days ) {
                $ts = strtotime( (string) $iso . ' 00:00:00 UTC' );
                if ( false === $ts ) {
                        return (string) $iso;
                }
                return gmdate( 'Y-m-d', $ts + (int) $days * 86400 );
        }

        /** فقط ساعت تهران — «H:i:s» */
        public static function his() {
                return self::fmt( 'H:i:s' );
        }

        /** همین فرمت دلخواه، به وقت تهران (معادل current_time($type) با گارانتی تهران) */
        public static function fmt( $format ) {
                $d = date_create( 'now', new DateTimeZone( self::TZ ) );
                return $d ? $d->format( $format ) : gmdate( $format );
        }

        /** افست تهران به ثانیه (+12600) — از خود PHP می‌پرسیم تا همیشه دقیق باشد */
        public static function offset() {
                $d = date_create( 'now', new DateTimeZone( self::TZ ) );
                return $d ? (int) $d->getOffset() : 12600;
        }

        /** «timestamp» محلی تهران — معادل current_time('timestamp'): برای gmdate() کردن زمانِ دیوارِ تهران */
        public static function ts() {
                return time() + self::offset();
        }

        /* ============================================================
         * ارقام
         * ============================================================ */

        /** 0-9 → ۰-۹ */
        public static function fa_num( $s ) {
                $fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
                return str_replace( array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), $fa, (string) $s );
        }

        /** ۰-۹ و ٠-٩ → 0-9 (فارسی/عربی → انگلیسی) */
        public static function en_num( $s ) {
                $map = array(
                        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                );
                return strtr( (string) $s, $map );
        }

        /** آیا رشته حاوی ارقام فارسی/عربی است؟ */
        public static function has_fa_digits( $s ) {
                return (bool) preg_match( '/[۰-۹٠-٩]/u', (string) $s );
        }

        /* ============================================================
         * تبدیل میلادی → شمسی (الگوریتم jalaali — پورت وفادار از tpp-app.js)
         * تقسیم/باقیمانده با گرد شدن به سمت صفر؛ Math.floor منفی جواب متفاوت می‌داد.
         * ============================================================ */

        private static function jdiv( $a, $b ) {
                return (int) ( $a / $b ); // intdiv رفتار trunc دارد — همان Math.trunc
        }

        private static function jmod( $a, $b ) {
                return $a - (int) ( $a / $b ) * $b;
        }

        /** سال شمسی → اطلاعات تقویم (روز جولیائیِ اول فروردین) */
        private static function jal_cal( $jy ) {
                $breaks = array( -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 );
                $gy      = $jy + 621;
                $leap_j  = -14;
                $jp      = $breaks[0];
                $jm      = 0;
                $jump    = 0;
                for ( $i = 1; $i < count( $breaks ); $i += 1 ) {
                        $jm   = $breaks[ $i ];
                        $jump = $jm - $jp;
                        if ( $jy < $jm ) {
                                break;
                        }
                        $leap_j += self::jdiv( $jump, 33 ) * 8 + self::jdiv( self::jmod( $jump, 33 ), 4 );
                        $jp = $jm;
                }
                $n = $jy - $jp;
                $leap_j += self::jdiv( $n, 33 ) * 8 + self::jdiv( self::jmod( $n, 33 ) + 3, 4 );
                if ( 4 === self::jmod( $jump, 33 ) && 4 === $jump - $n ) {
                        $leap_j += 1;
                }
                $leap_g = self::jdiv( $gy, 4 ) - self::jdiv( ( self::jdiv( $gy, 100 ) + 1 ) * 3, 4 ) - 150;
                $march  = 20 + $leap_j - $leap_g;
                if ( $jump - $n < 6 ) {
                        $n = $n - $jump + self::jdiv( $jump + 4, 33 ) * 33;
                }
                $leap = self::jmod( self::jmod( $n + 1, 33 ) - 1, 4 );
                if ( -1 === $leap ) {
                        $leap = 4;
                }
                return array( 'leap' => $leap, 'gy' => $gy, 'march' => $march );
        }

        private static function g2d( $y, $m, $d ) {
                $dd = self::jdiv( ( $y + self::jdiv( $m - 8, 6 ) + 100100 ) * 1461, 4 )
                        + self::jdiv( 153 * self::jmod( $m + 9, 12 ) + 2, 5 )
                        + $d - 34840408;
                return $dd - self::jdiv( self::jdiv( $y + 100100 + self::jdiv( $m - 8, 6 ), 100 ) * 3, 4 ) + 752;
        }

        private static function d2g( $jdn ) {
                $j = 4 * $jdn + 139361631;
                $j = $j + self::jdiv( self::jdiv( 4 * $jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;
                $i  = self::jdiv( self::jmod( $j, 1461 ), 4 ) * 5 + 308;
                $gd = self::jdiv( self::jmod( $i, 153 ), 5 ) + 1;
                $gm = self::jmod( self::jdiv( $i, 153 ), 12 ) + 1;
                $gy = self::jdiv( $j, 1461 ) - 100100 + self::jdiv( 8 - $gm, 6 );
                return array( 'gy' => $gy, 'gm' => $gm, 'gd' => $gd );
        }

        /** میلادی → شمسی — ورودی/خروجی عددی؛ همان نتیجه‌ی الگوریتم tpp-app.js */
        public static function to_jalali( $gy, $gm, $gd ) {
                $jdn  = self::g2d( (int) $gy, (int) $gm, (int) $gd );
                $gy2  = self::d2g( $jdn )['gy'];
                $jy   = $gy2 - 621;
                $r    = self::jal_cal( $jy );
                $jdn1f = self::g2d( $gy2, 3, $r['march'] );
                $k    = $jdn - $jdn1f;
                if ( $k >= 0 ) {
                        if ( $k <= 185 ) {
                                return array( 'jy' => $jy, 'jm' => 1 + self::jdiv( $k, 31 ), 'jd' => self::jmod( $k, 31 ) + 1 );
                        }
                        $k -= 186;
                } else {
                        // سال شمسی قبلی — r.leap مال سالِ قبل از کاهش است
                        $jy -= 1;
                        $k  += 179;
                        if ( 1 === $r['leap'] ) {
                                $k += 1;
                        }
                }
                return array( 'jy' => $jy, 'jm' => 7 + self::jdiv( $k, 30 ), 'jd' => self::jmod( $k, 30 ) + 1 );
        }

        /** شمسی → میلادی (برای کامل بودن مجموعه — در صورت نیاز آینده) */
        public static function to_gregorian( $jy, $jm, $jd ) {
                $r   = self::jal_cal( (int) $jy );
                $jdn = self::g2d( $r['gy'], 3, $r['march'] ) + ( $jm - 1 ) * 31 - self::jdiv( $jm, 7 ) * ( $jm - 7 ) + $jd - 1;
                return self::d2g( $jdn );
        }

        /* ============================================================
         * قالب‌های نمایش شمسی
         * ============================================================ */

        /**
         * تاریخ میلادی ثبت‌شده (رشته یا «Y-m-d H:i[:s]» یا فقط «Y-m-d») → متن شمسی فارسی:
         *   «۱۴۰۵/۰۶/۳۰ — ۱۰:۳۰»   یا فقط تاریخ «۱۴۰۵/۰۶/۳۰»
         * ورودی، زمانِ دیوارِ تهران فرض می‌شود (همان چیزی که TPP_Date::now() می‌نویسد).
         * اگر انتهای رشته Z یا ±HH:MM باشد (ISO کامل)، ابتدا به تهران تبدیل می‌شود.
         */
        public static function jalali( $dt, $with_time = true ) {
                $s = trim( (string) $dt );
                if ( '' === $s ) {
                        return '';
                }
                // ISO با منطقه‌زمانی؟ → به دیوار تهران تبدیل کن
                if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $s ) ) {
                        try {
                                $t   = date_create( preg_match( '/T/', $s ) ? $s : str_replace( ' ', 'T', $s ) );
                                if ( $t ) {
                                        $t->setTimezone( new DateTimeZone( self::TZ ) );
                                        $s = $t->format( 'Y-m-d H:i:s' );
                                }
                        } catch ( Throwable $e ) {
                                // رشته شکل عجیب بود — ادامه با تجزیه اجزاء
                        }
                }
                if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?/', $s, $m ) ) {
                        return $s; // قابل تجزیه نبود — همان را برگردان
                }
                $j = self::to_jalali( (int) $m[1], (int) $m[2], (int) $m[3] );
                $p2 = function ( $n ) {
                        return sprintf( '%02d', (int) $n );
                };
                $out = self::fa_num( $j['jy'] . '/' . $p2( $j['jm'] ) . '/' . $p2( $j['jd'] ) );
                if ( $with_time && isset( $m[4] ) && '' !== $m[4] ) {
                        $out .= ' — ' . self::fa_num( $p2( $m[4] ) . ':' . $m[5] );
                }
                return $out;
        }

        /** فقط تاریخ شمسی «۱۴۰۵/۰۶/۳۰» */
        public static function jalali_date( $dt ) {
                return self::jalali( $dt, false );
        }

        /** الان به وقت تهران به شکل شمسی «۱۴۰۵/۰۶/۳۰ — ۱۰:۳۰» */
        public static function jalali_now( $with_time = true ) {
                return self::jalali( self::now(), $with_time );
        }
}
