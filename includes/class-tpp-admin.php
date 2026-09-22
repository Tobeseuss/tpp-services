<?php
/**
 * رابط مدیریت وردپرس — منوی اختصاصی + لانچر اپ (iframe) + بخش توکن‌ها در پروفایل.
 * کل رابط کاربری روزمره در اپ PWA (پوشه pwa/) اجرا می‌شود تا کار آفلاین ممکن باشد.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TPP_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'show_user_profile', array( $this, 'profile_tokens' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_tokens' ) );
		add_action( 'admin_post_tpp_revoke_token', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_tpp_revoke_all', array( $this, 'handle_revoke_all' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TPP_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_menu_page(
			'سرویس‌های TPP',
			'سرویس‌های TPP',
			'tpp_view',
			'tpp-services',
			array( $this, 'render_launcher' ),
			'dashicons-networking',
			26
		);
		add_submenu_page(
			'tpp-services',
			'برنامه مدیریت سرویس‌ها',
			'برنامه مدیریت سرویس‌ها',
			'tpp_view',
			'tpp-services',
			array( $this, 'render_launcher' )
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=tpp-services' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">مدیریت سرویس‌ها</a>' );
		return $links;
	}

	public function render_launcher() {
		if ( ! current_user_can( 'tpp_view' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$app_url = TPP_PLUGIN_URL . 'pwa/index.html';
		require TPP_PLUGIN_DIR . 'admin/views/launcher.php';
	}

	/** بخش «دستگاه‌های متصل» در پروفایل کاربر */
	public function profile_tokens( $user ) {
		if ( ! tpp()->auth()->user_has_tpp_access( $user->ID ) ) {
			return;
		}
		$tokens = tpp()->auth()->tokens_of( $user->ID );
		?>
		<h2>دستگاه‌های متصل افزونه سرویس‌های TPP</h2>
		<p class="description">توکن‌های فعال برای کار آفلاین اپ. با حذف توکن، آن دستگاه از افزونه خارج می‌شود (تاریخچه داده‌ها دست‌نخورده می‌ماند).</p>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th>نام</th><th>آخرین استفاده</th><th>تاریخ ایجاد</th><th>مرورگر/دستگاه</th><th>عملیات</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $tokens ) ) : ?>
					<tr><td colspan="5">دستگاهی ثبت نشده است.</td></tr>
				<?php else : foreach ( $tokens as $t ) : ?>
					<tr>
						<td><?php echo esc_html( $t['label'] ); ?></td>
						<td><?php echo esc_html( $t['last_used'] ? $t['last_used'] : '—' ); ?></td>
						<td><?php echo esc_html( $t['created_at'] ); ?></td>
						<td style="max-width:320px"><?php echo esc_html( $t['user_agent'] ); ?></td>
						<td>
							<a class="button button-small"
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tpp_revoke_token&idx=' . $t['idx'] ), 'tpp_revoke' ) ); ?>"
								onclick="return confirm('این دستگاه از افزونه خارج شود؟');">خروج دستگاه</a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<p>
			<a class="button"
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tpp_revoke_all' ), 'tpp_revoke' ) ); ?>"
				onclick="return confirm('همه دستگاه‌ها (شامل دستگاه فعلی) از افزونه خارج شوند؟');">خروج همه دستگاه‌ها</a>
		</p>
		<?php
	}

	private function verify_revoke() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'tpp_revoke' );
	}

	public function handle_revoke() {
		$this->verify_revoke();
		$idx = isset( $_GET['idx'] ) ? (int) $_GET['idx'] : -1; // phpcs:ignore WordPress.Security.NonceVerification
		tpp()->auth()->revoke_by_idx( get_current_user_id(), $idx );
		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'profile.php' ) ) );
		exit;
	}

	public function handle_revoke_all() {
		$this->verify_revoke();
		tpp()->auth()->revoke_all( get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'profile.php' ) ) );
		exit;
	}
}
