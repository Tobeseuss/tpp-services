<?php
/**
 * موتور همگام‌سازی آفلاین — پردازش دسته‌ای عملیات‌های صف‌شده در دستگاه کاربر.
 * هر عملیات با op_id یکتا ارسال می‌شود؛ خروجی در op_log نگهداری می‌شود تا تکرار ارسال
 * (مثلاً قطع شبکه وسط همگام‌سازی) باعث ثبت دوباره نشود (idempotent).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_Sync {

        /**
         * پردازش دسته عملیات‌ها
         * $ops = [ ['op_id'=>uuid, 'kind'=>'service.create|service.update|service.delete|history.delete',
         *           'payload'=>{...}, 'base_version'=>n], ... ]
         */
        public function handle_batch( array $ops, $user_id ) {
                $results = array();
                foreach ( $ops as $op ) {
                        $results[] = $this->handle_one( $op, $user_id );
                }
                // ثبت گزارش همگام‌سازی
                $applied = $conflicted = $failed = 0;
                foreach ( $results as $r ) {
                        if ( 'error' === $r['status'] ) {
                                $failed++;
                        } elseif ( ! empty( $r['conflict'] ) ) {
                                $conflicted++;
                        } elseif ( 'duplicate' !== $r['status'] ) {
                                $applied++;
                        }
                }
                TPP_DB::insert( 'sync_log', array(
                        'user_id'       => (int) $user_id,
                        'ops_applied'   => $applied,
                        'ops_conflicted'=> $conflicted,
                        'ops_failed'    => $failed,
                        'synced_at'     => TPP_Date::now(),
                ) );
                // پاک‌سازی سبک op_log قدیمی
                $this->maybe_cleanup();

                return array(
                        'results' => $results,
                        'summary' => array( 'applied' => $applied, 'conflicted' => $conflicted, 'failed' => $failed ),
                );
        }

        private function handle_one( $op, $user_id ) {
                $op_id = isset( $op['op_id'] ) ? substr( preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $op['op_id'] ), 0, 64 ) : '';
                $kind  = (string) ( $op['kind'] ?? '' );
                if ( '' === $op_id || '' === $kind ) {
                        return array( 'op_id' => $op_id, 'status' => 'error', 'message' => 'ساختار عملیات نامعتبر است.' );
                }

                // بررسی دسترسی
                switch ( $kind ) {
                        case 'service.create':
                                $cap = 'tpp_create_services';
                                break;
                        case 'service.update':
                                $cap = 'tpp_edit_services';
                                break;
                        case 'service.delete':
                                $cap = 'tpp_delete_services';
                                break;
                        case 'history.delete':
                                $cap = 'tpp_delete_history';
                                break;
                        case 'service.bulk': // ۱.۱۳.۰ — تغییر گروهی پیشرفت/وضعیت از حالت آفلاین (۱.۱۳.۱: قابلیت مستقل «ویرایش سریع»)
                                $cap = 'tpp_quick_edit';
                                break;
                        default:
                                return array( 'op_id' => $op_id, 'status' => 'error', 'message' => 'نوع عملیات ناشناخته.' );
                }
                if ( ! TPP_Capabilities::user_can( $user_id, $cap ) ) {
                        return array( 'op_id' => $op_id, 'status' => 'error', 'message' => 'دسترسی لازم را ندارید.' );
                }

                $payload = (array) ( $op['payload'] ?? array() );
                $services = tpp()->services();

                if ( 'service.create' === $kind ) {
                        $result = $services->create( $payload, array(
                                'user_id' => $user_id,
                                'source'  => 'offline',
                                'op_id'   => $op_id,
                        ) );
                } elseif ( 'service.update' === $kind ) {
                        $result = $services->update(
                                (int) ( $payload['id'] ?? 0 ),
                                $payload,
                                array(
                                        'user_id'         => $user_id,
                                        'source'          => 'offline',
                                        'op_id'           => $op_id,
                                        'base_version'    => (int) ( $op['base_version'] ?? 0 ),
                                        'base_address_version' => (int) ( $op['base_address_version'] ?? 0 ),
                                )
                        );
                } elseif ( 'history.delete' === $kind ) {
                        // حذف تکی/گروهی رکوردهای تاریخچه از حالت آفلاین — idempotent (حذف موارد حذف‌شده خطا نمی‌دهد)
                        $ids     = isset( $payload['ids'] ) && is_array( $payload['ids'] ) ? array_map( 'intval', $payload['ids'] ) : array();
                        $deleted = TPP_History::delete_entries( $ids );
                        $result  = array( 'status' => 'deleted', 'deleted' => (int) $deleted );
                } elseif ( 'service.bulk' === $kind ) {
                        // ۱.۱۳.۰ — تغییر گروهی پیشرفت/وضعیت از حالت آفلاین — idempotent (اعمال دوباره = بدون تغییر)
                        $ids  = isset( $payload['ids'] ) && is_array( $payload['ids'] ) ? array_map( 'intval', $payload['ids'] ) : array();
                        $opts = array();
                        foreach ( array( 'mode', 'step', 'steps', 'skipped_policy', 'failure', 'failures', 'service' ) as $k ) {
                                if ( array_key_exists( $k, $payload ) ) {
                                        $opts[ $k ] = $payload[ $k ];
                                }
                        }
                        if ( isset( $payload['progress'] ) && is_array( $payload['progress'] ) ) {
                                $opts = array_merge( $opts, $payload['progress'] );
                        }
                        $result = $services->apply_bulk( $ids, $opts, array(
                                'user_id' => $user_id,
                                'source'  => 'offline',
                                'op_id'   => $op_id,
                        ) );
                } else {
                        $result = $services->delete( (int) ( $payload['id'] ?? 0 ), array( 'user_id' => $user_id, 'source' => 'offline', 'op_id' => $op_id ) );
                }

                if ( is_wp_error( $result ) ) {
                        return array( 'op_id' => $op_id, 'status' => 'error', 'message' => $result->get_error_message() );
                }

                // افزودن داده تازه سرور برای به‌روزرسانی دقیق کش دستگاه
                if ( is_array( $result ) && ! empty( $result['id'] ) && 'deleted' !== $result['status'] ) {
                        $row = $services->get( (int) $result['id'] );
                        if ( $row ) {
                                $result['data'] = $services->shape_row( $row );
                        }
                }

                $result['op_id'] = $op_id;
                return $result;
        }

        /** حذف op_log های قدیمی‌تر از ۳۰ روز (احتمالی) */
        private function maybe_cleanup() {
                if ( wp_rand( 1, 20 ) !== 1 ) {
                        return;
                }
                TPP_DB::query( "DELETE FROM " . TPP_DB::table( 'op_log' ) . " WHERE created_at < %s", array( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
        }

        /** آخرین همگام‌سازی کاربر */
        public function last_sync( $user_id ) {
                return TPP_DB::get_var( "SELECT synced_at FROM " . TPP_DB::table( 'sync_log' ) . " WHERE user_id = %d ORDER BY id DESC LIMIT 1", array( (int) $user_id ) );
        }
}
