<?php
/**
 * Plugin Name:       TPP Services
 * Plugin URI:        https://github.com/Tobeseuss/tpp-services
 * Description:       پلاگین سرویس‌های TPP — مدیریت و ارائه سرویس‌های برند TPP در وردپرس.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tobeseuss
 * Author URI:        https://github.com/Tobeseuss
 * Text Domain:       tpp-services
 * Domain Path:       /languages
 * Network:           false
 * Update URI:        false
 *
 * @package TPP_Services
 *
 * Copyright (c) 2026 TPP. All rights reserved.
 * This software is proprietary. Unauthorized copying, distribution,
 * or modification of this file, via any medium, is strictly prohibited.
 */

// جلوگیری از دسترسی مستقیم به فایل.
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

define( 'TPP_SERVICES_VERSION', '0.1.0' );
define( 'TPP_SERVICES_FILE', __FILE__ );
define( 'TPP_SERVICES_PATH', plugin_dir_path( __FILE__ ) );
define( 'TPP_SERVICES_URL', plugin_dir_url( __FILE__ ) );

/**
 * کلاس اصلی پلاگین TPP Services.
 *
 * هسته راه‌اندازی پلاگین است و بارگذاری بخش‌های
 * عمومی و ادمین را در چرخه حیات وردپرس مدیریت می‌کند.
 */
final class TPP_Services {

        /**
         * نمونه یکتای کلاس (Singleton).
         *
         * @var TPP_Services|null
         */
        private static $instance = null;

        /**
         * دریافت نمونه یکتای پلاگین.
         *
         * @return TPP_Services
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * سازنده خصوصی — ثبت هوک‌های اصلی.
         */
        private function __construct() {
                add_action( 'init', array( $this, 'load_textdomain' ) );
                add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
                register_activation_hook( TPP_SERVICES_FILE, array( $this, 'activate' ) );
                register_deactivation_hook( TPP_SERVICES_FILE, array( $this, 'deactivate' ) );
        }

        /**
         * بارگذاری فایل‌های ترجمه.
         *
         * @return void
         */
        public function load_textdomain() {
                load_plugin_textdomain(
                        'tpp-services',
                        false,
                        dirname( plugin_basename( TPP_SERVICES_FILE ) ) . '/languages'
                );
        }

        /**
         * ثبت مسیرهای REST API پلاگین.
         *
         * نقطه اتصال سرویس‌های TPP به بیرون از سایت؛
         * مسیرهای جدید باید از همین‌جا ثبت شوند.
         *
         * @return void
         */
        public function register_rest_routes() {
                register_rest_route(
                        'tpp-services/v1',
                        '/health',
                        array(
                                'methods'             => 'GET',
                                'callback'            => array( $this, 'health_check' ),
                                'permission_callback' => '__return_true',
                        )
                );
        }

        /**
         * پاسخ سلامت سرویس برای مانیتورینگ.
         *
         * @return WP_REST_Response
         */
        public function health_check() {
                return new WP_REST_Response(
                        array(
                                'service' => 'tpp-services',
                                'version' => TPP_SERVICES_VERSION,
                                'status'  => 'ok',
                        ),
                        200
                );
        }

        /**
         * عملیات فعال‌سازی پلاگین.
         *
         * @return void
         */
        public function activate() {
                add_option( 'tpp_services_version', TPP_SERVICES_VERSION );
                flush_rewrite_rules();
        }

        /**
         * عملیات غیرفعال‌سازی پلاگین.
         *
         * @return void
         */
        public function deactivate() {
                flush_rewrite_rules();
        }
}

TPP_Services::instance();
