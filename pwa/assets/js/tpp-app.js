/**
 * tpp-app.js — هسته اپلیکیشن تک‌صفحه‌ای (SPA)
 * شامل: راه‌اندازی/ورود، مسیریابی، داشبورد، فهرست سرویس‌ها با جستجوی زنده آجاکسی، تاریخچه
 * فرم سرویس در tpp-views-form.js و صفحه‌های مدیریتی در tpp-views-admin.js
 */
'use strict';

window.TPP = window.TPP || {};

/** نسخه این کد — با نسخه‌ای که سرور در bootstrap می‌فرستد مقایسه می‌شود؛
 *  اگر فرق کنند یعنی پوسته قدیمی در مرورگر مانده و باید تازه شود. */
TPP.VERSION = '1.21.2';

/* ==================== ۱.۱۳.۰ — منطق آبشاری مراحل دایری (معادل سرور) ====================
   مراحل وابسته‌اند: تیک مرحله N همه مراحل قبل از N را خودکار تیک می‌زند؛
   «مگر مراحل ردشده» که بعد از تیک‌خوردن تیکشان توسط کاربر برداشته شده (skipped).
   برداشتن تیکِ مرحله‌ای که مرحله بعدی تیک دارد → «ردشده»؛ اگر آخرین مرحله تیک‌دار بود → عقب‌گرد ساده. */
TPP.progressUtil = (function () {
        function catalog() {
                const st = TPP.app && TPP.app.state ? TPP.app.state() : null;
                const p = st && st.progress;
                return (p && p.steps && Object.keys(p.steps).length) ? p : { steps: {}, failures: {} };
        }
        function keys(cat) {
                cat = cat || catalog();
                return Object.keys(cat.steps || {});
        }
        /** معادل TPP_Progress::apply سرور — ورودی: {steps, skipped, failure, reset_skips} + رکورد قبلی {steps, excluded} */
        function apply(input, old) {
                const cat = catalog();
                const K = keys(cat);
                const checked = new Set((input.steps || []).filter((k) => K.indexOf(k) !== -1));
                const inputSkip = new Set((input.skipped || []).filter((k) => K.indexOf(k) !== -1));
                const oldSteps = new Set((old && old.steps || []).filter((k) => K.indexOf(k) !== -1));
                const oldExcl = new Set((old && old.excluded || []).filter((k) => K.indexOf(k) !== -1));
                const reset = !!(input.reset_skips);

                const excluded = new Set();
                if (!reset) {
                        oldExcl.forEach((k) => excluded.add(k));
                        inputSkip.forEach((k) => excluded.add(k));
                        oldSteps.forEach((x) => {
                                if (!checked.has(x)) {
                                        const xi = K.indexOf(x);
                                        let later = false;
                                        checked.forEach((y) => { if (K.indexOf(y) > xi) later = true; });
                                        if (later) excluded.add(x);
                                }
                        });
                        checked.forEach((k) => excluded.delete(k)); // تیک دوباره = رفع ردشدگی
                }

                let maxIdx = -1;
                checked.forEach((s) => { const i = K.indexOf(s); if (i > maxIdx) maxIdx = i; });
                const steps = [];
                for (let i = 0; i <= maxIdx; i++) {
                        if (!excluded.has(K[i])) steps.push(K[i]);
                }
                const excludedArr = K.filter((k) => excluded.has(k));
                return { steps, excluded: excludedArr, done: steps.length, total: K.length, pct: K.length ? Math.round(steps.length * 100 / K.length) : 0 };
        }
        /** خلاصه محلی از steps/excluded/failures (برای کش آفلاین) — ۱.۱۴.۰: خرابی‌های چندتایی */
        function summary(prog) {
                const cat = catalog();
                const K = keys(cat);
                const steps = (prog.steps || []).filter((k) => K.indexOf(k) !== -1);
                const excluded = (prog.excluded || []).filter((k) => K.indexOf(k) !== -1);
                const failKeys = (prog.failures && prog.failures.length) ? prog.failures.slice() : (prog.failure ? [prog.failure] : []);
                const failLabels = failKeys.map((k) => (cat.failures || {})[k] || k);
                const done = steps.length;
                const last = done ? steps[done - 1] : '';
                return {
                        steps, excluded, excluded_count: excluded.length,
                        failures: failKeys, failures_labels: failLabels,
                        failure: failKeys[0] || '', failure_label: failLabels[0] || '',
                        done, total: K.length,
                        pct: K.length ? Math.round(done * 100 / K.length) : 0,
                        status: (done >= K.length && K.length) ? 'done' : (done > 0 ? 'progress' : 'none'),
                        last_step: last, last_label: last ? cat.steps[last] : ''
                };
        }
        return { catalog, keys, apply, summary };
})();

TPP.app = (function () {

        const state = {
                user: null,
                caps: [],
                isManager: false,
                isWPAdmin: false,
                schema: { address: [], service: [] },
                settings: { rows_per_page: 25, default_match: 'f_phone', heartbeat_min: 5 },
                sms: { configured: false, mobile_field: '', copy_template: '' },
                site: '',
                loginUrl: '',
                logoutUrl: '',
                route: 'dashboard',
                params: {},
                stats: null
        };

        /* حالت امبد: اپ داخل iframe در صفحه سایت/پیشخوان — ورود/خروج با خود وردپرس پیوند شده است */
        const QS = new URLSearchParams(location.search);
        const EMBED = QS.get('embed') === '1' || (window.parent !== window);
        const PARENT_URL = QS.get('parent') || (document.referrer || '');

        const els = {};
        let searchAbort = null;
        let heartbeatTimer = null;
        let cacheTimer = null;
        let heightTimer = null;

        /* ==================== تم (دارک پیش‌فرض) ==================== */

        function applyTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : 'dark');
                try { localStorage.setItem('tpp-theme', theme === 'light' ? 'light' : 'dark'); } catch (e) {}
                const meta = document.querySelector('meta[name=theme-color]');
                if (meta) meta.setAttribute('content', theme === 'light' ? '#eef1f5' : '#0b1220');
                const btn = document.getElementById('theme-toggle');
                if (btn) btn.textContent = theme === 'light' ? '🌙 حالت تاریک' : '☀️ حالت روشن';
        }

        function initTheme() {
                let saved = 'dark';
                try { saved = localStorage.getItem('tpp-theme') || 'dark'; } catch (e) {}
                applyTheme(saved);
        }

        /* ==================== ابزارهای عمومی ==================== */

        function esc(s) {
                return String(s == null ? '' : s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function toast(msg, type, timeout) {
                const el = document.createElement('div');
                el.className = 'toast ' + (type || '');
                el.innerHTML = msg; // پیام‌های داخلی خودمان؛ ورودی‌های کاربر با esc پاس می‌شوند
                els.toastWrap.appendChild(el);
                setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 350); }, timeout || 4200);
        }

        function modal(html, opts) {
                opts = opts || {};
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop';
                backdrop.innerHTML = '<div class="modal' + (opts.wide ? ' wide' : '') + '">' + html + '</div>';
                els.modalRoot.appendChild(backdrop);
                function close() { backdrop.remove(); }
                backdrop.addEventListener('click', (e) => { if (e.target === backdrop && !opts.static) close(); });
                backdrop.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
                return { el: backdrop, close };
        }

        /** کپی متن در کلیپ‌بورد (با fallback مرورگرهای قدیمی) */
        async function copyText(text) {
                const str = String(text == null ? '' : text);
                if (!str.trim()) return false;
                if (navigator.clipboard && window.isSecureContext) {
                        try { await navigator.clipboard.writeText(str); return true; } catch (e) { /* ادامه به fallback */ }
                }
                const ta = document.createElement('textarea');
                ta.value = str;
                ta.style.position = 'fixed';
                ta.style.top = '0';
                ta.style.opacity = '0';
                ta.setAttribute('readonly', '');
                document.body.appendChild(ta);
                ta.focus(); ta.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                ta.remove();
                return ok;
        }

        function confirmBox(message, okLabel) {
                return new Promise((resolve) => {
                        const m = modal(
                                '<div class="modal-head"><h3>تأیید</h3><button class="modal-close" data-close>×</button></div>' +
                                '<div class="modal-body"><p style="line-height:2">' + message + '</p></div>' +
                                '<div class="modal-foot"><button class="btn btn-danger" id="cb-ok">' + esc(okLabel || 'تأیید و انجام') + '</button>' +
                                '<button class="btn" data-close>انصراف</button></div>',
                                { static: true }
                        );
                        m.el.querySelector('#cb-ok').addEventListener('click', () => { m.close(); resolve(true); });
                        m.el.querySelector('[data-close]').addEventListener('click', () => resolve(false));
                });
        }

        function debounce(fn, ms) {
                let t;
                return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
        }

        /** نمایش تاریخ شمسی به وقت تهران (۱.۱۸.۰)
         *  ورودی‌های پشتیبانی‌شده:
         *  - «Y-m-d H:i[:s]» زمانِ دیوارِ تهران (همان قالب ذخیره سرور) → اجزای همان رشته مستقیم نمایش داده می‌شود
         *  - «Y-m-d» فقط تاریخ
         *  - ISO کامل با منطقه‌زمانی (…Z یا ±HH:MM) → ابتدا به تهران (+03:30) تبدیل می‌شود
         *  خروجی: «۱۴۰۵/۰۶/۳۰ — ۱۰:۳۰» یا «۱۴۰۵/۰۶/۳۰» — مستقل از منطقه‌زمانی دستگاه کاربر */
        function fmtDate(s) {
                if (!s) return '—';
                const str = String(s).trim();
                const p2 = (n) => String(n).padStart(2, '0');
                const fa = (n) => String(n).replace(/[0-9]/g, (d) => faDigits[+d]);
                const render = (y, mo, d, h, mi) => {
                        const j = toJalaali(y, mo, d);
                        let out = fa(j.jy) + '/' + fa(p2(j.jm)) + '/' + fa(p2(j.jd));
                        if (h !== null) out += ' — ' + fa(p2(h) + ':' + p2(mi));
                        return out;
                };
                // فقط تاریخ؟
                let m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(str);
                if (m) return render(+m[1], +m[2], +m[3], null, null);
                // تاریخ+زمان (بدون منطقه‌زمانی = زمان دیوار تهران)
                m = /^(\d{4})-(\d{1,2})-(\d{1,2})[ T](\d{1,2}):(\d{2})/.exec(str);
                if (m && !/(Z|[+-]\d{2}:?\d{2})$/.test(str)) return render(+m[1], +m[2], +m[3], +m[4], +m[5]);
                // ISO کامل با منطقه‌زمانی → تبدیل به تهران
                try {
                        const d = new Date(str.includes('T') ? str : str.replace(' ', 'T'));
                        if (isNaN(d.getTime())) return str;
                        const tehran = new Date(d.getTime() + 3.5 * 3600000); // ایران از ۱۴۰۱ بدون ساعت تابستانی (+03:30)
                        return render(tehran.getUTCFullYear(), tehran.getUTCMonth() + 1, tehran.getUTCDate(), tehran.getUTCHours(), tehran.getUTCMinutes());
                } catch (e) { return str; }
        }

        /** امروز به وقت تهران — ISO میلادی «Y-m-d» (مستقل از منطقه‌زمانی دستگاه) */
        function tehranTodayIso() {
                try {
                        return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
                } catch (e) {
                        const d = new Date(Date.now() + 3.5 * 3600000);
                        return d.getUTCFullYear() + '-' + p2l(d.getUTCMonth() + 1) + '-' + p2l(d.getUTCDate());
                }
        }

        /** ISO میلادی ± چند روز (محاسبه امن با UTC) */
        function isoShift(iso, days) {
                const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
                if (!m) return null;
                const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]) + days * 86400000);
                return d.getUTCFullYear() + '-' + p2l(d.getUTCMonth() + 1) + '-' + p2l(d.getUTCDate());
        }
        function p2l(n) { return String(n).padStart(2, '0'); }

        /** حالا به وقت تهران — «Y-m-d H:i:s» (برای رکوردهای پیش‌نویس آفلاین، هم‌قالب ذخیره سرور) */
        function nowTehranSql() {
                try {
                        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).formatToParts(new Date());
                        const get = (t) => (parts.find((p) => p.type === t) || {}).value || '00';
                        return get('year') + '-' + get('month') + '-' + get('day') + ' ' + get('hour') + ':' + get('minute') + ':' + get('second');
                } catch (e) {
                        const d = new Date(Date.now() + 3.5 * 3600000);
                        return d.getUTCFullYear() + '-' + p2l(d.getUTCMonth() + 1) + '-' + p2l(d.getUTCDate()) + ' ' + p2l(d.getUTCHours()) + ':' + p2l(d.getUTCMinutes()) + ':' + p2l(d.getUTCSeconds());
                }
        }

        /* ==================== تبدیل تاریخ شمسی ↔ میلادی (الگوریتم jalaali) ==================== */
        /* برای فیلتر جستجو بر حسب زمان ویرایش — ورودی کاربر شمسی است و سرور میلادی می‌خواهد. */

        /* تقسیم و باقیمانده با گرد شدن به سمت صفر (همان ~~ الگوریتم jalaali) —
           Math.floor برای اعداد منفی جواب متفاوت می‌داد و کل تبدیل را یک روز جابه‌جا می‌کرد */
        const jdiv = (a, b) => Math.trunc(a / b);
        const jmod = (a, b) => a - Math.trunc(a / b) * b;

        /** سال شمسی → اطلاعات تقویم (روز جولیائیِ اول فروردین) */
        function jalCal(jy) {
                const breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
                let gy = jy + 621, leapJ = -14, jp = breaks[0], jm = 0, jump = 0, leap, leapG, march, n;
                for (let i = 1; i < breaks.length; i += 1) {
                        jm = breaks[i];
                        jump = jm - jp;
                        if (jy < jm) break;
                        leapJ = leapJ + jdiv(jump, 33) * 8 + jdiv(jmod(jump, 33), 4);
                        jp = jm;
                }
                n = jy - jp;
                leapJ = leapJ + jdiv(n, 33) * 8 + jdiv(jmod(n, 33) + 3, 4);
                if (jmod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
                leapG = jdiv(gy, 4) - jdiv((jdiv(gy, 100) + 1) * 3, 4) - 150;
                march = 20 + leapJ - leapG;
                if (jump - n < 6) n = n - jump + jdiv(jump + 4, 33) * 33;
                leap = jmod(jmod(n + 1, 33) - 1, 4);
                if (leap === -1) leap = 4;
                return { leap, gy, march };
        }

        /** میلادی → شمسی */
        function toJalaali(gy, gm, gd) {
                const g2d = (y, m, d) => {
                        let dd = jdiv((y + jdiv(m - 8, 6) + 100100) * 1461, 4) + jdiv(153 * jmod(m + 9, 12) + 2, 5) + d - 34840408;
                        return dd - jdiv(jdiv(y + 100100 + jdiv(m - 8, 6), 100) * 3, 4) + 752;
                };
                const d2g = (jdn) => {
                        let j = 4 * jdn + 139361631;
                        j = j + jdiv(jdiv(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
                        const i = jdiv(jmod(j, 1461), 4) * 5 + 308;
                        const gd = jdiv(jmod(i, 153), 5) + 1;
                        const gm = jmod(jdiv(i, 153), 12) + 1;
                        const gy = jdiv(j, 1461) - 100100 + jdiv(8 - gm, 6);
                        return { gy, gm, gd };
                };
                const jdn = g2d(gy, gm, gd);
                const gy2 = d2g(jdn).gy;
                let jy = gy2 - 621;
                const r = jalCal(jy);
                const jdn1f = g2d(gy2, 3, r.march);
                let k = jdn - jdn1f;
                if (k >= 0) {
                        if (k <= 185) return { jy, jm: 1 + jdiv(k, 31), jd: jmod(k, 31) + 1 };
                        k -= 186;
                } else {
                        // سال شمسی قبلی — r.leap مال سالِ قبل از کاهش است (jalaali: r.leap===1 یعنی jy-1 کبیسه است)
                        jy -= 1;
                        k += 179;
                        if (r.leap === 1) k += 1;
                }
                return { jy, jm: 7 + jdiv(k, 30), jd: jmod(k, 30) + 1 };
        }

        /** شمسی → میلادی */
        function toGregorian(jy, jm, jd) {
                const j2d = (y, m, d) => {
                        const r = jalCal(y);
                        const g2d = (gy, gm, gd) => {
                                let dd = jdiv((gy + jdiv(gm - 8, 6) + 100100) * 1461, 4) + jdiv(153 * jmod(gm + 9, 12) + 2, 5) + gd - 34840408;
                                return dd - jdiv(jdiv(gy + 100100 + jdiv(gm - 8, 6), 100) * 3, 4) + 752;
                        };
                        return g2d(r.gy, 3, r.march) + (m - 1) * 31 - jdiv(m, 7) * (m - 7) + d - 1;
                };
                const jdn = j2d(jy, jm, jd);
                let j = 4 * jdn + 139361631;
                j = j + jdiv(jdiv(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
                const i = jdiv(jmod(j, 1461), 4) * 5 + 308;
                const gd = jdiv(jmod(i, 153), 5) + 1;
                const gm = jmod(jdiv(i, 153), 12) + 1;
                const gy = jdiv(j, 1461) - 100100 + jdiv(8 - gm, 6);
                return { gy, gm, gd };
        }

        /** تبدیل ارقام فارسی/عربی به لاتین */
        const faDigits = '۰۱۲۳۴۵۶۷۸۹';
        const arDigits = '٠١٢٣٤٥٦٧٨٩';
        function faToEnDigits(s) {
                return String(s == null ? '' : s)
                        .replace(/[۰-۹]/g, (d) => faDigits.indexOf(d))
                        .replace(/[٠-٩]/g, (d) => arDigits.indexOf(d));
        }

        /**
         * متن تاریخ شمسی کاربر → تاریخ میلادی ISO (YYYY-MM-DD) یا null.
         * قالب‌های پذیرفته‌شده: «۱۴۰۴/۰۶/۱۲»، «1404-6-12»، «14040612»، «۱۴۰۴.۶.۱۲»
         */
        function jalToIso(text) {
                const t = faToEnDigits(String(text || '')).trim();
                if ('' === t) return null;
                let parts = t.split(/[^0-9]+/).filter(Boolean);
                if (1 === parts.length && 8 === parts[0].length) {
                        parts = [parts[0].slice(0, 4), parts[0].slice(4, 6), parts[0].slice(6, 8)];
                }
                if (3 !== parts.length) return null;
                const jy = parseInt(parts[0], 10), jm = parseInt(parts[1], 10), jd = parseInt(parts[2], 10);
                if (!jy || !jm || !jd) return null;
                if (jy < 1200 || jy > 1500) return null; // بازه معقول
                const maxDay = jm <= 6 ? 31 : (jm <= 11 ? 30 : (jalCal(jy).leap === 0 ? 30 : 29)); // leap=0 یعنی سال کبیسه است
                if (jm > 12 || jd > maxDay) return null;
                const g = toGregorian(jy, jm, jd);
                const p2 = (n) => String(n).padStart(2, '0');
                return g.gy + '-' + p2(g.gm) + '-' + p2(g.gd);
        }

        /** تاریخ میلادی ISO → متن شمسی با ارقام فارسی (برای نمایش در کادر) */
        function isoToJal(iso) {
                const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
                if (!m) return '';
                const j = toJalaali(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10));
                const p2 = (n) => String(n).padStart(2, '0');
                const fa = (n) => String(n).replace(/[0-9]/g, (d) => faDigits[+d]);
                return fa(j.jy) + '/' + fa(p2(j.jm)) + '/' + fa(p2(j.jd));
        }

        /** تاریخ امروز دیوایس → ISO میلادی (بدون toISOString تا خطای منطقه‌زمانی پیش نیاید) */
        function dateToIso(d) {
                const p2 = (n) => String(n).padStart(2, '0');
                return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
        }

        function can(cap) {
                return state.isManager || state.caps.indexOf(cap) !== -1;
        }

        /** ۱.۱۳.۱ — ویرایش سریع (⚡/🚀): قابلیت مستقل «tpp_quick_edit»؛ به‌طور پیش‌فرض فقط مدیر کل سایت.
         *  عمداً از isManager استفاده نمی‌کند (is_manager شامل «مدیریت نقش‌ها» است اما قابلیت ویرایش سریع را ندارد)
         *  و فقط caps سرور ملاک است — مدیر کل (caps کامل) خودکار شامل می‌شود. */
        function canQuick() {
                return state.caps.indexOf('tpp_quick_edit') !== -1;
        }

        function fieldDef(slug) {
                return (state.schema.address || []).concat(state.schema.service || []).find((f) => f.slug === slug) || null;
        }

        /* برچسب فیلدهای مجازی ثبت‌شده در تاریخچه (ادغام/انتقال آدرس) */
        const PSEUDO_FIELD_LABELS = { _merged_from: 'ادغام از سرویس', _address_moved: 'انتقال به آدرس', _progress_steps: 'پیشرفت دایری سرویس', _progress_failure: 'خرابی اعلام‌شده' };

        function fieldLabel(slug) {
                if (PSEUDO_FIELD_LABELS[slug]) return PSEUDO_FIELD_LABELS[slug];
                const f = fieldDef(slug);
                return f ? f.label : slug;
        }

        function isSensitiveVisible(slug) {
                const f = fieldDef(slug);
                return !f || !f.is_sensitive || can('tpp_view_sensitive');
        }

        /* ==================== به‌روزرسانی خودکار اپ (رفع مشکل نمایش داده نشدن نسخه جدید) ==================== */

        /** فقط یک بار برای هر نسخه سرور ریلود کن (جلوگیری از حلقه بی‌نهایت) */
        function reloadedAlreadyFor(serverVersion) {
                try {
                        return sessionStorage.getItem('tpp_reloaded_for') === String(serverVersion);
                } catch (e) { return true; } // sessionStorage نبود؟ ریسک حلقه نگیریم
        }
        function markReloaded(serverVersion) {
                try { sessionStorage.setItem('tpp_reloaded_for', String(serverVersion)); } catch (e) {}
        }

        /** پوسته کهنه است: کش‌ها و SW های قدیمی را پاک کن و یک بار ریلود کن */
        async function refreshStaleShell(serverVersion) {
                if (reloadedAlreadyFor(serverVersion)) return; // قبلاً برای این نسخه ریلود شده — ادامه بده
                markReloaded(serverVersion);
                try {
                        if ('caches' in window) {
                                const keys = await caches.keys();
                                await Promise.all(keys.filter((k) => k.indexOf('tpp-') === 0).map((k) => caches.delete(k)));
                        }
                } catch (e) {}
                try {
                        if ('serviceWorker' in navigator) {
                                const regs = await navigator.serviceWorker.getRegistrations();
                                await Promise.all(regs.map((r) => r.unregister().catch(() => {})));
                        }
                } catch (e) {}
                location.replace(cacheBustedUrl()); // بدون کش دوباره بارگذاری کن
        }

        /** آدرس فعلی + پارامتر یکتا تا پاسخ HTTP-cache هم دور زده شود */
        function cacheBustedUrl() {
                try {
                        const u = new URL(location.href);
                        u.searchParams.set('_t', String(Date.now()));
                        return u.href;
                } catch (e) { return location.href; }
        }

        /** پاک‌سازی میراث: SW قدیمیِ پوشه app/ (نسخه‌های قبل ۱.۵.۰) و کش‌هایش */
        async function cleanupLegacy() {
                try {
                        if (!('serviceWorker' in navigator)) return;
                        const regs = await navigator.serviceWorker.getRegistrations();
                        const mine = new URL('sw.js', location.href).href;
                        await Promise.all(regs.map(async (r) => {
                                const scope = String(r.scope || '');
                                const isMine = String(new URL('sw.js', r.scope).href) === mine;
                                if (!isMine && scope.indexOf('/tpp-services/') !== -1) {
                                        await r.unregister().catch(() => {});
                                }
                        }));
                } catch (e) {}
        }

        /** ثبت SW + گوش دادن به نسخه جدید: خودکار فعال و یک ریلود کنترل‌شده */
        function setupServiceWorker() {
                if (!('serviceWorker' in navigator)) return;

                // فقط وقتی SW «قبلی» وجود داشت و نسخه جدید جای آن را گرفت ریلود کن
                // (نصب اولین بار نیازی به ریلود ندارد)
                const hadController = !!navigator.serviceWorker.controller;
                let reloading = false;
                navigator.serviceWorker.addEventListener('controllerchange', () => {
                        if (!hadController || reloading) return;
                        reloading = true;
                        try {
                                if (sessionStorage.getItem('tpp_sw_reloaded') === '1') { reloading = false; return; }
                                sessionStorage.setItem('tpp_sw_reloaded', '1');
                        } catch (e) {}
                        location.reload();
                });

                navigator.serviceWorker.register('sw.js').then((reg) => {
                        // نسخه جدید در حال نصب است → فوراً فعالش کن (بدون انتظار بستن تب‌ها)
                        reg.addEventListener('updatefound', () => {
                                const nw = reg.installing;
                                if (!nw) return;
                                nw.addEventListener('statechange', () => {
                                        if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                                                nw.postMessage('tpp-skip-waiting');
                                        }
                                });
                        });

                        // بررسی دوره‌ای به‌روزرسانی (وقتی تب دیده می‌شود؛ حداکثر هر ۳۰ دقیقه)
                        let lastCheck = 0;
                        const checkUpdate = () => {
                                const now = Date.now();
                                if (now - lastCheck < 30 * 60 * 1000) return;
                                lastCheck = now;
                                reg.update().catch(() => {});
                        };
                        document.addEventListener('visibilitychange', () => {
                                if (!document.hidden) checkUpdate();
                        });
                        window.addEventListener('focus', checkUpdate);
                        checkUpdate();
                }).catch(() => {});
        }

        /* ==================== راه‌اندازی ==================== */

        document.addEventListener('DOMContentLoaded', () => {
                initTheme(); // پیش‌فرض: دارک پرکنتراست — قبل از هر چیز
                const themeBtn = document.getElementById('theme-toggle');
                if (themeBtn) themeBtn.addEventListener('click', () => {
                        applyTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
                });
                els.loginScreen = document.getElementById('login-screen');
                els.loginForm = document.getElementById('login-form');
                els.loginError = document.getElementById('login-error');
                els.loginSite = document.getElementById('login-site');
                els.app = document.getElementById('app');
                els.content = document.getElementById('content');
                els.pageTitle = document.getElementById('page-title');
                els.topbarActions = document.getElementById('topbar-actions');
                els.toastWrap = document.getElementById('toast-wrap');
                els.modalRoot = document.getElementById('modal-root');
                els.nav = document.getElementById('nav');
                els.outboxBadge = document.getElementById('outbox-badge');
                els.netLabel = document.getElementById('net-label');
                els.offlineIndicator = document.getElementById('offline-indicator');
                els.userName = document.getElementById('user-name');
                els.menuToggle = document.getElementById('menu-toggle');
                els.sidebar = document.getElementById('sidebar');

                // Service Worker (فقط حالت مستقل — برای کار آفلاین PWA)
                if (!EMBED) setupServiceWorker();
                cleanupLegacy(); // در هر دو حالت: SW قدیمیِ پوشه app/ (نسخه‌های قبل ۱.۵.۰) پاک شود
                // حالت امبد: استایل چیدمان داخل صفحه سایت
                if (EMBED) document.documentElement.classList.add('tpp-embed');

                els.loginForm.addEventListener('submit', onLoginSubmit);
                document.getElementById('logout-btn').addEventListener('click', onLogout);

                // سایدبار کشویی موبایل + پوشش تاریک (بستن با لمس بیرون)
                const closeSidebar = () => {
                        els.sidebar.classList.remove('open');
                        const ov = document.querySelector('.sidebar-open-overlay');
                        if (ov) ov.remove();
                };
                els.menuToggle.addEventListener('click', () => {
                        const willOpen = !els.sidebar.classList.contains('open');
                        closeSidebar();
                        if (willOpen) {
                                els.sidebar.classList.add('open');
                                const ov = document.createElement('div');
                                ov.className = 'sidebar-open-overlay';
                                ov.addEventListener('click', closeSidebar);
                                document.body.appendChild(ov);
                        }
                });
                els.nav.addEventListener('click', (e) => {
                        if (e.target.closest('a')) closeSidebar();
                });

                window.addEventListener('hashchange', route);
                TPP.offline.on('change', updateNetStatus);
                TPP.offline.on('sync', onSyncEvent);
                TPP.offline.init();
                // ۱.۹.۲: به‌روزرسانی زنده وضعیت کش آفلاین (نوار وضعیت سرویس‌ها / بنر آفلاین / داشبورد)
                TPP.offline.on('cache', updateOfflineStatus);
                TPP.offline.on('change', updateOfflineStatus);
                // ۱.۹.۲: با وصل شدن دوباره اینترنت، اگر نمای سرویس‌ها باز است نتایج از سرور تازه شود
                // (جای داده محلی/پیام خالی قدیمی، نتایج زنده سرور نمایش داده شود)
                window.addEventListener('online', () => {
                        setTimeout(() => {
                                if (document.getElementById('results-area') && TPP.offline.state().online) doSearch();
                        }, 600);
                });

                boot();
        });

        async function boot() {
                try {
                        const payload = await TPP.api.request('GET', 'bootstrap');
                        applyBootstrap(payload);
                } catch (e) {
                        // آفلاین هستیم؟ اگر کش محلی و توکن داریم، ادامه با داده محلی
                        if (TPP.api.hasToken() && (!e || !e.status || e.status >= 500 || e.network)) {
                                try {
                                        const cachedUser = await TPP_IDB.get('kv', 'user_state');
                                        if (cachedUser && cachedUser.v && cachedUser.v.user) {
                                                applyBootstrap({ ...cachedUser.v, token: null, offline_boot: true });
                                                toast('حالت آفلاین: در حال نمایش داده‌های ذخیره‌شده این دستگاه هستید.', 'warn');
                                                return;
                                        }
                                } catch (err) {}
                        }
                        // نشست وردپرس/توکن معتبر نیست
                        if (e && e.data && e.data.login_url) state.loginUrl = e.data.login_url;
                        showLogin(e && e.message ? e.message : '');
                }
        }

        function applyBootstrap(payload) {
                // دست‌دادن نسخه: اگر کد در حال اجرا قدیمی‌تر از سرور است، پوسته تازه شود
                if (payload.version && TPP.VERSION && String(payload.version) !== String(TPP.VERSION)) {
                        refreshStaleShell(payload.version);
                }
                if (payload.token) TPP.api.setToken(payload.token);
                if (payload.nonce) TPP.api.setNonce(payload.nonce);
                state.user = payload.user;
                state.caps = payload.caps || [];
                state.isManager = !!payload.is_manager;
                state.isWPAdmin = !!payload.is_wp_admin; // مدیر کل وردپرس — دسترسی «بررسی موارد تکراری»
                state.schema = payload.schema || { address: [], service: [] };
                state.settings = payload.settings || state.settings;
                state.sms = payload.sms || state.sms;
                state.progress = payload.progress || null; // ۱.۱۲.۰ — مراحل دایری + خرابی‌ها از سرور
                state.site = payload.site || '';
                state.loginUrl = payload.login_url || state.loginUrl;
                state.logoutUrl = payload.logout_url || '';
                state.stats = payload.stats || null;

                // ذخیره وضعیت برای بوت آفلاین
                TPP_IDB.set('kv', 'user_state', { user: state.user, caps: state.caps, isManager: state.isManager, isWPAdmin: state.isWPAdmin, schema: state.schema, settings: state.settings, sms: state.sms, progress: state.progress, site: state.site, loginUrl: state.loginUrl, logoutUrl: state.logoutUrl });

                els.loginScreen.classList.add('hidden');
                els.app.classList.remove('hidden');
                els.userName.textContent = state.user ? state.user.name : '';
                els.loginSite.textContent = state.site;

                // نمایش/پنهان‌سازی منوی مدیریتی
                document.querySelectorAll('.manager-only').forEach((a) => {
                        const view = a.getAttribute('data-view');
                        const cap = { fields: 'tpp_manage_fields', categories: 'tpp_manage_categories', roles: 'tpp_manage_roles', settings: 'tpp_manage_settings', activity: 'tpp_view_activity', api: 'tpp_manage_settings' }[view];
                        a.classList.toggle('hidden', !can(cap));
                });
                // «بررسی موارد تکراری» فقط برای مدیر کل سایت (۱.۹.۰)
                document.querySelectorAll('.admin-only').forEach((a) => a.classList.toggle('hidden', !state.isWPAdmin));
                // ۱.۲۰.۰ — پنهان‌سازی خودکار هر منویی که قابلیتش را نداریم (درخواست کاربر):
                // مثل «ایمپورت اکسل» و «خروجی و پشتیبان» برای نصاب — data-cap فهرست قابلیت‌ها با «یا»
                document.querySelectorAll('#nav a[data-cap]').forEach((a) => {
                        const caps = (a.getAttribute('data-cap') || '').split(',').map((s) => s.trim()).filter(Boolean);
                        a.classList.toggle('hidden', !caps.some((c) => can(c)));
                });

                startTimers();
                route();
                // کش کامل در پس‌زمینه
                if (TPP.offline.state().online) TPP.offline.cacheAll().catch(() => {});
        }

        function startTimers() {
                clearInterval(heartbeatTimer);
                clearInterval(cacheTimer);
                const hbMin = Math.max(2, parseInt(state.settings.heartbeat_min, 10) || 5);
                heartbeatTimer = setInterval(async () => {
                        if (!TPP.offline.state().online) return;
                        try { await TPP.api.request('GET', 'ping'); } catch (e) {}
                }, hbMin * 60 * 1000);
                cacheTimer = setInterval(() => {
                        if (TPP.offline.state().online && document.visibilityState === 'visible') {
                                TPP.offline.cacheAll().catch(() => {});
                        }
                }, 15 * 60 * 1000);
        }

        /** آدرس ورود وردپرس با بازگشت به صفحه درست (امبد: صفحه سایت / مستقل: خود اپ) */
        function wpLoginUrl() {
                const base = state.loginUrl || '/wp-login.php';
                const back = EMBED ? (PARENT_URL || location.href) : location.href;
                try {
                        const u = new URL(base, location.origin);
                        u.searchParams.set('redirect_to', back);
                        return u.toString();
                } catch (e) {
                        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'redirect_to=' + encodeURIComponent(back);
                }
        }

        function showLogin(message) {
                els.app.classList.add('hidden');
                els.loginScreen.classList.remove('hidden');
                if (EMBED) {
                        // حالت امبد: ورود از طریق صفحه ورود خود وردپرس انجام می‌شود
                        els.loginForm.innerHTML = `
                                <div class="login-logo"><svg width="56" height="56" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" fill="#1f4e79"/></svg></div>
                                <h1>مدیریت سرویس‌های TPP</h1>
                                <p class="login-sub">${esc(state.site || '')}</p>
                                <div class="login-error">${message ? '⚠️ ' + esc(message) : 'برای استفاده از این برنامه، ابتدا وارد حساب کاربری وردپرس شوید.'}</div>
                                <a href="${esc(wpLoginUrl())}" class="btn btn-primary btn-block" style="display:block;text-align:center;text-decoration:none;padding:11px 12px" target="_top">ورود به حساب کاربری وردپرس</a>
                                <p class="login-note">پس از ورود، به‌صورت خودکار به همین صفحه باز می‌گردید. حساب شما باید یکی از نقش‌های افزونه (مدیر سرویس‌ها، نصاب، اپراتور ثبت یا گزارش‌گیر) را داشته باشد.</p>`;
                        return;
                }
                els.loginError.textContent = message || '';
                setTimeout(() => els.loginForm.querySelector('input[name=username]').focus(), 60);
        }

        async function onLoginSubmit(e) {
                e.preventDefault();
                els.loginError.textContent = '';
                const btn = document.getElementById('login-btn');
                btn.disabled = true; btn.textContent = 'در حال ورود…';
                try {
                        const fd = new FormData(els.loginForm);
                        const payload = await TPP.api.login(fd.get('username'), fd.get('password'));
                        TPP.api.setToken(payload.token);
                        applyBootstrap(payload);
                        toast('خوش آمدید، ' + esc(payload.user.name) + '!', 'success');
                } catch (err) {
                        els.loginError.textContent = err.message || 'ورود ناموفق بود.';
                } finally {
                        btn.disabled = false; btn.textContent = 'ورود';
                }
        }

        async function onLogout() {
                if (EMBED) {
                        // خروج پیوندی: نشست وردپرس هم پایان می‌یابد
                        const ok = await confirmBox('از حساب کاربری وردپرس خارج می‌شوید؟<br><span class="muted">داده‌های محلی این دستگاه هم پاک می‌شود.</span>', 'خروج');
                        if (!ok) return;
                        try { await TPP.api.request('POST', 'logout', { wp: 1 }); } catch (e) {}
                        TPP.api.setToken('');
                        try { await TPP.offline.wipeLocal(); } catch (e) {}
                        clearInterval(heartbeatTimer);
                        clearInterval(cacheTimer);
                        if (PARENT_URL) {
                                try { window.top.location.href = PARENT_URL; return; } catch (err) {}
                        }
                        location.reload();
                        return;
                }
                const wipe = await confirmBox('از حساب کاربری خارج می‌شوید؟<br><span class="muted">داده‌های ذخیره‌شده این دستگاه (شامل صف همگام‌سازی) حذف می‌شوند. اگر عملیات همگام‌نشده دارید، ابتدا همگام‌سازی کنید.</span>', 'خروج');
                if (!wipe) return;
                try { await TPP.api.request('POST', 'logout', {}); } catch (e) {}
                TPP.api.setToken('');
                await TPP.offline.wipeLocal();
                clearInterval(heartbeatTimer);
                clearInterval(cacheTimer);
                location.reload();
        }

        /* ==================== وضعیت شبکه ==================== */

        function updateNetStatus(st) {
                els.offlineIndicator.classList.toggle('offline', !st.online);
                els.netLabel.textContent = st.online ? 'آنلاین' : 'آفلاین';
                if (st.pending > 0) {
                        els.outboxBadge.textContent = st.pending;
                        els.outboxBadge.classList.remove('hidden');
                } else {
                        els.outboxBadge.classList.add('hidden');
                }
        }

        function onSyncEvent(ev) {
                if (ev.conflict) {
                        toast('⚠️ تعارض همگام‌سازی در «' + esc(ev.conflict.payload && ev.conflict.payload.id ? 'سرویس #' + ev.conflict.payload.id : 'یک سرویس') + '» — تغییر شما ثبت شد اما شخص دیگری همزمان تغییر داده بود. جزئیات در تاریخچه.', 'warn', 9000);
                }
                if (ev.op_error) {
                        toast('❌ یک عملیات آفلاین اعمال نشد: ' + esc(ev.result && ev.result.message ? ev.result.message : 'خطای نامشخص'), 'error', 8000);
                }
                if (ev.syncing === false && ev.summary && (ev.summary.applied || ev.summary.failed)) {
                        toast('همگام‌سازی کامل شد — ' + ev.summary.applied + ' مورد اعمال شد' + (ev.summary.failed ? '، ' + ev.summary.failed + ' خطا' : ''), ev.summary.failed ? 'warn' : 'success');
                }
        }

        /* ==================== مسیریابی ==================== */

        const routes = [
                { name: 'dashboard', title: 'داشبورد' },
                { name: 'services', title: 'سرویس‌ها' },
                { name: 'workreport', title: 'گزارش کار' },
                { name: 'history', title: 'تاریخچه تغییرات' },
                { name: 'import', title: 'ایمپورت اکسل' },
                { name: 'export', title: 'خروجی و پشتیبان' },
                { name: 'outbox', title: 'صف همگان‌سازی' },
                { name: 'fields', title: 'فیلدهای اطلاعاتی' },
                { name: 'categories', title: 'دسته‌بندی پروژه‌ها' },
                { name: 'review', title: 'بازبینی' },
                { name: 'roles', title: 'نقش‌ها و دسترسی‌ها' },
                { name: 'activity', title: 'گزارش فعالیت' },
                { name: 'settings', title: 'تنظیمات' },
                { name: 'api', title: 'مرکز API' },
                { name: 'duplicates', title: 'بررسی موارد تکراری' },
                { name: 'service', title: 'مشخصات سرویس' },
                { name: 'address', title: 'آدرس و سرویس‌ها' }
        ];

        function route() {
                const hash = location.hash.replace(/^#\/?/, '') || 'dashboard';
                const [pathPart, queryPart] = hash.split('?');
                const [name, ...rest] = pathPart.split('/');
                const r = routes.find((x) => x.name === name) || routes[0];
                state.route = r.name;
                state.params = {
                        id: rest[0] ? decodeURIComponent(rest[0]) : null,
                        query: new URLSearchParams(queryPart || '')
                };

                els.pageTitle.textContent = r.title;
                els.topbarActions.innerHTML = '';
                els.nav.querySelectorAll('a').forEach((a) => a.classList.toggle('active', a.getAttribute('data-view') === r.name));

                const render = (TPP.views && TPP.views[r.name]) ? TPP.views[r.name] : null;
                if (render) {
                        els.content.innerHTML = '<div class="loading-block"><div class="spinner"></div><p>در حال بارگذاری…</p></div>';
                        Promise.resolve(render(state.params)).catch((e) => {
                                els.content.innerHTML = '<div class="card"><div class="alert err">خطا: ' + esc(e.message || e) + '</div></div>';
                        }).finally(() => { reportHeight(); setTimeout(reportHeight, 400); });
                }
        }

        function go(hash) { location.hash = '#/' + hash; }

        /* ==================== حالت امبد (iframe در صفحه سایت) ==================== */

        /** ارتفاع اپ را به صفحه میزبان گزارش می‌دهد تا iframe هم‌اندازه شود */
        function reportHeight() {
                if (!EMBED) return;
                try {
                        const h = Math.max(
                                document.body.scrollHeight,
                                document.documentElement.scrollHeight,
                                els.app && !els.app.classList.contains('hidden') ? els.app.scrollHeight : 0
                        );
                        window.parent.postMessage({ type: 'tpp:height', h }, '*');
                } catch (e) { /* noop */ }
        }

        if (EMBED) {
                heightTimer = setInterval(reportHeight, 1500);
                window.addEventListener('resize', reportHeight);
        }

        /* ==================== وضعیت کش آفلاین (۱.۹.۲) ==================== */

        /** آمار کش آفلاین — { n, total, complete, syncing, error, last } */
        function offlineCacheInfo() {
                const cs = TPP.offline.cacheStats ? TPP.offline.cacheStats() : null;
                if (!cs) return null;
                return {
                        n: parseInt(cs.cached, 10) || 0,
                        total: parseInt(cs.total, 10) || 0,
                        complete: !!cs.complete,
                        syncing: !!cs.syncing,
                        error: cs.error || null,
                        last: cs.ts || cs.lastSync || null
                };
        }

        function faNum(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

        /**
         * نوار/بنر وضعیت داده آفلاین — با رویدادهای 'cache' و 'change' زنده به‌روز می‌شود.
         * عناصر هدف: #offline-status (نمای سرویس‌ها) / #offline-banner (بنر آفلاین) / #dash-cache (داشبورد)
         */
        function updateOfflineStatus() {
                const info = offlineCacheInfo();
                if (!info) return;
                const lastTxt = info.last ? fmtDate(info.last) : '—';
                const isOffline = !TPP.offline.state().online;

                // ۱.۹.۲: اگر وسط کار آنلاین/آفلاین شدیم و نمای سرویس‌ها باز است، بنر آفلاین اضافه/حذف شود
                const content = document.getElementById('content');
                const resultsArea = document.getElementById('results-area');
                let banner = document.getElementById('offline-banner');
                if (content && resultsArea && isOffline && !banner) {
                        const div = document.createElement('div');
                        div.className = 'alert warn';
                        div.id = 'offline-banner';
                        div.innerHTML = '📴 حالت آفلاین — نتایج از داده‌های ذخیره‌شده این دستگاه نمایش داده می‌شوند.';
                        content.insertBefore(div, content.firstChild);
                        banner = div;
                } else if (content && !resultsArea && banner) {
                        banner.remove(); // نمای دیگری باز است (بنر مال نمای سرویس‌ها بود)
                        banner = null;
                } else if (banner && !isOffline) {
                        banner.remove(); // دوباره آنلاین شدیم
                        banner = null;
                }

                const bar = document.getElementById('offline-status');
                if (bar) {
                        if (isOffline) {
                                bar.classList.add('hidden'); // آفلاین: بنر بالای صفحه وضعیت را نشان می‌دهد
                        } else {
                                bar.classList.remove('hidden');
                                const counts = info.total
                                        ? `${faNum(info.n)} از ${faNum(info.total)} سرویس ذخیره‌شده روی این دستگاه`
                                        : (info.n ? `${faNum(info.n)} سرویس ذخیره‌شده` : 'هنوز داده‌ای ذخیره نشده');
                                let txt, cls = 'ok';
                                if (info.syncing) { txt = `📡 در حال همگام‌سازی داده آفلاین… ${counts}`; cls = 'busy'; }
                                else if (!info.n) { txt = '📡 داده آفلاین این دستگاه خالی است — همگام‌سازی خودکار در جریان است'; cls = 'warn'; }
                                else if (!info.complete) { txt = `⚠️ داده آفلاین ناقص است: ${counts} — با اتصال اینترنت به‌صورت خودکار ادامه می‌یابد`; cls = 'warn'; }
                                else { txt = `✅ داده آفلاین کامل: ${counts}`; }
                                bar.className = 'offline-status ' + cls;
                                bar.innerHTML = `<span class="os-txt">${txt} • آخرین همگام‌سازی: ${lastTxt}</span>
                                        <button class="btn btn-sm" id="btn-offline-sync" title="همگام‌سازی کامل داده‌ها روی این دستگاه برای استفاده آفلاین">${info.complete ? '🔄 به‌روزرسانی' : '🔄 همگام‌سازی کامل'}</button>`;
                                const sb = document.getElementById('btn-offline-sync');
                                if (sb) sb.addEventListener('click', runOfflineSync);
                        }
                }

                if (banner) {
                        const counts = info.total ? `${faNum(info.n)} از ${faNum(info.total)}` : String(info.n ? faNum(info.n) : '۰');
                        banner.innerHTML = `📴 حالت آفلاین — نتایج از داده‌های ذخیره‌شده این دستگاه نمایش داده می‌شوند (<b>${counts}</b> سرویس${info.complete ? '' : ' — همگام‌سازی ناقص؛ با وصل شدن اینترنت تکمیل می‌شود'}) • آخرین همگام‌سازی: ${lastTxt}`;
                }

                const dash = document.getElementById('dash-cache');
                if (dash) {
                        dash.textContent = info.total
                                ? `${faNum(info.n)} از ${faNum(info.total)} سرویس ${info.complete ? '(کامل)' : '(ناقص — در حال تکمیل)'}`
                                : (info.n ? `${faNum(info.n)} سرویس ذخیره‌شده` : 'خالی — همگام‌سازی خودکار در جریان');
                }
        }

        /** همگام‌سازی دستی/اجباری داده آفلاین (۱.۹.۲) — ارسال صف عملیات + کش کامل روی دستگاه */
        async function runOfflineSync() {
                if (!TPP.offline.state().online) { toast('همگام‌سازی به اتصال اینترنت نیاز دارد.', 'warn'); return; }
                toast('همگام‌سازی داده‌های آفلاین آغاز شد…');
                try {
                        await TPP.offline.flush(true).catch(() => {});
                        await TPP.offline.cacheAll();
                        toast('همگام‌سازی انجام شد — داده‌های آفلاین این دستگاه کامل است.', 'success');
                } catch (e) {
                        toast('همگام‌سازی ناقص ماند (' + esc(e && e.message ? e.message : 'خطای شبکه') + ') — به‌صورت خودکار دوباره تلاش می‌شود.', 'error', 7000);
                }
                updateOfflineStatus();
        }

        /* ==================== نما: داشبورد ==================== */

        async function viewDashboard() {
                let stats = state.stats || {};
                if (TPP.offline.state().online) {
                        try { stats = await TPP.api.request('GET', 'stats'); } catch (e) {}
                }
                const pending = TPP.offline.state().pending;
                const lastSync = await TPP.offline.lastSync();

                els.content.innerHTML = `
                <div class="stat-grid">
                        <div class="stat"><div class="num">${stats.services || 0}</div><div class="lbl">کل سرویس‌ها</div></div>
                        <div class="stat ok"><div class="num">${stats.addresses || 0}</div><div class="lbl">آدرس‌های ثبت‌شده</div></div>
                        <div class="stat"><div class="num">${stats.changes || 0}</div><div class="lbl">رکوردهای تاریخچه</div></div>
                        <div class="stat"><div class="num">${stats.today || 0}</div><div class="lbl">تغییرات امروز</div></div>
                        <div class="stat warn"><div class="num">${stats.conflicts || 0}</div><div class="lbl">تعارض‌های ثبت‌شده</div></div>
                        <div class="stat warn"><div class="num">${pending}</div><div class="lbl">در صف همگام‌سازی</div></div>
                </div>

                ${stats.progress ? `
                <div class="card prog-dash">
                        <h3>🚀 وضعیت دایری سرویس‌ها <span class="muted">(برای مشاهده فهرست روی هر کادر کلیک کنید)</span></h3>
                        <div class="stat-grid prog-stat-grid">
                                <div class="stat prog-click" data-prog="progress" title="سرویس‌هایی که دایری‌شان آغاز شده اما کامل نشده"><div class="num">${faNum(stats.progress.progress || 0)}</div><div class="lbl">🚧 در حال دایری</div></div>
                                <div class="stat ok prog-click" data-prog="done" title="همه ۱۶ مرحله دایری تکمیل شده"><div class="num">${faNum(stats.progress.done || 0)}</div><div class="lbl">✅ دایری کامل</div></div>
                                <div class="stat err prog-click" data-prog="fail" title="خرابی اعلام‌شده (LOS / قطع تلفن / قطع اینترنت / سایر)"><div class="num">${faNum(stats.progress.fail || 0)}</div><div class="lbl">❌ خرابی اعلام‌شده</div></div>
                                <div class="stat prog-click" data-prog="none" title="هیچ مرحله دایری شروع نشده"><div class="num">${faNum(stats.progress.none || 0)}</div><div class="lbl">⏳ شروع نشده</div></div>
                        </div>
                </div>` : ''}

                <div class="grid-2">
                        <div class="card">
                                <h3>دسترسی سریع</h3>
                                <div class="actions-row">
                                        ${can('tpp_create_services') ? '<button class="btn btn-primary" id="qa-new">➕ ثبت سرویس جدید</button>' : ''}
                                        <button class="btn" id="qa-search">🔍 جستجوی سرویس‌ها</button>
                                        ${can('tpp_import') ? '<button class="btn" id="qa-import">📥 ایمپورت اکسل</button>' : ''}
                                        ${can('tpp_export') ? '<button class="btn" id="qa-export">📤 خروجی/پشتیبان</button>' : ''}
                                        <button class="btn" id="qa-sync">🔄 همگان‌سازی دستی</button>
                                </div>
                                <div class="divider"></div>
                                <p class="muted">آخرین همگام‌سازی: ${lastSync ? fmtDate(lastSync) : '—'} | آخرین تغییر ثبت‌شده: ${stats.last_change ? fmtDate(stats.last_change) : '—'}</p>
                                <p class="muted">داده آفلاین این دستگاه: <b id="dash-cache">${(() => { const i = offlineCacheInfo(); return i ? (i.total ? faNum(i.n) + ' از ' + faNum(i.total) + ' سرویس ' + (i.complete ? '(کامل)' : '(ناقص)') : (i.n ? faNum(i.n) + ' سرویس' : 'خالی')) : '—'; })()}</b> — با دکمه «همگام‌سازی دستی» کامل/به‌روز می‌شود.</p>
                                <p class="muted" style="line-height:2.2">
                                        حالت کاری فعلی: <b>${TPP.offline.state().online ? 'آنلاین' : 'آفلاین'}</b> —
                                        در حالت آفلاین، ثبت و ویرایش در دستگاه شما ذخیره و با اتصال اینترنت به‌صورت خودکار اعمال می‌شود؛ تاریخچه تغییرات همواره در سرور حفظ می‌شود.
                                </p>
                        </div>
                        <div class="card">
                                <h3>راهنمای سریع</h3>
                                <ul class="muted" style="line-height:2.4;padding-right:18px;margin:0">
                                        <li>جستجوی زنده: در صفحه «سرویس‌ها» هر کلمه‌ای از هر فیلدی (نام، تلفن، آدرس، سریال مودم و…) را تایپ کنید.</li>
                                        <li>چند سرویس برای یک آدرس: در مشخصات سرویس، بخش «سرویس‌های این آدرس» → افزودن سرویس.</li>
                                        <li>ایمپورت گروهی: ابتدا «فایل نمونه» را بگیرید؛ ستون‌ها دقیقاً مطابق فیلدهای فعلی است و با تغییر فیلدها به‌روز می‌شود.</li>
                                        <li>تاریخچه: از صفحه «تاریخچه تغییرات» همه تغییرات با جزئیات قدیم/جدید قابل مشاهده است.</li>
                                </ul>
                        </div>
                </div>`;

                const qn = document.getElementById('qa-new');
                if (qn) qn.addEventListener('click', () => go('service/new'));
                document.getElementById('qa-search').addEventListener('click', () => go('services'));
                // ۱.۱۲.۰ — کلیک روی کادر وضعیت دایری → فهرست سرویس‌ها با همان فیلتر
                document.querySelectorAll('.prog-click[data-prog]').forEach((el) => {
                        el.addEventListener('click', () => {
                                searchState.prog = { status: el.getAttribute('data-prog') || '', step: '', stepState: 'done' };
                                searchState.query = '';
                                searchState.filters = {};
                                searchState.page = 1;
                                go('services');
                        });
                });
                const qi = document.getElementById('qa-import'); if (qi) qi.addEventListener('click', () => go('import'));
                const qe = document.getElementById('qa-export'); if (qe) qe.addEventListener('click', () => go('export'));
                document.getElementById('qa-sync').addEventListener('click', async () => {
                        await runOfflineSync();
                        viewDashboard();
                });
        }

        /* ==================== نما: سرویس‌ها (جستجوی زنده) ==================== */

        const searchState = { query: '', filters: {}, page: 1, perPage: 25, group: false, sort: 'updated', order: 'desc', result: null, selected: new Set(), updFrom: '', updTo: '', prog: { status: '', step: '', stepState: 'done' }, cat: '', tags: [] }; // ۱.۱۹.۰ — cat/tags

        /* ۱.۱۲.۰ — مراحل دایری برای فیلتر/نشان‌ها (از bootstrap؛ fallback خالی) */
        function progressSteps() {
                const p = state.progress && state.progress.steps;
                return (p && Object.keys(p).length) ? p : {};
        }

        /** نشان وضعیت دایری برای کارت سرویس: آخرین مرحله به رنگ سبز یا خرابی‌ها به رنگ قرمز — ۱.۱۴.۰: چند خرابی */
        function progressBadgeHtml(r) {
                const p = r && r.progress;
                if (!p) return '';
                const total = p.total || 16;
                const FAIL_SHORT = { los: 'LOS (قطع کامل)', phone: 'قطع تلفن', internet: 'قطع اینترنت', other: 'سایر خرابی' };
                const skipped = p.excluded_count ? ' · ' + faNum(p.excluded_count) + ' ردشده' : ''; // ۱.۱۳.۰
                const fails = (p.failures && p.failures.length) ? p.failures : (p.failure ? [p.failure] : []);
                if (fails.length) {
                        const labels = (p.failures_labels && p.failures_labels.length ? p.failures_labels : fails.map((k) => (p.failure_label && k === p.failure ? p.failure_label : FAIL_SHORT[k] || k)));
                        const title = labels.join('، ');
                        const txt = fails.length > 1
                                ? ('❌ ' + (labels[0] && labels[0].length > 18 ? labels[0].slice(0, 17) + '…' : (FAIL_SHORT[fails[0]] || fails[0])) + ' +' + faNum(fails.length - 1))
                                : ('❌ ' + (FAIL_SHORT[fails[0]] || fails[0]));
                        return `<span class="chip err svc-prog-badge fail" title="${esc(title)}">${esc(txt)}</span>`;
                }
                if (p.done > 0 && p.last_label) {
                        const short = String(p.last_label).length > 26 ? String(p.last_label).slice(0, 25) + '…' : String(p.last_label);
                        return `<span class="chip ok svc-prog-badge" title="آخرین مرحله دایری: ${esc(p.last_label)} (${faNum(p.done)} از ${faNum(total)} مرحله)${skipped ? ' — ' + faNum(p.excluded_count) + ' مرحله ردشده توسط کاربر' : ''}">✅ ${esc(short)}${esc(skipped)}</span>`;
                }
                if (p.done >= total && total > 0) {
                        return '<span class="chip ok svc-prog-badge">✅ دایری کامل</span>';
                }
                return '<span class="chip svc-prog-badge none" title="هیچ مرحله دایری تکمیل نشده">⏳ دایری شروع نشده</span>';
        }

        /** همگام‌سازی مجموعه انتخاب با چک‌باکس */
        function syncSel(id, checked) {
                const numId = parseInt(id, 10);
                if (checked) searchState.selected.add(numId);
                else searchState.selected.delete(numId);
        }

        function updateBulkBar() {
                const cnt = document.getElementById('sel-count');
                const btn = document.getElementById('btn-bulk-delete');
                const prog = document.getElementById('btn-bulk-progress');
                if (!cnt) return;
                const n = searchState.selected.size;
                cnt.textContent = n;
                if (btn) btn.disabled = n === 0;
                if (prog) prog.disabled = n === 0;
        }

        /* ==================== ۱.۱۳.۰ — ویرایش گروهی/تکی پیشرفت دایری و وضعیت ==================== */

        /** دیالوگ تغییر گروهی/تکی — ids: شناسه‌ها؛ single: حالت تکی (از دکمه ⚡ کارت) */
        async function openBulkEdit(ids, single) {
                const idsN = ids.map((i) => parseInt(i, 10)).filter((i) => i > 0);
                if (!idsN.length) return;
                const cat = TPP.progressUtil.catalog();
                const stepsCat = cat.steps || {};
                const failuresCat = cat.failures || {};
                const stepKeys = Object.keys(stepsCat);
                const svcFields = (state.schema.service || []).filter((f) => !f.is_sensitive);

                // وضعیت فعلی سرویس در حالت تکی — برای پیش‌نمایش زنده
                let curRow = null;
                if (single) {
                        const rows = (searchState.result && searchState.result.rows) || [];
                        curRow = rows.find((r) => parseInt(r.id, 10) === idsN[0]) || null;
                }
                const curProg = curRow && curRow.progress ? curRow.progress : null;

                const stepOptions = stepKeys.map((k, i) => `<option value="${esc(k)}">${faNum(i + 1)}. ${esc(stepsCat[k])}</option>`).join('');
                const stepChips = stepKeys.map((k, i) => `<button type="button" class="bstep-chip" data-bk="${esc(k)}" title="${esc(stepsCat[k])}">${faNum(i + 1)}</button>`).join('');
                // ۱.۱۴.۰ — خرابی‌ها به‌صورت چندانتخابی (چند خرابی همزمان، مثلاً «سایر» + «اینترنت قطع است»)
                const curFails = curProg ? ((curProg.failures && curProg.failures.length) ? curProg.failures : (curProg.failure ? [curProg.failure] : [])) : [];
                const failBoxes = Object.entries(failuresCat).map(([k, v]) => `
                        <label class="checkbox-row bfail-row${curFails.indexOf(k) !== -1 ? ' cur' : ''}" title="${curFails.indexOf(k) !== -1 ? 'فعلاً فعال است' : ''}">
                                <input type="checkbox" class="bfail" data-bfail="${esc(k)}"${curFails.indexOf(k) !== -1 ? ' checked' : ''}> ${esc(v)}
                        </label>`).join('');
                const fieldOptions = svcFields.map((f) => `<option value="${esc(f.slug)}">${esc(f.label)}</option>`).join('');

                const m = modal(
                        '<div class="modal-head"><h3>' + (single ? '⚡ ویرایش سریع سرویس #' + faNum(idsN[0]) : '🚀 تغییر گروهی پیشرفت و وضعیت') + '</h3><button class="modal-close" data-close>×</button></div>' +
                        '<div class="modal-body bulk-edit-body">' +
                        (single ? '' : `<p class="muted">این تغییرات روی <b>${faNum(idsN.length)}</b> سرویس انتخاب‌شده اعمال می‌شود و برای هر سرویس در تاریخچه/گزارش فعالیت ثبت می‌شود.</p>`) +
                        (curProg ? `<div class="bulk-cur">وضعیت فعلی: <b>${faNum(curProg.done || 0)} از ${faNum(curProg.total || 16)}</b> مرحله${curFails.length ? ' — <span class="chip err">❌ ' + esc((curProg.failures_labels && curProg.failures_labels.length ? curProg.failures_labels : curFails.map((k) => failuresCat[k] || k)).join('، ')) + '</span>' : ''}${curProg.excluded_count ? ' — <span class="muted">' + faNum(curProg.excluded_count) + ' مرحله ردشده</span>' : ''}</div>` : '') +
                        '<div class="bulk-section">' +
                        '<div class="bulk-sec-title">🚀 پیشرفت دایری</div>' +
                        '<label class="radio-row"><input type="radio" name="bmode" value="" checked> بدون تغییر مراحل</label>' +
                        '<label class="radio-row"><input type="radio" name="bmode" value="up_to"> تنظیم تا مرحله… (آبشاری — مراحل قبل خودکار تیک می‌خورند)</label>' +
                        '<div class="bulk-sub" id="b-upto"><select id="b-step" class="btn"><option value="all">✅ همه ۱۶ مرحله (دایری کامل)</option>' + stepOptions + '</select></div>' +
                        '<label class="radio-row"><input type="radio" name="bmode" value="add"> افزودن مرحله(ها)…</label>' +
                        '<div class="bulk-sub hidden" id="b-add"><div class="bstep-chips">' + stepChips + '</div><div class="hint">شماره مراحل موردنظر را لمس/کلیک کنید (آبشار اعمال می‌شود: افزودن مرحله N مراحل قبل را هم تیک می‌زند)</div></div>' +
                        '<label class="radio-row"><input type="radio" name="bmode" value="remove"> حذف مرحله(ها)…</label>' +
                        '<div class="bulk-sub hidden" id="b-remove"><div class="bstep-chips">' + stepChips + '</div><div class="hint">حذف مرحله‌ای که مرحله بعدی تیک دارد → «ردشده» می‌شود تا دوباره خودکار تیک نخورد</div></div>' +
                        '<label class="radio-row"><input type="radio" name="bmode" value="clear"> پاک‌کردن همه مراحل (شروع تازه)</label>' +
                        '<label class="checkbox-row" id="b-keepline"><input type="checkbox" id="b-keep" checked> مراحل «ردشده توسط کاربر» را نگه دار (پرش خودکار از آن‌ها بگذرد)</label>' +
                        '</div>' +
                        '<div class="bulk-section">' +
                        '<div class="bulk-sec-title">⚠️ وضعیت خرابی (چند مورد را می‌توان همزمان علامت زد)</div>' +
                        '<label class="checkbox-row"><input type="checkbox" id="b-fail-apply"> تغییر خرابی‌ها</label>' +
                        '<div class="bulk-sub" id="b-fail-box">' +
                        '<div class="bfail-opts">' + failBoxes + '</div>' +
                        '<div class="hint">تیک‌دارها = خرابی‌های اعلام‌شده؛ هیچ تیکی نماند = رفع همه خرابی‌ها. بدون تیک «تغییر خرابی‌ها» = بدون تغییر.</div>' +
                        '</div>' +
                        '</div>' +
                        '<div class="bulk-section">' +
                        '<div class="bulk-sec-title">📝 تغییر فیلد سرویس (مثلاً آخرین وضعیت اینترنت/تلفن)</div>' +
                        '<div class="grid-2">' +
                        '<select id="b-field" class="btn"><option value="">بدون تغییر</option>' + fieldOptions + '</select>' +
                        '<input type="text" id="b-value" class="btn" placeholder="مقدار جدید (خالی = پاک‌کردن)">' +
                        '</div>' +
                        '</div>' +
                        '<div id="b-preview" class="bulk-preview"></div>' +
                        '</div>' +
                        '<div class="modal-foot"><button class="btn" data-close>انصراف</button><button class="btn btn-primary" id="b-apply">✅ اعمال</button></div>',
                        { static: true }
                );

                const el = m.el;
                const modeRadios = () => el.querySelector('input[name="bmode"]:checked');
                const chipsOf = (wrap) => Array.from(wrap.querySelectorAll('.bstep-chip.on')).map((c) => c.getAttribute('data-bk'));
                const failApply = () => el.querySelector('#b-fail-apply');
                const pickedFails = () => Array.from(el.querySelectorAll('input.bfail:checked')).map((c) => c.getAttribute('data-bfail'));

                const refresh = () => {
                        const mode = modeRadios() ? modeRadios().value : '';
                        el.querySelector('#b-upto').classList.toggle('hidden', mode !== 'up_to');
                        el.querySelector('#b-add').classList.toggle('hidden', mode !== 'add');
                        el.querySelector('#b-remove').classList.toggle('hidden', mode !== 'remove');
                        el.querySelector('#b-keepline').classList.toggle('hidden', mode === 'clear' || mode === '');
                        el.querySelector('#b-fail-box').classList.toggle('hidden', !failApply().checked); // ۱.۱۴.۰
                        // پیش‌نمایش
                        const pv = el.querySelector('#b-preview');
                        const parts = [];
                        if (mode === 'up_to') {
                                const k = el.querySelector('#b-step').value;
                                const n = k === 'all' ? stepKeys.length : (stepKeys.indexOf(k) + 1);
                                parts.push('مراحل: <b>' + faNum(n) + ' از ' + faNum(stepKeys.length) + '</b> تیک می‌خورد' + (el.querySelector('#b-keep').checked ? ' (به‌جز ردشده‌ها)' : ''));
                        } else if (mode === 'add') {
                                const picked = chipsOf(el.querySelector('#b-add'));
                                parts.push(picked.length ? 'افزودن: <b>' + picked.map((k) => faNum(stepKeys.indexOf(k) + 1)).join('، ') + '</b> (آبشاری)' : 'مرحله‌ای انتخاب نشده');
                        } else if (mode === 'remove') {
                                const picked = chipsOf(el.querySelector('#b-remove'));
                                parts.push(picked.length ? 'حذف: <b>' + picked.map((k) => faNum(stepKeys.indexOf(k) + 1)).join('، ') + '</b>' : 'مرحله‌ای انتخاب نشده');
                        } else if (mode === 'clear') {
                                parts.push('همه مراحل و خرابی‌های ردشده <b>پاک</b> می‌شود');
                        }
                        if (failApply().checked) {
                                const f = pickedFails();
                                parts.push(f.length ? 'خرابی‌ها: <b>' + f.map((k) => esc(failuresCat[k] || k)).join('، ') + '</b>' : 'خرابی: <b>رفع همه</b>');
                        }
                        const fl = el.querySelector('#b-field').value;
                        if (fl) parts.push('فیلد «' + esc((svcFields.find((x) => x.slug === fl) || {}).label || fl) + '» = «' + esc(el.querySelector('#b-value').value) + '»');
                        if (!parts.length) parts.push('هیچ عملیاتی انتخاب نشده است');
                        pv.innerHTML = parts.join('<br>');
                        el.querySelector('#b-apply').disabled = !parts.length || parts[0] === 'هیچ عملیاتی انتخاب نشده است';
                };

                el.querySelectorAll('input[name="bmode"]').forEach((r) => r.addEventListener('change', refresh));
                el.querySelector('#b-step').addEventListener('change', refresh);
                el.querySelector('#b-keep').addEventListener('change', refresh);
                el.querySelector('#b-fail-apply').addEventListener('change', refresh);
                el.querySelectorAll('input.bfail').forEach((cb) => cb.addEventListener('change', refresh));
                el.querySelector('#b-field').addEventListener('change', refresh);
                el.querySelector('#b-value').addEventListener('input', refresh);
                el.querySelectorAll('.bstep-chips').forEach((wrap) => wrap.addEventListener('click', (e) => {
                        const c = e.target.closest('.bstep-chip');
                        if (!c) return;
                        c.classList.toggle('on');
                        refresh();
                }));
                refresh();

                el.querySelector('#b-apply').addEventListener('click', async () => {
                        const mode = modeRadios() ? modeRadios().value : '';
                        const keep = el.querySelector('#b-keep').checked;
                        const doFails = failApply().checked;
                        const failList = pickedFails();
                        const fieldSlug = el.querySelector('#b-field').value;
                        const fieldValue = el.querySelector('#b-value').value;

                        const payload = { ids: idsN, progress: {} };
                        if (mode) payload.progress.mode = mode;
                        if (mode === 'up_to') payload.progress.step = el.querySelector('#b-step').value;
                        if (mode === 'add' || mode === 'remove') {
                                const picked = chipsOf(el.querySelector(mode === 'add' ? '#b-add' : '#b-remove'));
                                if (!picked.length) { toast('مرحله‌ای انتخاب نشده است.', 'warn'); return; }
                                payload.progress.steps = picked;
                        }
                        if (mode && mode !== 'clear') payload.progress.skipped_policy = keep ? 'keep' : 'reset';
                        if (doFails) payload.progress.failures = failList; // ۱.۱۴.۰ — آرایه خرابی‌ها (خالی = رفع همه)
                        if (fieldSlug) payload.service = { [fieldSlug]: fieldValue };

                        // پیش‌نمایش تایید
                        const desc = [];
                        if (mode === 'up_to') desc.push('تنظیم مراحل تا «' + (el.querySelector('#b-step').value === 'all' ? 'دایری کامل' : stepsCat[el.querySelector('#b-step').value]) + '»');
                        if (mode === 'add') desc.push('افزودن مراحل ' + chipsOf(el.querySelector('#b-add')).map((k) => faNum(stepKeys.indexOf(k) + 1)).join('،'));
                        if (mode === 'remove') desc.push('حذف مراحل ' + chipsOf(el.querySelector('#b-remove')).map((k) => faNum(stepKeys.indexOf(k) + 1)).join('،'));
                        if (mode === 'clear') desc.push('پاک‌کردن همه مراحل');
                        if (doFails) desc.push(failList.length ? 'اعلام خرابی‌ها: ' + failList.map((k) => failuresCat[k] || k).join('، ') : 'رفع همه خرابی‌ها');
                        if (fieldSlug) desc.push('فیلد «' + ((svcFields.find((x) => x.slug === fieldSlug) || {}).label || fieldSlug) + '» = «' + fieldValue + '»');
                        const ok = await confirmBox('اعمال روی <b>' + faNum(idsN.length) + '</b> سرویس؟<br><span class="muted">' + desc.map((d) => '• ' + esc(d)).join('<br>') + '</span>', 'اعمال تغییرات');
                        if (!ok) return;

                        m.close();
                        await applyBulkEdit(payload, idsN);
                });
        }

        /** اجرای تغییر گروهی — آنلاین: POST services/bulk؛ آفلاین: صف همگام‌سازی + به‌روزرسانی محلی کش */
        async function applyBulkEdit(payload, idsN) {
                const online = TPP.offline.state().online;
                try {
                        let res = null;
                        if (online) {
                                res = await TPP.api.request('POST', 'services/bulk', payload);
                        } else {
                                const op = await TPP.offline.enqueue('service.bulk', payload);
                                res = op && op.result;
                                if (res && res.status === 'error') throw new Error(res.message || 'خطا');
                        }
                        const s = (res && res.summary) || { applied: idsN.length };
                        toast('✅ ' + faNum(s.applied || 0) + ' سرویس به‌روزرس شد' + (s.unchanged ? ' — ' + faNum(s.unchanged) + ' بدون تغییر' : '') + (online ? '' : ' (در صف همگام‌سازی)'), 'success', 6000);

                        // به‌روزرسانی کش محلی (IDB) + بازرند کارت‌ها بدون درخواست مجدد سرور
                        const perId = {};
                        if (res && Array.isArray(res.results)) res.results.forEach((r) => { if (r && r.progress) perId[r.id] = r.progress; });
                        await updateLocalProgress(idsN, perId, payload);
                        if (online) { await doSearch(); }
                        else { renderResults(document.getElementById('results-area'), searchState.result); }
                } catch (e) {
                        toast('خطا در اعمال تغییر گروهی: ' + esc(e.message || e), 'error', 9000);
                }
        }

        /** کش محلی: اعمال محاسبه آبشاری روی ردیف‌های IDB + نتیجه جستجوی فعلی */
        async function updateLocalProgress(idsN, perId, payload) {
                const prog = payload.progress || {};
                for (const id of idsN) {
                        try {
                                const row = await TPP_IDB.get('services', id);
                                if (!row) continue;
                                if (perId[id]) {
                                        row.progress = perId[id];
                                } else if (row.progress) {
                                        // محاسبه محلی معادل سرور (آفلاین)
                                        const old = { steps: row.progress.steps || [], excluded: row.progress.excluded || [] };
                                        // ۱.۱۴.۰ — خرابی‌های چندتایی: failures آرایه؛ failure قدیمی هم تبدیل می‌شود
                                        const newFails = (prog.failures !== undefined && prog.failures !== null)
                                                ? prog.failures
                                                : (prog.failure !== undefined && prog.failure !== null ? [prog.failure] : null);
                                        const keepFails = (row.progress.failures && row.progress.failures.length) ? row.progress.failures : (row.progress.failure ? [row.progress.failure] : []);
                                        let input = null;
                                        if (prog.mode === 'up_to') {
                                                const K = TPP.progressUtil.keys();
                                                let target = prog.step === 'all' ? K : K.slice(0, K.indexOf(prog.step) + 1);
                                                // هم‌گام با سرور (۱.۱۳.۰): با سیاست keep مراحل ردشده از فهرست هدف حذف می‌شوند
                                                // تا «تیک دوباره» تلقی نشوند و ردشده بمانند
                                                if (prog.skipped_policy !== 'reset') {
                                                        target = target.filter((k) => (old.excluded || []).indexOf(k) === -1);
                                                }
                                                input = { steps: target, skipped: prog.skipped_policy === 'reset' ? [] : old.excluded, reset_skips: prog.skipped_policy === 'reset' };
                                        } else if (prog.mode === 'add') {
                                                input = { steps: Array.from(new Set(old.steps.concat(prog.steps || []))), skipped: prog.skipped_policy === 'reset' ? [] : old.excluded };
                                        } else if (prog.mode === 'remove') {
                                                input = { steps: old.steps.filter((k) => (prog.steps || []).indexOf(k) === -1), skipped: old.excluded };
                                        } else if (prog.mode === 'clear') {
                                                input = { steps: [], skipped: [], reset_skips: true };
                                        }
                                        if (input) {
                                                const applied = TPP.progressUtil.apply(input, old);
                                                row.progress = TPP.progressUtil.summary(Object.assign(applied, { failures: newFails !== null ? newFails : keepFails }));
                                        } else if (newFails !== null) {
                                                row.progress = TPP.progressUtil.summary(Object.assign({}, row.progress, { failures: newFails }));
                                        }
                                        if (payload.service) {
                                                Object.assign(row, payload.service);
                                        }
                                }
                                await TPP_IDB.set('services', id, row);
                        } catch (e) { /* ردیف در کش نیست */ }
                        // نتیجه جستجوی فعلی هم زنده نوسازی شود
                        const cur = searchState.result;
                        if (cur && Array.isArray(cur.rows)) {
                                const r = cur.rows.find((x) => parseInt(x.id, 10) === id);
                                if (r && r.progress && perId[id]) r.progress = perId[id];
                        }
                }
        }

        async function viewServices() {
                searchState.perPage = parseInt(state.settings.rows_per_page, 10) || 25;

                const addrFields = state.schema.address || [];
                const svcFields = state.schema.service || [];

                els.content.innerHTML = `
                ${TPP.offline.state().online ? '' : '<div class="alert warn" id="offline-banner">📴 حالت آفلاین — نتایج از داده‌های ذخیره‌شده این دستگاه نمایش داده می‌شوند.</div>'}
                <div class="card">
                        <div class="search-bar">
                                <div class="main-search">
                                        <span class="search-ico">🔍</span>
                                        <input type="text" id="live-search" placeholder="جستجو در همه اطلاعات (نام، شماره تلفن، آدرس، سریال مودم، کد پستی…)" value="${esc(searchState.query)}" autocomplete="off">
                                        <span class="search-spinner hidden" id="search-spinner"><span class="spinner"></span></span>
                                </div>
                                <button class="btn" id="toggle-filters">🔎 فیلترها</button>
                                <label class="checkbox-row"><input type="checkbox" id="group-mode" ${searchState.group ? 'checked' : ''}> گروه‌بندی آدرس</label>
                                <div class="sort-box">
                                        <label for="sort-by">↕ مرتب‌سازی:</label>
                                        <select id="sort-by" class="btn" title="ترتیب نمایش نتایج">
                                                <option value="updated" ${searchState.sort === 'updated' ? 'selected' : ''}>آخرین ویرایش</option>
                                                <option value="unit" ${searchState.sort === 'unit' ? 'selected' : ''}>شماره واحد</option>
                                                <option value="block" ${searchState.sort === 'block' ? 'selected' : ''}>نام بلوک / خیابان</option>
                                                <option value="postal" ${searchState.sort === 'postal' ? 'selected' : ''}>کد پستی</option>
                                                <option value="address" ${searchState.sort === 'address' ? 'selected' : ''}>آدرس (حروف کوچک/بزرگ مهم نیست)</option>
                                        </select>
                                        <button class="btn btn-sm" id="sort-dir" title="تغییر ترتیب">${searchState.order === 'asc' ? '↑ صعودی' : '↓ نزولی'}</button>
                                </div>
                                ${can('tpp_create_services') ? '<button class="btn btn-primary" id="btn-new-service">➕ ثبت سرویس جدید</button>' : ''}
                                ${can('tpp_view_services') ? `
                                <span class="search-actions" title="خروجی اکسل و PDF از نتایج جستجوی فعلی — برای همه کاربران فعال است">
                                        <button class="btn" id="btn-export-xlsx" title="خروجی اکسل از نتایج جستجوی فعلی">📤 اکسل</button>
                                        <button class="btn" id="btn-export-pdf" title="فایل PDF آماده دانلود — تولیدشده در خود سایت (فونت فارسی تعبیه‌شده)">📄 PDF سایت</button>
                                        <button class="btn" id="btn-export-print" title="صفحه چاپ در پنجره جدید باز می‌شود — از منوی چاپ مرورگر «Save as PDF» را انتخاب کنید">🖨 PDF مرورگر</button>
                                </span>` : ''}
                        </div>
                        <div class="date-filter" title="جستجو بر حسب زمان آخرین ویرایش سرویس — بازه زمانی یا یک روز خاص">
                                <span class="df-label">🕒 زمان ویرایش:</span>
                                <input type="text" id="upd-from" class="df-input" placeholder="از تاریخ — مثال ۱۴۰۴/۰۶/۰۱" value="${esc(isoToJal(searchState.updFrom))}" autocomplete="off" inputmode="numeric">
                                <span class="df-sep">تا</span>
                                <input type="text" id="upd-to" class="df-input" placeholder="تا تاریخ — مثال ۱۴۰۴/۰۶/۳۱" value="${esc(isoToJal(searchState.updTo))}" autocomplete="off" inputmode="numeric">
                                <button class="btn btn-sm" data-dr="today" title="فقط سرویس‌های ویرایش‌شده امروز">امروز</button>
                                <button class="btn btn-sm" data-dr="yesterday" title="فقط سرویس‌های ویرایش‌شده دیروز">دیروز</button>
                                <button class="btn btn-sm" data-dr="7" title="۷ روز اخیر">۷ روز اخیر</button>
                                <button class="btn btn-sm" data-dr="30" title="۳۰ روز اخیر">۳۰ روز اخیر</button>
                                <button class="btn btn-sm" data-dr="clear" title="حذف فیلتر تاریخ">✕</button>
                                <span class="df-hint">تاریخ شمسی — برای «یک روز خاص» هر دو کادر را یک تاریخ بگذارید؛ خالی بگذارید تا همه نمایش داده شوند.</span>
                        </div>
                        <div class="offline-status" id="offline-status"></div>
                        ${(can('tpp_delete_services') || canQuick()) ? `
                        <div class="bulk-bar">
                                <label class="checkbox-row"><input type="checkbox" id="select-all"> <b>انتخاب همه در این صفحه</b></label>
                                ${canQuick() ? '<button class="btn btn-primary" id="btn-bulk-progress" disabled title="تغییر گروهی/تکی پیشرفت دایری، خرابی و وضعیت سرویس‌های انتخاب‌شده">🚀 تغییر پیشرفت/وضعیت (<span id="sel-count">0</span>)</button>' : '<span class="chip" id="sel-count">0 انتخاب</span>'}
                                ${can('tpp_delete_services') ? '<button class="btn btn-danger" id="btn-bulk-delete" disabled>🗑 حذف انتخاب‌شده‌ها</button>' : ''}
                                <span class="muted">${canQuick() ? 'تغییر گروهی پیشرفت دایری/وضعیت با تیک‌خوردن خودکار مراحل وابسته — یا ⚡ روی هر کارت برای ویرایش سریع همان سرویس.' : ''}${can('tpp_delete_services') ? ' با حذف سرویس، تاریخچه آن هم کامل پاک می‌شود.' : ''}</span>
                        </div>` : ''}
                        <div class="filters-panel hidden" id="filters-panel">
                                <div class="filters-title"><span>فیلتر بر اساس فیلدهای دلخواه:</span><button class="btn btn-sm" id="clear-filters">پاک‌کردن فیلترها</button></div>
                                <div class="prog-filter" id="prog-filter">
                                        <label class="pf-label">🚀 وضعیت دایری سرویس:</label>
                                        <select id="prog-status" class="btn" title="فیلتر سرویس‌ها بر اساس وضعیت پیشرفت دایری یا خرابی اعلام‌شده">
                                                <option value="">همه وضعیت‌ها</option>
                                                <option value="progress">🚧 در حال دایری</option>
                                                <option value="done">✅ دایری کامل</option>
                                                <option value="none">⏳ شروع نشده</option>
                                                <option value="fail">❌ خرابی اعلام‌شده (همه)</option>
                                                <option value="fail_los">❌ LOS — اینترنت و تلفن قطع</option>
                                                <option value="fail_phone">❌ قطع تلفن</option>
                                                <option value="fail_internet">❌ قطع اینترنت</option>
                                                <option value="fail_other">❌ سایر خرابی</option>
                                        </select>
                                        <span id="prog-step-wrap" class="hidden">
                                                <label class="pf-label">مرحله:</label>
                                                <select id="prog-step" class="btn" title="سرویس‌هایی که این مرحله را تکمیل کرده‌اند یا نه">
                                                        <option value="">— همه —</option>
                                                </select>
                                                <label class="checkbox-row" title="سرویس‌هایی که مرحله انتخابی را انجام داده‌اند"><input type="radio" name="prog-step-state" value="done" checked> انجام‌شده</label>
                                                <label class="checkbox-row" title="سرویس‌هایی که مرحله انتخابی هنوز کامل نشده (کار باقی‌مانده)"><input type="radio" name="prog-step-state" value="todo"> انجام‌نشده</label>
                                        </span>
                                </div>
                                <div class="filters-grid" id="filters-grid"></div>
                                <div class="prog-filter" id="cat-filter" style="margin-top:10px">
                                        <label class="pf-label">🏷 دسته‌بندی پروژه:</label>
                                        <select id="cat-filter-sel" class="btn" title="فیلتر سرویس‌ها بر اساس دسته‌بندی پروژه">
                                                <option value="">همه دسته‌ها</option>
                                        </select>
                                        <span class="pf-label" style="margin-right:8px">تگ‌ها:</span>
                                        <span class="tag-chips" id="tag-filter-box"><span class="muted">…</span></span>
                                </div>
                                <div class="filters-actions">
                                        <button class="btn btn-primary" id="btn-manual-search" title="اجرای اجباری جستجو با فیلترها و متن‌های تایپ‌شده">🔍 جستجوی دستی</button>
                                        <span class="muted">اگر نتیجه‌ها خودکار به‌روز نشد، این دکمه جستجو را اجباری اجرا می‌کند؛ متن تایپ‌شده در فیلترها همان‌طور که هست اعمال می‌شود (آنلاین: جستجو در کل پایگاه داده — آفلاین: جستجو در همه سرویس‌های ذخیره‌شده این دستگاه).</span>
                                </div>
                        </div>
                </div>
                <div id="results-area"><div class="loading-block"><div class="spinner"></div><p>در حال جستجو…</p></div></div>`;

                // ساخت فیلترهای اختصاصی — کشویی جستجوی اجاکسی (۱.۹.۰، جستجوی سراسری ۱.۹.۱):
                // همزمان با تایپ، عبارت در کل پایگاه داده (سرور) جستجو می‌شود و لیست بازشو با
                // نتایج سراسری به‌روز می‌شود؛ عبارت تایپ‌شده حتی اگر در لیست نبود هم قابل اعمال است.
                // روی موبایل هم کامل کار می‌کند (لمس، اسکرول داخلی لیست، جای‌گذاری هوشمند بر اساس فضای صفحه).
                const grid = document.getElementById('filters-grid');
                const filterFields = addrFields.concat(svcFields).filter((f) => f.is_searchable);
                grid.innerHTML = filterFields.map((f) => `
                        <div class="field" style="margin:0">
                                <label>${esc(f.label)}</label>
                                <div class="tpp-combo" data-filter="${esc(f.slug)}">
                                        <input type="text" class="combo-input" value="${esc(searchState.filters[f.slug] || '')}" placeholder="انتخاب ${esc(f.label)}…" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list">
                                        <button type="button" class="combo-clear${searchState.filters[f.slug] ? '' : ' hidden'}" tabindex="-1" title="حذف این فیلتر">✕</button>
                                        <div class="combo-list hidden" role="listbox"></div>
                                </div>
                        </div>`).join('');

                /* ۱.۱۹.۰ — فیلتر دسته‌بندی/تگ‌ها (مثل فیلتر دایری) */
                const catSel = document.getElementById('cat-filter-sel');
                const tagBox = document.getElementById('tag-filter-box');
                if (catSel && tagBox) {
                        const applyCat = () => { searchState.cat = catSel.value; searchState.page = 1; runSearch(); };
                        catSel.addEventListener('change', applyCat);
                        const renderCatFilter = (cats) => {
                                if (!cats) { tagBox.innerHTML = '<span class="muted">دسته‌بندی‌ای تعریف نشده — مدیر کل از «دسته‌بندی پروژه‌ها» تعریف کند.</span>'; return; }
                                catSel.innerHTML = '<option value="">همه دسته‌ها</option>' + (cats.categories || []).map((c) =>
                                        '<option value="' + esc(String(c.id)) + '"' + (String(c.id) === String(searchState.cat) ? ' selected' : '') + '>' + esc(c.label) + '</option>').join('');
                                tagBox.innerHTML = (cats.tags || []).map((t) =>
                                        '<label class="chip tag-chip' + (searchState.tags.indexOf(t.id) !== -1 ? ' on' : '') + '"><input type="checkbox" value="' + esc(String(t.id)) + '"' + (searchState.tags.indexOf(t.id) !== -1 ? ' checked' : '') + '> ' + esc(t.label) + '</label>').join('') || '<span class="muted">تگی تعریف نشده</span>';
                                tagBox.querySelectorAll('input[type=checkbox]').forEach((i) => i.addEventListener('change', () => {
                                        const v = parseInt(i.value, 10);
                                        const idx = searchState.tags.indexOf(v);
                                        if (i.checked && idx === -1) searchState.tags.push(v);
                                        if (!i.checked && idx !== -1) searchState.tags.splice(idx, 1);
                                        i.closest('.tag-chip').classList.toggle('on', i.checked);
                                        searchState.page = 1;
                                        runSearch();
                                }));
                        };
                        if (state.cats) renderCatFilter(state.cats);
                        else TPP.api.request('GET', 'categories').then((c) => { state.cats = c; renderCatFilter(c); }).catch(() => renderCatFilter(null));
                }

                const input = document.getElementById('live-search');
                const spinner = document.getElementById('search-spinner');

                /* ---------- ۱.۱۲.۰: فیلتر وضعیت دایری (نوع + مرحله خاص) ---------- */
                const progStatusSel = document.getElementById('prog-status');
                const progStepSel = document.getElementById('prog-step');
                const progStepWrap = document.getElementById('prog-step-wrap');
                const stepsCatalog = progressSteps();
                if (progStepSel && Object.keys(stepsCatalog).length) {
                        progStepWrap.classList.remove('hidden');
                        progStepSel.innerHTML = '<option value="">— همه —</option>' + Object.entries(stepsCatalog).map(([k, v]) =>
                                `<option value="${esc(k)}"${searchState.prog.step === k ? ' selected' : ''}>${esc(v)}</option>`).join('');
                        if (searchState.prog.stepState === 'todo') {
                                const r = progStepWrap.querySelector('input[name="prog-step-state"][value="todo"]');
                                if (r) r.checked = true;
                        }
                }
                if (progStatusSel) {
                        progStatusSel.value = searchState.prog.status || '';
                        const applyProg = () => {
                                searchState.prog.status = progStatusSel.value;
                                searchState.prog.step = progStepSel ? progStepSel.value : '';
                                const todo = document.querySelector('input[name="prog-step-state"]:checked');
                                searchState.prog.stepState = todo ? todo.value : 'done';
                                searchState.page = 1; runSearch();
                        };
                        progStatusSel.addEventListener('change', applyProg);
                        if (progStepSel) progStepSel.addEventListener('change', applyProg);
                        progStepWrap.querySelectorAll('input[name="prog-step-state"]').forEach((r) => r.addEventListener('change', () => { if (progStepSel.value) applyProg(); }));
                }

                const runSearch = debounce(async () => {
                        spinner.classList.remove('hidden');
                        try { await doSearch(); } finally { spinner.classList.add('hidden'); }
                }, 280);

                input.addEventListener('input', () => { searchState.query = input.value; searchState.page = 1; runSearch(); });

                /* ---------- کامبوباکس فیلترها: بارگذاری اجاکسی مقادیر + جستجوی همزمان با تایپ ---------- */

                const comboValuesCache = {}; // slug → فهرست کامل مقادیر (q خالی) برای این رندر صفحه

                /**
                 * مقادیر فیلد برای کشویی فیلترها (۱.۹.۱ / آفلاین ۱.۹.۲):
                 * - q خالی → فهرست کامل مقادیر یکتا (کش‌شده بعد از اولین دریافت)
                 * - q پر → جستجوی سراسری روی کل پایگاه داده در سرور (LIKE %q%)
                 * - آفلاین/خطای شبکه (۱.۹.۲) → جستجوی q روی «کل سرویس‌های ذخیره‌شده این دستگاه»
                 *   با نرمال‌سازی ارقام فارسی — همان تجربه جستجوی سراسری، روی داده محلی
                 */
                async function comboFetch(slug, q) {
                        q = String(q == null ? '' : q).trim();
                        if (!q && comboValuesCache[slug]) return comboValuesCache[slug];
                        if (TPP.offline.state().online) {
                                try {
                                        const params = { field: slug, limit: 200 };
                                        if (q) params.q = q;
                                        const res = await TPP.api.request('GET', 'values', null, params);
                                        const values = (res && res.values) || [];
                                        if (!q) comboValuesCache[slug] = values;
                                        return values;
                                } catch (e) { /* خطای شبکه/دسترسی → ادامه با داده محلی */ }
                        }
                        // آفلاین یا خطا → مقادیر یکتا از کل سرویس‌های ذخیره‌شده روی همین دستگاه (۱.۹.۲)
                        let values = [];
                        try { values = await TPP.offline.valuesLocal(slug, q, 200); } catch (e) { /* بدون داده محلی */ }
                        if (!q) comboValuesCache[slug] = values;
                        return values;
                }

                const combos = [];
                grid.querySelectorAll('.tpp-combo').forEach((box) => combos.push(makeCombo(box)));

                function makeCombo(box) {
                        const slug = box.getAttribute('data-filter');
                        const inp = box.querySelector('.combo-input');
                        const list = box.querySelector('.combo-list');
                        const clearBtn = box.querySelector('.combo-clear');
                        let items = [];
                        let loaded = false;
                        let open = false;
                        let active = -1;
                        let committed = String(searchState.filters[slug] || ''); // آخرین مقدار اعمال‌شده
                        let searchSeq = 0;        // شماره درخواست — پاسخ‌های قدیمی کنار گذاشته می‌شوند
                        let serverSearching = false; // جستجوی سراسری در جریان است؟
                        let searchTimer = null;    // تأخیر جستجوی سرور (debounce)

                        const applyValue = (val) => {
                                committed = String(val == null ? '' : val);
                                inp.value = committed;
                                if (committed) searchState.filters[slug] = committed;
                                else delete searchState.filters[slug];
                                clearBtn.classList.toggle('hidden', !committed);
                                searchState.page = 1;
                                runSearch();
                        };

                        /** ثبت بی‌صدای متنِ فعلی ورودی به‌عنوان مقدار فیلتر (بدون اجرای جستجو) — برای دکمه جستجوی دستی (۱.۹.۱) */
                        const commitTyped = () => {
                                const t = inp.value.trim();
                                if (t === committed) return false;
                                committed = t;
                                inp.value = t;
                                if (t) searchState.filters[slug] = t;
                                else delete searchState.filters[slug];
                                clearBtn.classList.toggle('hidden', !t);
                                searchState.page = 1;
                                return true;
                        };

                        const visibleItems = () => {
                                const q = inp.value.trim().toLowerCase();
                                if (!q) return items;
                                // ۱.۹.۲ آفلاین: تطبیق با نرمال‌سازی ارقام/حروف فارسی (هماهنگ با جستجوی محلی)
                                if (!TPP.offline.state().online) {
                                        const qn = TPP.offline.norm(q);
                                        return items.filter((v) => TPP.offline.norm(v).indexOf(qn) !== -1);
                                }
                                return items.filter((v) => String(v).toLowerCase().indexOf(q) !== -1);
                        };

                        /* ۱.۹.۱ — جستجوی سراسری: با تایپ، عبارت به سرور می‌رود و روی کل پایگاه
                         * داده جستجو می‌شود (نه فقط مقادیر لودشده). پاسخ‌های قدیمی با seq کنار می‌روند. */
                        const runServerSearch = async () => {
                                searchTimer = null;
                                if (!document.contains(box) || !open) return;
                                const q = inp.value.trim();
                                const seq = ++searchSeq;
                                try {
                                        const values = await comboFetch(slug, q);
                                        if (seq !== searchSeq || !document.contains(box)) return; // پاسخ قدیمی
                                        items = values;
                                        loaded = true;
                                } finally {
                                        if (seq === searchSeq) {
                                                serverSearching = false;
                                                if (document.contains(box)) { renderList(); positionList(); }
                                        }
                                }
                        };

                        const scheduleServerSearch = () => {
                                // ۱.۹.۲: آنلاین → جستجوی سراسری سرور؛ آفلاین → جستجو در کل داده ذخیره‌شده دستگاه
                                if (searchTimer) clearTimeout(searchTimer);
                                searchTimer = setTimeout(runServerSearch, TPP.offline.state().online ? 300 : 250);
                        };

                        const renderList = () => {
                                if (!loaded) {
                                        list.innerHTML = '<div class="combo-empty">در حال دریافت مقادیر…</div>';
                                        return;
                                }
                                const isOffline = !TPP.offline.state().online;
                                const rows = visibleItems();
                                if (!rows.length) {
                                        const typed = inp.value.trim();
                                        if (serverSearching) {
                                                list.innerHTML = '<div class="combo-empty">' + (isOffline ? '🔎 در حال جستجو در داده‌های ذخیره‌شده این دستگاه…' : '🔎 در حال جستجو در کل پایگاه داده…') + '</div>';
                                                return;
                                        }
                                        if (typed) {
                                                // اعمال عبارت تایپ‌شده — سرور با LIKE و جستجوی آفلاین با تطبیق نرمال‌شده، عبارت را می‌گیرد
                                                list.innerHTML = '<div class="combo-item combo-use-typed" role="option" data-val="' + esc(typed) + '">اعمال «' + esc(typed) + '» به‌عنوان فیلتر جستجو</div>';
                                                return;
                                        }
                                        list.innerHTML = '<div class="combo-empty">موردی یافت نشد — عبارت دیگری بنویسید.</div>';
                                        return;
                                }
                                if (active >= rows.length) active = rows.length - 1;
                                // ۱.۹.۲: در حالت آفلاین، منبع نتایج شفاف اعلام شود
                                const offlineNote = isOffline ? '<div class="combo-offline-note">📴 جستجوی آفلاین — نتایج از کل سرویس‌های ذخیره‌شده این دستگاه</div>' : '';
                                list.innerHTML = offlineNote + rows.slice(0, 150).map((v, i) =>
                                        `<div class="combo-item${i === active ? ' active' : ''}" role="option" data-val="${esc(v)}">${esc(v)}</div>`
                                ).join('') + (rows.length > 150 ? `<div class="combo-empty">… ${rows.length - 150} مورد دیگر — برای محدود کردن، تایپ کنید</div>` : '');
                        };

                        const positionList = () => {
                                // موبایل: سقف ارتفاع لیست بر اساس فضای واقعی صفحه؛ اگر پایین جا نبود، بالا باز شود
                                list.classList.remove('above');
                                const r = box.getBoundingClientRect();
                                const below = window.innerHeight - r.bottom - 14;
                                const above = r.top - 14;
                                list.style.maxHeight = Math.max(120, Math.min(260, Math.max(below, above))) + 'px';
                                if (below < 150 && above > below) list.classList.add('above');
                        };

                        const openList = async () => {
                                positionList();
                                if (open) { renderList(); return; }
                                open = true;
                                box.classList.add('open');
                                inp.setAttribute('aria-expanded', 'true');
                                list.classList.remove('hidden');
                                renderList();
                                if (!loaded) {
                                        items = await comboFetch(slug, '');
                                        loaded = true;
                                        positionList(); // بعد از دریافت مقادیر دوباره جای‌گذاری شود
                                        renderList();
                                } else if (!inp.value.trim() && comboValuesCache[slug]) {
                                        // بازگشت به فهرست کامل وقتی متن پاک شده و قبلاً با q فیلتر شده بودیم
                                        items = comboValuesCache[slug];
                                        renderList();
                                }
                        };

                        const closeList = (revert) => {
                                if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
                                searchSeq++;          // پاسخ‌های سرورِ در جریان باطل شوند
                                serverSearching = false;
                                if (!open) return;
                                open = false;
                                active = -1;
                                box.classList.remove('open');
                                inp.setAttribute('aria-expanded', 'false');
                                list.classList.add('hidden');
                                /* ۱.۹.۱: متن تایپ‌شدهٔ اعمال‌نشده —
                                 * - Escape → به آخرین مقدار اعمال‌شده برمی‌گردد (لغو)
                                 * - کلیک/لمس بیرون → همان متن به‌عنوان فیلتر ثبت و جستجو اجرا می‌شود
                                 *   (تایپ = جستجو؛ پاک‌کردن متن = پاک‌کردن فیلتر) */
                                const typed = inp.value.trim();
                                if (revert || typed === committed || !document.contains(box)) {
                                        inp.value = committed;
                                } else {
                                        commitTyped();
                                        runSearch();
                                }
                        };

                        inp.addEventListener('focus', () => { openList(); });
                        inp.addEventListener('click', (e) => { e.stopPropagation(); openList(); }); // لمس موبایل
                        inp.addEventListener('input', () => {
                                active = -1;
                                openList();
                                serverSearching = true; // تا رسیدن پاسخ، به‌جای «یافت نشد» نشانگر جستجو دیده شود
                                renderList();
                                scheduleServerSearch(); // ۱.۹.۲: آنلاین → جستجوی سراسری سرور / آفلاین → کل داده ذخیره‌شده دستگاه
                        });
                        inp.addEventListener('keydown', (e) => {
                                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                                        e.preventDefault();
                                        if (!open) { openList(); return; }
                                        const rows = visibleItems();
                                        if (!rows.length) return;
                                        active = e.key === 'ArrowDown' ? Math.min(active + 1, rows.length - 1) : Math.max(active - 1, 0);
                                        renderList();
                                        const el = list.querySelector('.combo-item.active');
                                        if (el) el.scrollIntoView({ block: 'nearest' });
                                } else if (e.key === 'Enter') {
                                        e.preventDefault();
                                        const rows = visibleItems();
                                        const pick = (active >= 0 && rows[active]) ? rows[active] : rows.find((v) => String(v).toLowerCase() === inp.value.trim().toLowerCase());
                                        if (pick) { applyValue(pick); closeList(); }
                                        else if (inp.value.trim()) {
                                                // ۱.۹.۱: عبارت آزاد هم فیلتر می‌شود — سرور با LIKE در کل دیتابیس جستجو می‌کند
                                                applyValue(inp.value.trim());
                                                closeList();
                                        }
                                } else if (e.key === 'Escape') {
                                        closeList(true); // بازگشت متن به مقدار قبلی — لغو تغییرات
                                }
                        });
                        // انتخاب آیتم با لمس/کلیک — pointerdown تا فوکوس ورودی (و متنش) از بین نرود
                        list.addEventListener('pointerdown', (e) => {
                                const item = e.target.closest ? e.target.closest('.combo-item') : null;
                                if (!item) return;
                                e.preventDefault();
                                applyValue(item.getAttribute('data-val'));
                                closeList();
                        });
                        clearBtn.addEventListener('click', () => { applyValue(''); inp.focus(); });

                        // بستن با لمس/کلیک بیرون + پاک‌سازی خودکار شنونده وقتی این نما از DOM حذف شد
                        const docClose = (e) => {
                                if (!document.contains(box)) {
                                        document.removeEventListener('pointerdown', docClose);
                                        window.removeEventListener('resize', onResize);
                                        return;
                                }
                                if (box.contains(e.target)) return;
                                // کلیک روی «جستجوی دستی» نوبت دکمه است — لیست همان‌جا بسته می‌شود (بدون جستجوی دوباره)
                                if (e.target.closest && e.target.closest('#btn-manual-search')) return;
                                closeList(); // ثبت متن تایپ‌شده + جستجو
                        };
                        const onResize = () => { if (open) positionList(); };
                        document.addEventListener('pointerdown', docClose);
                        window.addEventListener('resize', onResize);
                        // کیبورد: خروج فوکوس از کامبو (Tab) هم مثل کلیک بیرون رفتار می‌کند
                        inp.addEventListener('blur', () => {
                                setTimeout(() => {
                                        if (open && document.activeElement && !box.contains(document.activeElement)) closeList();
                                }, 120);
                        });

                        return {
                                reset: () => { committed = ''; inp.value = ''; clearBtn.classList.add('hidden'); closeList(); },
                                close: () => { closeList(); },
                                commitTyped: () => commitTyped()
                        };
                }

                document.getElementById('toggle-filters').addEventListener('click', () => document.getElementById('filters-panel').classList.toggle('hidden'));

                /* ۱.۹.۱ — جستجوی دستی: بدون انتظار برای تأخیر خودکار، جستجو اجباری اجرا می‌شود.
                 * متن تایپ‌شده در کادر جستجوی اصلی و متن‌های تایپ‌شدهٔ کامبوباکس‌ها (حتی بدون انتخاب از لیست)
                 * همان‌طور که هستند اعمال می‌شوند و بعد جستجوی سراسری اجرا می‌شود. */
                const manualBtn = document.getElementById('btn-manual-search');
                if (manualBtn) manualBtn.addEventListener('click', async () => {
                        const live = document.getElementById('live-search');
                        if (live && live.value !== searchState.query) searchState.query = live.value;
                        combos.forEach((c) => c.commitTyped());
                        searchState.page = 1;
                        combos.forEach((c) => c.close());
                        manualBtn.disabled = true;
                        spinner.classList.remove('hidden');
                        try { await doSearch(); } finally { spinner.classList.add('hidden'); manualBtn.disabled = false; }
                });

                document.getElementById('clear-filters').addEventListener('click', () => {
                        searchState.filters = {};
                        searchState.prog = { status: '', step: '', stepState: 'done' }; // ۱.۱۲.۰ — فیلتر دایری هم پاک شود
                        const ps = document.getElementById('prog-status');
                        if (ps) ps.value = '';
                        const pst = document.getElementById('prog-step');
                        if (pst) pst.value = '';
                        const done = document.querySelector('input[name="prog-step-state"][value="done"]');
                        if (done) done.checked = true;
                        combos.forEach((c) => c.reset());
                        searchState.page = 1; runSearch();
                });
                document.getElementById('group-mode').addEventListener('change', (e) => { searchState.group = e.target.checked; searchState.page = 1; runSearch(); });
                document.getElementById('sort-by').addEventListener('change', (e) => { searchState.sort = e.target.value; searchState.page = 1; runSearch(); });
                document.getElementById('sort-dir').addEventListener('click', () => {
                        searchState.order = searchState.order === 'asc' ? 'desc' : 'asc';
                        document.getElementById('sort-dir').textContent = searchState.order === 'asc' ? '↑ صعودی' : '↓ نزولی';
                        searchState.page = 1; runSearch();
                });

                /* ---- فیلتر بازه زمانی ویرایش (تاریخ شمسی) ---- */
                const updFromEl = document.getElementById('upd-from');
                const updToEl = document.getElementById('upd-to');
                const applyDateFilter = (markBad) => {
                        const f = jalToIso(updFromEl.value);
                        const t = jalToIso(updToEl.value);
                        // کادر پر ولی نامعتبر → علامت خطا؛ کادر خالی → بدون فیلتر
                        updFromEl.classList.toggle('bad', markBad && updFromEl.value.trim() !== '' && !f);
                        updToEl.classList.toggle('bad', markBad && updToEl.value.trim() !== '' && !t);
                        if (updFromEl.value.trim() !== '' && !f) return;      // تاریخ نامعتبر → جستجو اجرا نشود
                        if (updToEl.value.trim() !== '' && !t) return;
                        searchState.updFrom = f || '';
                        searchState.updTo = t || '';
                        if (searchState.updFrom && searchState.updTo && searchState.updFrom > searchState.updTo) {
                                // ترتیب برعکس → جابه‌جا (مثل سرور)
                                const tmp = searchState.updFrom; searchState.updFrom = searchState.updTo; searchState.updTo = tmp;
                                updFromEl.value = isoToJal(searchState.updFrom);
                                updToEl.value = isoToJal(searchState.updTo);
                        }
                        searchState.page = 1;
                        runSearch();
                };
                updFromEl.addEventListener('input', debounce(() => applyDateFilter(true), 500));
                updToEl.addEventListener('input', debounce(() => applyDateFilter(true), 500));
                document.querySelectorAll('button[data-dr]').forEach((b) => {
                        b.addEventListener('click', () => {
                                const kind = b.getAttribute('data-dr');
                                if (kind === 'clear') {
                                        updFromEl.value = ''; updToEl.value = '';
                                        searchState.updFrom = ''; searchState.updTo = '';
                                        searchState.page = 1; runSearch();
                                        return;
                                }
                                const today = tehranTodayIso(); // ۱.۱۸.۰ — «امروز» به وقت تهران
                                let from, to;
                                if (kind === 'today') { from = today; to = today; }
                                else if (kind === 'yesterday') {
                                        const y = isoShift(today, -1);
                                        from = y; to = y;
                                } else {
                                        const days = parseInt(kind, 10);
                                        from = isoShift(today, -(days - 1)); to = today;
                                }
                                searchState.updFrom = from;
                                searchState.updTo = to;
                                updFromEl.value = isoToJal(searchState.updFrom);
                                updToEl.value = isoToJal(searchState.updTo);
                                searchState.page = 1; runSearch();
                        });
                });
                const newBtn = document.getElementById('btn-new-service');
                if (newBtn) newBtn.addEventListener('click', () => go('service/new'));

                // انتخاب همه در صفحه + حذف گروهی
                const selectAll = document.getElementById('select-all');
                if (selectAll) selectAll.addEventListener('change', (e) => {
                        const checked = e.target.checked;
                        document.querySelectorAll('#results-area input[data-sel]').forEach((cb) => {
                                if (!cb.disabled) { cb.checked = checked; syncSel(cb.getAttribute('data-sel'), checked); }
                        });
                        updateBulkBar();
                });
                const bulkBtn = document.getElementById('btn-bulk-delete');
                if (bulkBtn) bulkBtn.addEventListener('click', async () => {
                        const ids = Array.from(searchState.selected).filter((id) => typeof id === 'number');
                        if (!ids.length) return;
                        if (!await confirmBox(`حذف <b>${ids.length}</b> سرویس انتخاب‌شده؟<br><span class="muted">تاریخچه هر سرویس هم به‌صورت کامل حذف می‌شود و این عمل قابل بازگشت نیست.</span>`, 'حذف قطعی')) return;
                        bulkBtn.disabled = true;
                        for (const id of ids) {
                                await TPP.offline.enqueue('service.delete', { id });
                        }
                        searchState.selected.clear();
                        toast(ids.length + ' سرویس حذف شد.', 'success');
                        await doSearch();
                });
                /* ۱.۱۳.۰ — تغییر گروهی پیشرفت/وضعیت سرویس‌های انتخاب‌شده */
                const bulkProgBtn = document.getElementById('btn-bulk-progress');
                if (bulkProgBtn) bulkProgBtn.addEventListener('click', () => {
                        const ids = Array.from(searchState.selected).filter((id) => typeof id === 'number');
                        if (!ids.length) return;
                        openBulkEdit(ids, false);
                });

                // خروجی اکسل/PDF از نتایج جستجوی فعلی (نیازمند اتصال)
                /* ۱.۲۱.۰ — رفع باگ «خروجی همیشه کل سرویس‌ها»: پارامترهای خروجی با یک تابع مشترک
                 * از searchState جمع می‌شود — دقیقاً همان فیلترهایی که doSearch می‌فرستد
                 * (متن جستجو + فیلتر فیلدها + بازه ویرایش + وضعیت دایری + دسته/تگ). */
                const collectSearchParams = () => {
                        const params = {};
                        if (searchState.query) params.query = searchState.query;
                        if (Object.keys(searchState.filters || {}).length) params.filters = JSON.stringify(searchState.filters);
                        if (searchState.updFrom) params.upd_from = searchState.updFrom;
                        if (searchState.updTo) params.upd_to = searchState.updTo;
                        /* ۱.۱۲.۰ — فیلتر وضعیت دایری هم در خروجی اعمال می‌شود */
                        if (searchState.prog && searchState.prog.status) params.progress_status = searchState.prog.status;
                        if (searchState.prog && searchState.prog.step) {
                                params.progress_step = searchState.prog.step;
                                params.progress_step_state = searchState.prog.stepState || 'done';
                        }
                        /* ۱.۱۹.۰ — فیلتر دسته‌بندی/تگ (۱.۲۱.۰: در خروجی هم اعمال می‌شود) */
                        if (searchState.cat) params.category = searchState.cat;
                        if (searchState.tags && searchState.tags.length) params.tags = searchState.tags.join(',');
                        return params;
                };
                const exportNow = async (kind, fname) => {
                        if (!TPP.offline.state().online) {
                                toast('خروجی‌گیری به اتصال اینترنت نیاز دارد.', 'warn');
                                return;
                        }
                        const params = collectSearchParams();
                        toast('در حال ساخت فایل ' + (kind === 'pdf' ? 'PDF' : 'اکسل') + '…');
                        try { await TPP.api.download('export/' + kind, params, fname); }
                        catch (e) { toast('خطا در تولید خروجی: ' + esc(e.message), 'error', 7000); }
                };
                const printNow = async () => {
                        if (!TPP.offline.state().online) {
                                toast('خروجی چاپ به اتصال اینترنت نیاز دارد.', 'warn');
                                return;
                        }
                        const params = collectSearchParams();
                        toast('در حال آماده‌سازی صفحه چاپ…');
                        try { await TPP.api.openHtml('export/print', params); }
                        catch (e) { toast('خطا در آماده‌سازی چاپ: ' + esc(e.message), 'error', 7000); }
                };
                const xlsxBtn = document.getElementById('btn-export-xlsx');
                if (xlsxBtn) xlsxBtn.addEventListener('click', () => exportNow('xlsx', 'tpp-services.xlsx'));
                const pdfBtn = document.getElementById('btn-export-pdf');
                if (pdfBtn) pdfBtn.addEventListener('click', () => exportNow('pdf', 'tpp-report.pdf'));
                const printBtn = document.getElementById('btn-export-print');
                if (printBtn) printBtn.addEventListener('click', printNow);

                await doSearch();
                updateOfflineStatus();
                // ۱.۹.۲: آنلاینیم و کش آفلاین ناقص/خالی/کهنه است → همگام‌سازی کامل در پس‌زمینه
                if (TPP.offline.state().online) {
                        const info = offlineCacheInfo();
                        const age = info && info.last ? (Date.now() - new Date(info.last).getTime()) : Infinity;
                        if (!info || !info.n || !info.complete || age > 10 * 60 * 1000) TPP.offline.cacheAll().catch(() => {});
                }
        }

        async function doSearch() {
                const area = document.getElementById('results-area');
                if (!area) return;

                let data;
                const online = TPP.offline.state().online;

                if (online) {
                        if (searchAbort) searchAbort.abort();
                        searchAbort = new AbortController();
                        try {
                                const params = {
                                        query: searchState.query,
                                        page: searchState.page,
                                        per_page: searchState.perPage,
                                        group: searchState.group ? 1 : 0,
                                        sort: searchState.sort,
                                        order: searchState.order === 'asc' ? 'ASC' : 'DESC'
                                };
                                if (Object.keys(searchState.filters).length) params.filters = JSON.stringify(searchState.filters);
                                if (searchState.updFrom) params.upd_from = searchState.updFrom;
                                if (searchState.updTo) params.upd_to = searchState.updTo;
                                /* ۱.۱۲.۰ — فیلتر وضعیت دایری */
                                if (searchState.prog.status) params.progress_status = searchState.prog.status;
                                if (searchState.prog.step) {
                                        params.progress_step = searchState.prog.step;
                                        params.progress_step_state = searchState.prog.stepState || 'done';
                                }
                                /* ۱.۱۹.۰ — فیلتر دسته‌بندی/تگ */
                                if (searchState.cat) params.category = searchState.cat;
                                if (searchState.tags.length) params.tags = searchState.tags.join(',');
                                data = await TPP.api.request('GET', 'search', null, params, { signal: searchAbort.signal });
                        } catch (e) {
                                if (e.name === 'AbortError') return;
                                // خطای شبکه → جستجوی محلی
                                data = await localSearchFallback();
                        }
                } else {
                        data = await localSearchFallback();
                }

                searchState.result = data;
                renderResults(area, data);
        }

        async function localSearchFallback() {
                const rows = await TPP.offline.searchLocal(searchState.query, searchState.filters, state.schema, searchState.sort, searchState.order, { from: searchState.updFrom, to: searchState.updTo, prog: searchState.prog });
                // ۱.۹.۲: اگر صفحه فعلی خارج از محدوده نتایج محلی است (مثلاً بعد از قطع اینترنت)، به صفحه اول برگرد
                if (searchState.page > 1 && (searchState.page - 1) * searchState.perPage >= rows.length) searchState.page = 1;
                const start = (searchState.page - 1) * searchState.perPage;
                if (searchState.group) {
                        const byAddr = {};
                        rows.forEach((r) => {
                                const key = r.address_id || (r.address && r.address.id) || 'n';
                                byAddr[key] = byAddr[key] || { address: r.address || {}, services: [] };
                                byAddr[key].services.push(r);
                        });
                        return { total: Object.keys(byAddr).length, grouped: Object.values(byAddr) };
                }
                return { total: rows.length, rows: rows.slice(start, start + searchState.perPage) };
        }

        function renderResults(area, data) {
                const addrFields = state.schema.address || [];
                const svcFields = state.schema.service || [];
                const total = parseInt(data.total, 10) || 0;
                const pages = Math.max(1, Math.ceil(total / searchState.perPage));

                if (searchState.group && data.grouped) {
                        if (!data.grouped.length) { area.innerHTML = emptyHtml(); return; }
                        area.innerHTML = data.grouped.map((g) => `
                                <div class="addr-card">
                                        <div class="addr-head" data-address="${g.address ? g.address.id : 0}">
                                                <div>
                                                        <div class="addr-title">${esc(addrFields.map((f) => (g.address ? g.address[f.slug] : '')).filter(Boolean).join('، ') || 'آدرس بدون مشخصات')}</div>
                                                        <div class="addr-sub">${g.services.length} سرویس — آخرین بروزرسانی: ${fmtDate(g.services[0] && g.services[0].updated_at)}</div>
                                                </div>
                                                <span class="chip">${g.count || g.services.length} سرویس</span>
                                        </div>
                                        <div class="addr-body">${servicesCards(g.services, addrFields, svcFields)}</div>
                                </div>`).join('') + paginationHtml(pages, total);
                } else {
                        const rows = data.rows || [];
                        if (!rows.length) { area.innerHTML = emptyHtml(); return; }
                        // چیدمان کارتی پاسخ‌گو در همه اندازه‌ها — بدون اسکرول افقی
                        area.innerHTML = servicesCards(rows, addrFields, svcFields) + paginationHtml(pages, total);
                }

                bindResultEvents(area);
                updateBulkBar();
        }

        /**
         * چیدمان کارتی پاسخ‌گو (دسکتاپ و موبایل) — بدون جدول و بدون اسکرول افقی.
         * کلیک/لمس روی هر مقدار = کپی؛ کارت شامل چک‌باکس انتخاب و دکمه حذف تکی (با دسترسی) است.
         */
        /** نمایش مقدار فیلدهای حالت‌دار (SBC / StandBy Proxy): AUTO → «خودکار (یارا)» */
        const MODE_SLUGS = ['f_sbc', 'f_standby_proxy'];
        function displayVal(slug, val) {
                const v = String(val == null ? '' : val);
                if (MODE_SLUGS.indexOf(slug) !== -1 && 'AUTO' === v.toUpperCase()) return 'خودکار (یارا)';
                return v;
        }

        function servicesCards(rows, addrFields, svcFields) {
                const cols = addrFields.concat(svcFields).slice(0, 12);
                const canDelete = can('tpp_delete_services');
                const canQ = canQuick(); // ۱.۱۳.۱ — ⚡ ویرایش سریع/🚀 تغییر گروهی: قابلیت مستقل (پیش‌فرض: فقط مدیر کل)
                const canSel = canDelete || canQ; // چک‌باکس انتخاب برای حذف گروهی یا تغییر گروهی پیشرفت
                return '<div class="svc-cards">' + rows.map((r) => {
                        const isTmp = typeof r.id === 'string' && r.id.indexOf('tmp_') === 0;
                        const svcId = isTmp ? 'new' : r.id;
                        const fieldsHtml = cols.map((f) => {
                                const isAddr = addrFields.indexOf(f) !== -1;
                                const val = isAddr ? (r.address ? r.address[f.slug] : '') : r[f.slug];
                                const raw = isAddr ? val : displayVal(f.slug, val);
                                const shown = f.is_sensitive ? (raw ? '••••' : '') : raw;
                                if (shown === '' || shown === null || shown === undefined) return '';
                                return `<div class="svc-field" data-copy="${esc(shown)}" title="کلیک = کپی">
                                        <span class="lbl">${esc(f.label)}</span>
                                        <span class="val">${esc(shown)}</span>
                                </div>`;
                        }).join('');
                        return `<div class="svc-card${searchState.selected.has(r.id) ? ' selected' : ''}${r && r.progress && ((r.progress.failures && r.progress.failures.length) || r.progress.failure) ? ' has-failure' : ''}">
                                <div class="svc-card-head">
                                        ${canSel ? `<input type="checkbox" class="svc-sel" data-sel="${esc(String(r.id))}" ${searchState.selected.has(r.id) ? 'checked' : ''} ${isTmp ? 'disabled title="در صف همگام‌سازی — پس از ثبت نهایی قابل انتخاب است"' : ''} title="انتخاب برای عملیات گروهی">` : ''}
                                        <span class="svc-id">#${esc(r.id)}</span>
                                        ${r._pending ? '<span class="chip warn">در صف همگام‌سازی</span>' : (r._dirty ? '<span class="chip warn">ذخیره محلی</span>' : '<span class="chip ok">ثبت‌شده</span>')}
                                        ${progressBadgeHtml(r)}
                                        ${(r.category && r.category.label) ? '<span class="chip cat-chip" title="دسته‌بندی پروژه">🏷 ' + esc(r.category.label) + '</span>' : ''}
                                        ${(Array.isArray(r.tags) && r.tags.length) ? r.tags.map((t) => '<span class="chip tag-chip on">🏷 ' + esc(t.label) + '</span>').join('') : ''}
                                </div>
                                <div class="svc-card-body">${fieldsHtml}</div>
                                <div class="svc-card-foot">
                                        <span class="svc-upd">🕒 ${fmtDate(r.updated_at)}</span>
                                        <span class="svc-actions">
                                                <button class="btn btn-sm btn-primary" data-open="${esc(String(svcId))}" ${isTmp ? 'data-tmp="' + esc(r.id) + '"' : ''}>👁 مشاهده و ویرایش</button>
                                                ${canQ && !isTmp ? `<button class="btn btn-sm btn-quick" data-quick="${esc(String(r.id))}" title="ویرایش سریع پیشرفت دایری و وضعیت همین سرویس">⚡</button>` : ''}
                                                ${canDelete && !isTmp ? `<button class="btn btn-sm btn-danger" data-del="${esc(String(r.id))}" title="حذف این سرویس">🗑</button>` : ''}
                                        </span>
                                </div>
                        </div>`;
                }).join('') + '</div>';
        }

        function paginationHtml(pages, total) {
                if (pages <= 1) return '<p class="muted" style="text-align:center">' + total + ' مورد</p>';
                let btns = '';
                const p = searchState.page;
                const range = [];
                for (let i = 1; i <= pages; i++) {
                        if (i === 1 || i === pages || Math.abs(i - p) <= 2) range.push(i);
                        else if (range[range.length - 1] !== '…') range.push('…');
                }
                range.forEach((i) => {
                        btns += i === '…'
                                ? '<span class="muted">…</span>'
                                : `<button class="btn btn-sm ${i === p ? 'btn-primary' : ''}" data-page="${i}">${i}</button>`;
                });
                return `<div class="pagination">${p > 1 ? `<button class="btn btn-sm" data-page="${p - 1}">قبلی</button>` : ''}${btns}${p < pages ? `<button class="btn btn-sm" data-page="${p + 1}">بعدی</button>` : ''}<span class="page-info">صفحه ${p} از ${pages} — ${total} مورد</span></div>`;
        }

        function emptyHtml() {
                // ۱.۹.۲: آفلاین + کش خالی → راهنمای شفاف به‌جای «نتیجه‌ای یافت نشد»
                if (!TPP.offline.state().online) {
                        const info = offlineCacheInfo();
                        if (!info || !info.n) {
                                return `<div class="card"><div class="empty-state"><div class="big">📴</div><p>هنوز داده‌ای از سرویس‌ها روی این دستگاه ذخیره نشده است.<br><span class="muted">یک بار با اتصال اینترنت این صفحه را باز کنید تا همه سرویس‌ها به‌صورت خودکار روی دستگاه ذخیره شوند (همگام‌سازی آفلاین). سپس در حالت آفلاین هم جستجو و نمایش کار می‌کند.</span></p></div></div>`;
                        }
                }
                return `<div class="card"><div class="empty-state"><div class="big">🔍</div><p>نتیجه‌ای یافت نشد.<br><span class="muted">عبارت دیگری جستجو کنید یا فیلترها را تغییر دهید.</span></p></div></div>`;
        }

        function bindResultEvents(area) {
                // دکمه «مشاهده و ویرایش» — تنها مسیر ورود به جزئیات سرویس
                area.querySelectorAll('button[data-open]').forEach((b) => {
                        b.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const tmp = b.getAttribute('data-tmp');
                                go(tmp ? 'service/' + encodeURIComponent(tmp) : 'service/' + b.getAttribute('data-open'));
                        });
                });
                // حذف تکی سرویس از خود کارت
                area.querySelectorAll('button[data-del]').forEach((b) => {
                        b.addEventListener('click', async (e) => {
                                e.stopPropagation();
                                const id = parseInt(b.getAttribute('data-del'), 10);
                                if (!id) return;
                                if (!await confirmBox('سرویس #' + id + ' حذف شود؟<br><span class="muted">تاریخچه این سرویس هم به‌طور کامل حذف می‌شود و این عمل قابل بازگشت نیست.</span>', 'حذف قطعی')) return;
                                b.disabled = true;
                                await TPP.offline.enqueue('service.delete', { id });
                                searchState.selected.delete(id);
                                toast('سرویس حذف شد.', 'success');
                                await doSearch();
                        });
                });
                // ویرایش سریع پیشرفت/وضعیت همین سرویس (۱.۱۳.۰)
                area.querySelectorAll('button[data-quick]').forEach((b) => {
                        b.addEventListener('click', (e) => {
                                e.stopPropagation();
                                openBulkEdit([parseInt(b.getAttribute('data-quick'), 10)], true);
                        });
                });
                // چک‌باکس انتخاب برای عملیات گروهی
                area.querySelectorAll('input[data-sel]').forEach((cb) => {
                        cb.addEventListener('click', (e) => e.stopPropagation());
                        cb.addEventListener('change', (e) => {
                                syncSel(cb.getAttribute('data-sel'), e.target.checked);
                                cb.closest('.svc-card').classList.toggle('selected', e.target.checked);
                                updateBulkBar();
                        });
                });
                // کلیک/لمس روی محتوا = کپی همان مقدار (فیلد کارت)
                area.querySelectorAll('[data-copy]').forEach((el) => {
                        el.addEventListener('click', async (e) => {
                                e.stopPropagation();
                                const val = el.getAttribute('data-copy') || el.textContent || '';
                                if (!String(val).trim()) return;
                                const ok = await copyText(val);
                                toast(ok ? '📋 کپی شد: ' + esc(String(val).slice(0, 40)) + (String(val).length > 40 ? '…' : '') : 'کپی ناموفق بود — متن را دستی انتخاب کنید.', ok ? 'success' : 'error');
                                el.classList.add('copied');
                                setTimeout(() => el.classList.remove('copied'), 700);
                        });
                });
                area.querySelectorAll('.addr-head').forEach((h) => {
                        h.addEventListener('click', () => go('address/' + h.getAttribute('data-address')));
                });
                area.querySelectorAll('button[data-page]').forEach((b) => {
                        b.addEventListener('click', () => {
                                searchState.page = parseInt(b.getAttribute('data-page'), 10);
                                doSearch();
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                        });
                });
        }

        /* ==================== نما: تاریخچه ==================== */

        const historyState = { page: 1, perPage: 30, action: '', entity: '', conflict: 0, user: '', selected: new Set(), offline: false };

        async function viewHistory() {
                // تعداد ردیف از تنظیمات افزونه (اعمال فوری بعد از ذخیره تنظیمات)
                historyState.perPage = Math.max(10, parseInt(state.settings.rows_per_page, 10) || 30);
                historyState.offline = !TPP.offline.state().online;
                const canDelHist = can('tpp_delete_history');

                els.content.innerHTML = `
                ${historyState.offline ? '<div class="alert warn">📴 حالت آفلاین — تاریخچه از داده‌های ذخیره‌شده این دستگاه نمایش داده می‌شود؛ حذف‌ها پس از اتصال همگام می‌شوند.</div>' : ''}
                <div class="card">
                        <div class="search-bar">
                                <select id="h-action" class="btn">
                                        <option value="">همه عملیات</option>
                                        <option value="create" ${historyState.action === 'create' ? 'selected' : ''}>ثبت</option>
                                        <option value="update" ${historyState.action === 'update' ? 'selected' : ''}>ویرایش</option>
                                        <option value="import" ${historyState.action === 'import' ? 'selected' : ''}>ایمپورت</option>
                                        <option value="delete" ${historyState.action === 'delete' ? 'selected' : ''}>حذف</option>
                                </select>
                                <select id="h-entity" class="btn">
                                        <option value="">همه موجودیت‌ها</option>
                                        <option value="service" ${historyState.entity === 'service' ? 'selected' : ''}>سرویس</option>
                                        <option value="address" ${historyState.entity === 'address' ? 'selected' : ''}>آدرس</option>
                                </select>
                                <label class="checkbox-row"><input type="checkbox" id="h-conflict" ${historyState.conflict ? 'checked' : ''}> فقط تعارض‌ها</label>
                                <button class="btn btn-primary" id="h-refresh">نمایش</button>
                        </div>
                        ${canDelHist ? `
                        <div class="bulk-bar">
                                <label class="checkbox-row"><input type="checkbox" id="h-select-all"> <b>انتخاب همه در این صفحه</b></label>
                                <button class="btn btn-danger" id="h-bulk-delete" disabled>🗑 حذف رکوردهای انتخاب‌شده (<span id="h-sel-count">0</span>)</button>
                                <span class="muted">رکورد حذف‌شده قابل بازگشت نیست.</span>
                        </div>` : ''}
                        ${can('tpp_view_all_history') ? '<p class="muted">شما تاریخچه تغییرات همه کاربران را می‌بینید.</p>' : '<p class="muted">شما فقط تاریخچه تغییرات خودتان را می‌بینید.</p>'}
                </div>
                <div id="history-area"><div class="loading-block"><div class="spinner"></div></div></div>`;

                document.getElementById('h-refresh').addEventListener('click', () => {
                        historyState.action = document.getElementById('h-action').value;
                        historyState.entity = document.getElementById('h-entity').value;
                        historyState.conflict = document.getElementById('h-conflict').checked ? 1 : 0;
                        historyState.page = 1;
                        loadHistory();
                });

                const hSelectAll = document.getElementById('h-select-all');
                if (hSelectAll) hSelectAll.addEventListener('change', (e) => {
                        const checked = e.target.checked;
                        document.querySelectorAll('#history-area input[data-hsel]').forEach((cb) => {
                                cb.checked = checked;
                                if (checked) historyState.selected.add(parseInt(cb.getAttribute('data-hsel'), 10));
                                else historyState.selected.delete(parseInt(cb.getAttribute('data-hsel'), 10));
                        });
                        updateHistBulkBar();
                });
                const hBulk = document.getElementById('h-bulk-delete');
                if (hBulk) hBulk.addEventListener('click', async () => {
                        await deleteHistoryIds(Array.from(historyState.selected), () => { historyState.page = 1; loadHistory(); });
                });

                await loadHistory();
        }

        function updateHistBulkBar() {
                const cnt = document.getElementById('h-sel-count');
                const btn = document.getElementById('h-bulk-delete');
                if (!cnt || !btn) return;
                cnt.textContent = historyState.selected.size;
                btn.disabled = historyState.selected.size === 0;
        }

        /** حذف تکی/گروهی رکورد تاریخچه — آنلاین فوری، آفلاین در صف همگام‌سازی */
        async function deleteHistoryIds(ids, after) {
                const list = (ids || []).map((i) => parseInt(i, 10)).filter(Boolean);
                if (!list.length) return;
                if (!await confirmBox(`حذف <b>${list.length}</b> رکورد تاریخچه؟<br><span class="muted">رکورد تاریخچه حذف‌شده قابل بازگشت نیست${list.length === 1 ? ' و امکان «بازگردانی مقادیر قبلی» آن از بین می‌رود' : ''}.</span>`, 'حذف قطعی')) return;
                try {
                        await TPP.offline.deleteHistoryEntries(list);
                        historyState.selected.clear();
                        toast(list.length + ' رکورد حذف شد.', 'success');
                        if (after) after(); else loadHistory();
                } catch (e) {
                        toast('خطا در حذف: ' + esc(e.message), 'error');
                }
        }

        async function loadHistory() {
                const area = document.getElementById('history-area');
                if (!area) return;
                try {
                        let data;
                        if (TPP.offline.state().online) {
                                const params = { page: historyState.page, per_page: historyState.perPage };
                                if (historyState.action) params.action = historyState.action;
                                if (historyState.entity) params.entity = historyState.entity;
                                if (historyState.conflict) params.conflict = 1;
                                try {
                                        data = await TPP.api.request('GET', 'history', null, params);
                                } catch (e) {
                                        // خطای شبکه → داده محلی
                                        data = await localHistory(params);
                                }
                        } else {
                                data = await localHistory({ action: historyState.action, entity: historyState.entity, conflict: historyState.conflict, page: historyState.page, per_page: historyState.perPage });
                        }
                        area.innerHTML = historyHtml(data) + historyPagination(data);
                        area.querySelectorAll('button[data-page]').forEach((b) => {
                                b.addEventListener('click', () => { historyState.page = parseInt(b.getAttribute('data-page'), 10); loadHistory(); });
                        });
                        area.querySelectorAll('button[data-restore]').forEach((b) => {
                                b.addEventListener('click', async () => {
                                        if (!await confirmBox('مقادیر قبلی این رکورد به‌عنوان یک بازبینی جدید بازگردانی شود؟', 'بازگردانی')) return;
                                        try {
                                                await TPP.api.request('POST', 'history/' + b.getAttribute('data-restore') + '/restore', {});
                                                toast('بازگردانی انجام شد.', 'success');
                                                loadHistory();
                                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                                });
                        });
                        // حذف تکی هر رکورد تاریخچه
                        area.querySelectorAll('button[data-hdel]').forEach((b) => {
                                b.addEventListener('click', async () => {
                                        const id = parseInt(b.getAttribute('data-hdel'), 10);
                                        await deleteHistoryIds([id], loadHistory);
                                });
                        });
                        // چک‌باکس انتخاب گروهی
                        area.querySelectorAll('input[data-hsel]').forEach((cb) => {
                                const id = parseInt(cb.getAttribute('data-hsel'), 10);
                                if (historyState.selected.has(id)) cb.checked = true;
                                cb.addEventListener('click', (e) => e.stopPropagation());
                                cb.addEventListener('change', (e) => {
                                        if (e.target.checked) historyState.selected.add(id);
                                        else historyState.selected.delete(id);
                                        updateHistBulkBar();
                                });
                        });
                        updateHistBulkBar();
                } catch (e) {
                        area.innerHTML = '<div class="alert err">خطا در دریافت تاریخچه: ' + esc(e.message) + '</div>';
                }
        }

        /** تاریخچه آفلاین از کش دیسکی — همان فیلترها و صفحه‌بندی */
        async function localHistory(params) {
                const rows = await TPP.offline.historyLocal({ action: params.action || '', entity: params.entity || '', conflict: params.conflict ? 1 : 0 });
                const per = parseInt(params.per_page, 10) || historyState.perPage;
                const page = Math.max(1, parseInt(params.page, 10) || 1);
                const start = (page - 1) * per;
                return { total: rows.length, rows: rows.slice(start, start + per) };
        }

        function historyHtml(data) {
                const rows = data.rows || [];
                if (!rows.length) return '<div class="card"><div class="empty-state"><div class="big">🕘</div><p>تاریخچه‌ای یافت نشد.</p></div></div>';
                const actionLabels = { create: 'ثبت', update: 'ویرایش', import: 'ایمپورت', delete: 'حذف', restore: 'بازگردانی' };
                const canDelHist = can('tpp_delete_history');
                return `<div class="card"><div class="timeline">` + rows.map((h) => `
                        <div class="tl-item ${h.is_conflict ? 'conflict' : ''}">
                                <div class="tl-head">
                                        ${canDelHist ? `<input type="checkbox" class="tl-sel" data-hsel="${h.id}" title="انتخاب برای حذف">` : ''}
                                        <b>${actionLabels[h.action] || h.action}</b>
                                        <span class="chip">${h.entity === 'service' ? 'سرویس #' + h.entity_id : 'آدرس #' + h.entity_id}</span>
                                        ${(h.changes && h.changes.fmt === 2 && Array.isArray(h.changes.events) && h.changes.events.length > 1) ? '<span class="chip ok">' + h.changes.events.length + ' تغییر امروز</span>' : ''}
                                        ${h.is_conflict ? '<span class="chip warn">تعارض آفلاین</span>' : ''}
                                        ${h.source === 'offline' ? '<span class="chip">آفلاین</span>' : ''}
                                        ${canDelHist ? `<button class="btn btn-sm btn-danger tl-del" data-hdel="${h.id}" title="حذف این رکورد تاریخچه">🗑</button>` : ''}
                                </div>
                                <div class="tl-meta">${esc(h.user_name)} — ${fmtDate(h.changed_at)} — بازبینی ${h.revision}</div>
                                <div class="tl-changes">${changesHtml(h.changes)}</div>
                                ${can('tpp_edit_services') && h.action !== 'delete' && h.entity === 'service' ? '<div style="margin-top:6px"><button class="btn btn-sm" data-restore="' + h.id + '">↩ بازگردانی مقادیر قبلی</button></div>' : ''}
                        </div>`).join('') + '</div></div>';
        }

        function changesHtml(changes) {
                if (!changes) return '<span class="muted">بدون جزئیات</span>';
                // ۱.۱۰.۰ — فرمت تجمیعی روزانه: فهرست رویدادهای همان روز
                if (changes.fmt === 2 && Array.isArray(changes.events)) {
                        if (!changes.events.length) return '<span class="muted">بدون جزئیات</span>';
                        return changes.events.map((ev) => evItemHtml(ev)).join('');
                }
                const items = Object.entries(changes).map(([slug, pair]) => `
                        <tr>
                                <td style="width:150px">${esc(fieldLabel(slug))}</td>
                                <td><span class="old-val">${esc(pair.old === null || pair.old === '' ? '—' : pair.old)}</span><span class="arrow">→</span><span class="new-val">${esc(pair.new === null || pair.new === '' ? '—' : pair.new)}</span></td>
                        </tr>`).join('');
                return '<table>' + items + '</table>';
        }

        /** رندر یک رویداد تاریخچه تجمیعی (ساعت + عمل + جدول تغییرات) */
        function evItemHtml(ev) {
                const actionLabels = { create: 'ثبت', update: 'ویرایش', import: 'ایمپورت', delete: 'حذف', restore: 'بازگردانی', sync: 'همگام‌سازی', merge: 'ادغام' };
                const c = ev.c || {};
                const items = Object.entries(c).map(([slug, pair]) => {
                        const p = (pair && typeof pair === 'object') ? pair : {};
                        const oldV = (p.old === null || p.old === undefined || p.old === '') ? '—' : p.old;
                        const newV = (p.new === null || p.new === undefined || p.new === '') ? '—' : p.new;
                        return `
                        <tr>
                                <td style="width:150px">${esc(fieldLabel(slug))}</td>
                                <td><span class="old-val">${esc(oldV)}</span><span class="arrow">→</span><span class="new-val">${esc(newV)}</span></td>
                        </tr>`;
                }).join('');
                return `<div class="ev-item">
                        <div class="ev-head"><span class="ev-time">${esc(String(ev.t || '').slice(0, 5))}</span>
                        <span class="chip">${esc(actionLabels[ev.a] || ev.a || '')}</span>
                        ${ev.y === 'address' ? '<span class="chip">آدرس</span>' : ''}
                        ${ev.s === 'offline' ? '<span class="chip">آفلاین</span>' : ''}</div>
                        ${items ? '<table>' + items + '</table>' : '<span class="muted">بدون تغییر فیلد</span>'}
                </div>`;
        }

        function historyPagination(data) {
                const total = parseInt(data.total, 10) || 0;
                const pages = Math.max(1, Math.ceil(total / historyState.perPage));
                if (pages <= 1) return '<p class="muted" style="text-align:center">' + total + ' رکورد</p>';
                let btns = '';
                for (let i = 1; i <= Math.min(pages, 12); i++) {
                        btns += `<button class="btn btn-sm ${i === historyState.page ? 'btn-primary' : ''}" data-page="${i}">${i}</button>`;
                }
                return `<div class="pagination">${btns}<span class="page-info">صفحه ${historyState.page} از ${pages}</span></div>`;
        }

        /* ==================== رندر قالب متن (پیامک/کپی) ==================== */

        /**
         * جای‌نگهدارهای {{نام‌کد_فیلد}} را با مقادیر سرویس جایگزین می‌کند.
         * داده‌های سرویس از قبل با دسترسی فیلد کاربر فیلتر شده‌اند (فیلدهای پنهان •••• هستند).
         */
        function renderTemplate(body, row) {
                const addr = row && row.address ? row.address : {};
                return String(body || '').replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (m, key) => {
                        if (key === 'service_id') return row ? String(row.id || '') : '';
                        if (key === 'site_name') return state.site || '';
                        const v = (row && row[key] !== undefined && row[key] !== null) ? row[key] : addr[key];
                        return v === undefined || v === null ? '' : String(v);
                });
        }

        /* ==================== API عمومی ماژول ==================== */

        return {
                state: () => state,
                esc, toast, modal, confirmBox, debounce, fmtDate, can, fieldDef, fieldLabel, go, renderTemplate, copyText,
                jalToIso, isoToJal, dateToIso, displayVal, tehranTodayIso, isoShift, nowTehranSql, faToEnDigits,
                refreshRoute: route,
                restartTimers: startTimers,
                viewDashboard, viewServices, viewHistory,
                searchState,
                isEmbed: () => EMBED,
                parentUrl: () => PARENT_URL,
                // برای نماهای دیگر
                setTopbar(html) { els.topbarActions.innerHTML = html; },
                getEls: () => els
        };
})();

/* ثبت نماها با نام‌های مسیر */
TPP.views = TPP.views || {};
TPP.views.dashboard = () => TPP.app.viewDashboard();
TPP.views.services = () => TPP.app.viewServices();
TPP.views.history = () => TPP.app.viewHistory();
