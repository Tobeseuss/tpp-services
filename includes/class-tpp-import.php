<?php
/**
 * ایمپورت گروهی اکسل — چهار مرحله:
 *  ۱) preview: آپلود فایل، تشخیص خودکار نگاشت سرستون‌ها به فیلدها، اعتبارسنجی و پیش‌نمایش
 *  ۲) تنظیم نگاشت/حالت/کلید تطبیق توسط کاربر (در سمت اپ)
 *  ۳) analyze: تشخیص ردیف‌های مشابه با داده‌های ثبت‌شده (کد پستی / آدرس / کد ملی مالک)
 *     و پرسیدن تصمیم کاربر: اتصال به آدرس موجود یا ثبت آدرس جدید مستقل
 *  ۴) commit: ثبت گروهی با تاریخچه (action=import) و اعمال تصمیم‌ها
 *
 * داده‌های پیش‌نمایش به‌صورت transient نگهداری می‌شوند (۱۵ دقیقه).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Import {

        /** نام‌های مستعار فارسی برای فیلدهای پیش‌فرض (تشخیص خودکار بهتر نگاشت) */
        private function alias_map() {
                return array(
                        'f_full_address'        => array( 'آدرس کامل', 'ادرس کامل', 'آدرس', 'نشانی کامل', 'نشانی' ),
                        'f_block'               => array( 'نام بلوک یا خیابان', 'بلوک', 'خیابان', 'نام بلوک', 'نام خیابان' ),
                        'f_plate'               => array( 'شماره پلاک', 'پلاک' ),
                        'f_unit'                => array( 'شماره واحد', 'واحد' ),
                        'f_postal_code'         => array( 'کد پستی', 'كد پستي', 'کدپستی', 'کد پست' ),
                        'f_center_name'         => array( 'نام مرکز', 'مرکز', 'نام مركز', 'نام مرکز مخابرات', 'مرکز مخابرات', 'center' ),
                        'f_owner_name'          => array( 'نام و نام خانوادگی', 'نام و نام خانوادگي', 'نام خانوادگی', 'نام مشترک', 'نام' ),
                        'f_phone'               => array( 'شماره تلفن', 'تلفن', 'شماره تماس', 'شماره تلفن ثابت' ),
                        'f_mobile'              => array( 'شماره موبایل', 'موبایل', 'شماره همراه', 'همراه' ),
                        'f_national_id_line'    => array( 'کد ملی مالک خط', 'کدملی مالک خط', 'کد ملی خط', 'کدملی خط', 'کد ملی مالک', 'ملک خط' ),
                        'f_national_id_service' => array( 'کد ملی مالک سرویس', 'کدملی مالک سرویس', 'کد ملی سرویس', 'کد ملی', 'کدملی', 'شماره ملی' ),
                        'f_virtual_number'      => array( 'شماره مجازی', 'شماره مجازي', 'مجازی' ),
                        // ۱.۲۱.۰ — نگاشت‌های شش فیلد حذف‌شده (وضعیت/وای‌فای) برداشته شد؛ ستون‌های قدیمی در ایمپورت «نگاشت‌نشده» گزارش و رد می‌شوند
                        'f_misc_notes'          => array( 'توضیحات متفرقه', 'متفرقه', 'توضیحات متفرقه سرویس', 'یادداشت', 'یادداشت‌ها', 'misc notes', 'notes' ),
                        'f_modem_model'         => array( 'مدل مودم', 'مودم' ),
                        'f_modem_serial'        => array( 'سریال مودم', 'سریال', 'سريال مودم' ),
                        'f_internet_pass'       => array( 'پسورد اینترنت', 'پسورد', 'رمز اینترنت', 'پسورد انترنت' ),
                        'f_sip_pass'            => array( 'sip pass', 'sip pas', 'sippass', 'sip password' ),
                        'f_sip_ip'              => array( 'sip ip', 'sipip' ),
                        'f_subnet'              => array( 'subnet mask', 'subnet', 'subnetmask' ),
                        'f_gateway'             => array( 'gateway', 'گیت وی' ),
                        'f_sbc'                 => array( 'sbc' ),
                        'f_standby_proxy'       => array( 'standby proxy', 'standbyproxy', 'stand by proxy' ),
                        'f_description'         => array( 'توضیحات', 'توضيحات', 'ملاحظات', 'یادداشت' ),
                );
        }

        /** ۱.۱۳.۰ — شبه‌فیلدهای پیشرفت دایری/خرابی در ایمپورت (ستون‌های محاسباتی — فیلد واقعی نیستند) */
        const PROGRESS_FIELD = '__progress';
        const FAILURE_FIELD  = '__progress_failure';
        /** ۱.۱۹.۰ — شبه‌فیلدهای دسته‌بندی/تگ در ایمپورت (مقدار = برچسب‌ها؛ در ثبت به شناسه تبدیل می‌شود) */
        const CATEGORY_FIELD = '__category';
        const TAGS_FIELD     = '__tags';

        private function pseudo_aliases() {
                return array(
                        self::PROGRESS_FIELD => array( 'پیشرفت دایری', 'پيشرفت دايري', 'مراحل دایری', 'مرحله دایری', 'مراحل پیشرفت', 'پیشرفت سرویس', 'پیشرفت', 'progress', 'dayeri', 'دایری' ),
                        self::FAILURE_FIELD => array( 'خرابی اعلام‌شده', 'خرابی اعلام شده', 'اعلام خرابی', 'وضعیت خرابی', 'خرابی سرویس', 'خرابی', 'failure' ),
                        /* ۱.۱۹.۰ — دسته‌بندی/تگ */
                        self::CATEGORY_FIELD => array( 'دسته‌بندی پروژه', 'دسته‌بندی', 'دسته بندی', 'دسته', 'category', 'پروژه' ),
                        self::TAGS_FIELD => array( 'تگ‌ها', 'تگها', 'تگ', 'برچسب‌ها', 'برچسب', 'tags', 'برچسب سرویس' ),
                );
        }

        private function norm_header( $text ) {
                $t = TPP_Services::normalize( strtolower( trim( (string) $text ) ) );
                $t = str_replace( array( '‌', 'ي', "\xEF\xBB\xBF" ), array( ' ', 'ی', '' ), $t );
                $t = preg_replace( '/\s+/u', ' ', $t );
                // ۱.۸.۰ — نشانگرهای «اجباری» و ستاره حذف می‌شوند.
                // قالب نمونه، ستون‌های الزامی را «عنوان *» می‌نویسد؛ بدون این پاک‌سازی همان ستون‌های مهم
                // در تشخیص خودکار نگاشت شناسایی نمی‌شدند و ردیف‌ها «بدون داده» به نظر می‌رسیدند.
                $t = str_replace( array( '*', '٭', '✱', '＊', '•', '·' ), ' ', $t );
                $t = str_ireplace( array( '(اجباری)', '(الزامی)', '[اجباری]', '[الزامی]', 'اجباری:', 'الزامی:', 'اجباری', 'الزامی', '(required)', 'required' ), ' ', $t );
                $t = preg_replace( '/^[\s:،؛,.\|\(\)\[\]\-_–——]+|[\s:،؛,.\|\(\)\[\]\-_–—]+$/u', ' ', $t );
                $t = preg_replace( '/\s+/u', ' ', $t );
                return trim( (string) $t );
        }

        /** تشخیص خودکار نگاشت سرستون → فیلد */
        private function auto_map( array $headers, array $fields_by_slug ) {
                $alias = array();
                foreach ( $this->alias_map() as $slug => $list ) {
                        foreach ( $list as $a ) {
                                $alias[ $this->norm_header( $a ) ] = $slug;
                        }
                }
                // ۱.۱۳.۰ — شبه‌فیلدهای پیشرفت دایری/خرابی
                foreach ( $this->pseudo_aliases() as $pseudo => $list ) {
                        foreach ( $list as $a ) {
                                $alias[ $this->norm_header( $a ) ] = $pseudo;
                        }
                }
                foreach ( $fields_by_slug as $slug => $f ) {
                        $alias[ $this->norm_header( $f['label'] ) ] = $slug; // برچسب فعلی فیلد
                        $alias[ $this->norm_header( $slug ) ] = $slug;
                }

                $mapping = array();
                $used    = array();
                $pseudo_used = array();
                foreach ( $headers as $col => $header ) {
                        $norm = $this->norm_header( $header );
                        $slug = isset( $alias[ $norm ] ) ? $alias[ $norm ] : '';
                        if ( '' !== $slug && isset( $fields_by_slug[ $slug ] ) && empty( $used[ $slug ] ) ) {
                                $mapping[ $col ] = $slug;
                                $used[ $slug ]   = true;
                        } elseif ( '' !== $slug && $this->is_pseudo( $slug ) && empty( $pseudo_used[ $slug ] ) ) {
                                $mapping[ $col ] = $slug; // ۱.۱۳.۰ — شبه‌فیلد دایری
                                $pseudo_used[ $slug ] = true;
                        } else {
                                $mapping[ $col ] = '';
                        }
                }
                return $mapping;
        }

        /** ۱.۱۳.۰ — آیا slug یک شبه‌فیلد پیشرفت است؟ (۱.۱۹.۰ — دسته/تگ هم) */
        private function is_pseudo( $slug ) {
                return in_array( $slug, array( self::PROGRESS_FIELD, self::FAILURE_FIELD, self::CATEGORY_FIELD, self::TAGS_FIELD ), true );
        }

        /** نرمال‌سازی کلیدهای عددی (کد پستی/کد ملی): ارقام فارسی/عربی → لاتین + حذف فاصله و خط تیره */
        private function norm_key( $text ) {
                $t = TPP_Services::normalize( (string) $text );
                $fa = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
                $en = array( '0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9' );
                $t = str_replace( $fa, $en, $t );
                $t = preg_replace( '/[\s\-\x{200f}\x{200e}\x{200c}]/u', '', $t );
                return null === $t ? '' : $t;
        }

        /** آیا ردیف کاملاً خالی است؟ */
        private function row_is_empty( array $row ) {
                foreach ( $row as $cell ) {
                        if ( '' !== trim( (string) $cell ) ) {
                                return false;
                        }
                }
                return true;
        }

        /**
         * آیا مقدار کلید تطبیق «بی‌ارزش» است؟ (خالی، فقط صفر یا فقط خط تیره)
         * برای مقادیری مثل تلفنِ «0» (راهنمای خود فرم: بدون تلفن = 0) — نباید برای
         * تشخیص سرویس تکراری به‌کار رود، وگرنه صدها ردیف بی‌دلیل «تکراری» رد می‌شوند.
         */
        private function is_trivial_key( $value ) {
                $v = $this->norm_key( $value );
                $v = preg_replace( '/[\-—–_.]/u', '', $v );
                $v = null === $v ? '' : $v;
                if ( '' === $v ) {
                        return true;
                }
                return 1 === preg_match( '/^0+$/', $v );
        }

        /** ساخت داده‌های یک ردیف بر اساس نگاشت — [address_data, service_data, progress_data, tax_data] */
        private function split_row( $row, array $mapping, array $fields_by_slug ) {
                $address_data = array();
                $service_data = array();
                $progress_data = array(); // ۱.۱۳.۰ — {steps, failure, skipped} از شبه‌ستون‌های دایری
                $tax_data = array(); // ۱.۱۹.۰ — {category, tags} از شبه‌ستون‌های دسته/تگ (برچسب‌ها)
                foreach ( $mapping as $col => $slug ) {
                        $val = isset( $row[ (int) $col ] ) ? trim( (string) $row[ (int) $col ] ) : '';
                        if ( '' === $slug || '' === $val ) {
                                continue;
                        }
                        if ( self::CATEGORY_FIELD === $slug ) {
                                $tax_data['category'] = $val; // برچسب دسته — در commit به شناسه رزولو می‌شود
                                continue;
                        }
                        if ( self::TAGS_FIELD === $slug ) {
                                $tax_data['tags'] = $val; // برچسب تگ‌ها با جداکننده
                                continue;
                        }
                        if ( $this->is_pseudo( $slug ) ) {
                                $progress_data = array_merge( $progress_data, $this->parse_progress_cell( $slug, $val ) );
                                continue;
                        }
                        if ( ! isset( $fields_by_slug[ $slug ] ) ) {
                                continue;
                        }
                        if ( 'address' === $fields_by_slug[ $slug ]['group_key'] ) {
                                $address_data[ $slug ] = $val;
                        } else {
                                $service_data[ $slug ] = $val;
                        }
                }
                // فیلدهای حالت‌دار SBC / StandBy Proxy: مقادیر «خودکار» به نشانگر AUTO نگاشت می‌شود
                // (سازگاری رفت‌وبرگشتی با خروجی اکسل که AUTO را «خودکار (تنظیم توسط یارا)» می‌نویسد)
                foreach ( array( 'f_sbc', 'f_standby_proxy' ) as $mode_slug ) {
                        if ( isset( $service_data[ $mode_slug ] ) ) {
                                $service_data[ $mode_slug ] = $this->normalize_mode_value( $service_data[ $mode_slug ] );
                        }
                }
                return array( $address_data, $service_data, $progress_data, $tax_data );
        }

        /**
         * ۱.۱۳.۰ — پارس مقدار سلول پیشرفت دایری/خرابی.
         * مقدار مرحله: عدد ۱..۱۶ (فارسی/لاتین) | درصد | کلید مرحله | متن کامل برچسب | «کامل» | «هیچ» | فهرست با کاما
         * مقدار خرابی: کلید یا برچسب — ۱.۱۴.۰: چند خرابی با جداساز کاما/؛ پشتیبانی می‌شود («قطع اینترنت، سایر»)
         * خروجی: keys قابل افزودن به progress payload (steps/failures)
         */
        private function parse_progress_cell( $pseudo, $value ) {
                $out = array();
                $t = trim( (string) $value );
                if ( '' === $t || '—' === $t || '-' === $t ) {
                        return $out;
                }
                if ( self::FAILURE_FIELD === $pseudo ) {
                        $fails = $this->parse_failures_value( $t );
                        if ( null !== $fails ) {
                                $out['failures'] = $fails; // ۱.۱۴.۰ — آرایه (خالی = رفع همه)
                        }
                        return $out;
                }
                $steps = $this->parse_steps_value( $t );
                if ( null !== $steps ) {
                        $out['steps'] = $steps;
                }
                return $out;
        }

        /** تبدیل ارقام فارسی/عربی به لاتین */
        private function fa_digits_to_en( $text ) {
                $fa = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
                $en = array( '0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9' );
                return str_replace( $fa, $en, (string) $text );
        }

        /** پارس مقدار مراحل — خروجی آرایه کلیدها یا null (نامعتبر) */
        private function parse_steps_value( $t ) {
                $keys = TPP_Progress::step_keys();
                $total = count( $keys );
                $norm = TPP_Services::normalize( strtolower( trim( $this->fa_digits_to_en( $t ) ) ) );
                $norm = str_replace( array( '‌', 'ي' ), array( ' ', 'ی' ), (string) $norm );

                // «کامل» / «همه» → هر ۱۶ مرحله
                if ( in_array( trim( (string) $norm ), array( 'کامل', 'تمام', 'همه', 'کامل شده', 'تمام شده', 'all', 'complete', '100%', '100' ), true ) ) {
                        return $keys;
                }
                // «هیچ» / «شروع نشده» → پاک‌کردن
                if ( in_array( trim( (string) $norm ), array( 'هیچ', 'هیچکدام', 'هیچ یک', 'none', 'خالی', '0', 'شروع نشده', '-' ), true ) ) {
                        return array();
                }
                // عدد خالی ۱..۱۶ یا درصد
                if ( preg_match( '/^(\d{1,3})\s*%?$/u', trim( (string) $norm ), $m ) ) {
                        $n = (int) $m[1];
                        if ( 0 === $n ) {
                                return array();
                        }
                        if ( $n > 100 ) {
                                return null;
                        }
                        if ( false !== strpos( (string) $norm, '%' ) || $n > $total ) {
                                $n = (int) round( $n * $total / 100 ); // درصد → تعداد مرحله
                        }
                        $n = max( 0, min( $total, $n ) );
                        return array_slice( $keys, 0, $n );
                }

                // فهرست با کاما / خط عمودی: کلید یا برچسب هر مرحله
                $parts = array_filter( array_map( 'trim', preg_split( '/[,،;؛|]/u', (string) $t ) ) );
                $found = array();
                $valid = true;
                foreach ( $parts as $part ) {
                        $key = $this->match_step( $part );
                        if ( null === $key ) {
                                $valid = false;
                                break;
                        }
                        $found[ $key ] = true;
                }
                if ( ! $valid || empty( $found ) ) {
                        // تک مقدار: تطبیق کلید یا برچسب
                        $key = $this->match_step( trim( (string) $t ) );
                        if ( null === $key ) {
                                return null;
                        }
                        return array( $key );
                }
                // به‌ترتیب کانونی (آبشار روی سرور بر اساس بالاترین مرحله اعمال می‌شود)
                return array_values( array_intersect( $keys, array_keys( $found ) ) );
        }

        /** تطبیق متن با کلید/برچسب یک مرحله — کلید یا null */
        private function match_step( $text ) {
                $t = TPP_Services::normalize( strtolower( trim( (string) $text ) ) );
                $steps = TPP_Progress::steps();
                if ( isset( $steps[ $t ] ) ) {
                        return $t; // کلید مستقیم
                }
                foreach ( $steps as $key => $label ) {
                    if ( TPP_Services::normalize( strtolower( $label ) ) === $t ) {
                                return $key;
                        }
                }
                // ۱.۱۳.۰ — تطبیق جزئی: «اینترنت متصل» = برچسب «اینترنت متصل می‌باشد»
                // (حداقل ۴ نویسه تا تطبیق شانسی جلوگیری شود؛ اولین تطبیق کانونی برنده است)
                if ( function_exists( 'mb_strlen' ) ? mb_strlen( $t, 'UTF-8' ) >= 4 : strlen( $t ) >= 8 ) {
                        foreach ( $steps as $key => $label ) {
                                $nl = TPP_Services::normalize( strtolower( $label ) );
                                if ( false !== strpos( $nl, (string) $t ) ) {
                                        return $key;
                                }
                        }
                }
                return null;
        }

        /** پارس مقدار خرابی — کلید معتبر یا '' (نامعتبر = null → بدون تغییر) */
        private function parse_failure_value( $t ) {
                $norm = TPP_Services::normalize( strtolower( trim( (string) $t ) ) );
                $map = array(
                        'los'        => array( 'los', 'ال او اس', 'قطع کامل', 'قطع کامل اینترنت و تلفن' ),
                        'phone'      => array( 'phone', 'قطع تلفن', 'تلفن قطع', 'قطع شدن تلفن' ),
                        'internet'   => array( 'internet', 'قطع اینترنت', 'اینترنت قطع', 'قطع شدن اینترنت' ),
                        'other'      => array( 'other', 'سایر', 'سایر خرابی', 'خرابی دیگر', 'متن' ),
                        ''           => array( 'رفع', 'رفع خرابی', 'حل', 'حل شد', 'سالم', 'بدون خرابی', 'نه', '-', '—', 'خالی', 'none' ),
                );
                foreach ( $map as $key => $labels ) {
                        if ( in_array( (string) $norm, $labels, true ) ) {
                                return $key;
                        }
                }
                return null; // نامعتبر → بدون تغییر خرابی
        }

        /** ۱.۱۴.۰ — پارس چند خرابی با جداساز (کاما/؛/|) — خروجی آرایه کلیدها یا null (نامعتبر) */
        private function parse_failures_value( $t ) {
                $parts = array_filter( array_map( 'trim', preg_split( '/[,،;؛|]/u', (string) $t ) ) );
                if ( empty( $parts ) ) {
                        return null;
                }
                // حالت‌های ویژه تک‌مقداری که معنای «رفع همه» دارند
                if ( 1 === count( $parts ) ) {
                        $one = $this->parse_failure_value( $parts[0] );
                        return ( null === $one ) ? null : ( '' === $one ? array() : array( $one ) );
                }
                $keys = array();
                foreach ( $parts as $part ) {
                        $one = $this->parse_failure_value( $part );
                        if ( null === $one ) {
                                return null; // بخشی نامعتبر بود → کل سلول نادیده
                        }
                        if ( '' !== $one ) {
                                $keys[ $one ] = true;
                        }
                }
                return array_keys( $keys );
        }

        /**
         * نرمال‌سازی مقدار فیلدهای حالت‌دار (SBC / StandBy Proxy):
         * «خودکار»، «AUTO»، «auto» و امثالهم → AUTO؛ بقیه (آدرس دستی) دست‌نخورده می‌مانند.
         */
        private function normalize_mode_value( $value ) {
                $t = strtolower( TPP_Services::normalize( (string) $value ) );
                $t = str_replace( '‌', ' ', $t ); // نیم‌فاصله
                $t = preg_replace( '/\s+/u', ' ', $t ) ?? '';
                $auto_words = array(
                        'auto', 'خودکار', 'تنظیم خودکار', 'خودکار توسط یارا', 'خودکار توسط یارا تنظیم می شود',
                        'خودکار (تنظیم توسط یارا)', 'yara', 'یارا', 'automatic', 'autoset', 'auto set',
                );
                if ( in_array( trim( $t ), $auto_words, true ) ) {
                        return 'AUTO';
                }
                return trim( (string) $value );
        }

        /**
         * مرحله ۱ — پیش‌نمایش فایل آپلودشده
         * خروجی: session_id + نگاشت + آمار + نمونه ردیف‌ها
         */
        public function preview( $file_path, $original_name, $user_id ) {
                $settings = tpp()->settings();
                $max_mb   = (int) $settings->get( 'import_max_size_mb', 10 );
                $max_rows = (int) $settings->get( 'import_max_rows', 20000 );
                if ( filesize( $file_path ) > $max_mb * 1024 * 1024 ) {
                        return new WP_Error( 'tpp_file_too_large', 'حجم فایل بیش از حد مجاز (' . $max_mb . ' مگابایت) است.' );
                }

                try {
                        $reader = new TPP_XLSX_Reader();
                        if ( ! $reader->open( $file_path ) ) {
                                return new WP_Error( 'tpp_bad_file', 'فایل اکسل معتبر نیست. فقط فایل‌های xlsx پشتیبانی می‌شوند (فایل‌های xls قدیمی را در اکسل با Save As به xlsx تبدیل کنید).' );
                        }
                        $rows = $reader->rows( $max_rows + 1 );
                        $reader->close();
                } catch ( Throwable $e ) {
                        return new WP_Error( 'tpp_read_error', 'خطا در خواندن فایل اکسل: ' . $e->getMessage() );
                }
                if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
                        return new WP_Error( 'tpp_empty', 'فایل خالی است یا فقط سرستون دارد.' );
                }

                $headers = array_map( 'trim', (array) array_shift( $rows ) );
                // ردیف‌های کاملاً خالی (مثلاً ردیف‌های خالی انتهای فایل) از شمارش و ذخیره حذف می‌شوند
                $raw_count   = count( $rows );
                $rows        = array_values( array_filter( $rows, array( $this, 'row_not_empty' ) ) );
                $empty_rows  = $raw_count - count( $rows ); // تعداد ردیف‌های خالی نادیده‌گرفته‌شده در فایل
                if ( empty( $rows ) ) {
                        return new WP_Error( 'tpp_empty', 'داده‌ای در فایل نیست — همه ردیف‌ها خالی هستند.' );
                }
                if ( count( $rows ) > $max_rows ) {
                        return new WP_Error( 'tpp_too_many_rows', 'تعداد ردیف‌ها بیش از سقف مجاز (' . number_format_i18n( $max_rows ) . ') است.' );
                }

                $fields_by_slug = array();
                foreach ( TPP_Fields::form_order() as $f ) { // ۱.۸.۰ — ترتیب مرجع مطابق فرم ثبت سرویس
                        $fields_by_slug[ $f['slug'] ] = $f;
                }

                $mapping = $this->auto_map( $headers, $fields_by_slug );
                $session = array(
                        'headers'     => $headers,
                        'rows'        => array_values( $rows ),
                        'mapping'     => $mapping,
                        'file'        => sanitize_file_name( $original_name ),
                        'user_id'     => (int) $user_id,
                        'created'     => time(),
                        'empty_rows'  => $empty_rows,
                );
                $session_id = 'imp_' . wp_generate_password( 20, false, false );
                set_transient( 'tpp_import_' . $session_id, $session, 15 * MINUTE_IN_SECONDS );

                // گزینه‌های کلید تطبیق: فیلدهای سرویس متنی (به‌جز حساس) + بدون تطبیق
                $match_options = array( array( 'value' => '', 'label' => 'بدون تطبیق (همه به‌صورت جدید)' ) );
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( ! $f['is_sensitive'] && 'checkbox' !== $f['field_type'] ) {
                                $match_options[] = array( 'value' => $f['slug'], 'label' => $f['label'] );
                        }
                }

                $mapped_count     = 0;
                $unmapped_headers = array();
                foreach ( $headers as $col => $header ) {
                        $slug = isset( $mapping[ $col ] ) ? $mapping[ $col ] : '';
                        if ( '' !== $slug && ( isset( $fields_by_slug[ $slug ] ) || $this->is_pseudo( $slug ) ) ) {
                                $mapped_count++;
                        } else {
                                $unmapped_headers[] = ( '' === trim( (string) $header ) ) ? '(ستون بدون عنوان)' : (string) $header;
                        }
                }

                return array(
                        'session_id'       => $session_id,
                        'headers'          => $headers,
                        'mapping'          => $mapping,
                        'fields'           => array_values( $fields_by_slug ),
                        // ۱.۱۳.۰ — شبه‌فیلدهای دایری برای مرحله نگاشت (computed — ستون فیلد واقعی نیستند)
                        'pseudo_fields'    => array(
                                array( 'slug' => self::PROGRESS_FIELD, 'label' => 'پیشرفت دایری (عدد ۱-۱۶، درصد، کلید یا برچسب مرحله، «کامل»/«هیچ»)' ),
                                array( 'slug' => self::FAILURE_FIELD, 'label' => 'خرابی اعلام‌شده (LOS / قطع تلفن / قطع اینترنت / سایر / «رفع»)' ),
                                /* ۱.۱۹.۰ — دسته‌بندی/تگ (مقدار = برچسب‌ها؛ ناموجود خودکار ساخته می‌شود) */
                                array( 'slug' => self::CATEGORY_FIELD, 'label' => 'دسته‌بندی پروژه (نام دسته — مثل «پروژه سازمانی»)' ),
                                array( 'slug' => self::TAGS_FIELD, 'label' => 'تگ‌ها (چند تگ با جداکننده کاما — مثل «ویژه، اولویت بالا»)' ),
                        ),
                        'row_count'        => count( $rows ),
                        'preview_rows'     => array_slice( $rows, 0, 10 ),
                        'match_options'    => $match_options,
                        'default_match'    => (string) $settings->get( 'default_match_key', 'f_phone' ),
                        'mapped_count'     => $mapped_count,
                        'unmapped_headers' => $unmapped_headers,
                        'empty_rows'       => $empty_rows,
                        'expires_in'       => 15 * MINUTE_IN_SECONDS,
                );
        }

        /** callback برای array_filter — نگه‌داشتن ردیف‌های غیرخالی */
        public function row_not_empty( $row ) {
                return ! $this->row_is_empty( is_array( $row ) ? $row : array() );
        }

        /** ۱.۸.۰ — آیا حداقل یک ستون به فیلد موجود نگاشت شده است؟ (۱.۱۳.۰ — شبه‌فیلدهای دایری هم معتبرند) */
        private function has_mapped_columns( array $mapping, array $fields_by_slug ) {
                foreach ( $mapping as $col => $slug ) {
                        if ( '' !== $slug && ( isset( $fields_by_slug[ $slug ] ) || $this->is_pseudo( $slug ) ) ) {
                                return true;
                        }
                }
                return false;
        }

        /* ==================== تشخیص ردیف‌های مشابه ==================== */

        /**
         * ساخت ایندکس‌های تطبیق از دیتابیس — کلیدهای شناسایی آدرس:
         *  کد پستی / کلید نرمال‌شده آدرس (آدرس کامل+بلوک+پلاک+واحد) / کد ملی مالک خط یا سرویس
         */
        private function match_indexes() {
                $key_slugs = array( 'f_full_address', 'f_block', 'f_plate', 'f_unit' );
                $idx = array(
                        'postal' => array(),  // کد پستی نرمال → address_id
                        'addr'   => array(),  // کلید آدرس نرمال → address_id
                        'nid'    => array(),  // کد ملی نرمال → [address_id, ...]
                        'labels' => array(),  // address_id → متن آدرس
                );

                foreach ( (array) TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'addresses' ) ) as $a ) {
                        $id = (int) $a['id'];
                        $label = array();
                        foreach ( TPP_Fields::all( 'address' ) as $f ) {
                                if ( ! empty( $a[ $f['slug'] ] ) ) {
                                        $label[] = $a[ $f['slug'] ];
                                }
                        }
                        $idx['labels'][ $id ] = implode( '، ', array_slice( $label, 0, 3 ) );

                        $postal = $this->norm_key( (string) ( $a['f_postal_code'] ?? '' ) );
                        if ( '' !== $postal ) {
                                $idx['postal'][ $postal ] = $id;
                        }
                        $parts = array();
                        foreach ( $key_slugs as $slug ) {
                                $parts[] = TPP_Services::normalize( (string) ( $a[ $slug ] ?? '' ) );
                        }
                        $key = trim( implode( '|', $parts ), ' |' );
                        if ( '' !== $parts[0] ) { // آدرس کامل مهم‌ترین بخش کلید است
                                $idx['addr'][ $key ] = $id;
                        }
                }

                // کد ملی‌ها از سرویس‌های ثبت‌شده (هر کد ملی ممکن است چند سرویس/آدرس داشته باشد)
                $nid_slugs = array( 'f_national_id_line', 'f_national_id_service' );
                $cols = '';
                foreach ( $nid_slugs as $slug ) {
                        if ( TPP_Fields::get( $slug ) ) {
                                $cols .= ( '' !== $cols ? ', ' : '' ) . $slug;
                        }
                }
                if ( '' !== $cols ) {
                        foreach ( (array) TPP_DB::get_results( "SELECT address_id, {$cols} FROM " . TPP_DB::table( 'services' ) . " WHERE address_id > 0" ) as $s ) {
                                foreach ( $nid_slugs as $slug ) {
                                        $v = $this->norm_key( (string) ( $s[ $slug ] ?? '' ) );
                                        if ( '' !== $v ) {
                                                $idx['nid'][ $v ][] = (int) $s['address_id'];
                                        }
                                }
                        }
                }
                return $idx;
        }

        /**
         * تطبیق یک ردیف با ایندکس‌ها — خروجی: ['best' => address_id, 'reasons' => [], 'confidence' => 'high'|'medium']
         * اولویت: کد پستی > آدرس یکسان > کد ملی
         */
        private function match_row( array $address_data, array $service_data, array $idx ) {
                $reasons = array();
                $best    = 0;
                $high    = false;

                $postal = $this->norm_key( (string) ( $address_data['f_postal_code'] ?? '' ) );
                if ( '' !== $postal && isset( $idx['postal'][ $postal ] ) ) {
                        $best     = $idx['postal'][ $postal ];
                        $reasons[] = 'کد پستی یکسان';
                        $high      = true;
                }

                if ( ! $best ) {
                        $parts = array();
                        foreach ( array( 'f_full_address', 'f_block', 'f_plate', 'f_unit' ) as $slug ) {
                                $parts[] = TPP_Services::normalize( (string) ( $address_data[ $slug ] ?? '' ) );
                        }
                        $key = trim( implode( '|', $parts ), ' |' );
                        if ( '' !== $parts[0] && isset( $idx['addr'][ $key ] ) ) {
                                $best      = $idx['addr'][ $key ];
                                $reasons[] = 'آدرس یکسان (آدرس کامل/بلوک/پلاک/واحد)';
                                $high       = true;
                        }
                }

                if ( ! $best ) {
                        foreach ( array( 'f_national_id_line', 'f_national_id_service' ) as $slug ) {
                                $v = $this->norm_key( (string) ( $service_data[ $slug ] ?? '' ) );
                                if ( '' !== $v && ! empty( $idx['nid'][ $v ] ) ) {
                                        $ids = array_values( array_unique( $idx['nid'][ $v ] ) );
                                        $best = (int) $ids[0];
                                        $reasons[] = ( 'f_national_id_line' === $slug ) ? 'کد ملی مالک خط مشابه سرویس ثبت‌شده' : 'کد ملی مالک سرویس مشابه سرویس ثبت‌شده';
                                        $high       = false;
                                        break;
                                }
                        }
                }

                if ( ! $best ) {
                        return null;
                }
                return array(
                        'address_id' => $best,
                        'reasons'    => $reasons,
                        'confidence' => $high ? 'high' : 'medium',
                        'label'      => isset( $idx['labels'][ $best ] ) ? $idx['labels'][ $best ] : ( 'آدرس #' . $best ),
                );
        }

        /**
         * مرحله ۳ — تحلیل تشابه ردیف‌ها با داده‌های ثبت‌شده.
         * خروجی: فهرست ردیف‌های مشابه (شماره ردیف، آدرس متناظر، دلیل، اطمینان)
         */
        public function analyze( $session_id, array $opts, $user_id ) {
                $session = get_transient( 'tpp_import_' . $session_id );
                if ( ! is_array( $session ) ) {
                        return new WP_Error( 'tpp_session_expired', 'نشست ایمپورت منقضی شده؛ فایل را دوباره آپلود کنید.' );
                }
                if ( (int) $session['user_id'] !== (int) $user_id ) {
                        return new WP_Error( 'tpp_forbidden', 'این نشست ایمپورت متعلق به شما نیست.' );
                }

                // ۱.۸.۱ — نگاشت خالی/غیرآرایه‌ای از کلاینت → بازگشت به نگاشت نشست (نتیجه خودکار preview)
                // تا حذف DOM مرحله ۲ (یا کلاینت قدیمی) ثبت نهایی را با خطای tpp_no_mapping متوقف نکند
                $mapping = ( isset( $opts['mapping'] ) && is_array( $opts['mapping'] ) && $opts['mapping'] )
                        ? $opts['mapping']
                        : ( isset( $session['mapping'] ) && is_array( $session['mapping'] ) ? $session['mapping'] : array() );

                $fields_by_slug = array();
                foreach ( TPP_Fields::form_order() as $f ) { // ترتیب مرجع مطابق فرم
                        $fields_by_slug[ $f['slug'] ] = $f;
                }

                // ۱.۸.۰ — اگر هیچ ستونی به فیلدی نگاشت نشده باشد، تحلیل بی‌معناست؛ خطای شفاف (به‌جای نتیجه خالی)
                if ( ! $this->has_mapped_columns( $mapping, $fields_by_slug ) ) {
                        return new WP_Error( 'tpp_no_mapping', 'هیچ ستونی از فایل به فیلدهای سیستم نگاشت نشده است. عنوان‌های سطر اول فایل باید نام فیلدها باشند (مطابق فایل نمونه) — در مرحله «نگاشت ستون‌ها» اصلاح کنید.' );
                }

                $idx  = $this->match_indexes();
                $dups = array();
                $row_no = 1; // ردیف سرستون = ۱

                foreach ( $session['rows'] as $row ) {
                        $row_no++;
                        list( $address_data, $service_data, $progress_data, $tax_data ) = $this->split_row( $row, $mapping, $fields_by_slug );
                        if ( empty( $address_data ) && empty( $service_data ) && empty( $progress_data ) && empty( $tax_data ) ) {
                                continue; // ردیف خالی
                        }
                        $m = $this->match_row( $address_data, $service_data, $idx );
                        if ( null !== $m ) {
                                $dups[] = array(
                                        'row'        => $row_no,
                                        'address_id' => $m['address_id'],
                                        'label'      => $m['label'],
                                        'reasons'    => $m['reasons'],
                                        'confidence' => $m['confidence'],
                                        'decision'   => 'link', // پیش‌فرض: اتصال به آدرس موجود
                                );
                        }
                }

                return array(
                        'duplicates'    => $dups,
                        'count'         => count( $dups ),
                        'total_rows'    => count( $session['rows'] ),
                        'high_conflict' => count( array_filter( $dups, function ( $d ) { return 'high' === $d['confidence']; } ) ),
                );
        }

        /**
         * مرحله ۴ — ثبت نهایی
         * $opts: mapping (col=>slug), mode (create|update|upsert), match_key (slug یا خالی),
         *        dup_mode (link|new — تصمیم سراسری ردیف‌های مشابه), dup_rows ({row=>link|new} — تصمیم تک‌تک ردیف‌ها)
         */
        public function commit( $session_id, array $opts, $user_id ) {
                $session = get_transient( 'tpp_import_' . $session_id );
                if ( ! is_array( $session ) ) {
                        return new WP_Error( 'tpp_session_expired', 'نشست ایمپورت منقضی شده؛ فایل را دوباره آپلود کنید.' );
                }
                if ( (int) $session['user_id'] !== (int) $user_id ) {
                        return new WP_Error( 'tpp_forbidden', 'این نشست ایمپورت متعلق به شما نیست.' );
                }

                // ۱.۸.۱ — مانند analyze: نگاشت خالی کلاینت به نگاشت نشست برمی‌گردد
                $mapping   = ( isset( $opts['mapping'] ) && is_array( $opts['mapping'] ) && $opts['mapping'] )
                        ? $opts['mapping']
                        : ( isset( $session['mapping'] ) && is_array( $session['mapping'] ) ? $session['mapping'] : array() );
                // ۱.۸.۰ — رفع باگ: خواندن دوباره کلید mode بدون ?? هشدار Undefined و مقدار خالی می‌داد
                $mode      = isset( $opts['mode'] ) && in_array( (string) $opts['mode'], array( 'create', 'update', 'upsert' ), true ) ? (string) $opts['mode'] : 'upsert';
                $match_key = (string) ( $opts['match_key'] ?? '' );
                $dup_mode  = 'new' === (string) ( $opts['dup_mode'] ?? '' ) ? 'new' : 'link';
                $dup_rows  = isset( $opts['dup_rows'] ) && is_array( $opts['dup_rows'] ) ? $opts['dup_rows'] : array();

                $fields_by_slug = array();
                foreach ( TPP_Fields::form_order() as $f ) { // ترتیب مرجع مطابق فرم
                        $fields_by_slug[ $f['slug'] ] = $f;
                }

                // ۱.۸.۰ — گارد صفر-نگاشت: بدون هیچ ستونِ نگاشت‌شده، همه ردیف‌ها «خالی» رد می‌شدند
                if ( ! $this->has_mapped_columns( $mapping, $fields_by_slug ) ) {
                        return new WP_Error( 'tpp_no_mapping', 'هیچ ستونی از فایل به فیلدهای سیستم نگاشت نشده است. عنوان‌های سطر اول فایل باید نام فیلدها باشند (مطابق فایل نمونه) — در مرحله «نگاشت ستون‌ها» اصلاح کنید و دوباره ثبت کنید.' );
                }

                // اعتبارسنجی match_key
                if ( '' !== $match_key && ! isset( $fields_by_slug[ $match_key ] ) ) {
                        return new WP_Error( 'tpp_bad_match_key', 'کلید تطبیق نامعتبر است.' );
                }
                if ( '' !== $match_key && 'service' !== $fields_by_slug[ $match_key ]['group_key'] ) {
                        return new WP_Error( 'tpp_bad_match_key', 'کلید تطبیق باید یک فیلد سرویس باشد.' );
                }

                $services = tpp()->services();
                // ۱.۸.۰ — ردیف‌های خالی فایل که در پیش‌نمایش حذف شدند در گزارش هم دیده می‌شوند
                $report   = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'linked' => 0, 'new_address' => 0, 'errors' => array(), 'conflicts' => 0, 'empty_rows' => (int) ( $session['empty_rows'] ?? 0 ) );
                /* ۱.۱۹.۰ — دسته‌بندی/تگ: برچسب‌های اکسل به شناسه تبدیل می‌شوند؛ ناموجودها خودکار ساخته می‌شوند */
                $report['categories_created'] = 0;
                $report['tags_created'] = 0;
                $in_file  = array(); // مقدار کلید → ردیف (تشخیص تکراری داخل فایل)
                $row_no   = 1;       // ردیف سرستون = ۱

                /* ۱.۱۵.۰ — پشتیبان خودکار کامل قبل از ثبت گروهی:
                 * در پوشه افزونه ذخیره می‌شود تا با یک کلیک وضعیتِ قبل از ایمپورت بازگردانی شود.
                 * اگر پشتیبان‌گیری ناموفق باشد ایمپورت متوقف می‌شود (زنجیره اطمینان نباید قطع شود)؛
                 * با خاموش کردن تنظیم «پشتیبان خودکار قبل از ایمپورت» می‌توان بدون پشتیبان ادامه داد. */
                $report['backup'] = null;
                $report['backup_auto'] = 0;
                if ( (int) tpp()->settings()->get( 'import_auto_backup', 1 ) ) {
                        $report['backup_auto'] = 1;
                        $backup = null;
                        try {
                                $backup = TPP_Backup::create( 'import', $user_id );
                        } catch ( Throwable $e ) {
                                $backup = new WP_Error( 'tpp_backup_error', $e->getMessage() );
                        }
                        if ( is_wp_error( $backup ) ) {
                                return new WP_Error( 'tpp_backup_failed', 'ایمپورت انجام نشد: پشتیبان‌گیری خودکار قبل از ثبت ناموفق بود — ' . $backup->get_error_message() . ' (برای ادامه بدون پشتیبان، «پشتیبان خودکار قبل از ایمپورت» را در تنظیمات خاموش کنید.)' );
                        }
                        $report['backup'] = $backup;
                }

                // ایندکس تشابه‌ها فقط وقتی لازم است ساخته می‌شود (برای تصمیم link/new)
                $idx = null;

                foreach ( $session['rows'] as $row ) {
                        $row_no++;
                        list( $address_data, $service_data, $progress_data, $tax_data ) = $this->split_row( $row, $mapping, $fields_by_slug );
                        if ( empty( $address_data ) && empty( $service_data ) && empty( $progress_data ) && empty( $tax_data ) ) {
                                if ( $this->row_is_empty( is_array( $row ) ? $row : array() ) ) {
                                        $report['empty_rows']++;
                                        continue; // ردیف کاملاً خالی (بدون هیچ داده‌ای) — بی‌صدا رد می‌شود
                                }
                                // ۱.۸.۰ — ردیف داده دارد اما هیچ ستون نگاشت‌شده‌ای مقدار ندارد؛ علت واقعی گزارش می‌شود
                                $report['skipped']++;
                                if ( count( $report['errors'] ) < 100 ) {
                                        $report['errors'][] = array( 'row' => $row_no, 'message' => 'این ردیف داده دارد اما هیچ ستون نگاشت‌شده‌ای مقدار ندارد — نگاشت ستون‌ها را در مرحله ۲ بررسی کنید (عنوان ستون شناخته نشده).' );
                                }
                                continue;
                        }

                        /* ۱.۱۹.۰ — رزولوی برچسب دسته/تگ به شناسه (برچسب ناموجود → تعریف خودکار) */
                        if ( ! empty( $tax_data['category'] ) ) {
                                list( $cat_ids, $cat_created ) = TPP_Categories::resolve_labels( 'category', $tax_data['category'], true );
                                $report['categories_created'] += $cat_created;
                                $tax_data['category'] = $cat_ids ? $cat_ids[0] : 0;
                        }
                        if ( ! empty( $tax_data['tags'] ) ) {
                                list( $tag_ids, $tag_created ) = TPP_Categories::resolve_labels( 'tag', $tax_data['tags'], true );
                                $report['tags_created'] += $tag_created;
                                $tax_data['tags'] = $tag_ids;
                        }

                        try {
                                // یافتن سرویس موجود بر اساس کلید تطبیق
                                // (مقادیر بی‌ارزش مثل تلفن 0 در تطبیق/تشخیص تکراری شرکت نمی‌کنند)
                                $existing_id = 0;
                                if ( '' !== $match_key && isset( $service_data[ $match_key ] ) && ! $this->is_trivial_key( $service_data[ $match_key ] ) ) {
                                        $key_val = $service_data[ $match_key ];
                                        if ( isset( $in_file[ $match_key ][ $key_val ] ) ) {
                                                $report['skipped']++;
                                                if ( count( $report['errors'] ) < 100 ) {
                                                        $report['errors'][] = array( 'row' => $row_no, 'message' => 'مقدار کلید تطبیق («' . $key_val . '») قبلاً در همین فایل آمده است.' );
                                                }
                                                continue;
                                        }
                                        $in_file[ $match_key ][ $key_val ] = true;
                                        $found = TPP_DB::get_row(
                                                "SELECT id FROM " . TPP_DB::table( 'services' ) . " WHERE {$match_key} = %s LIMIT 1",
                                                array( $key_val )
                                        );
                                        if ( $found ) {
                                                $existing_id = (int) $found['id'];
                                        }
                                }

                                if ( $existing_id > 0 ) {
                                        if ( 'create' === $mode ) {
                                                $report['skipped']++;
                                                if ( count( $report['errors'] ) < 100 ) {
                                                        $report['errors'][] = array( 'row' => $row_no, 'message' => 'سرویس موجود است و حالت «فقط ثبت جدید» انتخاب شده.' );
                                                }
                                                continue;
                                        }
                                        $update_payload = array(
                                                'service' => $service_data,
                                                'address' => $address_data,
                                        );
                                        if ( ! empty( $progress_data ) ) {
                                                $update_payload['progress'] = $progress_data; // ۱.۱۳.۰ — دایری از اکسل
                                        }
                                        if ( ! empty( $tax_data['category'] ) ) {
                                                $update_payload['category'] = $tax_data['category']; // ۱.۱۹.۰
                                        }
                                        if ( ! empty( $tax_data['tags'] ) ) {
                                                $update_payload['tags'] = $tax_data['tags']; // ۱.۱۹.۰
                                        }
                                        $result = $services->update( $existing_id, $update_payload, array( 'user_id' => $user_id, 'source' => 'import', 'force' => true ) );
                                        if ( is_wp_error( $result ) ) {
                                                $report['skipped']++;
                                                if ( count( $report['errors'] ) < 100 ) {
                                                        $report['errors'][] = array( 'row' => $row_no, 'message' => $result->get_error_message() );
                                                }
                                                continue;
                                        }
                                        $report['updated']++;
                                        if ( ! empty( $result['conflict'] ) ) {
                                                $report['conflicts']++;
                                        }
                                } else {
                                        if ( 'update' === $mode ) {
                                                $report['skipped']++;
                                                if ( count( $report['errors'] ) < 100 ) {
                                                        $report['errors'][] = array( 'row' => $row_no, 'message' => 'سرویسی با این کلید یافت نشد و حالت «فقط به‌روزرسانی» انتخاب شده.' );
                                                }
                                                continue;
                                        }
                                        // ۱.۱۳.۰ — ردیف فقط-دایری: بدون هیچ فیلد شناسایی، ثبت سرویس جدید بی‌معناست
                                        if ( empty( $address_data ) && empty( $service_data ) ) {
                                                $report['skipped']++;
                                                if ( count( $report['errors'] ) < 100 ) {
                                                        $report['errors'][] = array( 'row' => $row_no, 'message' => 'این ردیف فقط داده پیشرفت دایری دارد — برای به‌روزرسانی دایری، یک فیلد شناسایی (مثل شماره تلفن) + «کلید تشخیص سرویس تکراری» + حالت به‌روزرسانی لازم است.' );
                                                }
                                                continue;
                                        }

                                        // تصمیم ردیف مشابه: link (اتصال به آدرس موجود) یا new (آدرس جدید مستقل)
                                        $decision = '';
                                        if ( isset( $dup_rows[ $row_no ] ) && in_array( (string) $dup_rows[ $row_no ], array( 'link', 'new' ), true ) ) {
                                                $decision = (string) $dup_rows[ $row_no ];
                                        }
                                        $create_args = array( 'user_id' => $user_id, 'source' => 'import' );

                                        if ( null === $idx ) {
                                                $idx = $this->match_indexes();
                                        }
                                        $m = $this->match_row( $address_data, $service_data, $idx );
                                        if ( null !== $m ) {
                                                if ( '' === $decision ) {
                                                        $decision = $dup_mode;
                                                }
                                                if ( 'link' === $decision ) {
                                                        $create_args['address_id'] = $m['address_id']; // سرویس جدید برای آدرس موجود
                                                        $address_data = array(); // فیلدهای آدرس موجود دست نمی‌خورد
                                                        $report['linked']++;
                                                } else { // 'new'
                                                        $create_args['force_new_address'] = true; // شباهت نادیده گرفته شود
                                                        $report['new_address']++;
                                                }
                                        }

                                        $create_data = array(
                                                'address' => $address_data,
                                                'service' => $service_data,
                                        );
                                        if ( ! empty( $progress_data ) ) {
                                                $create_data['progress'] = $progress_data; // ۱.۱۳.۰ — دایری از اکسل
                                        }
                                        if ( ! empty( $tax_data['category'] ) ) {
                                                $create_data['category'] = $tax_data['category']; // ۱.۱۹.۰ — دسته از اکسل
                                        }
                                        if ( ! empty( $tax_data['tags'] ) ) {
                                                $create_data['tags'] = $tax_data['tags']; // ۱.۱۹.۰ — تگ‌ها از اکسل
                                        }
                                        if ( ! empty( $create_args['address_id'] ) ) {
                                                $create_data['address_id'] = (int) $create_args['address_id'];
                                        }
                                        if ( ! empty( $create_args['force_new_address'] ) ) {
                                                $create_data['force_new_address'] = true;
                                        }

                                        $result = $services->create( $create_data, $create_args );
                                        if ( is_wp_error( $result ) ) {
                                                $report['skipped']++;
                                                if ( count( $report['errors'] ) < 100 ) {
                                                        $report['errors'][] = array( 'row' => $row_no, 'message' => $result->get_error_message() );
                                                }
                                                continue;
                                        }
                                        $report['created']++;
                                }
                        } catch ( Throwable $e ) {
                                $report['skipped']++;
                                if ( count( $report['errors'] ) < 100 ) {
                                        $report['errors'][] = array( 'row' => $row_no, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage() );
                                }
                        }
                }

                delete_transient( 'tpp_import_' . $session_id );
                do_action( 'tpp_import_done', $report, $user_id );
                return $report;
        }
}
