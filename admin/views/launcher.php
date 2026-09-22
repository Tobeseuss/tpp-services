<?php
/**
 * نمایش لانچر اپ در پیشخوان وردپرس — اپ کامل در iframe بارگذاری می‌شود
 * تا استایل پیشخوان با آن تداخل نکند و کوکی نشست وردپرس مستقیماً برای احراز هویت استفاده شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}
$app_url = add_query_arg(
        array(
                'embed'  => '1',
                'parent' => rawurlencode( admin_url( 'admin.php?page=tpp-services' ) ),
                'v'      => TPP_VERSION, // نسخه → کش، آدرس تازه iframe را بعد از به‌روزرسانی بگیرد
        ),
        TPP_PLUGIN_URL . 'pwa/index.html'
);
?>
<div class="wrap" dir="rtl">
        <h1>سرویس‌های TPP <span class="dashicons dashicons-networking" style="font-size:28px"></span></h1>
        <p style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                <span class="button-primary" onclick="document.getElementById('tpp-frame').requestFullscreen ? document.getElementById('tpp-frame').requestFullscreen() : null" style="cursor:pointer">نمای تمام‌صفحه</span>
                <a class="button" href="<?php echo esc_url( TPP_PLUGIN_URL . 'pwa/index.html' ); ?>" target="_blank" rel="noopener">باز کردن اپ در تب جدید <span class="dashicons dashicons-external" style="line-height:1.4"></span></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">مدیریت کاربران و نقش‌ها</a>
        </p>
        <iframe id="tpp-frame" src="<?php echo esc_url( $app_url ); ?>" style="width:100%;height:1400px;border:1px solid #c3c4c7;border-radius:6px;background:#f0f2f5"></iframe>
        <p class="description">
                <b>نسخه رابط کاربری: <?php echo esc_html( TPP_VERSION ); ?></b> — اگر پس از به‌روزرسانی افزونه امکانات جدید را نمی‌بینید، یک بار صفحه را با کلیدهای <code dir="ltr">Ctrl+F5</code> تازه‌سازی کنید؛ از این نسخه به بعد برنامه به‌صورت خودکار به‌روزرسانی می‌شود.
        </p>
        <p class="description">
                اگر کاربر واردشده وردپرس باشید، اپ بالا بدون نیاز به ورود مجدد باز می‌شود (احراز هویت با نشست خود وردپرس).
                برای قرار دادن این برنامه در صفحات سایت، شورت‌کد <code dir="ltr">[tpp_services]</code> را در هر برگه/نوشته قرار دهید —
                فقط کاربرانِ واردشده دارای نقش افزونه به آن دسترسی خواهند داشت.
                اپ قابلیت کار آفلاین هم دارد: اگر اینترنت قطع شود، داده‌های آخرین همگام‌سازی به‌صورت محلی نمایش داده می‌شوند و
                تغییرات جدید در صف دستگاه ذخیره و با وصل شدن اینترنت به‌صورت خودکار ثبت می‌شوند.
                برای بهترین نتیجه، اپ را در تب جدید باز کنید و به مرورگر اجازه نصب بدهید.
        </p>
</div>
