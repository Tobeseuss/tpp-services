<?php
/**
 * خروجی‌ها و پشتیبان‌گیری:
 *  - قالب نمونه اکسل (همیشه بر اساس فیلدهای فعلی → با تغییر فیلدها خودکار به‌روز می‌شود)
 *  - خروجی اکسل داده‌ها (کامل یا نتیجه جستجو، با رعایت دسترسی فیلد کاربر)
 *  - خروجی PDF بومی با موتور TPP_PDF (فونت فارسی تعبیه‌شده — بدون نیاز به چاپ مرورگر)
 *  - پشتیبان کامل JSON + بازیابی
 *  - پشتیبان SQL کامل (جداول + تنظیمات + توکن‌ها — قابل درج در phpMyAdmin)
 *  - پشتیبان ZIP کامل (SQL + JSON + فایل‌های افزونه + راهنمای بازیابی)
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Export {

        /** ستون‌های خروجی — ۱.۸.۰: ترتیب کانونی مطابق فرم ثبت سرویس (اطلاعات اصلی → آدرس → سایر) */
        private function columns( $visible ) {
                $cols = array();
                foreach ( TPP_Fields::form_order() as $f ) {
                        if ( ! empty( $visible[ $f['slug'] ] ) ) {
                                $cols[] = array( 'label' => $f['label'], 'slug' => $f['slug'], 'group' => $f['group_key'] );
                        }
                }
                return $cols;
        }

        /**
         * قالب نمونه اکسل — با تغییر فیلدها همیشه به‌روز (نمونه‌ها بر اساس نام‌کد چیده می‌شوند).
         * ۱.۸.۰ — ترتیب ستون‌ها دقیقاً مطابق فرم ثبت سرویس جدید (اطلاعات اصلی → آدرس → سایر اطلاعات)؛
         * ستاره «*» نشان‌گر فیلد الزامی است و در تشخیص خودکار نگاشت ایمپورت نادیده گرفته می‌شود.
         */
        public function template() {
                $headers = array();
                $slugs   = array();
                foreach ( TPP_Fields::form_order() as $f ) {
                        $headers[] = $f['label'] . ( $f['is_required'] ? ' *' : '' );
                        $slugs[]   = $f['slug'];
                }

                // مقدار نمونه هر فیلد (بر اساس نام‌کد — با افزودن فیلد جدید چیدمان به‌هم نمی‌ریزد)
                $by_slug = array(
                        'f_full_address'        => 'تهران، خیابان آزادی، کوچه بهار',
                        'f_block'               => 'بلوک A',
                        'f_plate'               => '۱۲',
                        'f_unit'                => '۳',
                        'f_postal_code'         => '۱۳۹۷۸۵۴۶۲۱',
                        'f_center_name'         => 'مرکز مخابرات آزادی',
                        'f_owner_name'          => 'علی رضایی',
                        'f_phone'               => '۰۲۱۴۴۵۵۶۶۷۷',
                        'f_mobile'              => '۰۹۱۲۱۲۳۴۵۶۷',
                        'f_national_id_line'    => '۰۰۱۲۳۴۵۶۷۸',
                        'f_national_id_service' => '۰۰۱۲۳۴۵۶۷۸',
                        'f_virtual_number'      => '۰۲۱۹۱۰۰۱۲۳۴',
                        'f_internet_status'     => 'فعال',
                        'f_phone_status'        => 'فعال',
                        'f_modem_model'         => 'TD-W8970',
                        'f_modem_serial'        => 'SN-123456789',
                        'f_wifi24_name'         => 'MyHome-2.4',
                        'f_wifi24_pass'         => 'Pass1234',
                        'f_wifi5_name'          => 'MyHome-5',
                        'f_wifi5_pass'          => 'Pass5678',
                        'f_internet_pass'       => 'Pass1234',
                        'f_sip_pass'            => 'SipPass99',
                        'f_sip_ip'              => '10.0.0.1',
                        'f_subnet'              => '255.255.255.0',
                        'f_gateway'             => '10.0.0.138',
                        'f_sbc'                 => 'sbc.example.com',
                        'f_standby_proxy'       => 'proxy.example.com',
                        'f_description'         => 'نمونه توضیحات',
                );
                $sample = array();
                foreach ( $slugs as $slug ) {
                        $sample[] = isset( $by_slug[ $slug ] ) ? $by_slug[ $slug ] : 'نمونه';
                }
                // ۱.۱۳.۰ — ستون‌های پیشرفت دایری/خرابی در قالب (ایمپورت همان مقادیر را می‌فهمد)
                $headers[] = 'پیشرفت دایری';
                $sample[]  = 'اینترنت متصل می‌باشد';
                $headers[] = 'خرابی اعلام‌شده';
                $sample[]  = 'رفع';
                // ۱.۱۹.۰ — ستون‌های دسته‌بندی/تگ (مقدار = برچسب؛ در ایمپورت ناموجود خودکار ساخته می‌شود)
                $headers[] = 'دسته‌بندی پروژه';
                $sample[]  = 'پروژه سازمانی';
                $headers[] = 'تگ‌ها';
                $sample[]  = 'ویژه، اولویت بالا';

                $content = TPP_XLSX_Writer::build( $headers, array( $sample ), array( 'sheet_name' => 'قالب ثبت سرویس' ) );
                if ( is_wp_error( $content ) ) {
                        return $content;
                }
                return array(
                        'filename' => 'tpp-template-' . gmdate( 'Ymd-His' ) . '.xlsx',
                        'content'  => $content,
                );
        }

        /** جمع‌آوری همه ردیف‌ها برای خروجی (با احترام به دسترسی فیلد کاربر) */
        private function collect_rows( $args, $user_id ) {
                $search = tpp()->services()->search( array_merge( $args, array( 'per_page' => 500, 'group' => false ) ), $user_id );
                $rows   = $search['rows'];
                $page   = 2;
                while ( count( $search['rows'] ) >= 500 && $page <= 300 ) {
                        $search = tpp()->services()->search( array_merge( $args, array( 'per_page' => 500, 'page' => $page, 'group' => false ) ), $user_id );
                        if ( empty( $search['rows'] ) ) {
                                break;
                        }
                        $rows = array_merge( $rows, $search['rows'] );
                        $page++;
                }
                return $rows;
        }

        /**
         * نمایش مقدار فیلدهای حالت‌دار (SBC / StandBy Proxy) در خروجی‌ها:
         * AUTO → «خودکار (تنظیم توسط یارا)» — مقدار خالی همان خالی می‌ماند.
         */
        private static function fmt_value( $slug, $value ) {
                $v = (string) $value;
                if ( in_array( $slug, array( 'f_sbc', 'f_standby_proxy' ), true ) && 'AUTO' === strtoupper( trim( $v ) ) ) {
                        return 'خودکار (تنظیم توسط یارا)';
                }
                return $v;
        }

        /* ---- ۱.۱۲.۰/۱.۱۳.۰: ستون‌های پیشرفت دایری در خروجی‌ها ---- */

        /** سلول‌های پیشرفت یک ردیف: [وضعیت، آخرین مرحله، خرابی، مراحل انجام‌شده، مراحل ردشده] */
        private static function progress_cells( $row ) {
                $p = is_array( $row ) && isset( $row['progress'] ) && is_array( $row['progress'] ) ? $row['progress'] : array();
                $done = isset( $p['done'] ) ? (int) $p['done'] : 0;
                $total = isset( $p['total'] ) ? (int) $p['total'] : 16;
                $excluded = ( isset( $p['excluded'] ) && is_array( $p['excluded'] ) ) ? $p['excluded'] : array();
                $status = 'شروع نشده';
                // ۱.۱۴.۰ — خرابی‌ها آرایه‌اند (failures)؛ failure تکی برای سازگاری
                $failures = ( isset( $p['failures'] ) && is_array( $p['failures'] ) ) ? $p['failures'] : ( ! empty( $p['failure'] ) ? array( $p['failure'] ) : array() );
                if ( ! empty( $failures ) ) {
                        $status = 'خرابی اعلام‌شده';
                } elseif ( $done >= $total && $total > 0 ) {
                        $status = 'کامل (' . $done . ' از ' . $total . ')';
                } elseif ( $done > 0 ) {
                        $status = 'در جریان (' . $done . ' از ' . $total . ')';
                }
                if ( $excluded ) {
                        $status .= ' — ' . count( $excluded ) . ' مرحله ردشده';
                }
                $last = isset( $p['last_label'] ) ? (string) $p['last_label'] : '';
                // ۱.۱۴.۰ — متن خرابی: برچسب همه خرابی‌ها (failures_labels) یا برچسب تکی قدیمی
                $fail = '';
                if ( isset( $p['failures_labels'] ) && is_array( $p['failures_labels'] ) && ! empty( $p['failures_labels'] ) ) {
                        $fail = implode( '، ', array_filter( $p['failures_labels'] ) );
                } elseif ( isset( $p['failure_label'] ) ) {
                        $fail = (string) $p['failure_label'];
                }
                // ۱.۱۳.۰ — فهرست کامل مراحل انجام‌شده/ردشده برای اکسل (PDF/چاپ ستون فشرده دارد)
                $steps_list = '';
                if ( ! empty( $p['steps'] ) && is_array( $p['steps'] ) ) {
                        $steps_list = implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $p['steps'] ) );
                }
                $excl_list = $excluded ? implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $excluded ) ) : '';
                return array( $status, $last ? $last : '—', $fail ? $fail : '—', $steps_list, $excl_list );
        }

        /** ۱.۱۳.۰ — ستون‌های دایری برای اکسل: ۵ ستون (با فهرست مراحل) */
        private static function progress_headers_xlsx() {
                return array( 'وضعیت دایری', 'آخرین مرحله دایری', 'خرابی اعلام‌شده', 'مراحل انجام‌شده دایری', 'مراحل ردشده (توسط کاربر)' );
        }

        /** ۱.۱۳.۰ — ستون‌های دایری برای PDF/چاپ: ۳ ستون فشرده (وضعیت شامل تعداد ردشده) */
        private static function progress_headers_compact() {
                return array( 'وضعیت دایری', 'آخرین مرحله دایری', 'خرابی اعلام‌شده' );
        }

        /** برچسب فیلتر پیشرفت دایری برای سربرگ گزارش‌ها — خالی وقتی فیلتری نیست */
        private static function progress_label( $args ) {
                $st = sanitize_key( (string) ( $args['progress_status'] ?? '' ) );
                $labels = array(
                        'none' => 'دایری: شروع‌نشده', 'progress' => 'دایری: در جریان', 'done' => 'دایری: کامل',
                        'fail' => 'وضعیت: خرابی اعلام‌شده',
                        'fail_los' => 'خرابی: LOS', 'fail_phone' => 'خرابی: قطع تلفن',
                        'fail_internet' => 'خرابی: قطع اینترنت', 'fail_other' => 'خرابی: سایر',
                );
                $out = isset( $labels[ $st ] ) ? $labels[ $st ] : '';
                $step = sanitize_key( (string) ( $args['progress_step'] ?? '' ) );
                if ( $step ) {
                        $state = ( 'todo' === sanitize_key( (string) ( $args['progress_step_state'] ?? 'done' ) ) ) ? 'انجام‌نشده' : 'انجام‌شده';
                        $out .= ( $out ? ' + ' : '' ) . 'مرحله «' . TPP_Progress::step_label( $step ) . '»: ' . $state;
                }
                return $out;
        }

        /** برچسب بازه زمانی ویرایش برای گزارش‌ها — خالی وقتی فیلتری نیست */
        private static function range_label( $args ) {
                $from = trim( (string) ( $args['upd_from'] ?? '' ) );
                $to   = trim( (string) ( $args['upd_to'] ?? '' ) );
                if ( '' === $from && '' === $to ) {
                        return '';
                }
                if ( '' !== $from && '' !== $to ) {
                        return ( $from === $to )
                                ? 'ویرایش در تاریخ: ' . $from
                                : 'بازه ویرایش: از ' . $from . ' تا ' . $to;
                }
                return ( '' !== $from ) ? 'ویرایش از تاریخ: ' . $from : 'ویرایش تا تاریخ: ' . $to;
        }

        /** خروجی اکسل داده‌ها — تمام ردیف‌ها یا نتیجه جستجو */
        public function xlsx( $args, $user_id ) {
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $cols    = $this->columns( $visible );
                $rows    = $this->collect_rows( $args, $user_id );

                $headers = array( 'شناسه' );
                foreach ( $cols as $c ) {
                        $headers[] = $c['label'];
                }
                // ۱.۱۳.۰ — پنج ستون دایری در اکسل (شامل فهرست کامل مراحل/ردشده‌ها)
                foreach ( self::progress_headers_xlsx() as $h ) {
                        $headers[] = $h;
                }
                // ۱.۱۹.۰ — ستون‌های دسته‌بندی/تگ
                $headers[] = 'دسته‌بندی پروژه';
                $headers[] = 'تگ‌ها';
                $headers[] = 'تاریخ ثبت';
                $headers[] = 'آخرین بروزرسانی';

                $out = array();
                foreach ( $rows as $row ) {
                        $r = array( (string) $row['id'] );
                        foreach ( $cols as $c ) {
                                if ( 'address' === $c['group'] ) {
                                        $r[] = (string) ( $row['address'][ $c['slug'] ] ?? '' );
                                } else {
                                        $r[] = self::fmt_value( $c['slug'], $row[ $c['slug'] ] ?? '' );
                                }
                        }
                        foreach ( self::progress_cells( $row ) as $cell ) {
                                $r[] = $cell;
                        }
                        /* ۱.۱۹.۰ — دسته‌بندی/تگ (برچسب‌ها) */
                        $r[] = ( $row && $row['category'] && $row['category']['label'] ) ? $row['category']['label'] : '';
                        $r[] = ( $row && is_array( $row['tags'] ) ) ? implode( '، ', array_map( static function ( $t ) { return (string) $t['label']; }, $row['tags'] ) ) : '';
                        $r[] = TPP_Date::jalali( $row['created_at'] ); // ۱.۱۸.۰ — شمسی/تهران
                        $r[] = TPP_Date::jalali( $row['updated_at'] );
                        $out[] = $r;
                }

                $content = TPP_XLSX_Writer::build( $headers, $out, array( 'sheet_name' => 'سرویس‌ها' ) );
                if ( is_wp_error( $content ) ) {
                        return $content;
                }
                return array(
                        'filename' => 'tpp-services-' . gmdate( 'Ymd-His' ) . '.xlsx',
                        'content'  => $content,
                );
        }

        /**
         * خروجی PDF بومی — با موتور TCPDF (کتابخانه آماده، پشتیبانی کامل فارسی RTL).
         * اگر TCPDF در دسترس نباشد/خطا بدهد، به موتور داخلی TPP_PDF برمی‌گردد.
         */
        public function pdf( $args, $user_id, $title = 'گزارش سرویس‌ها' ) {
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $cols    = $this->columns( $visible );
                $rows    = $this->collect_rows( $args, $user_id );

                $user = get_userdata( $user_id );

                $meta = array(
                        'تاریخ گزارش: ' . TPP_Date::jalali_now( true ), // ۱.۱۸.۰ — شمسی به وقت تهران
                        'تعداد: ' . TPP_PDF::fa_num( number_format( count( $rows ) ) ) . ' سرویس',
                        'تهیه‌کننده: ' . ( $user ? $user->display_name : '' ),
                        ( ! empty( $args['query'] ) ? 'جستجو: ' . $args['query'] : '' ),
                        ( self::range_label( $args ) ? self::range_label( $args ) : '' ),
                        ( self::progress_label( $args ) ? self::progress_label( $args ) : '' ),
                );

                $headers = array_merge(
                        array( 'شناسه' ),
                        wp_list_pluck( $cols, 'label' ),
                        array_merge( self::progress_headers_compact(), array( 'آخرین بروزرسانی' ) ) // ۱.۱۳.۰ — فشرده برای PDF
                );
                $table_rows = array();
                foreach ( $rows as $row ) {
                        $r = array( (string) $row['id'] );
                        foreach ( $cols as $c ) {
                                if ( 'address' === $c['group'] ) {
                                        $r[] = (string) ( $row['address'][ $c['slug'] ] ?? '' );
                                } else {
                                        $r[] = self::fmt_value( $c['slug'], $row[ $c['slug'] ] ?? '' );
                                }
                        }
                        // ۱.۱۳.۰ — PDF: ۳ سلول فشرده (وضعیت شامل تعداد ردشده)
                        foreach ( array_slice( self::progress_cells( $row ), 0, 3 ) as $cell ) {
                                $r[] = $cell;
                        }
                        $r[] = TPP_Date::jalali( $row['updated_at'] ); // ۱.۱۸.۰ — شمسی/تهران
                        $table_rows[] = $r;
                }

                // ۱) موتور TCPDF (پیش‌فرض — کتابخانه آماده داخل افزونه)
                if ( TPP_PDF_Report::available() ) {
                        try {
                                $content = TPP_PDF_Report::build( $title . ' — ' . get_bloginfo( 'name' ), $meta, $headers, $table_rows, array( 'font_size' => 8.5 ) );
                                if ( ! is_wp_error( $content ) && strlen( (string) $content ) > 500 ) {
                                        return array(
                                                'filename' => 'tpp-report-' . gmdate( 'Ymd-His' ) . '.pdf',
                                                'content'  => $content,
                                        );
                                }
                        } catch ( Throwable $e ) {
                                // ادامه با موتور داخلی
                        }
                }

                // ۲) موتور داخلی TPP_PDF (جایگزین)
                if ( ! class_exists( 'TPP_PDF' ) ) {
                        return new WP_Error( 'tpp_no_pdf', 'موتور PDF افزونه در دسترس نیست.' );
                }
                $pdf = new TPP_PDF( count( $headers ) > 8 ? 'L' : 'P' );
                $pdf->set_title( $title . ' — ' . get_bloginfo( 'name' ), $meta );
                $pdf->set_footer( 'تولیدشده توسط افزونه TPP Services' );
                $pdf->table( $headers, $table_rows, array( 'font_size' => 8.5 ) );

                return array(
                        'filename' => 'tpp-report-' . gmdate( 'Ymd-His' ) . '.pdf',
                        'content'  => $pdf->output(),
                );
        }

        /**
         * خروجی HTML برای چاپ مرورگر (روش دوم خروجی PDF):
         * صفحه‌ای راست‌چین با استایل چاپ — کاربر از منوی چاپ مرورگر «Save as PDF» می‌زند.
         * فونت فارسی از خود سیستم/مرورگر خوانده می‌شود؛ نیازی به فونت سرور نیست.
         */
        public function print_html( $args, $user_id, $title = 'گزارش سرویس‌ها' ) {
                $visible = TPP_Capabilities::visible_fields( $user_id );
                $cols    = $this->columns( $visible );
                $rows    = $this->collect_rows( $args, $user_id );
                $user    = get_userdata( $user_id );

                $head_cells = '<th>شناسه</th>';
                foreach ( $cols as $c ) {
                        $head_cells .= '<th>' . esc_html( $c['label'] ) . '</th>';
                }
                $head_cells .= '<th>وضعیت دایری</th><th>آخرین مرحله دایری</th><th>خرابی اعلام‌شده</th><th>آخرین بروزرسانی</th>';

                $body_rows = '';
                $i = 0;
                foreach ( $rows as $row ) {
                        $cls = ( 0 === $i++ % 2 ) ? ' class="alt"' : '';
                        $body_rows .= '<tr' . $cls . '><td class="num">' . esc_html( (string) $row['id'] ) . '</td>';
                        foreach ( $cols as $c ) {
                                $v = 'address' === $c['group'] ? (string) ( $row['address'][ $c['slug'] ] ?? '' ) : self::fmt_value( $c['slug'], $row[ $c['slug'] ] ?? '' );
                                $body_rows .= '<td>' . esc_html( $v ) . '</td>';
                        }
                        // ۱.۱۳.۰ — چاپ: ۳ سلول فشرده (وضعیت شامل تعداد ردشده)
                        foreach ( array_slice( self::progress_cells( $row ), 0, 3 ) as $cell ) {
                                $body_rows .= '<td>' . esc_html( $cell ) . '</td>';
                        }
                        $body_rows .= '<td class="num">' . esc_html( TPP_Date::jalali( $row['updated_at'] ) ) . '</td></tr>';
                }
                if ( '' === $body_rows ) {
                        $body_rows = '<tr><td colspan="' . ( count( $cols ) + 5 ) . '" style="text-align:center;padding:30px">سرویسی یافت نشد</td></tr>';
                }

                $meta_lines = array(
                        'تاریخ گزارش: ' . esc_html( TPP_Date::jalali_now( true ) ),
                        'تعداد: ' . esc_html( number_format( count( $rows ) ) ) . ' سرویس',
                        'تهیه‌کننده: ' . esc_html( $user ? $user->display_name : '' ),
                );
                if ( ! empty( $args['query'] ) ) {
                        $meta_lines[] = 'جستجو: ' . esc_html( $args['query'] );
                }
                if ( self::range_label( $args ) ) {
                        $meta_lines[] = esc_html( self::range_label( $args ) );
                }
                if ( self::progress_label( $args ) ) {
                        $meta_lines[] = esc_html( self::progress_label( $args ) );
                }

                $site = esc_html( get_bloginfo( 'name' ) );
                $title_h = esc_html( $title ) . ' — ' . $site;

                $html = '<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $title_h . '</title>
<style>
        * { box-sizing: border-box; }
        body { font-family: Tahoma, "Segoe UI", Vazirmatn, "Iranian Sans", sans-serif; direction: rtl; margin: 0; padding: 18px; color: #1e242c; background: #fff; }
        .head { text-align: center; border-bottom: 3px solid #1f4e79; padding-bottom: 10px; margin-bottom: 12px; }
        .head h1 { font-size: 17px; margin: 0 0 6px; color: #1f4e79; }
        .head .meta { font-size: 11px; color: #5a6472; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        th, td { border: 1px solid #c9d1da; padding: 4px 6px; text-align: right; vertical-align: top; }
        td { white-space: pre-line; } /* ۱.۹.۳: مقادیر چندخطی در چند سطر چاپ می‌شوند */
        thead th { background: #1f4e79; color: #fff; text-align: center; position: sticky; top: 0; }
        tbody tr.alt td { background: #f3f6fa; }
        td.num { direction: ltr; text-align: center; white-space: nowrap; }
        .foot { margin-top: 14px; font-size: 10px; color: #7a8490; text-align: center; }
        .toolbar { text-align: center; padding: 10px 0 16px; }
        .toolbar button { font-family: inherit; font-size: 14px; padding: 9px 26px; background: #1f4e79; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
        @media print {
                .toolbar { display: none !important; }
                body { padding: 0; }
                table { font-size: 9.5px; }
                thead th { position: static; }
                thead { display: table-header-group; }
                tr { page-break-inside: avoid; }
        }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨 چاپ / ذخیره به‌عنوان PDF</button></div>
<div class="head">
        <h1>' . $title_h . '</h1>
        <div class="meta">' . implode( ' &nbsp;|&nbsp; ', $meta_lines ) . '</div>
</div>
<table>
        <thead><tr>' . $head_cells . '</tr></thead>
        <tbody>' . $body_rows . '</tbody>
</table>
<div class="foot">تولیدشده توسط افزونه TPP Services — ' . esc_html( home_url( '/' ) ) . '</div>
<script>setTimeout(function(){ try{ window.print(); }catch(e){} }, 600);</script>
</body>
</html>';
                return $html;
        }

        /* ==================== پشتیبان JSON — فرمت ۲ (۱.۱۰.۰) ==================== */

        /**
         * پشتیبان کامل JSON — قابل بازیابی روی هر سرور/دامنه/پیشوند دیگر.
         * $flags: with_history (پیش‌فرض ۱)، with_activity (گزارش بازدید/جستجو — پیش‌فرض ۰)، with_sms_log (پیش‌فرض ۰)
         * $extra (۱.۱۵.۰): کلیدهای اضافی برای پشتیبان‌های ذخیره‌شده روی سرور (زمینه/کاربر/شمارش) — بازیابی نادیده می‌گیرد
         */
        public function backup( $flags = array(), $extra = array() ) {
                $with_history  = ! array_key_exists( 'with_history', $flags ) || ! empty( $flags['with_history'] );
                $with_activity = ! empty( $flags['with_activity'] );
                $with_sms_log  = ! empty( $flags['with_sms_log'] );

                $data = array(
                        'plugin'      => 'tpp-services',
                        'fmt'         => 2,
                        'version'     => TPP_VERSION,
                        'exported_at' => TPP_Date::now(),
                        'site'        => function_exists( 'home_url' ) ? home_url() : get_bloginfo( 'url' ),
                        'settings'    => tpp()->settings()->all(),
                        'role_caps'   => get_option( TPP_Capabilities::CAPS_OPTION, array() ),
                        'fields'      => TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'fields' ) ),
                        'addresses'   => TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'addresses' ) ),
                        'services'    => TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'services' ) ),
                        'categories'  => TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'categories' ) . " ORDER BY id ASC" ), // ۱.۱۹.۰ — دسته‌بندی/تگ‌ها
                        'sms_templates' => TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'sms_templates' ) ),
                        'work_reports' => TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'work_reports' ) . " ORDER BY id ASC" ), // ۱.۱۸.۰ — گزارش‌های کار هم جزو پشتیبان کامل
                );
                if ( is_array( $extra ) ) {
                        $data = array_merge( $data, $extra );
                }
                if ( $with_history ) {
                        $data['history'] = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'history' ) . " ORDER BY id ASC" );
                }
                if ( $with_activity ) {
                        $data['view_log']   = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'view_log' ) . " ORDER BY id ASC" );
                        $data['search_log'] = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'search_log' ) . " ORDER BY id ASC" );
                }
                if ( $with_sms_log ) {
                        $data['sms_log'] = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'sms_log' ) . " ORDER BY id ASC" );
                }
                return wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        }

        /** اطلاعات پشتیبان برای نمایش قبل از عملیات (تعداد رکورد/حجم تقریبی/محدودیت‌های PHP) */
        public function backup_info() {
                $tables = array();
                $sizes  = array();
                $db     = TPP_DB::db();
                foreach ( TPP_DB::TABLES as $t ) {
                        $table = TPP_DB::table( $t );
                        if ( ! $table ) {
                                continue;
                        }
                        $tables[ $t ] = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM `{$table}`" );
                        $sizes[ $t ]  = 0;
                        $sz = TPP_DB::get_var(
                                "SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                                array( $table )
                        );
                        if ( is_numeric( $sz ) ) {
                                $sizes[ $t ] = (int) $sz;
                        }
                }
                $logs_bytes = (int) $sizes['view_log'] + (int) $sizes['search_log'] + (int) $sizes['sms_log'];
                $total      = array_sum( $sizes );
                $mb = static function ( $bytes ) {
                        return round( $bytes / 1048576, 1 );
                };
                return array(
                        'tables' => $tables,
                        'estimate_mb' => array(
                                'default'  => $mb( max( 0, $total - $logs_bytes ) * 1.1 ), // بدون گزارش‌ها
                                'full'     => $mb( $total * 1.1 ), // با همه بخش‌ها
                                'sql'      => $mb( $total * 1.4 ),
                        ),
                        'php' => array(
                                'version'            => PHP_VERSION,
                                'upload_max_filesize'=> (string) ini_get( 'upload_max_filesize' ),
                                'post_max_size'      => (string) ini_get( 'post_max_size' ),
                                'memory_limit'       => (string) ini_get( 'memory_limit' ),
                                'max_execution_time' => (string) ini_get( 'max_execution_time' ),
                        ),
                        'chunk_size'    => 300000, // اندازه تکه در آپلود تکه‌ای (کاراکتر)
                        'chunked'       => true,
                        'external_db'   => TPP_DB::is_external(),
                );
        }

        /* ==================== ابزارهای بازیابی ==================== */

        /** ستون‌های واقعی یک جدول (کش‌شده) */
        private function table_columns( $t ) {
                static $cache = array();
                if ( isset( $cache[ $t ] ) ) {
                        return $cache[ $t ];
                }
                $table = TPP_DB::table( $t );
                if ( ! $table ) {
                        return $cache[ $t ] = array();
                }
                $rows = TPP_DB::db()->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
                $cols = array();
                foreach ( (array) $rows as $r ) {
                        $name = isset( $r['Field'] ) ? $r['Field'] : ( isset( $r['name'] ) ? $r['name'] : '' ); // MySQL / سازگاری تست
                        if ( $name && preg_match( '/^[A-Za-z0-9_]+$/', (string) $name ) ) {
                                $cols[] = $name;
                        }
                }
                return $cache[ $t ] = $cols;
        }

        /**
         * بازسازی ستون‌های داینامیک مطابق فیلدهای پشتیبان — بر اساس ستون‌های واقعی جدول مقصد.
         * خروجی: تعداد ستون افزوده‌شده.
         */
        private function ensure_backup_columns( $fields ) {
                $added = 0;
                $cache = array();
                foreach ( (array) $fields as $f ) {
                        $slug = isset( $f['slug'] ) ? (string) $f['slug'] : '';
                        if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $slug ) ) {
                                continue; // نام‌کد نامعتبر از فایل پشتیبان
                        }
                        $t = ( 'address' === ( $f['group_key'] ?? '' ) ) ? 'addresses' : 'services';
                        if ( ! isset( $cache[ $t ] ) ) {
                                $cache[ $t ] = $this->table_columns( $t );
                        }
                        if ( in_array( $slug, $cache[ $t ], true ) ) {
                                continue;
                        }
                        $column = ( 'textarea' === ( $f['field_type'] ?? '' ) ) ? 'TEXT NULL' : 'VARCHAR(500) NULL DEFAULT NULL';
                        $table  = TPP_DB::table( $t );
                        TPP_DB::query( "ALTER TABLE `{$table}` ADD COLUMN `{$slug}` {$column}" );
                        $cache[ $t ][] = $slug;
                        $added++;
                }
                return $added;
        }

        /** درج یک دسته ردیف — خروجی false در خطا */
        private function insert_batch( $t, $rows ) {
                if ( empty( $rows ) ) {
                        return 0;
                }
                $db    = TPP_DB::db();
                $table = TPP_DB::table( $t );
                $cols  = array();
                foreach ( $rows as $r ) {
                        foreach ( array_keys( $r ) as $k ) {
                                if ( preg_match( '/^[A-Za-z0-9_]+$/', (string) $k ) ) {
                                        $cols[ $k ] = true;
                                }
                        }
                }
                $cols = array_keys( $cols );
                if ( empty( $cols ) ) {
                        return 0;
                }
                $sql  = "INSERT INTO `{$table}` (`" . implode( '`, `', $cols ) . "`) VALUES ";
                $vals = array();
                foreach ( $rows as $r ) {
                        $vv = array();
                        foreach ( $cols as $c ) {
                                $vv[] = array_key_exists( $c, $r ) ? $this->sql_val( $r[ $c ] ) : 'NULL';
                        }
                        $vals[] = '(' . implode( ', ', $vv ) . ')';
                }
                $sql .= implode( ",\n", $vals );
                $res  = $db->query( $sql );
                if ( false === $res ) {
                        return false;
                }
                return (int) $res;
        }

        /**
         * بازیابی از پشتیبان JSON (فرمت ۱ و ۲) — بازنویسی کامل ۱.۱۰.۰:
         *  - درج دسته‌ای (ده‌ها برابر سریع‌تر از درج ردیف‌به‌ردیف → فایل‌های بزرگ بدون تایم‌اوت)
         *  - تطبیق ستون‌ها با ساختار جدول مقصد (ستون ناشناخته رد می‌شود؛ دیگر خطای خاموش نداریم)
         *  - گزارش شمارش/خطا/هشدار برای هر جدول
         *  - مستقل از سرور/دامنه/پیشوند مبدأ
         */
        public function restore( $json ) {
                $start = microtime( true );
                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- بازیابی فایل بزرگ ممکن است طول بکشد
                }
                if ( function_exists( 'wp_raise_memory_limit' ) ) {
                        wp_raise_memory_limit( 'admin' );
                }
                $data = json_decode( (string) $json, true );
                if ( ! is_array( $data ) ) {
                        $why = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '';
                        return new WP_Error( 'tpp_bad_json', 'محتوای فایل JSON قابل خواندن نیست' . ( $why ? ' (' . $why . ')' : '' ) . ' — فایل پشتیبان کامل دانلود شده است؟' );
                }
                if ( empty( $data['fields'] ) || ! isset( $data['services'] ) ) {
                        return new WP_Error( 'tpp_bad_backup', 'فایل پشتیبان معتبر نیست (فهرست فیلدها/سرویس‌ها در آن نیست) — فایل پشتیبان JSON افزونه (data.json یا tpp-backup-*.json) را انتخاب کنید.' );
                }
                if ( ! empty( $data['plugin'] ) && 'tpp-services' !== (string) $data['plugin'] ) {
                        return new WP_Error( 'tpp_bad_backup', 'این فایل پشتیبان متعلق به افزونه TPP Services نیست.' );
                }

                $warnings = array();
                $errors   = array();
                $tables   = array();

                // ۱) ستون‌های داینامیک پشتیبان باید روی مقصد وجود داشته باشند
                $added_cols = $this->ensure_backup_columns( (array) $data['fields'] );
                // ۱.۱۲.۰ — ستون‌های پیشرفت دایری هم اگر در مقصد نباشند ساخته می‌شوند
                TPP_Progress::ensure_columns();
                // ۱.۱۹.۰ — ستون‌های دسته‌بندی/تگ + جدول categories
                TPP_Categories::ensure_table();
                TPP_Categories::ensure_columns();
                if ( $added_cols > 0 ) {
                        $warnings[] = $added_cols . ' ستون فیلد داینامیکِ پشتیبان به جداول مقصد اضافه شد.';
                }

                // ۲) جداول داده‌ای قابل بازیابی (به‌ترتیب وابستگی)
                $order = array( 'fields', 'addresses', 'categories', 'services', 'history', 'view_log', 'search_log', 'sms_templates', 'sms_log', 'work_reports' ); // ۱.۱۹.۰ — categories قبل از services
                $present = array();
                foreach ( $order as $t ) {
                        if ( isset( $data[ $t ] ) && is_array( $data[ $t ] ) && null !== TPP_DB::table( $t ) ) {
                                $present[] = $t;
                        }
                }
                if ( ! in_array( 'history', $present, true ) ) {
                        $warnings[] = 'در فایل پشتیبان «تاریخچه تغییرات» نبود — تاریخچه فعلی هم پاک می‌شود (پشتیبان بدون تاریخچه ساخته شده).';
                }

                TPP_DB::query( "SET FOREIGN_KEY_CHECKS = 0" );
                foreach ( $present as $t ) {
                        TPP_DB::query( "TRUNCATE TABLE " . TPP_DB::table( $t ) );
                }

                // ۳) درج دسته‌ای با ایزوله‌سازی خطا (در خطا → ردیف‌به‌ردیف برای یافتن ردیف مشکل‌دار)
                $db = TPP_DB::db();
                foreach ( $present as $t ) {
                        $cols = $this->table_columns( $t );
                        if ( empty( $cols ) ) {
                                $errors[] = 'جدول «' . $t . '» روی این نصب در دسترس نیست — رد شد.';
                                continue;
                        }
                        $rows = array();
                        $skipped = 0;
                        foreach ( (array) $data[ $t ] as $row ) {
                                if ( ! is_array( $row ) ) {
                                        $skipped++;
                                        continue;
                                }
                                $clean = array();
                                foreach ( $row as $k => $v ) {
                                        if ( in_array( (string) $k, $cols, true ) && ( is_scalar( $v ) || null === $v ) ) {
                                                $clean[ (string) $k ] = $v;
                                        }
                                }
                                if ( empty( $clean ) ) {
                                        $skipped++;
                                        continue;
                                }
                                $rows[] = $clean;
                        }
                        $inserted = 0;
                        $failed   = 0;
                        $batch    = 50;
                        $count    = count( $rows );
                        for ( $i = 0; $i < $count; $i += $batch ) {
                                $slice = array_slice( $rows, $i, $batch );
                                $ok = $this->insert_batch( $t, $slice );
                                if ( false !== $ok ) {
                                        $inserted += count( $slice );
                                        continue;
                                }
                                $batch = 10; // کاهش اندازه دسته بعد از خطا
                                foreach ( $slice as $r ) {
                                        if ( false === $this->insert_batch( $t, array( $r ) ) ) {
                                                $failed++;
                                                if ( count( $errors ) < 10 ) {
                                                        $errors[] = 'جدول «' . $t . '»: یک ردیف درج نشد' . ( ! empty( $db->last_error ) ? ' — ' . sanitize_text_field( (string) $db->last_error ) : '' ) . '.';
                                                }
                                        } else {
                                                $inserted++;
                                        }
                                }
                        }
                        $actual = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( $t ) );
                        $tables[ $t ] = array(
                                'inserted' => $inserted,
                                'actual'   => $actual,
                                'skipped'  => $skipped,
                                'failed'   => $failed,
                        );
                }
                TPP_DB::query( "SET FOREIGN_KEY_CHECKS = 1" );

                // ۴) تنظیمات و نقش‌ها
                $settings_restored = false;
                if ( isset( $data['role_caps'] ) && is_array( $data['role_caps'] ) ) {
                        update_option( TPP_Capabilities::CAPS_OPTION, $data['role_caps'], false );
                }
                if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
                        tpp()->settings()->update( $data['settings'] );
                        $settings_restored = true;
                        $warnings[] = 'تنظیمات افزونه با مقدارهای پشتیبان جایگزین شد (نشست‌ها/توکن‌ها منتقل نمی‌شوند — کاربران با رمز خودشان وارد می‌شوند).';
                }
                TPP_Fields::flush_cache();
                TPP_Fields::apply_canonical_order_v180();
                do_action( 'tpp_backup_restored' );

                $elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );
                return array(
                        'status' => 'restored',
                        'summary' => array(
                                'elapsed_ms' => $elapsed,
                                'tables'     => $tables,
                                'warnings'   => $warnings,
                                'errors'     => $errors,
                                'settings_restored' => $settings_restored,
                                'backup_from' => isset( $data['site'] ) ? (string) $data['site'] : ( isset( $data['version'] ) ? 'نسخه ' . (string) $data['version'] : '' ),
                                'backup_at'   => isset( $data['exported_at'] ) ? (string) $data['exported_at'] : '',
                        ),
                );
        }

        /* ==================== پشتیبان SQL قابل انتقال (۱.۱۰.۰) ==================== */

        /** فرار مقدار SQL با احترام به اتصال فعال (درج مستقیم روی مقصد) */
        private function sql_val( $v ) {
                if ( null === $v ) {
                        return 'NULL';
                }
                if ( is_int( $v ) || is_float( $v ) ) {
                        return (string) $v;
                }
                $db = TPP_DB::db();
                if ( $db instanceof wpdb && method_exists( $db, '_real_escape' ) ) {
                        return "'" . $db->_real_escape( (string) $v ) . "'";
                }
                return "'" . addslashes( (string) $v ) . "'";
        }

        /** فرار مقدار برای رشته داخل PREPARE — مستقل از sql_mode (فقط دوبرابر‌کردن کوتیشن) */
        private function sql_lit( $v ) {
                if ( null === $v ) {
                        return 'NULL';
                }
                if ( is_int( $v ) || is_float( $v ) ) {
                        return (string) $v;
                }
                $s = (string) $v;
                $s = str_replace( "\0", '', $s );
                $s = str_replace( array( '\\', "'" ), array( '\\\\', "''" ), $s );
                return "'" . $s . "'";
        }

        /**
         * تبدیل یک دستور به فرم قابل‌حمل: پیشوند جدول‌های وردپرس مقصد جایگزین {{PFX}} می‌شود
         * (PREPARE/EXECUTE — سازگار با phpMyAdmin). در دیتابیس جداگانه دستور مستقیم می‌ماند.
         */
        private function portable_stmt( $stmt, $is_external ) {
                if ( $is_external ) {
                        return $stmt . "\n";
                }
                $esc = str_replace( "'", "''", (string) $stmt );
                return "SET @tpp_stmt := '" . $esc . "';\n"
                        . "SET @tpp_stmt := REPLACE(@tpp_stmt, '{{PFX}}', @tpp_pfx);\n"
                        . "PREPARE tpp_st FROM @tpp_stmt; EXECUTE tpp_st; DEALLOCATE PREPARE tpp_st;\n";
        }

        /** درج‌های یک جدول در فرم قابل‌حمل (دسته ۴۰تایی) */
        private function portable_inserts( $t, $rows, $is_external, $batch = 40 ) {
                if ( empty( $rows ) ) {
                        return '';
                }
                $name = $is_external ? TPP_DB::table( $t ) : ( '{{PFX}}tpp_' . $t );
                $sql  = '';
                $chunk = array();
                foreach ( $rows as $row ) {
                        $vals = array();
                        foreach ( $row as $v ) {
                                $vals[] = $this->sql_lit( $v );
                        }
                        $chunk[] = '(' . implode( ', ', $vals ) . ')';
                        if ( count( $chunk ) >= $batch ) {
                                $sql .= $this->portable_stmt( "INSERT INTO `{$name}` VALUES " . implode( ",\n", $chunk ) . ";", $is_external );
                                $chunk = array();
                        }
                }
                if ( $chunk ) {
                        $sql .= $this->portable_stmt( "INSERT INTO `{$name}` VALUES " . implode( ",\n", $chunk ) . ";", $is_external );
                }
                return $sql;
        }

        /**
         * پشتیبان کامل SQL — قابل درج مستقیم در phpMyAdmin روی هر سرور:
         *  پیشوند جداول وردپرس مقصد به‌صورت خودکار تشخیص و جایگزین می‌شود ({{PFX}} + PREPARE).
         *  توکن‌های نشست عمداً شامل نمی‌شوند (شناسه کاربران روی سرور مقصد متفاوت است؛ ورود مجدد لازم است).
         */
        public function sql_dump() {
                $db          = TPP_DB::db();
                $is_external = TPP_DB::is_external();
                $site        = function_exists( 'home_url' ) ? home_url() : get_bloginfo( 'url' );

                $sql  = "-- TPP Services — پشتیبان کامل دیتابیس افزونه (قابل انتقال به هر سرور/پیشوند)\n";
                $sql .= "-- نسخه: " . TPP_VERSION . " | تاریخ: " . TPP_Date::now() . " | سایت مبدأ: {$site}\n";
                $sql .= "-- بازیابی: افزونه را روی سایت مقصد نصب و فعال کنید، سپس این فایل را در phpMyAdmin (تب Import) اجرا کنید.\n";
                $sql .= "-- پیشوند جداول مقصد به‌صورت خودکار تشخیص می‌شود — نیازی به ویرایش دستی نیست.\n";
                $sql .= "-- توکن‌های نشست منتقل نمی‌شوند؛ کاربران روی سایت مقصد با رمز خودشان وارد می‌شوند.\n";
                $sql .= "SET NAMES utf8mb4;\n";
                $sql .= "SET SESSION foreign_key_checks = 0;\n";
                $sql .= "SET SESSION unique_checks = 0;\n\n";

                if ( ! $is_external ) {
                        $sql .= "-- کشف خودکار پیشوند جداول وردپرس مقصد (بر اساس جدول‌های افزونه؛ در نبود آن‌ها، جدول usermeta)\n";
                        $sql .= "SET @tpp_pfx := (SELECT SUBSTRING_INDEX(TABLE_NAME, 'tpp_fields', 1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%tpp_fields' ORDER BY TABLE_NAME LIMIT 1);\n";
                        $sql .= "SET @tpp_pfx := IFNULL(@tpp_pfx, (SELECT SUBSTRING_INDEX(TABLE_NAME, 'usermeta', 1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%usermeta' ORDER BY TABLE_NAME LIMIT 1));\n";
                        $sql .= "SET @tpp_pfx := IFNULL(@tpp_pfx, '');\n";
                        $sql .= "-- برای تعیین دستی پیشوند، خط زیر را از کامنت خارج کنید و مقدارش را اصلاح کنید:\n";
                        $sql .= "-- SET @tpp_pfx := 'wp_';\n\n";
                }

                $tables = array( 'fields', 'addresses', 'services', 'history', 'view_log', 'search_log', 'op_log', 'sync_log', 'field_archives', 'sms_templates', 'sms_log', 'work_reports' ); // ۱.۱۸.۰ — work_reports
                foreach ( $tables as $t ) {
                        $full = TPP_DB::table( $t );
                        if ( ! $full ) {
                                continue;
                        }
                        $name = $is_external ? $full : ( '{{PFX}}tpp_' . $t );
                        $create = $db->get_row( "SHOW CREATE TABLE `{$full}`", ARRAY_N );
                        $sql .= $this->portable_stmt( "DROP TABLE IF EXISTS `{$name}`;", $is_external );
                        if ( $create ) {
                                $sql .= $this->portable_stmt( $create[1] . ';', $is_external );
                        }
                        $rows = TPP_DB::get_results( "SELECT * FROM `{$full}`" );
                        $sql .= $this->portable_inserts( $t, is_array( $rows ) ? $rows : array(), $is_external );
                        $sql .= "\n";
                }

                // تنظیمات و دسترسی نقش‌ها (بدون توکن‌ها — قابل انتقال)
                if ( ! $is_external ) {
                        global $wpdb;
                        $opts = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'tpp_%' AND option_name <> 'tpp_token_index'", ARRAY_A );
                        if ( $opts ) {
                                $sql .= "\n-- تنظیمات افزونه و دسترسی نقش‌ها\n";
                                $chunk = array();
                                foreach ( $opts as $o ) {
                                        $chunk[] = '(' . $this->sql_lit( $o['option_name'] ) . ', ' . $this->sql_lit( $o['option_value'] ) . ', ' . $this->sql_lit( $o['autoload'] ) . ')';
                                }
                                $sql .= $this->portable_stmt( "DELETE FROM `{{PFX}}options` WHERE option_name LIKE 'tpp_%';", false );
                                $sql .= $this->portable_stmt( "INSERT INTO `{{PFX}}options` (option_name, option_value, autoload) VALUES\n" . implode( ",\n", $chunk ) . ";", false );
                        }
                        $sql .= "\n-- توکن‌های نشست عمداً منتقل نمی‌شوند (شناسه کاربران روی سرور مقصد فرق دارد)\n";
                }

                $sql .= "\nSET SESSION foreign_key_checks = 1;\n";
                return $sql;
        }

        /* ==================== پشتیبان ZIP کامل ==================== */

        /**
         * پشتیبان کامل ZIP — همه‌چیز در یک فایل:
         *  database.sql (قابل انتقال) / data.json (بازیابی از داخل افزونه) / plugin/ / RESTORE-FA.txt / manifest.json
         */
        public function backup_zip() {
                if ( ! class_exists( 'TPP_Zip' ) ) {
                        return new WP_Error( 'tpp_no_zip', 'موتور ZIP افزونه در دسترس نیست.' );
                }
                $zip = new TPP_Zip();

                // ۱) SQL کامل قابل انتقال
                $zip->add_file( 'database.sql', $this->sql_dump() );

                // ۲) JSON کامل قابل بازیابی از داخل افزونه (همه بخش‌ها — آرشیو کامل)
                $zip->add_file( 'data.json', $this->backup( array( 'with_history' => 1, 'with_activity' => 1, 'with_sms_log' => 1 ) ) );

                // ۳) کل فایل‌های افزونه
                $plugin_files = 0;
                $dir = TPP_PLUGIN_DIR;
                $stack = array( $dir );
                while ( $stack ) {
                        $cur = array_pop( $stack );
                        foreach ( (array) scandir( $cur ) as $entry ) {
                                if ( '.' === $entry || '..' === $entry ) {
                                        continue;
                                }
                                // ۱.۱۵.۰ — پشتیبان‌های ذخیره‌شده داخل بسته ZIP نمی‌روند (جلوگیری از تودرتویی/حجم چندبرابری)
                                if ( class_exists( 'TPP_Backup' ) && TPP_Backup::DIR_NAME === $entry && rtrim( $cur, '/\\' ) === rtrim( TPP_PLUGIN_DIR, '/\\' ) ) {
                                        continue;
                                }
                                $full = $cur . '/' . $entry;
                                $rel  = 'plugin/' . ltrim( substr( $full, strlen( $dir ) ), '/' );
                                if ( is_dir( $full ) ) {
                                        $stack[] = $full;
                                        continue;
                                }
                                if ( $zip->add_path( $rel, $full, false ) ) {
                                        $plugin_files++;
                                }
                        }
                }

                // ۴) راهنمای بازیابی
                $counts = array(
                        'services' => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'services' ) ),
                        'history'  => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'history' ) ),
                );
                $readme  = "راهنمای بازیابی پشتیبان TPP Services\n";
                $readme .= "=====================================\n\n";
                $readme .= "تاریخ تولید: " . TPP_Date::jalali_now( true ) . "\n"; // ۱.۱۸.۰ — شمسی/تهران
                $readme .= "نسخه افزونه: " . TPP_VERSION . "\n";
                $readme .= "سایت مبدأ: " . ( function_exists( 'home_url' ) ? home_url() : get_bloginfo( 'url' ) ) . "\n";
                $readme .= "محتوا: " . $counts['services'] . " سرویس، " . $counts['history'] . " رکورد تاریخچه\n\n";
                $readme .= "محتویات:\n";
                $readme .= "  database.sql  — پشتیبان کامل دیتابیس (جداول داده + تاریخچه + قالب‌های پیامک + تنظیمات)\n";
                $readme .= "  data.json     — پشتیبان داده — قابل بازیابی از داخل خود افزونه\n";
                $readme .= "  plugin/       — کل فایل‌های افزونه (نسخه " . TPP_VERSION . ")\n\n";
                $readme .= "روش بازیابی روی هر سرور/دامنه دیگر (به ترتیب اولویت):\n\n";
                $readme .= "  روش ۱ — از داخل افزونه (پیشنهادی، ساده‌ترین):\n";
                $readme .= "    ۱) افزونه TPP Services را روی سایت مقصد نصب و فعال کنید.\n";
                $readme .= "    ۲) کاربران را بسازید و نقش‌هایشان را تنظیم کنید (نقش‌ها با بازیابی منتقل می‌شوند، انتساب نقش‌ها نه).\n";
                $readme .= "    ۳) در اپ افزونه: «خروجی و پشتیبان» ← بخش «بازیابی از پشتیبان» ← فایل data.json را انتخاب و بازیابی کنید.\n";
                $readme .= "       آپلود به‌صورت خودکار تکه‌تکه انجام می‌شود — محدودیت حجم آپلود سرور مشکلی ایجاد نمی‌کند.\n";
                $readme .= "       در پایان، گزارش دقیق تعداد رکورد بازیابی‌شده هر جدول نمایش داده می‌شود.\n\n";
                $readme .= "  روش ۲ — با phpMyAdmin:\n";
                $readme .= "    ۱) افزونه را روی سایت مقصد نصب و فعال کنید (تا جداول با پیشوند سایت مقصد ساخته شوند).\n";
                $readme .= "    ۲) فایل database.sql را در تب Import اجرا کنید.\n";
                $readme .= "       پیشوند جداول مقصد به‌صورت خودکار تشخیص می‌شود — نیازی به ویرایش فایل نیست.\n";
                $readme .= "       اگر افزونه هنوز نصب نشده، پیشوند از جدول usermeta حدس زده می‌شود؛ برای اطمینان خط\n";
                $readme .= "       «SET @tpp_pfx := 'wp_';» را در ابتدای فایل از کامنت خارج کنید و پیشوند واقعی را بنویسید.\n\n";
                $readme .= "  روش ۳ — بازگردانی کامل فایل‌ها: پوشه plugin/ را جایگزین wp-content/plugins/tpp-services کنید.\n\n";
                $readme .= "نکته‌ها:\n";
                $readme .= "  • توکن‌های نشست و رمزهای کاربران منتقل نمی‌شوند — کاربران روی سایت مقصد با همان نام کاربری و رمز خودشان وارد می‌شوند.\n";
                $readme .= "  • تاریخچه بازدید/جستجو فقط وقتی در پشتیبان باشد بازیابی می‌شود (در پشتیبان ZIP کامل هست).\n";
                $readme .= "  • این فایل شامل داده‌های حساس (رمز سرویس‌ها) است؛ در محل امن نگه دارید.\n";
                $zip->add_file( 'RESTORE-FA.txt', $readme );

                // ۵) مانیفست
                $manifest = wp_json_encode( array(
                        'plugin'      => 'tpp-services',
                        'fmt'         => 2,
                        'version'     => TPP_VERSION,
                        'created_at'  => TPP_Date::now(),
                        'site'        => function_exists( 'home_url' ) ? home_url() : get_bloginfo( 'url' ),
                        'counts'      => $counts,
                        'plugin_files'=> $plugin_files,
                ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
                $zip->add_file( 'manifest.json', $manifest );

                $content = $zip->build();
                if ( strlen( $content ) < 200 ) {
                        return new WP_Error( 'tpp_zip_failed', 'ایجاد فایل پشتیبان ZIP ناموفق بود.' );
                }
                return array(
                        'filename' => 'tpp-backup-' . gmdate( 'Ymd-His' ) . '.zip',
                        'content'  => $content,
                        'files'    => $plugin_files,
                );
        }
}
