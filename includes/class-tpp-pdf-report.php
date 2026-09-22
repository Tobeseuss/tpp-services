<?php
/**
 * خروجی PDF سمت سرور با موتور TCPDF (کتابخانه آماده و آزموده — پشتیبانی کامل فارسی/RTL).
 *
 * چرا TCPDF؟ موتور دست‌نویس قبلی (TPP_PDF) روی برخی سرورها خروجی ناخوانا داشت؛
 * TCPDF استاندارد de-facto تولید PDF فارسی در PHP است (شکل‌دهی حروف عربی/فارسی،
 * ترتیب راست‌به‌چپ، تعبیه فونت). نسخه سبک‌شده (فقط فایل‌های لازم + فونت DejaVu Sans)
 * داخل افزونه بسته‌بندی شده — نیازی به composer یا نصب جداگانه نیست.
 *
 * اگر کتابخانه در دسترس نباشد یا خطا بدهد، خودکار به موتور داخلی TPP_PDF برمی‌گردد.
 *
 * فونت: DejaVu Sans (پروانه آزاد Bitstream Vera/PD — قابل تعبیه)
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_PDF_Report {

        /** آیا کتابخانه TCPDF داخل افزونه موجود است؟ */
        public static function available() {
                return file_exists( TPP_PLUGIN_DIR . 'includes/tcpdf/tcpdf.php' );
        }

        /** بارگذاری کتابخانه (یک‌بار) — مسیرها و ثابت‌ها خودکار از config بسته‌بندی‌شده خوانده می‌شوند */
        private static function load() {
                if ( ! class_exists( 'TCPDF' ) ) {
                        if ( ! defined( 'K_TCPDF_EXTERNAL_CONFIG' ) ) {
                                // نکته: اگر افزونه دیگری TCPDF دارد، از تنظیمات خودش استفاده نکن
                                define( 'K_TCPDF_EXTERNAL_CONFIG', false );
                        }
                        require_once TPP_PLUGIN_DIR . 'includes/tcpdf/tcpdf.php';
                        if ( ! class_exists( 'TCPDF' ) ) {
                                return false;
                        }
                }
                if ( ! class_exists( 'TPP_Tcpdf_Doc' ) ) {
                        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-tcpdf-doc.php';
                }
                return class_exists( 'TPP_Tcpdf_Doc' );
        }

        /**
         * ساخت گزارش جدولی PDF — خروجی: رشته باینری PDF (یا WP_Error)
         *
         * @param string $title   عنوان گزارش
         * @param array  $meta    خطوط اطلاعات (تاریخ/تعداد/تهیه‌کننده/جستجو)
         * @param array  $headers سرستون‌ها
         * @param array  $rows    ردیف‌ها
         * @param array  $opts    font_size, orientation ('L'|'P'|'auto')
         */
        public static function build( $title, array $meta, array $headers, array $rows, array $opts = array() ) {
                if ( ! self::load() ) {
                        return new WP_Error( 'tpp_tcpdf_missing', 'کتابخانه TCPDF در دسترس نیست.' );
                }

                $font_size  = isset( $opts['font_size'] ) ? (float) $opts['font_size'] : 8.5;
                $has_date   = ! empty( $headers ) && 'آخرین بروزرسانی' === $headers[ count( $headers ) - 1 ];
                if ( isset( $opts['orientation'] ) && in_array( $opts['orientation'], array( 'L', 'P' ), true ) ) {
                        $orientation = $opts['orientation'];
                } else {
                        $orientation = count( $headers ) > 6 ? 'L' : 'P';
                }

                $pdf = new TPP_Tcpdf_Doc( $orientation, 'mm', 'A4', true, 'UTF-8', false );
                $pdf->report_title = (string) $title;
                $pdf->report_meta  = array_values( array_filter( array_map( 'strval', $meta ) ) );

                $pdf->SetCreator( 'TPP Services' );
                $pdf->SetAuthor( 'TPP Services' );
                $pdf->SetTitle( (string) $title );
                $pdf->SetMargins( 10, 30, 10 );
                $pdf->SetHeaderMargin( 5 );
                $pdf->SetFooterMargin( 9 );
                $pdf->SetAutoPageBreak( true, 14 );
                $pdf->setPrintHeader( true );
                $pdf->setPrintFooter( true );
                $pdf->setRTL( true );
                $pdf->SetFont( 'dejavusans', '', $font_size );
                $pdf->SetCellHeightRatio( 1.22 );
                $pdf->AddPage();

                // عرض ستون‌ها (mm) — شناسه و تاریخ باریک‌تر؛ بقیه برابر
                $page_w  = 'L' === $orientation ? 297 : 210;
                $content_w = $page_w - 20; // حاشیه ۱۰ از هر طرف
                $n       = max( 1, count( $headers ) );
                $id_w    = 11;
                $date_w  = min( 30, $content_w * 0.14 );
                $rest_n  = max( 1, $n - 1 - ( $has_date ? 1 : 0 ) );
                $rest_w  = $content_w - $id_w - ( $has_date ? $date_w : 0 );
                $widths  = array();
                for ( $i = 0; $i < $n; $i++ ) {
                        if ( 0 === $i ) {
                                $widths[] = $id_w;
                        } elseif ( $has_date && $n - 1 === $i ) {
                                $widths[] = $date_w;
                        } else {
                                $widths[] = $rest_w / $rest_n;
                        }
                }
                $pdf->table_widths  = $widths;
                $pdf->table_headers = $headers;
                $pdf->table_active  = true;

                $pdf->draw_table_header();

                $fill = false;
                $pdf->SetFillColor( 243, 246, 250 );
                $pdf->SetTextColor( 30, 36, 44 );
                $pdf->SetDrawColor( 201, 209, 218 );
                $pdf->SetLineWidth( 0.15 );

                foreach ( $rows as $row ) {
                        // ارتفاع ردیف از بیشترین تعداد خط سلول‌ها
                        $lines = 1;
                        foreach ( $row as $i => $cell ) {
                                if ( '' !== (string) $cell ) {
                                        $l = $pdf->getNumLines( (string) $cell, $widths[ $i ] );
                                        if ( $l > $lines ) {
                                                $lines = $l;
                                        }
                                }
                        }
                        $row_h = $lines * ( $font_size * 0.52 ) + 1.4;

                        $y = $pdf->GetY();
                        if ( $y + $row_h > $pdf->getPageHeight() - $pdf->getBreakMargin() ) {
                                $pdf->AddPage(); // Header() صفحه جدید، سرستون را خودکار می‌کشد
                                $y = $pdf->GetY();
                        }

                        // رسم از راست به چپ: ستون اول در سمت راست
                        $x = $pdf->getPageWidth() - 10;
                        foreach ( $row as $i => $cell ) {
                                $w = $widths[ $i ];
                                $x -= $w;
                                $pdf->SetXY( $x, $y );
                                $pdf->MultiCell( $w, $row_h, (string) $cell, 1, 'R', $fill, 0, $x, $y, true, 0, false, true, $row_h, 'T', false );
                        }
                        $pdf->SetY( $y + $row_h, false );
                        $fill = ! $fill;
                }

                return $pdf->Output( 'report.pdf', 'S' );
        }
}
