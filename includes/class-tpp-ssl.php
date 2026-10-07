<?php
/**
 * مقاوم‌سازی SSL/HTTP — ۱.۳۲.۰
 *
 * مشکل: وقتی گواهی SSL سایت مشکل پیدا می‌کند اما نسخه HTTP در دسترس است، وردپرس
 * همه پیوندهای داخلی را با اسکیمای ذخیره‌شده در گزینه siteurl (مثلاً https) تولید
 * می‌کند؛ نتیجه: پیشخوان، لانچر اپ، فایل‌های js/css افزونه و REST-API همه به
 * httpsِ خراب اشاره می‌کنند و «نسخه http» عملاً unusable می‌شود.
 *
 * راه‌حل: اسکیمای همه پیوندهای داخلی وردپرس «آینه» اسکیمای درخواست جاری می‌شود:
 *   - بازدید با http  → همه پیوندهای داخلی http  → سایت، اپ و API کار می‌کنند
 *   - بازدید با https → همه پیوندهای داخلی https → رفتار عادی
 * یعنی «با و بدون SSL» هر دو جهت به‌صورت خودکار پشتیبانی می‌شود و هیچ تنظیمی لازم نیست.
 *
 * محافظت‌ها:
 *  - فقط پیوندهای «همان میزبانِ» درخواست جاری بازنویسی می‌شوند (لینک‌های خارجی/CDN دست‌نخورده)
 *  - در کرون/CLI و درخواست‌های بدون میزبان، هیچ بازنویسی‌ای انجام نمی‌شود
 *  - ریدایرکت canonical هم اسکیمای فعلی بازدیدکننده را نگه می‌دارد تا کاربر به https خراب پرتاب نشود
 *  - هدرهای توکن افزونه به فهرست هدرهای مجاز CORS اضافه می‌شوند تا API از یک صفحه http
 *    بتواند با REST روی https (یا برعکس) کار کند — «مسیر جایگزین» خودکار
 *
 * سمت اپ هم در tpp-offline.js هر دو اسکیما + rest_route + admin-ajax به‌صورت خودکار
 * امتحان می‌شوند و مسیر برنده به‌خاطر سپرده می‌شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** بازنویسی اسکیمای یک URL داخلی مطابق اسکیمای درخواست جاری (توابع ساده برای استفاده پیش از تعریف ثابت‌های افزونه) */
function tpp_mirror_url( $url ) {
	return call_user_func( array( 'TPP_Ssl', 'mirror' ), $url );
}

class TPP_Ssl {

	/** ثبت فیلترها — در بارگذاری افزونه فراخوانی می‌شود (قبل از تولید هر پیوندی) */
	public static function init() {
		$filters = array(
			'site_url', 'home_url', 'admin_url', 'includes_url', 'content_url',
			'plugins_url', 'rest_url', 'network_site_url', 'network_home_url',
		);
		foreach ( $filters as $f ) {
			add_filter( $f, array( __CLASS__, 'mirror' ), 999 );
		}
		// جلوگیری از پرتاب کاربر به https خراب (یا http قدیمی) در ریدایرکت canonical
		add_filter( 'redirect_canonical', array( __CLASS__, 'mirror' ), 999 );
		// هدرهای مجاز CORS برای مصرف بین‌اسکیمایی API (اپ/اتصال‌های بیرونی با توکن)
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
	}

	/**
	 * آینه‌کردن اسکیما — فقط URLهای http(s) از همان میزبانِ درخواست جاری.
	 * درخواست https → پیوندهای http به https می‌روند؛ درخواست http → برعکس.
	 * خروجی کرون/CLI و URLهای میزبان دیگر عیناً برگردانده می‌شوند.
	 *
	 * @param string $url پیوند تولیدشده وردپرس.
	 * @return string
	 */
	public static function mirror( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		// فقط اسکیمای متنی — پیوندهای نسبی/protocol-relative/غیره دست‌نخورده
		if ( 0 !== stripos( $url, 'http://' ) && 0 !== stripos( $url, 'https://' ) ) {
			return $url;
		}
		$scheme = is_ssl() ? 'https' : 'http';
		if ( 0 === stripos( $url, $scheme . '://' ) ) {
			return $url; // هم‌اسکیما با درخواست جاری — کاری لازم نیست
		}
		// فقط درخواست‌های وب واقعی — کرون/CLI/بدون میزبان دست‌نخورده
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || empty( $_SERVER['HTTP_HOST'] ) ) {
			return $url;
		}
		$req_host = self::host_only( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$url_host = self::host_only( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $req_host || '' === $url_host || 0 !== strcasecmp( $url_host, $req_host ) ) {
			return $url; // میزبان دیگر (CDN/خارجی) — دست‌نخورده
		}
		return $scheme . '://' . preg_replace( '#^https?://#i', '', $url );
	}

	/** میزبان بدون پورت و کاراکترهای ناایمن — برای مقایسه امن */
	private static function host_only( $host ) {
		$host = trim( strtolower( (string) $host ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		return (string) preg_replace( '/[^a-z0-9\.\-]/', '', $host );
	}

	/**
	 * هر دو واریانت اسکیمای یک URL — برای نمایش «آدرس جایگزین» در مرکز API.
	 *
	 * @param string $url یک URL با اسکیمای متنی.
	 * @return array {http: string, https: string} یا خالی.
	 */
	public static function variants( $url ) {
		if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return array();
		}
		$rest = preg_replace( '#^https?://#i', '', $url );
		return array(
			'http'  => 'http://' . $rest,
			'https' => 'https://' . $rest,
		);
	}

	/** اجازه هدرهای توکن افزونه در پیش‌فلایت CORS (کنار X-WP-Nonce پیش‌فرض وردپرس) */
	public static function cors_headers( $headers ) {
		foreach ( array( 'X-TPP-Token', 'X-TPP-Nonce' ) as $h ) {
			if ( ! in_array( $h, (array) $headers, true ) ) {
				$headers[] = $h;
			}
		}
		return $headers;
	}
}
