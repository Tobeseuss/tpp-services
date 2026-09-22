/**
 * Service Worker افزونه TPP Services
 * scope: پوشه pwa افزونه
 *
 * استراتژی:
 *  - ناوبری صفحه اپ: شبکه-اول (اینترنتی هست → همیشه HTML تازه؛ آفلاین → پوسته کش‌شده)
 *    ← دلیل: با کش-اول، پس از به‌روزرسانی افزونه نسخه قدیمی اپ برای همیشه نمایش داده می‌شد.
 *  - فایل‌های پوسته (css/js/img): کش-اول با URL نسخه‌دار (?v=) — با هر نسخه کلید کش عوض می‌شود
 *  - درخواست‌های GET به REST افزونه (tpp/v1): شبکه-اول + fallback کش (کار آفلاین)
 *  - سایر درخواست‌ها و همه متدهای نوشتاری: عبور مستقیم (صف آفلاین در اپ مدیریت می‌شود)
 *  - نصب مقاوم: خرابی یک فایل، کل به‌روزرسانی SW را نمی‌اندازد (allSettled)
 */
'use strict';

const SW_VERSION = 'tpp-v1.20.0';
const SHELL_CACHE = 'tpp-shell-' + SW_VERSION;
const DATA_CACHE  = 'tpp-data-' + SW_VERSION;

const SHELL_ASSETS = [
        './index.html',
        './manifest.json',
        './assets/img/icon.svg',
        './assets/css/app.css?v=1.20.0',
        './assets/js/idb.js?v=1.20.0',
        './assets/js/tpp-offline.js?v=1.20.0',
        './assets/js/tpp-app.js?v=1.20.0',
        './assets/js/tpp-views-form.js?v=1.20.0',
        './assets/js/tpp-views-admin.js?v=1.20.0',
        './assets/js/tpp-views-report.js?v=1.20.0',
        './assets/js/tpp-views-review.js?v=1.20.0',
        './assets/js/tpp-views-api.js?v=1.20.0'
];

self.addEventListener('install', (event) => {
        // همه‌تلاشی: اگر یک فایل هم نصف شد، نصب ادامه می‌یابد؛ در اولین fetch واقعی پر می‌شود
        event.waitUntil((async () => {
                try {
                        const cache = await caches.open(SHELL_CACHE);
                        await Promise.allSettled(SHELL_ASSETS.map(async (u) => {
                                try {
                                        await cache.add(new Request(u, { cache: 'reload' }));
                                } catch (e) { /* بی‌اهمیت — runtime پرش می‌کند */ }
                        }));
                } catch (e) { /* بی‌اهمیت */ }
                await self.skipWaiting();
        })());
});

self.addEventListener('activate', (event) => {
        event.waitUntil((async () => {
                try {
                        const keys = await caches.keys();
                        await Promise.all(
                                keys.filter((k) => k.indexOf('tpp-') === 0 && k !== SHELL_CACHE && k !== DATA_CACHE)
                                        .map((k) => caches.delete(k))
                        );
                } catch (e) { /* بی‌اهمیت */ }
                await self.clients.claim();
        })());
});

/** آیا درخواست به REST افزونه است؟ (مسیر /wp-json/tpp/v1 یا rest_route یا رله admin-ajax) */
function isTppRest(url) {
        if (url.origin !== self.location.origin) return false;
        if (url.pathname.indexOf('/wp-json/tpp/') !== -1) return true;
        if (url.searchParams && url.searchParams.get('rest_route') && url.searchParams.get('rest_route').indexOf('/tpp/') === 0) return true;
        if (url.pathname.endsWith('/admin-ajax.php') && url.searchParams && url.searchParams.get('action') === 'tpp_api') return true;
        return false;
}

/** آیا فایل پوسته اپ است؟ */
function isShellAsset(url) {
        if (url.origin !== self.location.origin) return false;
        const p = url.pathname;
        return p.indexOf('/wp-content/plugins/tpp-services/pwa/') === 0 || (p.endsWith('/pwa/') ) || (p.endsWith('/pwa/index.html'));
}

/** اینترنت در دسترس است؟ (خوش‌بینانه — اگر شبکه خطا داد به کش برمی‌گردیم) */
function netFetch(req) {
        return fetch(req);
}

self.addEventListener('fetch', (event) => {
        const req = event.request;
        if (req.method !== 'GET') return; // نوشتن: همیشه شبکه (اپ صف آفلاین خودش را دارد)

        const url = new URL(req.url);

        // ناوبری به صفحه اپ — شبکه-اول: اینترنت داریم یعنی همیشه آخرین نسخه HTML
        if (req.mode === 'navigate' && isShellAsset(url)) {
                event.respondWith((async () => {
                        try {
                                const fresh = await netFetch(req);
                                if (fresh && fresh.ok) {
                                        try {
                                                const cache = await caches.open(SHELL_CACHE);
                                                await cache.put('./index.html', fresh.clone());
                                        } catch (e) {}
                                }
                                if (fresh) return fresh;
                        } catch (e) { /* آفلاین */ }
                        const hit = await caches.match('./index.html');
                        return hit || netFetch(req).catch(() => new Response(
                                '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>آفلاین</title></head><body style="font-family:sans-serif;text-align:center;padding:40px"><h2>اتصال اینترنت برقرار نیست</h2><p>برای استفاده آفلاین، اپ را حداقل یک بار با اینترنت باز کرده باشید.</p></body></html>',
                                { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                        ));
                })());
                return;
        }

        // ۱) فایل‌های پوسته: کش-اول (URL ها نسخه‌دار هستند) + پرکردن خودکار کش در پس‌زمینه
        if (isShellAsset(url)) {
                event.respondWith((async () => {
                        const exact = await caches.match(req);
                        if (exact) return exact;
                        const loose = await caches.match(req, { ignoreSearch: true });
                        if (loose) {
                                // نسخه بدون پارامتر هم کش شده — عیناً همان فایل است
                                return loose;
                        }
                        try {
                                const fresh = await netFetch(req);
                                if (fresh && fresh.ok) {
                                        try {
                                                const cache = await caches.open(SHELL_CACHE);
                                                await cache.put(req, fresh.clone());
                                        } catch (e) {}
                                }
                                return fresh;
                        } catch (e) {
                                return new Response('', { status: 504 });
                        }
                })());
                return;
        }

        // ۲) REST افزونه: شبکه اول، fallback کش (پاسخ قدیمی بهتر از هیچ است)
        if (isTppRest(url)) {
                event.respondWith(
                        netFetch(req).then((res) => {
                                if (res && res.ok) {
                                        const clone = res.clone();
                                        caches.open(DATA_CACHE).then((c) => c.put(req, clone)).catch(() => {});
                                }
                                return res;
                        }).catch(() => {
                                return caches.match(req).then((hit) => {
                                        if (hit) return hit;
                                        return new Response(JSON.stringify({ code: 'tpp_offline', message: 'آفلاین هستید و داده کش‌شده‌ای برای این درخواست موجود نیست.' }), {
                                                status: 503, headers: { 'Content-Type': 'application/json' }
                                        });
                                });
                        })
                );
                return;
        }

        // ۳) بقیه: شبکه
});

/** پیام‌های اپ: فعال‌سازی فوری نسخه جدید / پاک‌سازی کش / بررسی به‌روزرسانی */
self.addEventListener('message', (event) => {
        const data = event.data || {};
        if (data === 'tpp-skip-waiting' || (data && data.type === 'tpp-skip-waiting')) {
                self.skipWaiting();
        } else if (data === 'tpp-clear-caches' || (data && data.type === 'tpp-clear-caches')) {
                event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))));
        }
});
