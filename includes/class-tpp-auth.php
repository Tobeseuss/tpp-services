<?php
/**
 * احراز هویت اختصاصی افزونه — توکن بلندعمر برای کار آفلاین و عدم خروج کاربر.
 *
 * مکانیزم:
 *  ۱) توکن تصادفی ۶۴ کاراکتری هنگام ورود/بوت‌استرپ صادر و هش آن در user_meta نگهداری می‌شود.
 *  ۲) اپ توکن را در localStorage نگه می‌دارد و با هدر X-TPP-Token ارسال می‌کند؛
 *     REST بدون نیاز به nonce وردپرس کاربر را شناسایی می‌کند → حتی اگر کوکی/نونس منقضی شده
 *     باشد، کاربر از افزونه خارج نمی‌شود.
 *  ۳) کوکی نشست وردپرس برای نقش‌های افزونه تا سقف تنظیمات تمدید می‌شود (حالت پیش‌فرض: نامحدود/۱۰ سال)
 *     و با heartbeat هنگام فعالیت، تمدید خودکار انجام می‌شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Auth {

        const TOKEN_HEADER = 'X-TPP-Token';
        const META_TOKENS   = 'tpp_tokens';
        const INDEX_OPTION  = 'tpp_token_index';

        public function __construct() {
                add_filter( 'determine_current_user', array( $this, 'rest_token_auth' ), 20 );
                add_filter( 'rest_authentication_errors', array( $this, 'bypass_cookie_nonce_for_token' ), 200 );
                add_filter( 'auth_cookie_expiration', array( $this, 'extend_session' ), 20, 3 );
                add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
                add_action( 'after_password_reset', array( $this, 'revoke_on_password_change' ), 10, 2 );
                add_action( 'profile_update', array( $this, 'maybe_revoke_on_profile_password' ), 10, 2 );
        }

        /** شناسایی کاربر در REST از طریق توکن */
        public function rest_token_auth( $user_id ) {
                if ( ! empty( $user_id ) || ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
                        return $user_id;
                }
                $token = isset( $_SERVER['HTTP_X_TPP_TOKEN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_X_TPP_TOKEN'] ) ) : '';
                if ( '' === $token ) {
                        return $user_id;
                }
                $found = $this->validate_token( $token );
                if ( is_int( $found ) && $found > 0 ) {
                        return $found;
                }
                return $user_id;
        }

        /**
         * اگر کاربر با توکن TPP شناسایی شده باشد، الزال nonce کوکی وردپرس برای درخواست‌های نوشتاری برداشته می‌شود
         * (همان الگوی افزونه‌های JWT) — کار آفلاین اپ بدون نقض امنیت ممکن می‌ماند.
         */
        public function bypass_cookie_nonce_for_token( $result ) {
                if ( ! empty( $result ) && is_wp_error( $result ) && 'rest_cookie_invalid_nonce' === $result->get_error_code() ) {
                        $token = isset( $_SERVER['HTTP_X_TPP_TOKEN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_X_TPP_TOKEN'] ) ) : '';
                        if ( '' !== $token ) {
                                $uid = $this->validate_token( $token );
                                if ( $uid ) {
                                        return true; // احراز هویت با توکن معتبر — nonce لازم نیست
                                }
                        }
                }
                return $result;
        }

        /** تمدید نشست وردپرس برای کاربران افزونه (حالت نامحدود پیش‌فرض) */
        public function extend_session( $expiration, $user_id, $remember ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) {
                        return $expiration;
                }
                $has_tpp_role = false;
                foreach ( (array) $user->roles as $role ) {
                        if ( 0 === strpos( $role, 'tpp_' ) || 'administrator' === $role ) {
                                $has_tpp_role = true;
                                break;
                        }
                }
                if ( ! $has_tpp_role ) {
                        return $expiration;
                }
                $settings = tpp()->settings();
                $days     = (int) $settings->get( 'session_days', 3650 );
                if ( 'days' === $settings->get( 'session_mode' ) ) {
                        $days = min( $days, 3650 );
                } else {
                        $days = 3650; // نامحدود (۱۰ سال)
                }
                $custom = $days * DAY_IN_SECONDS;
                return max( $expiration, $custom );
        }

        /** هنگام ورود موفق وردپرس، توکن برای اپ صادر می‌شود */
        public function on_login( $user_login, $user ) {
                if ( $user instanceof WP_User && $this->user_has_tpp_access( $user->ID ) ) {
                        $tokens = (array) get_user_meta( $user->ID, self::META_TOKENS, true );
                        if ( count( $tokens ) >= 10 ) {
                                return; // هر کاربر حداکثر ۱۰ توکن (ده دستگاه)
                        }
                        $this->issue_token( $user->ID, 'ورود وردپرس' );
                }
        }

        public function user_has_tpp_access( $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) {
                        return false;
                }
                if ( $user->has_cap( 'manage_options' ) ) {
                        return true;
                }
                foreach ( (array) $user->roles as $role ) {
                        if ( 0 === strpos( $role, 'tpp_' ) ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * صدور توکن جدید — مقدار خام فقط یک‌بار برگردانده می‌شود؛ سرور فقط هش را نگه می‌دارد.
         */
        public function issue_token( $user_id, $label = '' ) {
                $token = 'tpp_' . bin2hex( random_bytes( 32 ) );
                $hash  = hash( 'sha256', $token );

                /* ۱.۱۱.۰ — متای خالی ('') در PHP به [''] تبدیل می‌شود و کلید شبح ایجاد می‌کرد؛
                   همیشه آرایه تمیز بسازیم تا اندیس ابطال توکن‌ها جابه‌جا نشود */
                $tokens = get_user_meta( $user_id, self::META_TOKENS, true );
                $tokens = is_array( $tokens ) ? $tokens : array();
                foreach ( $tokens as $k => $v ) {
                        if ( ! is_array( $v ) ) {
                                unset( $tokens[ $k ] ); // پاکسازی کلیدهای شبح نصب‌های قدیمی
                        }
                }
                $tokens[ $hash ] = array(
                        'label'      => sanitize_text_field( $label ) ? sanitize_text_field( $label ) : 'دستگاه',
                        'created_at' => TPP_Date::now(),
                        'last_used'  => '',
                        'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 190 ) : '',
                );
                update_user_meta( $user_id, self::META_TOKENS, $tokens );

                $index = (array) get_option( self::INDEX_OPTION, array() );
                $index[ $hash ] = (int) $user_id;
                update_option( self::INDEX_OPTION, $index, false );

                return $token;
        }

        /** اعتبارسنجی توکن خام → user_id یا false */
        public function validate_token( $token ) {
                if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
                        return false;
                }
                $hash  = hash( 'sha256', $token );
                $index = (array) get_option( self::INDEX_OPTION, array() );
                if ( empty( $index[ $hash ] ) ) {
                        return false;
                }
                $user_id = (int) $index[ $hash ];
                $tokens  = (array) get_user_meta( $user_id, self::META_TOKENS, true );
                if ( empty( $tokens[ $hash ] ) || ! $this->user_has_tpp_access( $user_id ) ) {
                        return false;
                }
                // به‌روزرسانی آخرین استفاده (سبک)
                if ( empty( $tokens[ $hash ]['last_used'] ) || time() - strtotime( (string) $tokens[ $hash ]['last_used'] ) > 300 ) {
                        $tokens[ $hash ]['last_used'] = TPP_Date::now();
                        update_user_meta( $user_id, self::META_TOKENS, $tokens );
                }
                return $user_id;
        }

        /** ابطال یک توکن خام */
        public function revoke_token( $token ) {
                $hash  = hash( 'sha256', (string) $token );
                $index = (array) get_option( self::INDEX_OPTION, array() );
                if ( empty( $index[ $hash ] ) ) {
                        return false;
                }
                $user_id = (int) $index[ $hash ];
                $tokens  = (array) get_user_meta( $user_id, self::META_TOKENS, true );
                unset( $tokens[ $hash ] );
                update_user_meta( $user_id, self::META_TOKENS, $tokens );
                unset( $index[ $hash ] );
                update_option( self::INDEX_OPTION, $index, false );
                return true;
        }

        /** ابطال همه توکن‌های یک کاربر (تغییر رمز / خروج از همه دستگاه‌ها) */
        public function revoke_all( $user_id ) {
                $tokens = (array) get_user_meta( $user_id, self::META_TOKENS, true );
                $index  = (array) get_option( self::INDEX_OPTION, array() );
                foreach ( array_keys( $tokens ) as $hash ) {
                        unset( $index[ $hash ] );
                }
                update_option( self::INDEX_OPTION, $index, false );
                delete_user_meta( $user_id, self::META_TOKENS );
                return true;
        }

        public function revoke_on_password_change( $user, $new_pass ) {
                if ( $user instanceof WP_User ) {
                        $this->revoke_all( $user->ID );
                }
        }

        public function maybe_revoke_on_profile_password( $user_id, $old_user_data ) {
                if ( isset( $_POST['pass1'] ) && ! empty( $_POST['pass1'] ) ) {
                        $this->revoke_all( $user_id );
                }
        }

        /** فهرست دستگاه‌ها/توکن‌های کاربر (بدون مقادیر خام) */
        public function tokens_of( $user_id ) {
                $meta = get_user_meta( $user_id, self::META_TOKENS, true );
                $tokens = is_array( $meta ) ? $meta : array();
                $out = array();
                $i = 0;
                foreach ( $tokens as $hash => $info ) {
                        if ( ! is_array( $info ) ) {
                                continue; // کلید شبح قدیمی
                        }
                        $out[] = array(
                                'idx'        => $i,
                                'hash'       => substr( $hash, 0, 8 ),
                                'label'      => $info['label'] ?? '',
                                'created_at' => $info['created_at'] ?? '',
                                'last_used'  => $info['last_used'] ?? '',
                                'user_agent' => $info['user_agent'] ?? '',
                        );
                        $i++;
                }
                return $out;
        }

        /** ابطال با اندیس — اندیس همان ترتیب فهرست tokens_of (فقط کلیدهای معتبر شمرده می‌شوند) */
        public function revoke_by_idx( $user_id, $idx ) {
                $meta = get_user_meta( $user_id, self::META_TOKENS, true );
                if ( ! is_array( $meta ) ) {
                        return false;
                }
                $keys = array();
                foreach ( $meta as $hash => $info ) {
                        if ( is_array( $info ) ) {
                                $keys[] = $hash;
                        }
                }
                $idx = (int) $idx;
                if ( ! isset( $keys[ $idx ] ) ) {
                        return false;
                }
                $hash = $keys[ $idx ];
                unset( $meta[ $hash ] );
                update_user_meta( $user_id, self::META_TOKENS, $meta );
                $index = (array) get_option( self::INDEX_OPTION, array() );
                unset( $index[ $hash ] );
                update_option( self::INDEX_OPTION, $index, false );
                return true;
        }

        /**
         * ورود از داخل اپ (بدون فرم وردپرس) — با محدودیت تلاش.
         */
        public function login( $username, $password, $remember = true ) {
                $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
                $throttle_key = 'tpp_login_fails_' . md5( $ip );
                $fails = (int) get_transient( $throttle_key );
                if ( $fails >= 5 ) {
                        return new WP_Error( 'tpp_throttled', 'تلاش‌های ناموفق زیاد بوده؛ ۱۵ دقیقه بعد دوباره امتحان کنید.' );
                }

                $user = wp_authenticate( sanitize_user( $username ), (string) $password );
                if ( is_wp_error( $user ) ) {
                        set_transient( $throttle_key, $fails + 1, 15 * MINUTE_IN_SECONDS );
                        return new WP_Error( 'tpp_invalid_login', 'نام کاربری یا رمز عبور نادرست است.' );
                }
                if ( ! $this->user_has_tpp_access( $user->ID ) ) {
                        return new WP_Error( 'tpp_no_access', 'این حساب کاربری به افزونه دسترسی ندارد.' );
                }

                delete_transient( $throttle_key );
                $token = $this->issue_token( $user->ID, 'اپ TPP' );
                if ( $remember ) {
                        wp_set_current_user( $user->ID );
                        wp_set_auth_cookie( $user->ID, true ); // همگام‌سازی نشست وردپرس
                }
                do_action( 'tpp_app_login', $user->ID );
                return array(
                        'token'    => $token,
                        'nonce'    => wp_create_nonce( 'wp_rest' ),
                        'user'     => array( 'id' => $user->ID, 'name' => $user->display_name, 'login' => $user->user_login ),
                );
        }

        /**
         * تمدید خودکار کوکی نشست (heartbeat) — فقط وقتی کوکی در ۳۰ روز آینده منقضی می‌شود
         * تا نشست‌های تکراری در وردپرس ساخته نشود.
         */
        public function heartbeat_cookie_refresh() {
                if ( ! is_user_logged_in() ) {
                        return;
                }
                $cookie = '';
                if ( defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
                        $cookie = (string) wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] );
                }
                if ( '' === $cookie ) {
                        return;
                }
                $parts = explode( '|', $cookie );
                if ( count( $parts ) < 2 ) {
                        return;
                }
                $expires = (int) $parts[1];
                if ( $expires > 0 && ( $expires - time() ) < 30 * DAY_IN_SECONDS ) {
                        wp_set_auth_cookie( get_current_user_id(), true );
                }
        }
}
