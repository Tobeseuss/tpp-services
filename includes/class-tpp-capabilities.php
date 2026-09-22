<?php
/**
 * نقش‌ها و قابلیت‌ها — لایه دسترسی اختصاصی افزونه
 *
 * نقش‌ها به‌صورت نقش وردپرسی (با پیشوند tpp_) ساخته می‌شوند تا از صفحه «کاربران» وردپرس قابل تخصیص باشند،
 * اما قابلیت‌های ریز (شامل نمایش/عدم نمایش هر فیلد) در option اختصاصی افزونه نگهداری می‌شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Capabilities {

        const CAPS_OPTION = 'tpp_role_caps';

        /** فهرست قابلیت‌ها با برچسب فارسی */
        public static function caps_labels() {
                return array(
                        'tpp_view'            => 'دسترسی به افزونه',
                        'tpp_view_services'   => 'مشاهده سرویس‌ها و جستجو',
                        'tpp_create_services' => 'ثبت سرویس جدید',
                        'tpp_edit_services'   => 'ویرایش سرویس‌ها',
                        'tpp_quick_edit'      => 'ویرایش سریع پیشرفت/وضعیت (⚡/🚀)',
                        'tpp_delete_services' => 'حذف سرویس',
                        'tpp_import'          => 'ایمپورت گروهی اکسل',
                        'tpp_export'          => 'خروجی اکسل/PDF و پشتیبان‌گیری',
                        'tpp_view_history'    => 'مشاهده تاریخچه تغییرات',
                        'tpp_view_all_history'=> 'مشاهده تاریخچه تغییرات همه کاربران',
                        'tpp_delete_history'  => 'حذف رکوردهای تاریخچه (تکی/گروهی)',
                        'tpp_view_activity'   => 'مشاهده گزارش فعالیت (بازدید/جستجو کاربران)',
                        'tpp_view_sensitive'  => 'مشاهده فیلدهای حساس (رمزها)',
                        'tpp_manage_fields'   => 'مدیریت فیلدهای اطلاعاتی',
                        'tpp_manage_categories' => 'دسته‌بندی پروژه‌ها و تگ‌ها (تعریف دسته‌بندی/تگ سیستمی)',
                        'tpp_manage_roles'    => 'مدیریت نقش‌ها و دسترسی‌ها',
                        'tpp_manage_settings' => 'مدیریت تنظیمات افزونه',
                        'tpp_send_sms'        => 'ارسال پیامک از پنل پیامک',
                        'tpp_review_queue'    => 'بازبینی سرویس‌های ارجاعی (دسته «ثبت جهت بازبینی»)',
                        'tpp_review_installer'=> 'بازبینی اقدامات نصاب‌ها (نگه‌داری/بازگردانی تغییرات)',
                );
        }

        /** نقش‌های پیش‌فرض افزونه */
        public static function default_roles() {
                $all = array_keys( self::caps_labels() );
                // ۱.۱۳.۱ — «ویرایش سریع» (⚡/🚀) به‌طور پیش‌فرض فقط برای مدیر کل سایت است (user_caps مدیر وردپرس همه قابلیت‌ها را می‌گیرد)؛
                // برای نقش‌ها (حتی مدیر سرویس‌ها) پیش‌فرض خاموش است و از «نقش‌ها و دسترسی‌ها» قابل اعطاست.
                // ۱.۱۹.۰ — «دسته‌بندی پروژه‌ها» هم طبق درخواست کاربر به‌طور پیش‌فرض فقط برای مدیر کل سایت است.
                // ۱.۲۰.۰ — «بازبینی سرویس‌های ارجاعی» و «بازبینی اقدامات نصاب‌ها» نیز پیش‌فرض فقط مدیر کل سایت؛
                // با تیک این قابلیت‌ها در «نقش‌ها و دسترسی‌ها» برای اپراتور ثبت/گزارش‌گیر/... هم فعال می‌شوند.
                return array(
                        'tpp_manager'  => array( 'label' => 'مدیر سرویس‌ها', 'caps' => array_merge( array_fill_keys( $all, true ), array( 'tpp_quick_edit' => false, 'tpp_manage_categories' => false, 'tpp_review_queue' => false, 'tpp_review_installer' => false ) ) ),
                        'tpp_installer'=> array(
                                'label' => 'نصاب',
                                'caps'  => array(
                                        'tpp_view' => true, 'tpp_view_services' => true, 'tpp_create_services' => true,
                                        'tpp_edit_services' => true, 'tpp_view_history' => true, 'tpp_send_sms' => true,
                                        'tpp_view_activity' => true, 'tpp_quick_edit' => false,
                                ),
                        ),
                        'tpp_operator' => array(
                                'label' => 'اپراتور ثبت',
                                'caps'  => array(
                                        'tpp_view' => true, 'tpp_view_services' => true, 'tpp_create_services' => true,
                                        'tpp_edit_services' => true, 'tpp_import' => true, 'tpp_view_history' => true,
                                        'tpp_view_activity' => true, 'tpp_quick_edit' => false,
                                ),
                        ),
                        'tpp_reporter' => array(
                                'label' => 'گزارش‌گیر',
                                'caps'  => array(
                                        'tpp_view' => true, 'tpp_view_services' => true, 'tpp_export' => true,
                                        'tpp_view_history' => true, 'tpp_view_activity' => true, 'tpp_quick_edit' => false,
                                ),
                        ),
                );
        }

        /** افزودن قابلیت‌های جدید به نقش‌های پیش‌فرضِ ذخیره‌شده در نسخه‌های قبلی (بدون دست‌زدن به سفارشی‌سازی‌ها) */
        public static function merge_new_caps( $new_caps_by_role ) {
                $all = get_option( self::CAPS_OPTION, array() );
                if ( ! is_array( $all ) ) {
                        $all = array();
                }
                foreach ( $new_caps_by_role as $slug => $caps ) {
                        if ( empty( $all[ $slug ] ) || ! is_array( $all[ $slug ] ) || ! isset( $all[ $slug ]['caps'] ) || ! is_array( $all[ $slug ]['caps'] ) ) {
                                continue; // نقشی ذخیره نشده → ساختار پیش‌فرض بعداً اعمال می‌شود
                        }
                        foreach ( (array) $caps as $cap => $on ) {
                                if ( ! array_key_exists( $cap, $all[ $slug ]['caps'] ) ) {
                                        $all[ $slug ]['caps'][ $cap ] = (bool) $on;
                                }
                        }
                }
                update_option( self::CAPS_OPTION, $all, false );
        }

        /** ساخت نقش‌های وردپرسی (در فعال‌سازی) */
        public static function register_roles() {
                foreach ( self::default_roles() as $slug => $def ) {
                        $role = get_role( $slug );
                        if ( null === $role ) {
                                add_role( $slug, $def['label'], array( 'read' => true, 'tpp_view' => true ) );
                        }
                        self::set_role_caps( $slug, $def['caps'], array() );
                }
                // مدیر سایت همیشه به افزونه دسترسی داشته باشد
                $admin = get_role( 'administrator' );
                if ( $admin && ! $admin->has_cap( 'tpp_view' ) ) {
                        $admin->add_cap( 'tpp_view' );
                }
        }

        /** caps ذخیره‌شده یک نقش */
        public static function role_caps( $slug ) {
                $all = get_option( self::CAPS_OPTION, array() );
                if ( ! isset( $all[ $slug ] ) || ! is_array( $all[ $slug ] ) ) {
                        $defaults = self::default_roles();
                        return isset( $defaults[ $slug ] ) ? array( 'caps' => $defaults[ $slug ]['caps'], 'fields' => array() ) : array( 'caps' => array(), 'fields' => array() );
                }
                return wp_parse_args( $all[ $slug ], array( 'caps' => array(), 'fields' => array() ) );
        }

        /** ذخیره caps و دسترسی فیلد یک نقش */
        public static function set_role_caps( $slug, $caps, $fields ) {
                $all       = get_option( self::CAPS_OPTION, array() );
                $valid     = self::caps_labels();
                $clean     = array();
                foreach ( $valid as $cap => $label ) {
                        $clean[ $cap ] = ! empty( $caps[ $cap ] );
                }
                $clean_fields = array();
                if ( is_array( $fields ) ) {
                        foreach ( $fields as $fslug => $on ) {
                                if ( preg_match( '/^[a-z0-9_]{1,64}$/', (string) $fslug ) ) {
                                        $clean_fields[ $fslug ] = (bool) $on;
                                }
                        }
                }
                $all[ $slug ] = array( 'caps' => $clean, 'fields' => $clean_fields );
                update_option( self::CAPS_OPTION, $all, false );
                return true;
        }

        /** آیا کاربر مدیر کامل است؟ */
        public static function is_manager( $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) {
                        return false;
                }
                return $user->has_cap( 'manage_options' ) || $user->has_cap( 'tpp_manage_roles' );
        }

        /** قابلیت‌های تجمیعی کاربر (اتحاد همه نقش‌های tpp_*) */
        public static function user_caps( $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) {
                        return array();
                }
                if ( $user->has_cap( 'manage_options' ) ) {
                        return array_fill_keys( array_keys( self::caps_labels() ), true ); // مدیر سایت: دسترسی کامل
                }
                $caps = array();
                foreach ( (array) $user->roles as $role_slug ) {
                        if ( 0 !== strpos( $role_slug, 'tpp_' ) ) {
                                continue;
                        }
                        $stored = self::role_caps( $role_slug );
                        foreach ( $stored['caps'] as $cap => $on ) {
                                if ( $on ) {
                                        $caps[ $cap ] = true;
                                }
                        }
                        // نقش‌های پیش‌فرض، cap سطح وردپرس هم دارند
                        if ( $user->has_cap( 'tpp_view' ) ) {
                                $caps['tpp_view'] = true;
                        }
                }
                return $caps;
        }

        public static function user_can( $user_id, $cap ) {
                $caps = self::user_caps( $user_id );
                return ! empty( $caps[ $cap ] );
        }

        /**
         * فیلدهای قابل مشاهده برای کاربر: [slug => true]
         * فیلد حساس فقط با قابلیت tpp_view_sensitive؛ نقش می‌تواند فیلدی را صریحاً مخفی/نمایان کند.
         */
        public static function visible_fields( $user_id ) {
                $user = get_userdata( $user_id );
                if ( ! $user ) {
                        return array();
                }
                $caps      = self::user_caps( $user_id );
                $sensitive = ! empty( $caps['tpp_view_sensitive'] );
                $visible   = array();

                // نقش‌های کاربر
                $roles = array();
                if ( $user->has_cap( 'manage_options' ) ) {
                        $roles = array_keys( self::default_roles() );
                        $roles[] = 'tpp_manager';
                } else {
                        foreach ( (array) $user->roles as $r ) {
                                if ( 0 === strpos( $r, 'tpp_' ) ) {
                                        $roles[] = $r;
                                }
                        }
                }

                // نقشه visibility تجمیعی: slug => [مقادیر از نقش‌های مختلف]
                $field_maps = array();
                foreach ( $roles as $r ) {
                        $stored = self::role_caps( $r );
                        foreach ( $stored['fields'] as $fs => $on ) {
                                $field_maps[ $fs ][] = (bool) $on;
                        }
                }

                foreach ( TPP_Fields::all() as $field ) {
                        $slug = $field['slug'];
                        // حساس: پیش‌فرض مخفی مگر قابلیت
                        $show = $field['is_sensitive'] ? $sensitive : true;
                        if ( isset( $field_maps[ $slug ] ) ) {
                                // اگر هر نقشی صریحاً نمایان کرده باشد → نمایان؛ اگر همه صریحاً مخفی کرده باشند → مخفی
                                $show = in_array( true, $field_maps[ $slug ], true );
                        }
                        if ( $show ) {
                                $visible[ $slug ] = true;
                        }
                }
                return $visible;
        }

        /** نقش‌های tpp موجود (برای UI) */
        public static function roles_list() {
                global $wp_roles;
                $out = array();
                if ( ! isset( $wp_roles ) ) {
                        $wp_roles = wp_roles();
                }
                foreach ( $wp_roles->roles as $slug => $role ) {
                        if ( 0 === strpos( $slug, 'tpp_' ) ) {
                                $stored = self::role_caps( $slug );
                                $out[]  = array(
                                        'slug'   => $slug,
                                        'label'  => isset( $role['name'] ) ? $role['name'] : $slug,
                                        'caps'   => $stored['caps'],
                                        'fields' => $stored['fields'],
                                        'users'  => count( get_users( array( 'role' => $slug, 'fields' => 'ID' ) ) ),
                                );
                        }
                }
                return $out;
        }
}
