<?php
/**
 * Plugin Name:       TPP Services | سرویس‌های TPP
 * Plugin URI:        https://github.com/Tobeseuss/tpp-services
 * Description:       مدیریت اطلاعات سرویس‌های اینترنت و تلفن — ثبت تکی و گروهی (اکسل) با تشخیص هوشمند آدرس‌های مشابه، تاریخچه کامل تغییرات، چند سرویس برای هر آدرس، جستجوی زنده آجاکسی، کار آفلاین با همگام‌سازی خودکار، پیوند با ورود/خروج وردپرس + شورت‌کد برای صفحات سایت، پنل پیامک SMS.ir با قالب‌های پیامک و کپی مشخصات، نقش‌های کاربری داینامیک با دسترسی سطح فیلد، فیلدهای قابل افزودن/حذف و خروجی اکسل/PDF (دو روش: تولید در سایت با TCPDF + چاپ مرورگر). ۱.۱۳.۰: پیشرفت دایری ۱۶ مرحله‌ای با منطق آبشاری (تیک مرحله بعدی = تیک خودکار مراحل قبلی، مگر ردشده توسط کاربر) + تغییر گروهی/تکی پیشرفت و وضعیت سرویس‌ها + پوشش کامل دایری در بکاپ/بازیابی/ایمپورت/خروجی. ۱.۱۴.۰: انتخاب همزمان چند خرابی برای هر سرویس + بخش «گزارش کار» روزانه هر کاربر (افزودن از تاریخچه فعالیت، افزودن دستی با جستجوی سرویس، ویرایش/حذف اقلام — برای ارائه به مدیران). ۱.۱۵.۰: پشتیبان‌گیری خودکار کامل قبل از هر ایمپورت گروهی و ذخیره در پوشه افزونه + بازگردانی یک‌کلیکی از فهرست پشتیبان‌های ذخیره‌شده روی سرور + مدیریت تعداد نسخه‌های نگهداری‌شده. ۱.۱۸.۰: همه تاریخ‌ها و تقویم‌های افزونه به وقت تهران و نمایش شمسی + دکمه «اصلاح اعداد فارسی به انگلیسی» در تنظیمات (با پشتیبان کامل خودکار پیش از اجرا و بازگردانی یک‌کلیکی) + شماره‌گذاری صحیح نسخه‌ها (ادغام ۱.۱۶/۱.۱۷). ۱.۱۹.۰: متن ساده‌تر اقلام گزارش کار (بدون شمارش مراحل) + گزارش کار n-روزه/هفتگی/ماهانه + تقویم شمسی روزهای دارای گزارش/فعالیت + انتخاب تاریخ تقویمی با اخطار نگهداشت تاریخچه + انتخاب «اقدام انجام‌شده» از فهرست + جستجوی کامل سرویس با فیلتر و صفحه‌بندی در افزودن دستی + بهینه‌سازی حذف خودکار تاریخچه + بخش «دسته‌بندی پروژه‌ها» (فقط مدیر کل) با فیلد اجباری دسته‌بندی و تگ‌ها برای هر سرویس + فیلتر جستجو بر حسب دسته/تگ + ایمپورت/بکاپ/نمونه اکسل ارتقایافته. ۱.۲۰.۰: رفع باگ تقویم شمسی گزارش کار (روزهای دارای گزارش سبز داخل تقویم — قبلاً خطای «تاریخ نامعتبر») + دسته‌بندی پیش‌فرض «ثبت جهت بازبینی و ویرایش یا تأیید مدیریت» با صف بازبینی برای ارجاع سرویس‌های بدون دسته مناسب + بخش «بازبینی اقدامات نصاب‌ها» (سجل روزانه ثبت/ویرایش/حذف کاربران غیرمدیر با نگه‌داری یا بازگردانی یک‌کلیکی به وضعیت قبل) + پنهان‌سازی خودکار منوها/دکمه‌های فاقد دسترس (مثل ایمپورت برای نصاب). ۱.۲۱.۰: دو تقویم مجزای «گزارش کار» و «روزهای دارای فعالیت» در گزارش کار با رفع باگ انتخاب روز + پیش‌انتخاب دسته بازبینی در فرم ثبت/ویرایش + انتخاب آجاکسی دسته‌بندی/تگ در فرم سرویس (مثل فیلترها) + تنظیم «تعداد روز تایید خودکار اقدامات نصاب‌ها» (استثنا: سرویس‌های با دسته بازبینی) + حذف شش فیلد وضعیت/وای‌فای و افزودن «توضیحات متفرقه» + دکمه «بروزآوری دیتابیس» (انتقال محتوای ستون‌های یتیم به توضیحات متفرقه و حذف کامل آن‌ها با پشتیبان خودکار) + جستجو/صفحه‌بندی دسته‌بندی‌ها با فرم‌های افزودن در بالا + رفع باگ خروجی اکسل/PDF (اعمال کامل فیلترهای جستجو از جمله دسته/تگ).
 * Version:           1.21.1
 * Author:            TPP
 * License:           GPL-2.0+
 * Text Domain:       tpp-services
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit; // دسترسی مستقیم ممنوع
}

/**
 * ۱.۱۲.۱ — محافظ کپی تکراری (رفع «خطای مهلک هنگام فعال‌سازی» هنگام ارتقا).
 *
 * اگر نسخه دیگری از همین افزونه از قبل در همین درخواست بارگذاری شده باشد
 * (مثلاً کپی قدیمی در پوشه‌ای با نام دیگر مثل tpp-services-v1.11.0/)،
 * این فایل دیگر کلاس‌ها و توابع را برای بار دوم تعریف نمی‌کند؛ در عوض کپی
 * تکراری را خودکار غیرفعال می‌کند و یک پیام راهنما برای مدیر می‌گذارد.
 * نتیجه: خطای «Cannot redeclare» هرگز رخ نمی‌دهد و ارتقا همیشه موفق است.
 *
 * نکته فنی: تمام کد افزونه داخل بلوک شرطی زیر قرار دارد تا اعلان توابع
 * (tpp و …) «شرطی» و در زمان اجرا باشد — PHP توابع سطح-بالای فایل را هنگام
 * include مستقل از return اعلان می‌کند و دقیقاً همین باعث خطای مهلک
 * «Cannot redeclare tpp» هنگام فعال‌سازی نسخه دوم می‌شد.
 */
$tpp_this_version = '1.21.1';

if ( ! defined( 'TPP_VERSION' ) ) {

        // باید با $tpp_this_version (بالای فایل) هم‌گام بماند
        define( 'TPP_VERSION', '1.21.1' );
        define( 'TPP_DB_VERSION', '1.21.1' );
        define( 'TPP_PLUGIN_FILE', __FILE__ );
        define( 'TPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        define( 'TPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-db.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-settings.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-date.php'; // 1.18.0 — ساعت/تقویم تهران + شمسی + ارقام
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-capabilities.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-fields.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-progress.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-history.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-activity.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-workreport.php'; // 1.14.0 — گزارش کار روزانه
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-services.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-auth.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-sync.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-zip.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-pdf.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-pdf-report.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-xlsx-reader.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-xlsx-writer.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-import.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-export.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-backup.php'; // 1.15.0 — پشتیبان‌های ذخیره‌شده روی سرور
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-numfix.php'; // 1.18.0 — اصلاح اعداد فارسی/عربی → انگلیسی
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-categories.php'; // 1.20.0 — دسته‌بندی پروژه‌ها و تگ‌ها
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-review.php'; // 1.20.0 — بازبینی (صف ارجاع + سجل اقدامات نصاب‌ها)
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-sms.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-rest.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-admin.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-shortcode.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-install.php';
        require_once TPP_PLUGIN_DIR . 'includes/class-tpp-plugin.php';

        /**
         * دسترسی سراسری برای توسعه‌دهندگان افزونه‌های دیگر
         *
         * نمونه:
         *   tpp()->services()->search( array( 'query' => 'تهران' ) );
         *   tpp()->fields()->all( 'service' );
         *   tpp()->user_can( 'tpp_edit_services' );
         */
        function tpp() {
                return TPP_Plugin::instance();
        }

        register_activation_hook( __FILE__, array( 'TPP_Install', 'activate' ) );
        register_deactivation_hook( __FILE__, array( 'TPP_Install', 'deactivate' ) );

        // راه‌اندازی هسته
        tpp()->boot();

        /**
         * نمایش یک‌باره پیام «کپی تکراری» بعد از ترمیم خودکار (۱.۱۲.۱).
         * پیام زمان تشخیص تکراری ذخیره می‌شود و اینجا فقط یک بار دیده و پاک می‌شود.
         */
        function tpp_show_dup_notice() {
                $tpp_msg = get_option( 'tpp_dup_notice' );
                if ( ! empty( $tpp_msg ) && is_string( $tpp_msg ) ) {
                        delete_option( 'tpp_dup_notice' );
                        echo '<div class="notice notice-warning is-dismissible"><p><strong>TPP Services:</strong> ' . esc_html( $tpp_msg ) . '</p></div>';
                }
        }
        add_action( 'admin_notices', 'tpp_show_dup_notice' );

} else {

        // ─── کپی تکراری شناسایی شد: نسخه دیگری از افزونه از قبل بارگذاری شده است ───
        $tpp_other_version  = (string) TPP_VERSION;
        $tpp_other_basename = defined( 'TPP_PLUGIN_FILE' ) ? plugin_basename( TPP_PLUGIN_FILE ) : '';

        // قدیمی‌تر می‌بازد: اگر نسخه بارگذاری‌شده قبلی قدیمی‌تر باشد همان غیرفعال می‌شود؛
        // اگر جدیدتر/هم‌نسخ باشد، خود این کپی کنار می‌رود.
        $tpp_other_is_older = version_compare( $tpp_other_version, $tpp_this_version, '<' );
        $tpp_drop_basename  = $tpp_other_is_older ? $tpp_other_basename : plugin_basename( __FILE__ );

        if ( ! empty( $tpp_drop_basename ) ) {
                if ( function_exists( 'deactivate_plugins' ) ) {
                        deactivate_plugins( $tpp_drop_basename, true );
                } else {
                        // در درخواست‌های غیرمدیر (بدون wp-admin/includes/plugin.php)
                        $tpp_active = get_option( 'active_plugins', array() );
                        if ( is_array( $tpp_active ) ) {
                                $tpp_pos = array_search( $tpp_drop_basename, $tpp_active, true );
                                if ( false !== $tpp_pos ) {
                                        unset( $tpp_active[ $tpp_pos ] );
                                        update_option( 'active_plugins', array_values( $tpp_active ) );
                                }
                        }
                }
        }

        // پیام یک‌باره — در درخواست بعدی توسط نسخه سالم نمایش داده می‌شود
        if ( $tpp_other_is_older ) {
                update_option( 'tpp_dup_notice', sprintf(
                        'هنگام ارتقا یک کپی قدیمی‌تر (نسخه %1$s) پیدا و خودکار غیرفعال شد و نسخه %2$s جای آن را گرفت. لطفاً از صفحه «افزونه‌ها» ورودی نسخه %1$s (کپی تکراری) را حذف کنید — داده‌ها دست‌نخورده باقی می‌مانند.',
                        $tpp_other_version,
                        $tpp_this_version
                ), false );
        } else {
                update_option( 'tpp_dup_notice', sprintf(
                        'نسخه %1$s از TPP Services از قبل فعال است؛ این نسخه (%2$s) تکراری بود و خودکار غیرفعال شد. لطفاً ورودی تکراری آن را از صفحه «افزونه‌ها» حذف کنید.',
                        $tpp_other_version,
                        $tpp_this_version
                ), false );
        }
        // بدون تعریف مجدد کلاس‌ها/تابع tpp() — این کپی در همین درخواست ساکت می‌ماند
}
