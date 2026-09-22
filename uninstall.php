<?php
/**
 * حذف افزونه — ۱.۹.۰ سیاست حفظ داده:
 *
 * پیش‌فرض (تیک «حذف کامل داده‌ها» خاموش): حذف و نصب مجدد افزونه هیچ چیز را پاک نمی‌کند —
 * تنظیمات (tpp_settings)، قالب‌های پیامک و قالب کپی OMC، نقش‌ها و دسترسی‌ها،
 * توکن‌های دستگاه‌ها (نشست‌ها) و همه جدول‌های داده باقی می‌مانند.
 *
 * فقط وقتی تیک «حذف کامل داده‌ها هنگام حذف افزونه» (تنظیمات → منطقه خطر) فعال باشد:
 * جدول‌ها، آپشن‌ها، نقش‌ها، توکن‌ها و داده‌ها کامل حذف می‌شوند.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
}

$tpp_settings = get_option( 'tpp_settings', array() );
$tpp_delete   = ! empty( $tpp_settings['delete_on_uninstall'] );

// داده موقت (کش ۵ دقیقه‌ای موجودی پیامک) در هر حالت پاک می‌شود — تنظیم محسوب نمی‌شود.
delete_transient( 'tpp_sms_credit' );

if ( ! $tpp_delete ) {
        // حفظ کامل: تنظیمات، قالب‌های پیامک، نقش‌ها/دسترسی‌ها، توکن‌ها و جدول‌ها دست‌نخورده می‌مانند
        // تا بعد از نصب مجدد همه چیز (از جمله قالب‌های پیام و تنظیمات) سر جای خودش باشد.
        return;
}

// حذف نقش‌های افزونه از همه کاربران
if ( function_exists( 'wp_roles' ) ) {
        $tpp_roles_obj = wp_roles();
        foreach ( array_keys( $tpp_roles_obj->roles ) as $role_slug ) {
                if ( 0 === strpos( $role_slug, 'tpp_' ) ) {
                        remove_role( $role_slug );
                }
        }
}

// حذف توکن‌های همه کاربران
$tpp_token_index = get_option( 'tpp_token_index', array() );
foreach ( (array) $tpp_token_index as $hash => $uid ) {
        delete_user_meta( (int) $uid, 'tpp_tokens' );
}

// حذف آپشن‌ها (تنظیمات، دسترسی نقش‌ها، پرچم‌های seed و شماره نسخه دیتابیس)
delete_option( 'tpp_settings' );
delete_option( 'tpp_role_caps' );
delete_option( 'tpp_token_index' );
delete_option( 'tpp_db_version' );
delete_option( 'tpp_seed_skip' );
delete_option( 'tpp_seed_skip_tpl' );
delete_option( 'tpp_dup_dismissed' );
delete_option( 'tpp_last_activity_cleanup' );

// حذف زمان‌بندی پاک‌سازی دوره‌ای
wp_clear_scheduled_hook( 'tpp_daily_cleanup' );

global $wpdb;

// حذف جدول‌های اختصاصی (دیتابیس جداگانه پشتیبانی نمی‌شود در این حالت — دستی حذف کنید)
$tpp_tables = array( 'fields', 'addresses', 'services', 'history', 'op_log', 'sync_log', 'field_archives', 'sms_templates', 'sms_log', 'view_log', 'search_log' );
foreach ( $tpp_tables as $tpp_table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tpp_{$tpp_table}" );
}
