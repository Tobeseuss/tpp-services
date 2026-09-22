/**
 * tpp-offline.js — موتور آفلاین/آنلاین:
 *  ۱) TPP.api      → کلاینت REST با تشخیص خودکار آدرس و احراز هویت توکنی (هدر X-TPP-Token)
 *  ۲) TPP.offline  → کش کامل داده‌ها در IndexedDB + جستجوی محلی + صف عملیات (outbox) + همگام‌سازی خودکار
 *
 * سیاست همگام‌سازی: هر عملیات با op_id یکتا در صف ثبت و بلافاصله تلاش برای ارسال می‌شود؛
 * اگر شبکه در دسترس نبود، در دستگاه می‌ماند و با وصل شدن اینترنت به‌صورت خودکار ارسال می‌شود.
 * سرور با op_log از ثبت تکراری جلوگیری می‌کند (idempotent).
 */
'use strict';

window.TPP = window.TPP || {};

/* ============================ REST Client ============================ */
/**
 * کلاینت API با تشخیص خودکار ترنسپورت:
 *  ۱) REST-API وردپرس (wp-json یا ?rest_route= برای پیوند یکتای خاموش)
 *  ۲) در صورت مسدود بودن REST توسط افزونه امنیتی/سرور → پشتیبان admin-ajax (action=tpp_api)
 * آدرس سایت از روی آدرس خودِ اسکریپت پیدا می‌شود تا نصب وردپرس در زیرپوشه هم پشتیبانی شود.
 * احراز هویت: کوکی وردپرس + X-WP-Nonce (حالت سایت/پیشخوان) یا هدر X-TPP-Token (همیشه).
 */
TPP.api = (function () {

        const CFG = window.TPP_CONFIG || {};

        let transport = null; // { mode:'rest', base:{type,url} } | { mode:'ajax', url }
        let token = localStorage.getItem('tpp_token') || '';
        let nonce = null;
        try { nonce = localStorage.getItem('tpp_nonce') || null; } catch (e) {}

        function setToken(t) {
                token = t || '';
                if (t) localStorage.setItem('tpp_token', t);
                else localStorage.removeItem('tpp_token');
        }

        function hasToken() { return !!token; }

        function setNonce(n) {
                if (n && typeof n === 'string') {
                        nonce = n;
                        try { localStorage.setItem('tpp_nonce', n); } catch (e) {}
                }
        }

        /* ---------- استخراج آدرس سایت از آدرس خود اسکریپت ---------- */

        function siteBaseCandidates() {
                const out = [];
                try {
                        const scripts = document.querySelectorAll('script[src]');
                        for (const sc of scripts) {
                                const m = String(sc.src || '').match(/^(.*\/)wp-content\/plugins\//);
                                if (m) { out.push(m[1].replace(/\/$/, '')); break; }
                        }
                } catch (e) { /* noop */ }
                if (CFG.siteUrl) out.push(String(CFG.siteUrl).replace(/\/$/, ''));
                out.push(location.origin);
                return out.filter((v, i, a) => v && a.indexOf(v) === i);
        }

        function restCandidates() {
                const list = [];
                if (CFG.restBase) list.push({ type: 'pretty', url: String(CFG.restBase).replace(/\/$/, '') });
                for (const base of siteBaseCandidates()) {
                        list.push({ type: 'pretty', url: base + '/wp-json/tpp/v1' });
                        list.push({ type: 'ugly', url: base + '/index.php?rest_route=/tpp/v1' });
                }
                return list.filter((v, i, a) => v.url && a.findIndex((x) => x.url === v.url) === i);
        }

        function ajaxCandidates() {
                const list = [];
                if (CFG.ajaxUrl) list.push(CFG.ajaxUrl);
                for (const base of siteBaseCandidates()) {
                        list.push(base + '/wp-admin/admin-ajax.php');
                }
                return list.filter((v, i, a) => v && a.indexOf(v) === i);
        }

        /* ---------- ساخت URL ---------- */

        function qsOf(params) {
                return params ? new URLSearchParams(
                        Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
                ).toString() : '';
        }

        /** مسیر و پارامترها را جدا می‌کند (پشتیبانی از path?query) */
        function normalize(path, params) {
                let p = String(path).replace(/^\//, '');
                const qi = p.indexOf('?');
                if (qi !== -1) {
                        const qs = new URLSearchParams(p.slice(qi + 1));
                        p = p.slice(0, qi);
                        params = Object.assign({}, Object.fromEntries(qs.entries()), params || {});
                }
                return [p, params];
        }

        function restUrl(base, path, params) {
                const [p, prm] = normalize(path, params);
                const qs = qsOf(prm);
                let u = base.url + '/' + p;
                if (base.type === 'ugly') {
                        u = base.url + '/' + p + (qs ? '&' + qs : '');
                } else if (qs) {
                        u += '?' + qs;
                }
                return u;
        }

        function ajaxUrl(path, params) {
                const [p, prm] = normalize(path, params);
                const qs = qsOf(prm);
                let u = transport.url + '?action=tpp_api&route=' + encodeURIComponent(p) + '&method=GET';
                if (qs) u += '&' + qs;
                if (token) u += '&token=' + encodeURIComponent(token);
                return u;
        }

        /* ---------- اجرای درخواست ---------- */

        function headersBase() {
                const headers = { 'Accept': 'application/json' };
                if (token) headers['X-TPP-Token'] = token;
                if (nonce) headers['X-WP-Nonce'] = nonce;
                return headers;
        }

        function catchNonce(res, json) {
                const h = res.headers && res.headers.get ? res.headers.get('X-TPP-Nonce') : null;
                if (h) setNonce(h);
                else if (json && json.nonce) setNonce(json.nonce);
        }

        async function restRequest(method, path, body, params, opts) {
                opts = opts || {};
                const headers = headersBase();
                let payload;
                if (body !== undefined && body !== null && method !== 'GET') {
                        headers['Content-Type'] = 'application/json; charset=utf-8';
                        payload = JSON.stringify(body);
                }
                const init = { method, headers, credentials: 'same-origin', cache: 'no-store', signal: opts.signal };
                if (payload) init.body = payload;

                let res;
                try {
                        res = await fetch(restUrl(transport.base, path, params), init);
                } catch (e) {
                        const err = new Error('خطای شبکه — اتصال اینترنت را بررسی کنید');
                        err.network = true;
                        throw err;
                }

                if (opts.raw) {
                        if (!res.ok) {
                                let msg = 'خطا در دریافت فایل';
                                try { const j = await res.clone().json(); if (j && j.message) msg = j.message; } catch (e) {}
                                const err = new Error(msg);
                                err.status = res.status;
                                throw err;
                        }
                        return res;
                }

                let json = null;
                try { json = await res.json(); } catch (e) { json = null; }
                catchNonce(res, json);

                if (!res.ok) {
                        const err = new Error((json && json.message) ? json.message : 'خطای سرور (' + res.status + ')');
                        err.code = json && json.code ? json.code : 'http_' + res.status;
                        err.status = res.status;
                        err.data = json && json.data ? json.data : null;
                        throw err;
                }
                return json;
        }

        async function ajaxRequest(url, method, path, body, params, opts) {
                opts = opts || {};
                const fd = new FormData();
                const [p, prm] = normalize(path, params);
                fd.append('action', 'tpp_api');
                fd.append('route', p);
                fd.append('method', method);
                if (prm) fd.append('args', JSON.stringify(prm));
                if (body !== undefined && body !== null && method !== 'GET') fd.append('body', JSON.stringify(body));
                if (token) fd.append('token', token);
                const headers = {};
                if (token) headers['X-TPP-Token'] = token;
                if (nonce) headers['X-WP-Nonce'] = nonce;

                let res;
                try {
                        res = await fetch(url, { method: 'POST', headers, body: fd, credentials: 'same-origin', cache: 'no-store', signal: opts.signal });
                } catch (e) {
                        const err = new Error('خطای شبکه — اتصال اینترنت را بررسی کنید');
                        err.network = true;
                        throw err;
                }

                if (opts.raw) {
                        if (!res.ok) {
                                let msg = 'خطا در دریافت فایل';
                                try { const j = await res.clone().json(); if (j && j.message) msg = j.message; } catch (e) {}
                                const err = new Error(msg);
                                err.status = res.status;
                                throw err;
                        }
                        return res;
                }

                let json = null;
                try { json = await res.json(); } catch (e) { json = null; }
                catchNonce(res, json);

                if (!res.ok) {
                        const err = new Error((json && json.message) ? json.message : 'خطای سرور (' + res.status + ')');
                        err.code = json && json.code ? json.code : 'http_' + res.status;
                        err.status = res.status;
                        err.data = json && json.data ? json.data : null;
                        throw err;
                }
                return json;
        }

        function doRequest(method, path, body, params, opts) {
                if (transport.mode === 'ajax') return ajaxRequest(transport.url, method, path, body, params, opts);
                return restRequest(method, path, body, params, opts);
        }

        /* ---------- تشخیص ترنسپورت ---------- */

        async function detectTransport() {
                // ۱) REST-API (ابتدا آدرس مشتق‌شده از خود اسکریپت — سازگار با نصب در زیرپوشه)
                let blockedRest = null;
                for (const base of restCandidates()) {
                        try {
                                const res = await fetch(restUrl(base, 'ping'), { cache: 'no-store', credentials: 'same-origin' });
                                const json = await res.json().catch(() => null);
                                if (json && json.ok === true) {
                                        if (json.nonce) setNonce(json.nonce);
                                        transport = { mode: 'rest', base };
                                        return transport;
                                }
                                // REST هست ولی برای کاربر ناشناس محدود شده (افزونه امنیتی) — به‌عنوان گزینه آخر نگه دار
                                if (json && json.code && (res.status === 401 || res.status === 403) && !blockedRest) {
                                        blockedRest = base;
                                }
                        } catch (e) { /* گزینه بعدی */ }
                }
                // ۲) پشتیبان admin-ajax
                for (const url of ajaxCandidates()) {
                        const saved = transport;
                        try {
                                transport = { mode: 'ajax', url };
                                const json = await ajaxRequest(url, 'GET', 'ping', null, null, {});
                                if (json && json.ok === true) {
                                        if (json.nonce) setNonce(json.nonce);
                                        return transport;
                                }
                                transport = saved;
                        } catch (e) {
                                transport = saved;
                        }
                }
                // ۳) آخرین گزینه: RESTِ محدودشده (شاید با کوکی کاربر واردشده باز شود)
                if (blockedRest) {
                                transport = { mode: 'rest', base: blockedRest };
                                return transport;
                }
                transport = null;
                throw new Error('REST-API افزونه در دسترس نیست — اتصال اینترنت و مسدودسازی توسط افزونه امنیتی را بررسی کنید');
        }

        async function ensureTransport(force) {
                if (transport && !force) return transport;
                await detectTransport();
                return transport;
        }

        /* ---------- درخواست عمومی با تلاش مجدد هوشمند ---------- */

        async function request(method, path, body, params, opts) {
                opts = opts || {};
                await ensureTransport();
                try {
                        return await doRequest(method, path, body, params, opts);
                } catch (e) {
                        // nonce کوکی منقضی شده → تازه‌سازی از ping و تلاش مجدد
                        if (e && e.code === 'rest_cookie_invalid_nonce' && !opts._retried) {
                                try {
                                        const ping = await doRequest('GET', 'ping', null, null, { _retried: true });
                                        if (ping && ping.nonce) setNonce(ping.nonce);
                                } catch (e2) { /* noop */ }
                                return doRequest(method, path, body, params, Object.assign({}, opts, { _retried: true }));
                        }
                        // خطای شبکه → شاید ترنسپورت خراب است؛ دوباره تشخیص بده و یک‌بار تلاش کن
                        if (e && e.network && !opts._retried) {
                                try {
                                        await ensureTransport(true);
                                        return await doRequest(method, path, body, params, Object.assign({}, opts, { _retried: true }));
                                } catch (e2) { throw e2; }
                        }
                        throw e;
                }
        }

        /** ورود — در صورت مسدود بودن REST، خودکار از admin-ajax تلاش می‌کند */
        async function login(username, password) {
                try {
                        return await request('POST', 'login', { username, password });
                } catch (e) {
                        if (transport && transport.mode === 'rest' && (e.status === 403 || e.status === 404 || e.network)) {
                                const saved = transport;
                                for (const url of ajaxCandidates()) {
                                        try {
                                                transport = { mode: 'ajax', url };
                                                const json = await ajaxRequest(url, 'POST', 'login', { username, password }, null, {});
                                                if (json && json.token) return json;
                                                transport = saved;
                                        } catch (e2) {
                                                transport = saved;
                                                // خطای ۴۰۱ یعنی رمز اشتباه است — نیازی به گزینه بعدی نیست
                                                if (e2 && e2.status === 401) throw e2;
                                        }
                                }
                        }
                        throw e;
                }
        }

        /* ---------- آپلود فایل (multipart) ---------- */

        async function upload(path, file, fieldName) {
                await ensureTransport();
                const [p] = normalize(path, null);
                let res;
                try {
                        if (transport.mode === 'ajax') {
                                const fd = new FormData();
                                fd.append('action', 'tpp_api');
                                fd.append('route', p);
                                fd.append('method', 'POST');
                                if (token) fd.append('token', token);
                                fd.append(fieldName || 'file', file, file.name);
                                const headers = {};
                                if (token) headers['X-TPP-Token'] = token;
                                if (nonce) headers['X-WP-Nonce'] = nonce;
                                res = await fetch(transport.url, { method: 'POST', headers, body: fd, credentials: 'same-origin', cache: 'no-store' });
                        } else {
                                const fd = new FormData();
                                fd.append(fieldName || 'file', file, file.name);
                                const headers = {};
                                if (token) headers['X-TPP-Token'] = token;
                                if (nonce) headers['X-WP-Nonce'] = nonce;
                                res = await fetch(restUrl(transport.base, p), { method: 'POST', headers, body: fd, credentials: 'same-origin', cache: 'no-store' });
                        }
                } catch (e) {
                        const err = new Error('خطای شبکه هنگام آپلود — اتصال اینترنت را بررسی کنید');
                        err.network = true;
                        throw err;
                }

                const text = await res.text();
                let json = null;
                try { json = JSON.parse(text); } catch (e) { json = null; }
                catchNonce(res, json);

                if (!res.ok) {
                        const err = new Error((json && json.message) ? json.message : 'خطا در آپلود (کد ' + res.status + ')');
                        err.status = res.status;
                        throw err;
                }
                // پاسخ ۲۰۰ ولی غیر JSON — معمولاً صفحه خطای PHP/میزبان یا محدودیت آپلود سرور
                if (null === json || 'undefined' === typeof json) {
                        const snippet = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 140);
                        const err = new Error('پاسخ سرور قابل خواندن نبود (احتمالاً خطای PHP یا محدودیت حجم آپلود سرور). ' + (snippet ? 'پاسخ سرور: «' + snippet + '»' : ''));
                        err.status = res.status;
                        throw err;
                }
                return json;
        }

        /* ---------- بازکردن خروجی HTML (چاپ مرورگر) در پنجره جدید ---------- */

        /** دریافت HTML از سرور و بازکردن در تب جدید — مناسب خروجی «چاپ/PDF مرورگر» */
        async function openHtml(path, params) {
                const res = await request('GET', path, null, params, { raw: true });
                const html = await res.text();
                const blob = new Blob([html], { type: 'text/html; charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const w = window.open(url, '_blank');
                if (!w) {
                        const err = new Error('پنجره جدید توسط مرورگر مسدود شد — اجازه بازشدن پنجره‌های این سایت را بدهید.');
                        URL.revokeObjectURL(url);
                        throw err;
                }
                setTimeout(() => { URL.revokeObjectURL(url); }, 120000);
        }

        /* ---------- دانلود فایل (اکسل/پشتیبان) ---------- */

        async function download(path, params, filename) {
                await ensureTransport();
                let res;
                if (transport.mode === 'ajax') {
                        res = await ajaxRequest(transport.url, 'GET', path, null, params, { raw: true });
                } else {
                        res = await restRequest('GET', path, null, params, { raw: true });
                }
                const blob = await res.blob();
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename || 'download';
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
        }

        return { request, upload, download, openHtml, login, setToken, setNonce, hasToken, ensureTransport, detectBase: ensureTransport, mode: () => (transport ? transport.mode : null) };
})();

/* ============================ Offline Engine ============================ */

TPP.offline = (function () {

        const listeners = { change: [], sync: [], cache: [] };
        let state = {
                online: navigator.onLine,
                pending: 0,
                lastSync: null,
                syncing: false,
                cache: null // وضعیت کش آفلاین (۱.۹.۲) — { syncing, complete, cached, total, error, ts }
        };

        function emit(ev, data) { (listeners[ev] || []).forEach((fn) => { try { fn(data); } catch (e) {} }); }
        function on(ev, fn) { listeners[ev].push(fn); }

        function refreshPendingBadge() {
                TPP_IDB.count('outbox').then((n) => {
                        state.pending = n;
                        emit('change', { ...state });
                }).catch(() => {});
        }

        /* ---------- نرمال‌سازی متن فارسی برای جستجو/مقایسه ---------- */
        function norm(text) {
                return String(text == null ? '' : text)
                        .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                        .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
                        .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
                        .replace(/\u200c/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim().toLowerCase();
        }

        /* ---------- کش داده‌ها ---------- */

        /**
         * وضعیت کش آفلاین روی این دستگاه (۱.۹.۲):
         * syncing (در جریان است؟) / complete (کل پایگاه داده ذخیره شده؟) / cached (تعداد رکورد روی دیسک)
         * total (کل رکورد سرور) / error (آخرین خطا) / ts (زمان آخرین همگام‌سازی کامل) / perPage (اندازه صفحه موفق)
         */
        const CACHE_STATE_KEY = 'offline_cache_state';
        const PAGE_SIZES = [500, 200, 100, 50];
        function defaultCacheState() {
                return { syncing: false, complete: false, cached: 0, total: 0, error: null, ts: null, perPage: 500 };
        }
        let cacheState = defaultCacheState();
        let cacheRun = null;   // اجرای همزمان نداریم — پروسه جاری برمی‌گردد
        let retryTimer = null; // تایمر retry خودکار پس از خطا
        let retryDelay = 0;    // ثانیه — ۳۰ → ۶۰ → ۱۲۰ → ۲۴۰ → ۳۰۰ (بیشینه)

        function loadCacheState() {
                return TPP_IDB.get('kv', CACHE_STATE_KEY).then((rec) => {
                        if (rec && rec.v) cacheState = Object.assign(defaultCacheState(), rec.v);
                        state.cache = { ...cacheState };
                }).catch(() => {});
        }

        function saveCacheState(patch) {
                cacheState = Object.assign(defaultCacheState(), cacheState, patch);
                state.cache = { ...cacheState };
                emit('cache', { ...cacheState });
                TPP_IDB.set('kv', CACHE_STATE_KEY, cacheState).catch(() => {});
        }

        /** retry خودکار پس از خطا — فقط وقتی آنلاین و ناقص هستیم */
        function scheduleCacheRetry() {
                if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
                if (!state.online || cacheState.complete || !TPP.api.hasToken()) return;
                retryDelay = retryDelay ? Math.min(retryDelay * 2, 300) : 30;
                retryTimer = setTimeout(() => { retryTimer = null; cacheAll().catch(() => {}); }, retryDelay * 1000);
        }

        function resetCacheRetry() {
                retryDelay = 0;
                if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
        }

        /** آمار کش برای نمایش در رابط کاربری */
        function cacheStats() {
                return { ...cacheState, lastSync: state.lastSync };
        }

        /**
         * دریافت کامل داده‌های قابل مشاهده و ذخیره در IndexedDB (بازنویسی ۱.۹.۲):
         * - نوشتن دسته‌ای: یک تراکنش IndexedDB برای هر صفحه (به‌جای یک تراکنش به‌ازای هر ردیف)
         * - کاهش خودکار اندازه صفحه در صورت خطای سرور (500 → 200 → 100 → 50) و یادآوری اندازه موفق
         * - خطای شبکه → توقف فوری + retry خودکار با فاصله زمانی فزاینده؛ داده‌های قبلی حفظ می‌شوند
         * - وضعیت و پیشرفت از طریق state.cache و رویداد 'cache' برای نمایش به کاربر
         */
        function cacheAll(onProgress) {
                if (!TPP.api.hasToken()) return Promise.resolve();
                if (cacheRun) return cacheRun; // از قبل در جریان است — همان پروسه (هویت Promise حفظ می‌شود)
                if (!state.online) return Promise.resolve(); // آفلاین: با وصل شدن اینترنت دوباره اجرا می‌شود
                cacheRun = (async () => {
                        let perSize = PAGE_SIZES.indexOf(cacheState.perPage) !== -1 ? cacheState.perPage : PAGE_SIZES[0];
                        let page = 1, total = Infinity, fetched = 0;
                        const fresh = new Set();
                        let completed = false;
                        saveCacheState({ syncing: true, error: null });
                        try {
                                while (fetched < total && page <= 400) {
                                        let res;
                                        try {
                                                res = await TPP.api.request('GET', 'search', null, { page, per_page: perSize });
                                        } catch (e) {
                                                if (e && e.network) throw e; // شبکه قطع است — اندازه صفحه ربطی ندارد
                                                // خطای سرور (حجم/وقت/دسترسی) → یک اندازه کوچک‌تر امتحان شود
                                                const next = PAGE_SIZES[PAGE_SIZES.indexOf(perSize) + 1];
                                                if (!next) throw e;
                                                perSize = next;
                                                saveCacheState({ perPage: perSize });
                                                res = await TPP.api.request('GET', 'search', null, { page, per_page: perSize });
                                        }
                                        total = parseInt(res.total, 10) || 0;
                                        const rows = res.rows || [];
                                        // نوشتن دسته‌ای — یک تراکنش برای کل صفحه
                                        await TPP_IDB.run(['services'], 'readwrite', (t) => {
                                                const os = t.objectStore('services');
                                                rows.forEach((row) => {
                                                        if (row && row.id != null) { os.put(row); fresh.add(row.id); }
                                                });
                                        });
                                        fetched += rows.length;
                                        saveCacheState({ cached: fresh.size, total, syncing: true });
                                        if (onProgress) onProgress(fetched, total);
                                        if (rows.length === 0) break;
                                        page++;
                                }
                                completed = true;
                        } catch (e) {
                                // ناقص ماند — داده‌های ذخیره‌شده حفظ می‌شوند و retry خودکار برنامه می‌شود
                                const n = await TPP_IDB.count('services').catch(() => fresh.size);
                                saveCacheState({ syncing: false, complete: false, cached: n, total: total === Infinity ? 0 : total, error: (e && e.message) ? e.message : String(e) });
                                scheduleCacheRetry();
                                throw e;
                        } finally {
                                cacheRun = null;
                        }
                        if (completed) {
                                // حذف رکوردهای موقت قدیمی که دیگر در سرور نیستند و هم‌زمان صف خالی است
                                const outboxCount = await TPP_IDB.count('outbox');
                                if (outboxCount === 0 && fresh.size > 0) {
                                        const stale = [];
                                        await TPP_IDB.cursor('services', (row, key) => {
                                                if (!fresh.has(row.id)) stale.push(key);
                                        });
                                        if (stale.length) {
                                                await TPP_IDB.run(['services'], 'readwrite', (t) => {
                                                        const os = t.objectStore('services');
                                                        stale.forEach((k) => os.delete(k));
                                                });
                                        }
                                }
                                const n = await TPP_IDB.count('services').catch(() => fresh.size);
                                resetCacheRetry();
                                saveCacheState({ syncing: false, complete: true, cached: n, total, error: null, ts: new Date().toISOString() });
                                // کش تاریخچه برای حالت آفلاین (محدود به سقف تنظیم‌شده)
                                cacheHistory().catch(() => {});
                                await TPP_IDB.set('kv', 'last_full_sync', new Date().toISOString());
                                state.lastSync = new Date().toISOString();
                                emit('change', { ...state });
                        }
                })();
                return cacheRun;
        }

        /**
         * کش تاریخچه در IndexedDB (store «history» — روی دیسک).
         * صفحه‌به‌صفحه از سرور خوانده می‌شود؛ سقف رکورد از تنظیمات offline_cache_size.
         */
        async function cacheHistory() {
                const st = (TPP.app && TPP.app.state && TPP.app.state().settings) || {};
                const cap = Math.max(500, parseInt(st.offline_cache_size, 10) || 5000);
                let page = 1;
                const perPage = 500;
                const fresh = new Set();
                let total = Infinity;
                while (fresh.size < Math.min(total, cap) && page <= 60) {
                        const res = await TPP.api.request('GET', 'history', null, { page, per_page: perPage });
                        total = parseInt(res.total, 10) || 0;
                        const rows = res.rows || [];
                        for (const h of rows) {
                                await TPP_IDB.set('history', 'h' + h.id, { ...h, key: 'h' + h.id });
                                fresh.add(h.id);
                        }
                        if (!rows.length) break;
                        page++;
                }
                // حذف رکوردهای کش‌شده‌ای که دیگر در سرور نیستند (مثلاً حذف آبشاری)
                const stale = [];
                await TPP_IDB.cursor('history', (row, key) => {
                        if (!fresh.has(row.id)) stale.push(key);
                });
                for (const key of stale) await TPP_IDB.del('history', key);
        }

        async function lastSync() {
                if (!state.lastSync) {
                        const rec = await TPP_IDB.get('kv', 'last_full_sync');
                        state.lastSync = rec ? rec.v : null;
                }
                return state.lastSync;
        }

        /**
         * مقادیر یکتای یک فیلد از داده‌های ذخیره‌شده روی همین دستگاه (۱.۹.۲):
         * جستجوی کامبوباکس فیلترها در حالت آفلاین — مانند جستجوی سراسری سرور،
         * عبارت q روی «کل سرویس‌های ذخیره‌شده» اعمال می‌شود (نه فقط فهرست محدود قبلی).
         * slug می‌تواند فیلد سرویس یا فیلد آدرس باشد؛ مقادیر بی‌ارزش حذف می‌شوند.
         */
        async function valuesLocal(slug, q, limit) {
                const ql = norm(q);
                const cap = Math.max(1, Math.min(parseInt(limit, 10) || 200, 500));
                const seen = {};
                const out = [];
                await TPP_IDB.cursor('services', (row) => {
                        let v = row[slug];
                        if (v == null || v === '') v = row.address ? row.address[slug] : '';
                        const t = String(v == null ? '' : v).trim();
                        if (!t || t === '0' || t === '-' || t.toUpperCase() === 'AUTO') return;
                        if (seen[t]) return;
                        if (ql && norm(t).indexOf(ql) === -1) return;
                        seen[t] = 1;
                        out.push(t);
                        if (out.length >= cap) return false; // کافی است — پیمایش متوقف شود
                });
                out.sort((a, b) => a.localeCompare(b, 'fa'));
                return out;
        }

        /* ---------- جستجوی محلی (آفلاین — استریم با cursor) ---------- */

        /**
         * جستجوی محلی در کش دیسکی — رکوردها با cursor یکی‌یکی از دیسک خوانده می‌شوند
         * (بدون لود کل داده در رم) و فقط نتایج مطابق در حافظه می‌مانند.
         * sort: updated|unit|block|postal|address — order: asc|desc (هماهنگ با سرور)
         * upd: {from, to} — بازه زمانی ویرایش به تاریخ میلادی ISO (اختیاری، هماهنگ با سرور)
         */
        async function searchLocal(query, filters, schema, sort, order, upd) {
                const q = norm(query);
                const uFrom = upd && upd.from ? String(upd.from) : '';
                const uTo = upd && upd.to ? String(upd.to) : '';
                const prog = (upd && upd.prog) || null; // ۱.۱۲.۰ — فیلتر وضعیت دایری {status, step, stepState}
                const searchableSlugs = [];
                (schema.address || []).forEach((f) => { if (f.is_searchable) searchableSlugs.push('a:' + f.slug); });
                (schema.service || []).forEach((f) => { if (f.is_searchable) searchableSlugs.push('s:' + f.slug); });

                const normFilters = {};
                Object.entries(filters || {}).forEach(([k, v]) => { if (v) normFilters[k] = norm(v); });

                // ۱.۱۲.۰ — تطبیق وضعیت دایری روی خلاصه progress ردیف (معادل منطق SQL سرور)
                const progMatch = (row) => {
                        if (!prog || (!prog.status && !prog.step)) return true;
                        const p = row && row.progress;
                        const done = p ? (p.done || 0) : 0;
                        const total = p ? (p.total || 16) : 16;
                        // ۱.۱۴.۰ — خرابی‌ها آرایه‌اند (failures)؛ failure تکی برای داده قدیمی
                        const fails = p ? ((p.failures && p.failures.length) ? p.failures.slice() : (p.failure ? [p.failure] : [])) : [];
                        const hasFail = fails.length > 0;
                        const steps = p && Array.isArray(p.steps) ? p.steps : [];
                        if (prog.status) {
                                const st = prog.status;
                                // هماهنگ با سرور: خرابی اولویت دارد (none/progress/done فقط بدون خرابی)
                                if (st === 'none' && !(done === 0 && !hasFail)) return false;
                                if (st === 'progress' && !((done > 0 && done < total) && !hasFail)) return false;
                                if (st === 'done' && !(done >= total && !hasFail)) return false;
                                if (st === 'fail' && !hasFail) return false;
                                if (st.indexOf('fail_') === 0 && fails.indexOf(st.slice(5)) === -1) return false;
                        }
                        if (prog.step) {
                                const has = steps.indexOf(prog.step) !== -1;
                                if (prog.stepState === 'todo' ? has : !has) return false;
                        }
                        return true;
                };

                const out = [];
                await TPP_IDB.cursor('services', (row) => {
                        // بازه زمانی ویرایش — مقایسه بخش تاریخ (۱۰ نویسه اول) به‌صورت متنی و بدون منطقه‌زمانی
                        if (uFrom || uTo) {
                                const d = String(row.updated_at || '').slice(0, 10);
                                if (uFrom && d < uFrom) return;
                                if (uTo && d > uTo) return;
                        }
                        // ۱.۱۲.۰ — وضعیت دایری
                        if (!progMatch(row)) return;
                        // فیلترهای اختصاصی
                        for (const [slug, val] of Object.entries(normFilters)) {
                                const svcVal = norm(row[slug] !== undefined ? row[slug] : (row.address ? row.address[slug] : ''));
                                if (svcVal.indexOf(val) === -1) return; // رد شد — سراغ رکورد بعدی
                        }
                        // متن آزاد
                        if (q) {
                                let matched = false;
                                for (const key of searchableSlugs) {
                                        const [grp, slug] = key.split(':');
                                        const val = grp === 'a' ? (row.address ? row.address[slug] : '') : row[slug];
                                        if (norm(val).indexOf(q) !== -1) { matched = true; break; }
                                }
                                if (!matched) return;
                        }
                        out.push(row);
                });
                sortRows(out, sort, order);
                return out;
        }

        /** مرتب‌سازی محلی — همان قواعد سرور (عددی‌گونه برای واحد/کد پستی، بدون حساسیت بزرگی حروف برای آدرس) */
        function sortRows(rows, sort, order) {
                const dir = order === 'asc' ? 1 : -1;
                const addrVal = (r, slug) => String((r.address && r.address[slug] != null) ? r.address[slug] : '');
                const numPrefix = (s) => { const m = String(s).match(/-?\d+(\.\d+)?/); return m ? parseFloat(m[0]) : NaN; };
                const cmpNumish = (a, b) => {
                        const na = numPrefix(a), nb = numPrefix(b);
                        if (!isNaN(na) && !isNaN(nb) && na !== nb) return (na - nb) * dir;
                        if (!isNaN(na) && isNaN(nb)) return -1 * dir; // عددی‌ها اول
                        if (isNaN(na) && !isNaN(nb)) return 1 * dir;
                        return a.localeCompare(b, 'fa') * dir;
                };
                const cmpText = (a, b) => a.toLowerCase().localeCompare(b.toLowerCase(), 'fa') * dir;
                switch (sort) {
                        case 'unit':
                                rows.sort((r1, r2) => cmpNumish(addrVal(r1, 'f_unit'), addrVal(r2, 'f_unit')) || (String(r2.updated_at || '').localeCompare(String(r1.updated_at || ''))));
                                break;
                        case 'block':
                                rows.sort((r1, r2) => cmpText(addrVal(r1, 'f_block'), addrVal(r2, 'f_block')) || cmpText(addrVal(r1, 'f_full_address'), addrVal(r2, 'f_full_address')));
                                break;
                        case 'postal':
                                rows.sort((r1, r2) => cmpNumish(addrVal(r1, 'f_postal_code'), addrVal(r2, 'f_postal_code')));
                                break;
                        case 'address':
                                rows.sort((r1, r2) => cmpText(addrVal(r1, 'f_full_address'), addrVal(r2, 'f_full_address')));
                                break;
                        default:
                                rows.sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')));
                }
                return rows;
        }

        /* ---------- تاریخچه آفلاین (از کش دیسکی) ---------- */

        /** فهرست تاریخچه آفلاین با فیلتر — استریم با cursor */
        async function historyLocal(filters) {
                const f = filters || {};
                const out = [];
                await TPP_IDB.cursor('history', (h) => {
                        if (f.action && h.action !== f.action) return;
                        if (f.entity && h.entity !== f.entity) return;
                        if (f.conflict && !h.is_conflict) return;
                        out.push(h);
                });
                out.sort((a, b) => (b.id || 0) - (a.id || 0));
                return out;
        }

        /** تاریخچه یک سرویس از کش آفلاین */
        async function historyForService(serviceId) {
                const out = [];
                await TPP_IDB.cursor('history', (h) => {
                        if (h.entity === 'service' && parseInt(h.entity_id, 10) === parseInt(serviceId, 10)) out.push(h);
                });
                out.sort((a, b) => (b.id || 0) - (a.id || 0));
                return out;
        }

        /* ---------- صف عملیات (Outbox) ---------- */

        function uid() {
                return 'op-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        }

        /** اعمال خوش‌بینانه روی کش محلی */
        async function applyLocal(kind, opId, payload, serverRow) {
                if (kind === 'service.create') {
                        const row = serverRow || {
                                id: 'tmp_' + opId,
                                address_id: 0,
                                version: 0,
                                created_at: TPP.app.nowTehranSql(), // ۱.۱۸.۰ — هم‌قالب ذخیره سرور: زمان دیوار تهران
                                updated_at: TPP.app.nowTehranSql(),
                                _pending: true,
                                ...(payload.service || {})
                        };
                        if (payload.address) row.address = { ...payload.address, version: 0 };
                        else if (payload.address_id) row.address_id = payload.address_id;
                        /* ۱.۱۹.۰ — دسته/تگ عملیات آفلاین (شناسه‌ها؛ پس از همگام‌سازی با برچسب سرور جایگزین می‌شود) */
                        if (payload.category !== undefined && payload.category !== null) row.category = { id: parseInt(payload.category, 10) || 0, label: null };
                        if (Array.isArray(payload.tags)) row.tags = payload.tags.map((t) => ({ id: parseInt(t, 10) || 0, label: null }));
                        await TPP_IDB.set('services', row.id, row);
                } else if (kind === 'service.update') {
                        const row = await TPP_IDB.get('services', payload.id);
                        if (row) {
                                Object.assign(row, payload.service || {});
                                if (payload.address) row.address = Object.assign(row.address || {}, payload.address);
                                if (payload.address_id) row.address_id = payload.address_id;
                                /* ۱.۱۹.۰ — دسته/تگ ویرایش آفلاین */
                                if (payload.category !== undefined && payload.category !== null) row.category = { id: parseInt(payload.category, 10) || 0, label: (row.category && row.category.id === (parseInt(payload.category, 10) || 0)) ? row.category.label : null };
                                if (Array.isArray(payload.tags)) row.tags = payload.tags.map((t) => ({ id: parseInt(t, 10) || 0, label: (row.tags || []).find((x) => x && x.id === (parseInt(t, 10) || 0)) ? ((row.tags || []).find((x) => x && x.id === (parseInt(t, 10) || 0)).label) : null }));
                                row._dirty = true;
                                row.updated_at = TPP.app.nowTehranSql(); // ۱.۱۸.۰ — زمان دیوار تهران
                                await TPP_IDB.set('services', row.id, row);
                        }
                } else if (kind === 'service.delete') {
                        await TPP_IDB.del('services', payload.id);
                        // حذف آبشاری: تاریخچه این سرویس هم از کش حذف شود
                        await removeHistoryFromCache({ entity: 'service', entity_id: parseInt(payload.id, 10) || 0 });
                } else if (kind === 'history.delete') {
                        await removeHistoryFromCache({ ids: (payload.ids || []).map((i) => parseInt(i, 10)) });
                }
        }

        /** حذف رکوردهای تاریخچه از کش محلی (بر اساس شناسه یا کل تاریخچه یک سرویس) */
        async function removeHistoryFromCache(opts) {
                const ids = new Set((opts.ids || []).map((i) => 'h' + i));
                const dropKeys = [];
                await TPP_IDB.cursor('history', (h, key) => {
                        if (ids.has(key)) { dropKeys.push(key); return; }
                        if (opts.entity && h.entity === opts.entity && parseInt(h.entity_id, 10) === parseInt(opts.entity_id, 10)) dropKeys.push(key);
                });
                for (const k of dropKeys) await TPP_IDB.del('history', k);
                // اگر تاریخچه داخل کش سرویس هم ذخیره شده، آن هم پاک شود
                if (opts.entity === 'service' && opts.entity_id) {
                        const row = await TPP_IDB.get('services', opts.entity_id);
                        if (row && row.history) { delete row.history; await TPP_IDB.set('services', row.id, row); }
                }
        }

        /** حذف تکی/گروهی رکوردهای تاریخچه — آنلاین فوری، آفلاین در صف (همان تجربه آنلاین) */
        async function deleteHistoryEntries(ids) {
                return enqueue('history.delete', { ids: ids.map((i) => parseInt(i, 10)) });
        }

        /*
         * نتیجه اعمال هر عملیات در سرور (op_id → result) — پس از هر flush ثبت می‌شود
         * تا فرم بلافاصله بعد از ذخیره بداند عملیات موفق بود یا خطا و شناسه واقعی سرویس جدید چیست.
         */
        const appliedResults = new Map();
        function rememberResult(opId, result) {
                appliedResults.set(opId, result);
                if (appliedResults.size > 200) { // جلوگیری از رشد بی‌اندازه
                        const first = appliedResults.keys().next().value;
                        appliedResults.delete(first);
                }
        }

        /** ثبت عملیات در صف و تلاش فوری برای ارسال (آنلاین: تا اعمال کامل صبر می‌کند) */
        async function enqueue(kind, payload, extra) {
                const op = {
                        op_id: uid(),
                        kind,
                        payload,
                        created_at: new Date().toISOString(),
                        retries: 0,
                        ...(extra || {})
                };
                await TPP_IDB.set('outbox', op.op_id, op);
                await applyLocal(kind, op.op_id, payload);
                refreshPendingBadge();

                if (state.online) {
                        // ارسال فوری + انتظار برای اعمال (حداکثر ۸ ثانیه) تا UI همیشه روان و به‌روز باشد
                        const start = Date.now();
                        flush().catch(() => {});
                        while (Date.now() - start < 8000) {
                                await new Promise((r) => setTimeout(r, 250));
                                const still = await TPP_IDB.get('outbox', op.op_id);
                                if (!still) break; // اعمال شد
                                if (!state.syncing) flush().catch(() => {});
                        }
                }
                // نتیجه سرور (در صورت اعمال در همین مدت) به عملیات الصاق می‌شود
                if (appliedResults.has(op.op_id)) {
                        op.result = appliedResults.get(op.op_id);
                }
                return op;
        }

        /* ---------- همگام‌سازی ---------- */

        async function flush(force) {
                if (state.syncing) return { skipped: true };
                if (!TPP.api.hasToken()) return;
                const ops = await TPP_IDB.all('outbox');
                if (!ops.length) { refreshPendingBadge(); return { results: [] }; }

                state.syncing = true;
                emit('sync', { syncing: true, count: ops.length });
                try {
                        const cleanOps = ops.map((op) => ({
                                op_id: op.op_id,
                                kind: op.kind,
                                payload: op.payload,
                                base_version: op.base_version || 0,
                                base_address_version: op.base_address_version || 0
                        }));
                        const res = await TPP.api.request('POST', 'sync', { ops: cleanOps });
                        const results = res.results || [];
                        const summary = res.summary || {};
                        const keep = [];

                        for (let i = 0; i < ops.length; i++) {
                                const op = ops[i];
                                const result = results.find((r) => r.op_id === op.op_id);
                                if (!result) { keep.push(op); continue; }

                                if (result.status === 'error') {
                                        // خطای منطقی (اعتبارسنجی/دسترسی) — گزارش و حذف از صف؛ تکرار بی‌فایده است
                                        rememberResult(op.op_id, result);
                                        emit('sync', { op_error: op, result });
                                        await TPP_IDB.del('outbox', op.op_id);
                                        continue;
                                }
                                // موفق (یا duplicate از قبل اعمال‌شده)
                                rememberResult(op.op_id, result);
                                await TPP_IDB.del('outbox', op.op_id);
                                // به‌روزرسانی کش با داده معتبر سرور
                                if (result.data) {
                                        const oldId = op.kind === 'service.create' ? 'tmp_' + op.op_id : op.payload.id;
                                        if (oldId !== result.data.id) await TPP_IDB.del('services', oldId);
                                        await TPP_IDB.set('services', result.data.id, result.data);
                                } else if (op.kind === 'service.delete') {
                                        await TPP_IDB.del('services', op.payload.id);
                                }
                                if (result.conflict) {
                                        emit('sync', { conflict: op, result });
                                }
                        }
                        // عملیات‌های باقی‌مانده (نباید رخ دهد مگر پاسخ ناقص)
                        refreshPendingBadge();
                        emit('sync', { syncing: false, summary, results });
                        // بعد از همگام‌سازی موفق، کش کامل تازه شود
                        if (keep.length === 0 && !force) {
                                cacheAll().catch(() => {});
                        }
                        return { results, summary };
                } catch (e) {
                        // خطای شبکه — همه عملیات در صف می‌مانند
                        emit('sync', { syncing: false, network_error: e.message });
                        return { network_error: e.message };
                } finally {
                        state.syncing = false;
                }
        }

        /* ---------- رویدادهای شبکه ---------- */

        window.addEventListener('online', () => {
                state.online = true;
                emit('change', { ...state });
                resetCacheRetry();
                flush().then(() => cacheAll()).catch(() => { scheduleCacheRetry(); });
        });
        window.addEventListener('offline', () => {
                state.online = false;
                emit('change', { ...state });
        });

        /** راه‌اندازی اولیه */
        async function init() {
                refreshPendingBadge();
                await loadCacheState().catch(() => {});
                if (state.online && TPP.api.hasToken()) {
                        flush().catch(() => {});
                        // اگر کش قبلی ناقص مانده، ادامه‌اش بگیریم (همگام‌سازی کامل پس از ورود)
                        if (!cacheState.complete) cacheAll().catch(() => { scheduleCacheRetry(); });
                }
        }

        /** خروج کامل: پاک‌سازی داده‌های محلی */
        async function wipeLocal() {
                resetCacheRetry();
                cacheState = defaultCacheState();
                state.cache = { ...cacheState };
                emit('cache', { ...cacheState });
                await TPP_IDB.clear('services');
                await TPP_IDB.clear('outbox');
                await TPP_IDB.clear('history');
                await TPP_IDB.clear('kv');
                refreshPendingBadge();
        }

        return {
                state: () => ({ ...state }),
                on, init, flush, cacheAll, searchLocal, enqueue, norm,
                refreshPendingBadge, wipeLocal, lastSync, cacheStats, valuesLocal,
                cacheHistory, historyLocal, historyForService, deleteHistoryEntries, sortRows,
                getPendingCount: () => state.pending
        };
})();
