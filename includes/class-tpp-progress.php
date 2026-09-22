<?php
/**
 * پیشرفت دایری سرویس (۱.۱۲.۰) — چرخه راه‌اندازی اینترنت/تلفن + اعلام خرابی.
 *
 * ۱۶ مرحله دایری به‌ترتیب کانونی + ۴ وضعیت خرابی (LOS / قطع تلفن / قطع اینترنت / سایر).
 * ذخیره‌سازی روی جدول services:
 *   progress_steps    LONGTEXT      — JSON آرایه کلیدهای مراحل انجام‌شده ["fat","drop",…]
 *   progress_done     INT UNSIGNED  — تعداد مراحل انجام‌شده (برای فیلتر سریع و قابل‌حمل SQL)
 *   progress_failure  VARCHAR(20)   — '' یا los|phone|internet|other
 *   progress_updated_at DATETIME    — زمان آخرین تغییر پیشرفت
 *   progress_excluded LONGTEXT      — ۱.۱۳.۰ — JSON مراحل «ردشده توسط کاربر» (cascade از آن‌ها می‌پرد)
 *
 * منطق آبشاری (۱.۱۳.۰): مراحل وابسته به هم‌اند — تیک خوردن مرحله N یعنی همه مراحل قبل از N
 * هم انجام شده و خودکار تیک می‌خورند؛ «مگر آنکه بعد از تیک‌خوردن، تیک آن مرحله توسط کاربر
 * برداشته شده باشد» — چنین مرحله‌ای در progress_excluded ثبت می‌شود تا پرش خودکار نکند.
 *
 * تغییرات پیشرفت در تاریخچه سرویس با کلیدهای `_progress_steps` / `_progress_failure`
 * ثبت می‌شود تا در گزارش فعالیت (چه کسی چه سرویسی را کجا رساند) دیده شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Progress {

        /** ستون‌های پیشرفت روی جدول services => تعریف DDL */
        const COLUMNS = array(
                'progress_steps'     => "LONGTEXT NULL",
                'progress_done'      => "INT UNSIGNED NOT NULL DEFAULT 0",
                'progress_failure'   => "VARCHAR(20) NOT NULL DEFAULT ''",
                'progress_failures'  => "LONGTEXT NULL", // ۱.۱۴.۰ — JSON آرایه خرابی‌های چندتایی
                'progress_updated_at'=> "DATETIME NULL",
                'progress_excluded'  => "LONGTEXT NULL", // ۱.۱۳.۰ — مراحل ردشده توسط کاربر
        );

        /** ۱۶ مرحله پیشرفت دایری سرویس — به‌ترتیب (کلید => برچسب) */
        public static function steps() {
                return array(
                        'infra'          => 'زیرساخت موجود است (پیوستگی دارد)',
                        'fat'            => 'FAT و اسپلیترهای آورنده آن نصب شده',
                        'drop'           => 'دراپ‌کشی انجام شده',
                        'fusion_fat'     => 'فیوژن سمت FAT انجام شده',
                        'atb'            => 'فیوژن و نصب ATB داخل واحد انجام شده',
                        'patch'          => 'پچ‌کورد دارد یا داده می‌شود',
                        'modem'          => 'مودم دارد یا داده می‌شود',
                        'registered'     => 'ثبت‌نام شده است',
                        'ready'          => 'سرویس آماده تحویل می‌باشد',
                        'sip_info'       => 'اطلاعات مربوط به سیپ‌فون از مرکز دریافت شده یا در یارا موجود می‌باشد',
                        'inet_config'    => 'تنظیمات اینترنت انجام شده است',
                        'inet_omc'       => 'اینترنت جهت دایری به OMC ارسال شده است یا دکمه ZTP زده شده است',
                        'inet_connected' => 'اینترنت متصل می‌باشد',
                        'sip_config'     => 'تنظیمات سیپ‌فون انجام شده است',
                        'sip_omc'        => 'اطلاعات سیپ‌فون جهت دایری به OMC ارسال شده است یا دکمه ZTP زده شده است',
                        'phone_connected'=> 'تلفن متصل می‌باشد',
                );
        }

        /** وضعیت‌های اعلام خرابی مشترک — کلید => برچسب */
        public static function failures() {
                return array(
                        'los'      => 'مشترک LOS می‌باشد (اینترنت و تلفن قطع می‌باشد)',
                        'phone'    => 'تلفن مشترک قطع می‌باشد',
                        'internet' => 'اینترنت مشترک قطع می‌باشد',
                        'other'    => 'سایر (شرح در توضیحات)',
                );
        }

        /** کلیدهای مرحله معتبر */
        public static function step_keys() {
                return array_keys( self::steps() );
        }

        /** برچسب یک مرحله (یا خود کلید اگر نامعتبر) */
        public static function step_label( $key ) {
                $steps = self::steps();
                return isset( $steps[ $key ] ) ? $steps[ $key ] : (string) $key;
        }

        /** برچسب وضعیت خرابی (یا '' اگر بی‌اعتبار) */
        public static function failure_label( $key ) {
                $f = self::failures();
                $key = (string) $key;
                return isset( $f[ $key ] ) ? $f[ $key ] : '';
        }

        /** ۱.۱۴.۰ — برچسب‌های چند خرابی با جداکننده «،» (کلیدهای نامعتبر نادیده گرفته می‌شوند) */
        public static function failures_labels_text( $keys, $sep = '، ' ) {
                $out = array();
                foreach ( (array) $keys as $k ) {
                        $label = self::failure_label( (string) $k );
                        if ( '' !== $label ) {
                                $out[] = $label;
                        }
                }
                return implode( $sep, $out );
        }

        /** ۱.۱۴.۰ — پارس فهرست خرابی‌های ورودی (آرایه / JSON رشته / رشته با کاما) → کلیدهای معتبر به‌ترتیب کانونی */
        public static function parse_failure_list( $raw ) {
                if ( is_string( $raw ) ) {
                        $decoded = json_decode( $raw, true );
                        $raw = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                }
                if ( ! is_array( $raw ) ) {
                        return array();
                }
                $valid = array_fill_keys( array_keys( self::failures() ), false );
                foreach ( $raw as $k ) {
                        $k = sanitize_key( (string) $k );
                        if ( isset( $valid[ $k ] ) ) {
                                $valid[ $k ] = true;
                        }
                }
                return array_keys( array_filter( $valid ) );
        }

        /** ۱.۱۴.۰ — استخراج خرابی‌ها از ورودی خام: failures (آرایه/رشته) + legacy failure (رشته) */
        private static function resolve_input_failures( $input ) {
                $keys = array();
                if ( array_key_exists( 'failures', $input ) && null !== $input['failures'] && '' !== $input['failures'] ) {
                        $keys = self::parse_failure_list( $input['failures'] );
                }
                if ( empty( $keys ) && array_key_exists( 'failure', $input ) && null !== $input['failure'] ) {
                        $one = sanitize_key( (string) $input['failure'] );
                        if ( array_key_exists( $one, self::failures() ) ) {
                                $keys = array( $one );
                        }
                }
                return $keys;
        }

        /** ۱.۱۴.۰ — خرابی‌های رکورد خام: از ستون progress_failures؛ در نبودش (داده قدیمی) از progress_failure تکی */
        public static function failures_of( $row ) {
                $keys = array();
                if ( isset( $row['progress_failures'] ) ) {
                        $keys = self::parse_failure_list( (string) $row['progress_failures'] );
                }
                if ( empty( $keys ) && isset( $row['progress_failure'] ) ) {
                        $one = sanitize_key( (string) $row['progress_failure'] );
                        if ( '' !== $one && array_key_exists( $one, self::failures() ) ) {
                                $keys = array( $one );
                        }
                }
                return $keys;
        }

        /**
         * پاک‌سازی ورودی پیشرفت از کلاینت/API.
         * ورود: array('steps' => ['fat','drop',…] یا رشته با کاما, 'failures' => ['internet','other']|'los'|…, 'failure' => legacy)
         * خروجی: array('steps','steps_array','done','failures','failures_array','failure')
         * — فقط کلیدهای معتبر، بدون تکرار، به‌ترتیب کانونی.
         * ۱.۱۴.۰ — failures آرایه خرابی‌هاست؛ failure (رشته، قدیمی) هم پذیرفته و به فهرست تبدیل می‌شود.
         */
        public static function sanitize( $input ) {
                if ( is_string( $input ) ) {
                        $decoded = json_decode( $input, true );
                        $input = is_array( $decoded ) ? $decoded : array();
                }
                if ( ! is_array( $input ) ) {
                        $input = array();
                }
                $raw_steps = $input['steps'] ?? array();
                if ( is_string( $raw_steps ) ) {
                        // رشته JSON آرایه، یا فهرست با کاما — هر دو پشتیبانی می‌شوند
                        $decoded  = json_decode( $raw_steps, true );
                        $raw_steps = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw_steps ) ) );
                }
                $valid = array_fill_keys( self::step_keys(), false );
                foreach ( (array) $raw_steps as $s ) {
                        $s = sanitize_key( (string) $s );
                        if ( isset( $valid[ $s ] ) ) {
                                $valid[ $s ] = true;
                        }
                }
                // به‌ترتیب کانونی
                $steps = array_keys( array_filter( $valid ) );
                $failures = self::resolve_input_failures( $input ); // ۱.۱۴.۰ — چند خرابی همزمان
                return array(
                        'steps'         => wp_json_encode( $steps, JSON_UNESCAPED_UNICODE ),
                        'steps_array'   => $steps,
                        'done'          => count( $steps ),
                        'failures'      => wp_json_encode( $failures, JSON_UNESCAPED_UNICODE ),
                        'failures_array'=> $failures,
                        'failure'       => $failures ? $failures[0] : '',
                );
        }

        /**
         * ۱.۱۳.۰ — اعمال منطق آبشاری مراحل وابسته (منبع حقیقت سرور).
         *
         * مراحل دایری به‌ترتیب انجام‌اند: تیک خوردن مرحله N یعنی همه مراحل قبل از N انجام شده‌اند
         * و خودکار تیک می‌خورند — مگر مراحل «ردشده» که کاربر بعد از تیک‌خوردن تیکشان را برداشته
         * (progress_excluded). قاعده ردشدگی: برداشتن تیک مرحله X وقتی مرحله بعدی‌تری هنوز تیک دارد
         * → X «ردشده» می‌شود؛ اگر X آخرین مرحله تیک‌دار بود → صرفاً بدون تیک می‌شود (عقب‌گرد طبیعی).
         *
         * ورود: $input = {'steps' => […|رشته], 'skipped' => […], 'failure' => key|'', 'reset_skips' => bool}
         *       $old_row = رکورد فعلی سرویس (در ثبت جدید null)
         * خروجی: {'steps','steps_array','done','failure','excluded','excluded_array'}
         */
        public static function apply( $input, $old_row = null ) {
                // ---- پارس ورودی (همان پاک‌سازی sanitize) ----
                if ( is_string( $input ) ) {
                        $decoded = json_decode( $input, true );
                        $input = is_array( $decoded ) ? $decoded : array();
                }
                if ( ! is_array( $input ) ) {
                        $input = array();
                }
                $checked = self::parse_step_list( $input['steps'] ?? array() );
                $input_skipped = self::parse_step_list( $input['skipped'] ?? '' );
                $failures = self::resolve_input_failures( $input ); // ۱.۱۴.۰ — چند خرابی همزمان
                $reset = ! empty( $input['reset_skips'] );

                $keys       = self::step_keys();
                $old_steps  = $old_row ? self::steps_of( $old_row ) : array();
                $old_excl   = $old_row ? self::excluded_of( $old_row ) : array();

                // ---- محاسبه مجموعه ردشده جدید ----
                $excluded = array();
                if ( ! $reset ) {
                        $excluded = array_merge( $old_excl, $input_skipped );
                        // ردکردن خودکار: مرحله‌ای که تیک داشته، تیکش برداشته شده و مرحله بعدی هنوز تیک دارد
                        foreach ( array_diff( $old_steps, $checked ) as $x ) {
                                if ( self::has_step_after( $x, $checked ) ) {
                                        $excluded[] = $x;
                                }
                        }
                        // تیک دوباره توسط کاربر = رفع ردشدگی
                        $excluded = array_diff( array_unique( $excluded ), $checked );
                }

                // ---- آبشار: همه مراحل تا آخرین مرحله تیک‌دار (به‌جز ردشده‌ها) ----
                $max_idx = -1;
                foreach ( $checked as $s ) {
                        $i = array_search( $s, $keys, true );
                        if ( false !== $i && $i > $max_idx ) {
                                $max_idx = $i;
                        }
                }
                $final = array();
                for ( $i = 0; $i <= $max_idx; $i++ ) {
                        if ( ! in_array( $keys[ $i ], $excluded, true ) ) {
                                $final[] = $keys[ $i ];
                        }
                }

                $excluded = array_values( array_intersect( $keys, $excluded ) ); // به‌ترتیب کانونی
                return array(
                        'steps'         => wp_json_encode( $final, JSON_UNESCAPED_UNICODE ),
                        'steps_array'   => $final,
                        'done'          => count( $final ),
                        'failures'      => wp_json_encode( $failures, JSON_UNESCAPED_UNICODE ),
                        'failures_array'=> $failures,
                        'failure'       => $failures ? $failures[0] : '',
                        'excluded'      => wp_json_encode( $excluded, JSON_UNESCAPED_UNICODE ),
                        'excluded_array'=> $excluded,
                );
        }

        /** پارس فهرست مراحل ورودی (آرایه / JSON رشته / رشته کاما) → کلیدهای معتبر به‌ترتیب کانونی */
        private static function parse_step_list( $raw ) {
                if ( is_string( $raw ) ) {
                        $decoded = json_decode( $raw, true );
                        $raw = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                }
                if ( ! is_array( $raw ) ) {
                        return array();
                }
                $valid = array_fill_keys( self::step_keys(), false );
                foreach ( $raw as $s ) {
                        $s = sanitize_key( (string) $s );
                        if ( isset( $valid[ $s ] ) ) {
                                $valid[ $s ] = true;
                        }
                }
                return array_keys( array_filter( $valid ) );
        }

        /** آیا در $steps مرحله‌ای بعد از $step (در ترتیب کانونی) وجود دارد؟ */
        private static function has_step_after( $step, array $steps ) {
                $keys = self::step_keys();
                $i = array_search( (string) $step, $keys, true );
                if ( false === $i ) {
                        return false;
                }
                foreach ( $steps as $s ) {
                        $j = array_search( (string) $s, $keys, true );
                        if ( false !== $j && $j > $i ) {
                                return true;
                        }
                }
                return false;
        }

        /** steps_array از ستون progress_steps رکورد خام */
        public static function steps_of( $row ) {
                $raw = isset( $row['progress_steps'] ) ? (string) $row['progress_steps'] : '';
                if ( '' === $raw || 'null' === $raw ) {
                        return array();
                }
                $decoded = json_decode( $raw, true );
                if ( ! is_array( $decoded ) ) {
                        return array();
                }
                $valid = array_fill_keys( self::step_keys(), false );
                foreach ( $decoded as $s ) {
                        $s = (string) $s;
                        if ( isset( $valid[ $s ] ) ) {
                                $valid[ $s ] = true;
                        }
                }
                return array_keys( array_filter( $valid ) );
        }

        /** ۱.۱۳.۰ — مراحل ردشده از ستون progress_excluded رکورد خام */
        public static function excluded_of( $row ) {
                $raw = isset( $row['progress_excluded'] ) ? (string) $row['progress_excluded'] : '';
                if ( '' === $raw || 'null' === $raw ) {
                        return array();
                }
                $decoded = json_decode( $raw, true );
                if ( ! is_array( $decoded ) ) {
                        return array();
                }
                $valid = array_fill_keys( self::step_keys(), false );
                foreach ( $decoded as $s ) {
                        $s = (string) $s;
                        if ( isset( $valid[ $s ] ) ) {
                                $valid[ $s ] = true;
                        }
                }
                return array_keys( array_filter( $valid ) );
        }

        /**
         * خلاصه پیشرفت برای خروجی JSON (shape_row).
         * خروجی: {steps, failures, failures_labels, failure, failure_label, done, total, pct, status, last_step, last_label, excluded, excluded_count}
         * ۱.۱۴.۰ — failures آرایه خرابی‌ها + failures_labels آرایه برچسب‌ها؛ failure/failure_label (اولین) برای سازگاری باقی می‌مانند.
         * status: 'none' | 'progress' | 'done' (+ خرابی‌ها جداگانه)
         */
        public static function summary( $row ) {
                $steps     = self::steps_of( $row );
                $excl      = self::excluded_of( $row );
                $done      = count( $steps );
                $total     = count( self::steps() );
                $failures  = self::failures_of( $row ); // ۱.۱۴.۰ — چند خرابی
                $failure   = $failures ? $failures[0] : '';
                $last_step = $done ? $steps[ $done - 1 ] : '';
                $status = 'none';
                if ( $done >= $total ) {
                        $status = 'done';
                } elseif ( $done > 0 ) {
                        $status = 'progress';
                }
                $labels = array();
                foreach ( $failures as $k ) {
                        $labels[] = self::failure_label( $k );
                }
                return array(
                        'steps'          => $steps,
                        'failures'       => $failures,
                        'failures_labels'=> $labels,
                        'failure'        => $failure,
                        'failure_label'  => $failure ? self::failure_label( $failure ) : '',
                        'done'           => $done,
                        'total'          => $total,
                        'pct'            => $total ? (int) round( $done * 100 / $total ) : 0,
                        'status'         => $status,
                        'status_label'   => self::status_label( $status, $failures ),
                        'last_step'      => $last_step,
                        'last_label'     => $last_step ? self::step_label( $last_step ) : '',
                        'updated_at'     => isset( $row['progress_updated_at'] ) ? (string) $row['progress_updated_at'] : '',
                        'excluded'       => $excl, // ۱.۱۳.۰ — مراحل ردشده توسط کاربر
                        'excluded_count' => count( $excl ),
                );
        }

        /** برچسب وضعیت کلی برای کارت/خروجی‌ها — ۱.۱۴.۰: پذیرش آرایه خرابی‌ها یا رشته تکی */
        public static function status_label( $status, $failures = '' ) {
                if ( is_array( $failures ) ) {
                        if ( ! empty( $failures ) ) {
                                $text = self::failures_labels_text( $failures );
                                return '❌ خرابی: ' . ( '' !== $text ? $text : implode( '، ', $failures ) );
                        }
                } elseif ( (string) $failures ) {
                        $f = self::failure_label( (string) $failures );
                        return '❌ خرابی: ' . ( '' !== $f ? $f : (string) $failures );
                }
                if ( 'done' === $status ) {
                        return '✅ دایری کامل';
                }
                if ( 'progress' === $status ) {
                        return '🚧 در حال دایری';
                }
                return '⏳ دایری شروع نشده';
        }

        /**
         * شرح خوانای تغییر مراحل (برای تاریخچه/گزارش فعالیت).
         * خروجی: «+2 مرحله (فیوژن سمت FAT، دراپ‌کشی انجام شده)»
         * ۱.۱۳.۰ — تغییر «مراحل ردشده» هم در همان متن می‌آید.
         */
        public static function steps_change_text( $old_steps, $new_steps, $old_excluded = array(), $new_excluded = array() ) {
                $added   = array_values( array_diff( $new_steps, $old_steps ) );
                $removed = array_values( array_diff( $old_steps, $new_steps ) );
                $parts   = array();
                if ( $added ) {
                        $parts[] = '+' . count( $added ) . ' مرحله: ' . implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $added ) );
                }
                if ( $removed ) {
                        $parts[] = '−' . count( $removed ) . ' مرحله: ' . implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $removed ) );
                }
                if ( is_array( $old_excluded ) && is_array( $new_excluded ) ) {
                        $skip_added   = array_values( array_diff( $new_excluded, $old_excluded ) );
                        $skip_removed = array_values( array_diff( $old_excluded, $new_excluded ) );
                        if ( $skip_added ) {
                                $parts[] = 'ردشده توسط کاربر: ' . implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $skip_added ) );
                        }
                        if ( $skip_removed ) {
                                $parts[] = 'رفع ردشدگی: ' . implode( '، ', array_map( array( 'TPP_Progress', 'step_label' ), $skip_removed ) );
                        }
                }
                return $parts ? implode( ' | ', $parts ) : '';
        }

        /**
         * فیلتر وضعیت پیشرفت در جستجو → شرط SQL قابل‌حمل (LIKE روی JSON).
         * $status: none|progress|done|fail|fail_los|fail_phone|fail_internet|fail_other
         * ۱.۱۴.۰ — fail_X با LIKE روی JSON ستون progress_failures (چند خرابی) + سازگاری با ستون قدیمی progress_failure
         * خروجی: array(where, params) یا null (بدون فیلتر)
         */
        public static function status_where( $status, $alias = 's' ) {
                $status = sanitize_key( (string) $status );
                if ( '' === $status ) {
                        return null;
                }
                switch ( $status ) {
                        case 'none':
                                return array( " AND {$alias}.progress_done = 0 AND {$alias}.progress_failure = ''", array() );
                        case 'progress':
                                return array( " AND {$alias}.progress_done > 0 AND {$alias}.progress_done < %d AND {$alias}.progress_failure = ''", array( count( self::steps() ) ) );
                        case 'done':
                                return array( " AND {$alias}.progress_done >= %d AND {$alias}.progress_failure = ''", array( count( self::steps() ) ) );
                        case 'fail':
                                return array( " AND {$alias}.progress_failure <> ''", array() );
                        case 'fail_los':
                        case 'fail_phone':
                        case 'fail_internet':
                        case 'fail_other':
                                $key  = substr( $status, 5 );
                                $like = '%"' . $key . '"%';
                                // ۱.۱۴.۰ — ستون جدید JSON چندتایی + ستون قدیمی تکی (پس از مهاجرت backfill فقط JSON)
                                return array( " AND ({$alias}.progress_failures LIKE %s OR {$alias}.progress_failure = %s)", array( $like, $key ) );
                }
                return null;
        }

        /** شرط یک مرحله خاص انجام‌شده/نشده → LIKE قابل‌حمل روی JSON ستون */
        public static function step_where( $step, $state, $alias = 's' ) {
                $step = sanitize_key( (string) $step );
                if ( ! array_key_exists( $step, self::steps() ) ) {
                        return null;
                }
                $like = '%"' . $step . '"%';
                if ( 'todo' === sanitize_key( (string) $state ) ) {
                        // انجام‌نشده: JSON فاقد کلید است (یا ستون خالی — پیش‌فرض ستون خالی = هیچ مرحله)
                        return array( " AND ({$alias}.progress_steps IS NULL OR {$alias}.progress_steps NOT LIKE %s)", array( $like ) );
                }
                return array( " AND {$alias}.progress_steps LIKE %s", array( $like ) );
        }

        /** آمار کلی پیشرفت برای داشبورد */
        public static function stats() {
                $total = count( self::steps() );
                $st = TPP_DB::table( 'services' );
                $out = array(
                        'none'     => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} WHERE progress_done = 0 AND progress_failure = ''" ),
                        'progress' => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} WHERE progress_done > 0 AND progress_done < %d AND progress_failure = ''", array( $total ) ),
                        'done'     => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} WHERE progress_done >= %d AND progress_failure = ''", array( $total ) ),
                        'fail'     => (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st} WHERE progress_failure <> ''" ),
                );
                $out['total'] = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM {$st}" );
                foreach ( array_keys( self::failures() ) as $key ) {
                        // ۱.۱۴.۰ — شمارش با LIKE روی JSON چندتایی + ستون قدیمی تکی
                        $like = '%"' . $key . '"%';
                        $out[ 'fail_' . $key ] = (int) TPP_DB::get_var(
                                "SELECT COUNT(*) FROM {$st} WHERE progress_failures LIKE %s OR progress_failure = %s",
                                array( $like, $key )
                        );
                }
                return $out;
        }

        /**
         * اطمینان از وجود ستون‌های پیشرفت روی جدول services (نصب‌های موجود).
         * مثل ensure_history_indexes — dbDelta ستون‌های جدید را همیشه اضافه نمی‌کند.
         */
        public static function ensure_columns() {
                $table = TPP_DB::table( 'services' );
                if ( ! $table ) {
                        return;
                }
                $db = TPP_DB::db();
                $existing = array();
                $rows = $db->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
                foreach ( (array) $rows as $r ) {
                        $name = isset( $r['Field'] ) ? $r['Field'] : ( isset( $r['name'] ) ? $r['name'] : '' );
                        if ( $name ) {
                                $existing[ strtolower( (string) $name ) ] = true;
                        }
                }
                foreach ( self::COLUMNS as $col => $def ) {
                        if ( ! isset( $existing[ strtolower( $col ) ] ) ) {
                                TPP_DB::query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
                        }
                }
        }

        /**
         * ۱.۱۴.۰ — مهاجرت داده: ستون جدید progress_failures برای رکوردهای موجود از ستون تکی
         * progress_failure پر می‌شود (یک‌بار در ارتقا؛ فقط رکوردهای دارای خرابی و بدون JSON جدید).
         */
        public static function backfill_failures() {
                $table = TPP_DB::table( 'services' );
                if ( ! $table ) {
                        return;
                }
                // رکوردهای دارای خرابی که ستون JSON ندارند → ["key"]
                $rows = TPP_DB::get_results(
                        "SELECT id, progress_failure FROM {$table} WHERE progress_failure <> '' AND (progress_failures IS NULL OR progress_failures = '' OR progress_failures = 'null' OR progress_failures = '[]')"
                );
                foreach ( (array) $rows as $r ) {
                        $key = sanitize_key( (string) $r['progress_failure'] );
                        if ( '' === $key || ! array_key_exists( $key, self::failures() ) ) {
                                continue;
                        }
                        TPP_DB::query(
                                "UPDATE {$table} SET progress_failures = %s WHERE id = %d",
                                array( wp_json_encode( array( $key ), JSON_UNESCAPED_UNICODE ), (int) $r['id'] )
                        );
                }
        }

        /** کلید وضعیت برای UI/JS — فهرست کامل مراحل و خرابی‌ها (برای bootstrap) */
        public static function catalog() {
                return array(
                        'steps'    => self::steps(),
                        'failures' => self::failures(),
                );
        }
}
