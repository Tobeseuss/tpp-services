<?php
/**
 * شورت‌کد اپ — نمایش مدیریت سرویس‌ها در هر صفحه/نوشته سایت
 *
 * استفاده: [tpp_services]  (یا [tpp_app])
 * پارامترها: height="900" (ارتفاع ثابت پیکسلی؛ پیش‌فرض: خودکار)
 *
 * - کاربرِ واردشده وردپرس که به افزونه دسترسی دارد → اپ کامل داخل صفحه (ورود خودکار با نشست وردپرس)
 * - کاربر وارد نشده → پیام ورود با لینک ورود وردپرس (بازگشت به همین صفحه)
 * - کاربر بدون نقش افزونه → پیام عدم دسترسی
 *
 * اپ در iframe همان‌دامنه اجرا می‌شود تا استایل قالب سایت با آن تداخل نکند و
 * کوکی نشست وردپرس به‌صورت خودکار برای احراز هویت REST استفاده شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Shortcode {

        public function __construct() {
                add_shortcode( 'tpp_services', array( $this, 'render' ) );
                add_shortcode( 'tpp_app', array( $this, 'render' ) );
        }

        /** آدرس صفحه فعلی (برای بازگشت پس از ورود) */
        private function current_url() {
                if ( is_admin() ) {
                        return admin_url( 'admin.php?page=tpp-services' );
                }
                if ( is_singular() ) {
                        $permalink = get_permalink();
                        if ( $permalink ) {
                                return $permalink;
                        }
                }
                $scheme = is_ssl() ? 'https' : 'http';
                $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : parse_url( home_url(), PHP_URL_HOST );
                $uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
                return $scheme . '://' . $host . $uri;
        }

        public function render( $atts = array() ) {
                static $rendered = false;
                if ( $rendered ) {
                        return ''; // فقط یک نمونه در هر صفحه
                }
                $rendered = true;

                $atts = shortcode_atts( array(
                        'height' => 'auto',
                ), $atts, 'tpp_services' );

                if ( ! is_user_logged_in() ) {
                        return $this->notice_login();
                }
                if ( ! tpp()->auth()->user_has_tpp_access( get_current_user_id() ) ) {
                        return $this->notice_no_access();
                }

                $parent = rawurlencode( $this->current_url() );
                // v = نسخه افزونه → کش صفحه هم پس از به‌روزرسانی، آدرس تازه iframe را می‌سازد
                $src    = add_query_arg( array( 'embed' => '1', 'parent' => $parent, 'v' => TPP_VERSION ), TPP_PLUGIN_URL . 'pwa/index.html' );
                $height = 'auto' === $atts['height'] ? 'auto' : max( 400, (int) $atts['height'] );
                $style  = 'auto' === $height ? 'height:1000px' : 'height:' . $height . 'px';

                $html  = '<div class="tpp-embed-wrap" dir="rtl" style="margin:0 auto">';
                $html .= '<iframe id="tpp-embed-frame" src="' . esc_url( $src ) . '" title="مدیریت سرویس‌های TPP" loading="lazy" style="width:100%;' . esc_attr( $style ) . ';border:1px solid #d9dee6;border-radius:12px;background:#eef1f5"></iframe>';
                $html .= '</div>';

                if ( 'auto' === $height ) {
                        $html .= "<script>\n";
                        $html .= "(function(){\n";
                        $html .= "\tvar f = document.getElementById('tpp-embed-frame');\n";
                        $html .= "\tif (!f) return;\n";
                        $html .= "\twindow.addEventListener('message', function (ev) {\n";
                        $html .= "\t\tif (ev.data && ev.data.type === 'tpp:height' && f) {\n";
                        $html .= "\t\t\tvar h = parseInt(ev.data.h, 10);\n";
                        $html .= "\t\t\tif (!isNaN(h) && h > 300) { f.style.height = Math.min(h + 40, 4000) + 'px'; }\n";
                        $html .= "\t\t}\n";
                        $html .= "\t});\n";
                        $html .= "})();\n";
                        $html .= "</script>";
                }

                return $html;
        }

        private function notice_login() {
                $url      = wp_login_url( $this->current_url() );
                $login    = wp_parse_url( wp_login_url(), PHP_URL_PATH );
                $register = get_option( 'users_can_register' ) ? '<p style="margin:6px 0 0">حساب کاربری ندارید؟ از <a href="' . esc_url( wp_registration_url() ) . '">صفحه ثبت‌نام</a> اقدام کنید.</p>' : '';
                return '<div dir="rtl" style="max-width:560px;margin:24px auto;padding:28px 26px;background:#fff;border:1px solid #d9dee6;border-radius:12px;text-align:center;font-family:inherit;box-shadow:0 1px 3px rgba(16,32,48,.08)">'
                        . '<div style="font-size:38px;line-height:1">🔐</div>'
                        . '<h3 style="margin:10px 0 8px;font-size:17px;color:#1f4e79">ورود لازم است</h3>'
                        . '<p style="margin:0 0 16px;color:#5c6b7a;font-size:14px;line-height:2">برای استفاده از مدیریت سرویس‌ها، ابتدا با حساب کاربری وردپرس خود وارد شوید. اگر نام کاربری شما یکی از نقش‌های افزونه (مدیر سرویس‌ها، نصاب، اپراتور ثبت یا گزارش‌گیر) را داشته باشد، برنامه به‌صورت خودکار در همین صفحه باز می‌شود.</p>'
                        . '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f4e79;color:#fff;text-decoration:none;padding:10px 26px;border-radius:8px;font-size:14px">ورود به حساب کاربری</a>'
                        . $register
                        . '</div>';
        }

        private function notice_no_access() {
                $user = wp_get_current_user();
                $name = $user ? $user->display_name : '';
                return '<div dir="rtl" style="max-width:560px;margin:24px auto;padding:28px 26px;background:#fff;border:1px solid #f0d4a8;border-radius:12px;text-align:center;font-family:inherit;box-shadow:0 1px 3px rgba(16,32,48,.08)">'
                        . '<div style="font-size:38px;line-height:1">⛔</div>'
                        . '<h3 style="margin:10px 0 8px;font-size:17px;color:#b3541e">دسترسی ندارید</h3>'
                        . '<p style="margin:0;color:#5c6b7a;font-size:14px;line-height:2">حساب کاربری «' . esc_html( $name ) . '» به مدیریت سرویس‌ها دسترسی ندارد. برای دریافت دسترسی، مدیر سایت باید از بخش «کاربران» پیشخوان وردپرس، یکی از نقش‌های افزونه (مدیر سرویس‌ها، نصاب، اپراتور ثبت یا گزارش‌گیر) را به حساب شما اضافه کند.</p>'
                        . '</div>';
        }
}
