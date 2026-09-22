<?php
/**
 * TPP_Numfix — اصلاح اعداد فارسی/عربی به انگلیسی (۱.۱۸.۰)
 *
 * اسکن همه فیلدهای سرویس/آدرس + متن گزارش‌های کار؛ هر کاراکتر عددی فارسی (۰-۹)
 * یا عربی (٠-٩) به همتای انگلیسی (0-9) تبدیل می‌شود.
 *
 * زنجیره اطمینان (مطابق ایمپورت v1.15.0): قبل از اجرای واقعی، یک پشتیبان کامل خودکار
 * در پوشه افزونه گرفته می‌شود؛ اگر پشتیبان‌گیری شکست بخورد، اجرای اصلاح متوقف می‌ماند —
 * هیچ تغییری بدون پشتیبان قابل‌بازگردانی اعمال نمی‌شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Numfix {

        /** نقشه جستجو: کدام ستون‌های کدام جدول اسکن شوند */
        private static function targets() {
                $targets = array();
                foreach ( array( 'service', 'address' ) as $group ) {
                        $table = 'service' === $group ? TPP_DB::table( 'services' ) : TPP_DB::table( 'addresses' );
                        foreach ( TPP_Fields::all( $group ) as $f ) {
                                $targets[] = array( 'table' => $table, 'table_label' => 'service' === $group ? 'سرویس‌ها' : 'آدرس‌ها', 'group' => $group, 'slug' => $f['slug'], 'label' => $f['label'] );
                        }
                }
                // متن گزارش‌های کار — یک «فیلد متنی» دیگر پروژه
                if ( TPP_DB::table( 'work_reports' ) ) {
                        $targets[] = array( 'table' => TPP_DB::table( 'work_reports' ), 'table_label' => 'گزارش‌های کار', 'group' => 'workreport', 'slug' => 'content', 'label' => 'متن گزارش کار' );
                }
                return $targets;
        }

        /**
         * اجرای اصلاح.
         *
         * @param bool $dry_run فقط شمارش — بدون تغییر و بدون پشتیبان.
         * @param int  $user_id کاربر اجراکننده (برای ثبت در پشتیبان).
         * @return array|WP_Error خلاصه: backup + columns[] + rows + chars
         */
        public static function run( $dry_run = false, $user_id = 0 ) {
                $summary = array(
                        'dry_run' => $dry_run ? 1 : 0,
                        'backup'  => null,
                        'columns' => array(),
                        'rows'    => 0,
                        'chars'   => 0,
                        'ran_at'  => TPP_Date::now(),
                        'ran_at_jalali' => TPP_Date::jalali_now( true ),
                );

                // ── پشتیبان کامل خودکار قبل از هر تغییری (zنجیره اطمینان) ──
                if ( ! $dry_run ) {
                        $backup = TPP_Backup::create( 'numfix', $user_id );
                        if ( is_wp_error( $backup ) ) {
                                return new WP_Error( 'tpp_backup_failed', sprintf(
                                        'پشتیبان‌گیری خودکار قبل از اصلاح اعداد ناموفق بود (%s) — اجرا متوقف شد و هیچ تغییری اعمال نشد. وضعیت پوشه پشتیبان‌ها را از «خروجی و پشتیبان» بررسی کنید.',
                                        $backup->get_error_message()
                                ) );
                        }
                        $summary['backup'] = $backup;
                }

                foreach ( self::targets() as $t ) {
                        $col  = '`' . $t['slug'] . '`';
                        $cond = implode( ' OR ', array_fill( 0, 20, $col . ' LIKE %s' ) );
                        $args = array();
                        foreach ( array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ) as $d ) {
                                $args[] = '%' . $d . '%';
                        }
                        $rows = TPP_DB::get_results( "SELECT id, {$col} AS v FROM {$t['table']} WHERE {$cond}", $args );
                        if ( empty( $rows ) ) {
                                continue;
                        }
                        $col_rows = 0;
                        $col_chars = 0;
                        $samples  = array();
                        foreach ( $rows as $r ) {
                                $fixed = TPP_Date::en_num( $r['v'] );
                                if ( $fixed === $r['v'] ) {
                                        continue; // با وجود LIKE ناهمسان — ایمن
                                }
                                $col_rows++;
                                $col_chars += self::count_fa_digits( $r['v'] );
                                if ( count( $samples ) < 3 ) {
                                        $samples[] = array( 'id' => (int) $r['id'], 'before' => mb_substr( (string) $r['v'], 0, 40 ), 'after' => mb_substr( $fixed, 0, 40 ) );
                                }
                                if ( ! $dry_run ) {
                                        TPP_DB::query( "UPDATE {$t['table']} SET {$col} = %s WHERE id = %d", array( $fixed, (int) $r['id'] ) );
                                }
                        }
                        if ( $col_rows ) {
                                $summary['columns'][] = array(
                                        'table_label' => $t['table_label'],
                                        'group'       => $t['group'],
                                        'label'       => $t['label'],
                                        'slug'        => $t['slug'],
                                        'rows'        => $col_rows,
                                        'chars'       => $col_chars,
                                        'samples'     => $samples,
                                );
                                $summary['rows']  += $col_rows;
                                $summary['chars'] += $col_chars;
                        }
                }

                return $summary;
        }

        /** شمارش ارقام فارسی/عربی یک رشته */
        private static function count_fa_digits( $s ) {
                return preg_match_all( '/[۰-۹٠-٩]/u', (string) $s );
        }
}
