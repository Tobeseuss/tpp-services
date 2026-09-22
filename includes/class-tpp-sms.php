<?php
/**
 * پنل پیامک — یکپارچگی با سرویس SMS.ir (https://sms.ir/rest-api/)
 *
 * امکانات:
 *  - نمایش موجودی پیامک‌های باقیمانده (GET /v1/credit)
 *  - ارسال پیامک به شماره موبایل ثبت‌شده سرویس یا شماره دلخواه
 *  - قالب‌های پیامک با عنوان + متن و جای‌نگهدار {{نام‌کد_فیلد}} (مثل {{f_owner_name}})
 *  - ثبت گزارش ارسال‌ها در جدول اختصاصی tpp_sms_log
 *
 * احراز هویت سرویس: هدر X-API-KEY (کلید از پنل کاربری sms.ir بخش «کلیدهای دسترسی»)
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_SMS {

        const API_BASE    = 'https://api.sms.ir/v1';
        const CREDIT_CACHE = 'tpp_sms_credit'; // transient

        public function __construct() {
                add_action( 'tpp_settings_updated', array( $this, 'flush_credit_cache' ) );
        }

        /* ==================== تنظیمات ==================== */

        public function api_key() {
                return (string) tpp()->settings()->get( 'sms_api_key', '' );
        }

        public function line_number() {
                return (string) tpp()->settings()->get( 'sms_line_number', '' );
        }

        public function is_configured() {
                return '' !== $this->api_key();
        }

        public function flush_credit_cache() {
                delete_transient( self::CREDIT_CACHE );
        }

        /* ==================== نرمال‌سازی موبایل ==================== */

        /** تبدیل ارقام فارسی/عربی و پیش‌شماره‌ها به قالب استاندارد 09xxxxxxxxx */
        public static function normalize_mobile( $raw ) {
                $fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
                $ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
                $en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
                $mobile = str_replace( $ar, $en, str_replace( $fa, $en, (string) $raw ) );
                $mobile = preg_replace( '/[^0-9]/', '', $mobile );

                // 0098xxxxxxxxxx / +98xxxxxxxxxx / 98xxxxxxxxxx → 0xxxxxxxxxx
                if ( 0 === strpos( $mobile, '0098' ) ) {
                        $mobile = '0' . substr( $mobile, 4 );
                } elseif ( 0 === strpos( $mobile, '98' ) && 12 === strlen( $mobile ) ) {
                        $mobile = '0' . substr( $mobile, 2 );
                } elseif ( 10 === strlen( $mobile ) && 0 === strpos( $mobile, '9' ) ) {
                        $mobile = '0' . $mobile; // 9xxxxxxxxx → 09xxxxxxxxx
                }
                return $mobile;
        }

        public static function is_valid_mobile( $mobile ) {
                return (bool) preg_match( '/^09[0-9]{9}$/', (string) $mobile );
        }

        /* ==================== کلاینت SMS.ir ==================== */

        private function remote_request( $method, $endpoint, $body = null ) {
                if ( ! $this->is_configured() ) {
                        return new WP_Error( 'tpp_sms_not_configured', 'کلید API پنل پیامک تنظیم نشده است. ابتدا از تنظیمات افزونه کلید را وارد کنید.', array( 'status' => 400 ) );
                }
                $args = array(
                        'method'  => $method,
                        'timeout' => 25,
                        'headers' => array(
                                'X-API-KEY'     => $this->api_key(),
                                'Content-Type'  => 'application/json',
                                'Accept'        => 'application/json',
                        ),
                );
                if ( null !== $body ) {
                        $args['body'] = wp_json_encode( $body );
                }
                $response = wp_remote_request( self::API_BASE . $endpoint, $args );

                if ( is_wp_error( $response ) ) {
                        return new WP_Error( 'tpp_sms_http', 'خطای ارتباط با سرور پیامک: ' . $response->get_error_message(), array( 'status' => 502 ) );
                }
                $code = (int) wp_remote_retrieve_response_code( $response );
                $json = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $json ) ) {
                        return new WP_Error( 'tpp_sms_bad_response', 'پاسخ نامعتبر از سرور پیامک (کد ' . $code . ').', array( 'status' => 502 ) );
                }
                $ok  = isset( $json['status'] ) && (int) $json['status'] === 1;
                $msg = isset( $json['message'] ) ? (string) $json['message'] : '';
                if ( ! $ok ) {
                        /* translators: %s: پیام خطای سرویس پیامک */
                        return new WP_Error( 'tpp_sms_error', $msg ? $msg : sprintf( 'خطای سرویس پیامک (کد %d).', $code ), array( 'status' => 502, 'raw' => $json ) );
                }
                return array(
                        'message' => $msg,
                        'data'    => isset( $json['data'] ) ? $json['data'] : null,
                );
        }

        /** موجودی پیامک‌های باقیمانده (با کش ۵ دقیقه‌ای) */
        public function credit( $fresh = false ) {
                if ( ! $fresh ) {
                        $cached = get_transient( self::CREDIT_CACHE );
                        if ( false !== $cached && '' !== $cached ) {
                                return $cached;
                        }
                }
                $result = $this->remote_request( 'GET', '/credit' );
                if ( is_wp_error( $result ) ) {
                        return $result;
                }
                $credit = is_array( $result['data'] ) && isset( $result['data']['credit'] ) ? $result['data']['credit'] : $result['data'];
                $credit = round( (float) $credit, 2 );
                set_transient( self::CREDIT_CACHE, $credit, 5 * MINUTE_IN_SECONDS );
                return $credit;
        }

        /**
         * ارسال پیامک — اگر شماره خط تنظیم شده باشد از ارسال گروهی (bulk) و در غیر این صورت از ارسال سریع استفاده می‌شود.
         *
         * @param string|array $mobiles یک شماره یا آرایه‌ای از شماره‌ها (حداکثر ۱۰۰)
         * @param string       $message متن پیامک
         * @return array|WP_Error
         */
        public function send( $mobiles, $message ) {
                $mobiles = is_array( $mobiles ) ? array_values( $mobiles ) : array( $mobiles );
                $clean   = array();
                foreach ( $mobiles as $m ) {
                        $m = self::normalize_mobile( $m );
                        if ( ! self::is_valid_mobile( $m ) ) {
                                /* translators: %s: شماره موبایل نامعتبر */
                                return new WP_Error( 'tpp_sms_bad_mobile', sprintf( 'شماره موبایل «%s» معتبر نیست. قالب درست: 09xxxxxxxxx', $m ), array( 'status' => 400 ) );
                        }
                        $clean[] = $m;
                }
                if ( empty( $clean ) ) {
                        return new WP_Error( 'tpp_sms_no_mobile', 'شماره موبایل وارد نشده است.', array( 'status' => 400 ) );
                }
                if ( count( $clean ) > 100 ) {
                        return new WP_Error( 'tpp_sms_too_many', 'حداکثر ۱۰۰ شماره در هر ارسال.', array( 'status' => 400 ) );
                }
                $message = trim( (string) $message );
                if ( '' === $message ) {
                        return new WP_Error( 'tpp_sms_empty', 'متن پیامک خالی است.', array( 'status' => 400 ) );
                }

                $line = $this->line_number();
                if ( '' !== $line ) {
                        $result = $this->remote_request( 'POST', '/send/bulk', array(
                                'lineNumber'    => $line,
                                'messageText'   => $message,
                                'mobiles'       => $clean,
                                'sendDateTime'  => null,
                        ) );
                } else {
                        // ارسال سریع — بدون نیاز به شماره خط (خط پیش‌فرض پنل)
                        $result = $this->remote_request( 'POST', '/send/send', array(
                                'messageText'   => $message,
                                'mobiles'       => $clean,
                                'sendDateTime'  => null,
                        ) );
                }
                return $result;
        }

        /* ==================== قالب‌های پیامک ==================== */

        /** همه قالب‌ها */
        public function templates() {
                $rows = TPP_DB::get_results( "SELECT * FROM " . TPP_DB::table( 'sms_templates' ) . " ORDER BY id ASC" );
                if ( ! is_array( $rows ) ) {
                        return array(); // جدول هنوز ساخته نشده یا خطا — مودال نباید بشکند
                }
                foreach ( $rows as &$r ) {
                        $r['id'] = (int) $r['id'];
                }
                unset( $r );
                return $rows;
        }

        public function template( $id ) {
                return TPP_DB::get_row( "SELECT * FROM " . TPP_DB::table( 'sms_templates' ) . " WHERE id = %d", array( (int) $id ) );
        }

        public function save_template( $args, $id = 0 ) {
                $title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
                $body  = trim( (string) ( $args['body'] ?? '' ) );
                $body  = preg_replace( '/<\/?script.*?>/i', '', $body );
                $body  = mb_substr( $body, 0, 2000 );
                if ( '' === $title ) {
                        return new WP_Error( 'tpp_bad_title', 'عنوان قالب الزامی است.', array( 'status' => 400 ) );
                }
                if ( '' === $body ) {
                        return new WP_Error( 'tpp_bad_body', 'متن قالب الزامی است.', array( 'status' => 400 ) );
                }
                $data = array(
                        'title'      => $title,
                        'body'       => $body,
                        'updated_at' => TPP_Date::now(),
                );
                if ( $id ) {
                        TPP_DB::update( 'sms_templates', $data, array( 'id' => (int) $id ) );
                        return array( 'id' => (int) $id );
                }
                $data['created_at'] = TPP_Date::now();
                $new_id = TPP_DB::insert( 'sms_templates', $data );
                if ( ! $new_id ) {
                        return new WP_Error( 'tpp_insert_failed', 'ثبت قالب ناموفق بود.', array( 'status' => 500 ) );
                }
                return array( 'id' => (int) $new_id );
        }

        public function delete_template( $id ) {
                // اگر قالبِ پیش‌فرض سیستم حذف شد، عنوانش ثبت شود تا در ارتقاهای بعدی دوباره ساخته نشود
                $row = TPP_DB::get_row( "SELECT title FROM " . TPP_DB::table( 'sms_templates' ) . " WHERE id = %d", array( (int) $id ) );
                if ( $row ) {
                        $defaults = array( 'مشخصات ورود به سرویس (پیش‌فرض)', 'ارسال مشخصات به کاربر' );
                        if ( in_array( $row['title'], $defaults, true ) ) {
                                $skip   = get_option( 'tpp_seed_skip_tpl' );
                                $skip   = is_array( $skip ) ? $skip : array();
                                $skip[] = $row['title'];
                                update_option( 'tpp_seed_skip_tpl', array_values( array_unique( $skip ) ), false );
                        }
                }
                TPP_DB::delete( 'sms_templates', array( 'id' => (int) $id ) );
                return true;
        }

        /* ==================== موتور قالب (جای‌نگهدارها) ==================== */

        /**
         * تبدیل جای‌نگهدارها به مقادیر واقعی سرویس.
         * فرمت: {{نام‌کد_فیلد}} مثل {{f_owner_name}} — به‌علاوه {{service_id}} و {{site_name}}
         *
         * @param string       $body    متن قالب
         * @param array        $service ردیف خام سرویس (شامل فیلدها)
         * @param array|null   $address ردیف خام آدرس
         * @param array|null   $visible نقشه فیلدهای قابل مشاهده کاربر — مقدار فیلد پنهان خالی می‌شود
         * @return string
         */
        public static function render( $body, $service, $address = null, $visible = null ) {
                $values = array();
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        $slug = $f['slug'];
                        $show = null === $visible || ! empty( $visible[ $slug ] );
                        $values[ $slug ] = $show ? (string) ( $service[ $slug ] ?? '' ) : '';
                }
                if ( is_array( $address ) ) {
                        foreach ( TPP_Fields::all( 'address' ) as $f ) {
                                $slug = $f['slug'];
                                $show = null === $visible || ! empty( $visible[ $slug ] );
                                $values[ $slug ] = $show ? (string) ( $address[ $slug ] ?? '' ) : '';
                        }
                }
                $values['service_id'] = isset( $service['id'] ) ? (string) $service['id'] : '';
                $values['site_name']  = get_bloginfo( 'name' );

                return preg_replace_callback(
                        '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u',
                        function ( $m ) use ( $values ) {
                                $key = $m[1];
                                return isset( $values[ $key ] ) ? $values[ $key ] : '';
                        },
                        (string) $body
                );
        }

        /** متن قالب کپی مشخصات (OMC) — اگر تنظیم نشده باشد قالب پیش‌فرض ساخته می‌شود */
        public function copy_template() {
                $tpl = (string) tpp()->settings()->get( 'sms_copy_template', '' );
                if ( '' === trim( $tpl ) ) {
                        $tpl = TPP_Settings::default_copy_template();
                }
                return $tpl;
        }

        /* ==================== ارسال با قالب + گزارش ==================== */

        /**
         * ارسال پیامک برای یک سرویس — متن از قالب رندر می‌شود.
         *
         * @param int    $service_id
         * @param int    $template_id
         * @param string $mobile      شماره مقصد (اختیاری — پیش‌فرض: فیلد شماره موبایل سرویس)
         * @param string $custom_text متن صریح به‌جای قالب (اختیاری)
         * @param int    $user_id     ارسال‌کننده
         */
        public function send_for_service( $service_id, $template_id = 0, $mobile = '', $custom_text = '', $user_id = 0 ) {
                $service = tpp()->services()->get( (int) $service_id );
                if ( ! $service ) {
                        return new WP_Error( 'tpp_not_found', 'سرویس یافت نشد.', array( 'status' => 404 ) );
                }
                $address = tpp()->services()->get_address( (int) $service['address_id'] );
                $visible = TPP_Capabilities::visible_fields( $user_id );

                $template = null;
                if ( $template_id ) {
                        $template = $this->template( $template_id );
                        if ( ! $template ) {
                                return new WP_Error( 'tpp_not_found', 'قالب پیامک یافت نشد.', array( 'status' => 404 ) );
                        }
                }

                // شماره مقصد: ورودی دستی یا فیلد موبایل سرویس
                $dest = self::normalize_mobile( $mobile );
                if ( '' === $dest && ! empty( $visible['f_mobile'] ) ) {
                        $dest = self::normalize_mobile( $service['f_mobile'] ?? '' );
                }

                // متن: متن صریح یا رندر قالب (فیلدهای پنهان خالی می‌مانند)
                if ( '' !== trim( (string) $custom_text ) ) {
                        $message = trim( (string) $custom_text );
                } elseif ( $template ) {
                        $message = self::render( $template['body'], $service, $address, $visible );
                } else {
                        return new WP_Error( 'tpp_no_text', 'قالب یا متن پیامک مشخص نشده است.', array( 'status' => 400 ) );
                }

                $result = $this->send( $dest, $message );
                $this->log_send( array(
                        'user_id'     => (int) $user_id,
                        'service_id'  => (int) $service_id,
                        'template_id' => $template ? (int) $template['id'] : 0,
                        'mobile'      => $dest,
                        'message'     => $message,
                        'status'      => is_wp_error( $result ) ? 'failed' : 'sent',
                        'error'       => is_wp_error( $result ) ? $result->get_error_message() : '',
                ) );

                if ( is_wp_error( $result ) ) {
                        return $result;
                }
                return array(
                        'status'  => 'sent',
                        'message' => 'پیامک با موفقیت ارسال شد.',
                        'mobile'  => $dest,
                        'text'    => $message,
                        'credit'  => $this->credit( true ),
                );
        }

        public function log_send( $args ) {
                TPP_DB::insert( 'sms_log', array(
                        'user_id'     => (int) ( $args['user_id'] ?? 0 ),
                        'service_id'  => (int) ( $args['service_id'] ?? 0 ),
                        'template_id' => (int) ( $args['template_id'] ?? 0 ),
                        'mobile'      => (string) ( $args['mobile'] ?? '' ),
                        'message'     => (string) ( $args['message'] ?? '' ),
                        'status'      => (string) ( $args['status'] ?? '' ),
                        'error'       => (string) ( $args['error'] ?? '' ),
                        'created_at'  => TPP_Date::now(),
                ) );
        }

        /** گزارش آخرین ارسال‌ها */
        public function log( $per_page = 50 ) {
                $per_page = max( 1, min( 200, (int) $per_page ) );
                $rows = TPP_DB::get_results(
                        "SELECT l.* FROM " . TPP_DB::table( 'sms_log' ) . " l ORDER BY l.id DESC LIMIT %d",
                        array( $per_page )
                );
                // نام کاربران با get_userdata خوانده می‌شود تا در حالت دیتابیس جداگانه هم JOIN بین‌دیتابیس لازم نشود
                $user_names = array();
                foreach ( $rows as &$r ) {
                        $r['id']     = (int) $r['id'];
                        $r['user_id'] = (int) $r['user_id'];
                        $r['service_id'] = (int) $r['service_id'];
                        $r['template_id'] = (int) $r['template_id'];
                        $r['message'] = mb_substr( (string) $r['message'], 0, 160 );
                        $uid = $r['user_id'];
                        if ( $uid && ! isset( $user_names[ $uid ] ) ) {
                                $u = get_userdata( $uid );
                                $user_names[ $uid ] = $u ? $u->display_name : '—';
                        }
                        $r['user_name'] = isset( $user_names[ $uid ] ) ? $user_names[ $uid ] : '—';
                        if ( $r['template_id'] && ! isset( $this->_tpl_titles[ $r['template_id'] ] ) ) {
                                $tpl = $this->template( $r['template_id'] );
                                $this->_tpl_titles[ $r['template_id'] ] = $tpl ? $tpl['title'] : '';
                        }
                        $r['template_title'] = isset( $this->_tpl_titles[ $r['template_id'] ] ) ? $this->_tpl_titles[ $r['template_id'] ] : '';
                }
                unset( $r );
                return $rows;
        }

        /** @var array کش عنوان قالب‌ها برای گزارش */
        private $_tpl_titles = array();

        /** شماره موبایل اولین فیلد tel با نام‌کد f_mobile یا مشابه */
        public static function mobile_field_slug() {
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( 'f_mobile' === $f['slug'] ) {
                                return $f['slug'];
                        }
                }
                foreach ( TPP_Fields::all( 'service' ) as $f ) {
                        if ( false !== stripos( $f['slug'], 'mobile' ) ) {
                                return $f['slug'];
                        }
                }
                return '';
        }

        /** قالب‌های پیش‌فرض (نصب جدید — فقط وقتی هیچ قالبی وجود ندارد) */
        public static function seed_default_templates() {
                $count = (int) TPP_DB::get_var( "SELECT COUNT(*) FROM " . TPP_DB::table( 'sms_templates' ) );
                if ( $count > 0 ) {
                        return;
                }
                $now  = TPP_Date::now();
                $rows = array(
                        array(
                                'title' => 'مشخصات ورود به سرویس (پیش‌فرض)',
                                'body'  => self::default_login_template(),
                        ),
                        array(
                                'title' => 'ارسال مشخصات به کاربر',
                                'body'  => "{{f_owner_name}} عزیز\nمشخصات سرویس شما:\nآدرس: {{f_full_address}}\nشماره تلفن: {{f_phone}}\nوضعیت اینترنت: {{f_internet_status}}\nوضعیت تلفن: {{f_phone_status}}",
                        ),
                );
                foreach ( $rows as $r ) {
                        TPP_DB::insert( 'sms_templates', array(
                                'title'      => $r['title'],
                                'body'       => $r['body'],
                                'created_at' => $now,
                                'updated_at' => $now,
                        ) );
                }
        }

        /**
         * قالب پیش‌فرض «مشخصات ورود» — نام کاربری = شماره موبایل، رمز عبور = کد ملی مالک سرویس
         * + لینک پرتال مدیریت سرویس myftth.tci.ir (درخواست کاربر)
         */
        public static function default_login_template() {
                return "{{f_owner_name}} عزیز؛ سرویس اینترنت شما فعال شد.\n"
                        . "نام کاربری: {{f_mobile}}\n"
                        . "رمز عبور: {{f_national_id_service}}\n"
                        . "برای مدیریت سرویس (مشاهده مصرف، تغییر رمز و…) به نشانی https://myftth.tci.ir/ وارد شوید.";
        }

        /**
         * افزودن قالب‌های پیش‌فرضِ نسخه‌های جدید به نصب‌های موجود (بدون دست‌زدن به قالب‌های کاربر).
         * اگر کاربر قالب هم‌عنوان را حذف کرده باشد، با پرچم tpp_seed_skip دوباره ساخته نمی‌شود.
         */
        public static function seed_missing_templates() {
                $now  = TPP_Date::now();
                $want = array(
                        'مشخصات ورود به سرویس (پیش‌فرض)' => self::default_login_template(),
                );
                foreach ( $want as $title => $body ) {
                        $exists = (int) TPP_DB::get_var(
                        "SELECT COUNT(*) FROM " . TPP_DB::table( 'sms_templates' ) . " WHERE title = %s",
                                array( $title )
                        );
                        if ( $exists > 0 ) {
                                continue;
                        }
                        // حذف عمدی کاربر؟
                        $skip = get_option( 'tpp_seed_skip_tpl' );
                        $skip = is_array( $skip ) ? $skip : array();
                        if ( in_array( $title, $skip, true ) ) {
                                continue;
                        }
                        TPP_DB::insert( 'sms_templates', array(
                                'title'      => $title,
                                'body'       => $body,
                                'created_at' => $now,
                                'updated_at' => $now,
                        ) );
                }
        }
}
