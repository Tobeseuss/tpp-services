/**
 * tpp-views-report.js — گزارش کار (۱.۱۴.۰ / ۱.۱۹.۰)
 * گزارش اقدامات روزانه هر کاربر برای ارائه به مدیران شرکت:
 *  ۱) تقویم شمسی روزهای دارای گزارش (سبز) و روزهای دارای فعالیت (نشان) + انتخاب تاریخ با کلیک
 *     (۱.۲۰.۰ — رفع باگ ارقام فارسی در خروجی isoToJal که تقویم را با «تاریخ نامعتبر» می‌شکست؛
 *      تقویم حالا پیش‌فرض باز است و روزهای دارای گزارش فقط داخل تقویم نمایش داده می‌شوند)
 *  ۲) دوره‌های گزارش: روزانه / هفتگی / ماهانه / n-روزه (۱.۱۹.۰)
 *  ۳) فعالیت‌های همان روز (بدون جستجوها — درخواست کاربر ۱.۱۹.۰) با آدرس کامل سرویس و دکمه «افزودن»
 *  ۴) «افزودن دستی»: انتخاب تاریخ از تقویم + «اقدام انجام‌شده» از فهرست آماده (گروه‌بندی‌شده)
 *     + جستجوی کامل سرویس در همه فیلدها با دکمه «فیلتر» (فیلتر فیلد/وضعیت دایری/دسته/تگ) و صفحه‌بندی
 *  ۵) اخطار نگهداشت تاریخچه فعالیت (n روز — طبق تنظیمات): اگر به گزارش تبدیل نشود از دست می‌رود
 * مدیران می‌توانند گزارش سایر کاربران را ببینند (فقط-مشاهده)؛ افزودن/ویرایش/حذف مالک خود گزارش است.
 */
'use strict';

TPP.views = TPP.views || {};

(function () {

        const esc = (s) => TPP.app.esc(s);
        const can = TPP.app.can;
        const go = TPP.app.go;
        const toast = TPP.app.toast;
        const confirmBox = TPP.app.confirmBox;
        const modal = TPP.app.modal;
        const fmtDate = TPP.app.fmtDate;
        const faToEn = (s) => String(s || '')
                .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        const faNum = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[+d]);

        /* وضعیت نما */
        const wr = {
                mode: 'day',      // day | range
                period: 'day',    // day | week | month | ndays
                nDays: 7,
                date: '',         // ISO روز جاری (حالت روزانه / مرجع بازه)
                from: '',         // ISO شروع بازه (حالت بازه‌ای)
                to: '',           // ISO پایان بازه
                userId: 0,        // 0 = خودم؛ برای مدیران: کاربرِ انتخاب‌شده
                data: null,       // پاسخ workreport
                cats: null,       // دسته‌بندی/تگ‌ها (برای فیلتر جستجوی سرویس)
                actDaysCache: {}  // 'Y-m' → [iso, …] روزهای دارای فعالیت
        };

        const isManagerView = () => wr.userId > 0;
        const canEdit = () => !!(wr.data && wr.data.can_edit);

        /** تبدیل تاریخ ISO به شمسی برای نمایش */
        function jal(iso) { return TPP.app.isoToJal(iso) || '—'; }

        /** سال/ماه/روز شمسی یک ISO — ۱.۲۰.۰ رفع باگ: خروجی isoToJal ارقام فارسی است،
         *  پیش از regex باید به لاتین تبدیل شود (\d فقط ارقام ASCII را می‌گیرد) */
        function jalYm(iso) {
                const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
                if (!m) return null;
                const j = faToEn(jalToIso_(iso)).trim(); // «۱۴۰۴/۰۶/۳۱» → «1404/06/31»
                if (!j) return null;
                const p = /^(\d{4})\/(\d{2})\/(\d{2})$/.exec(j);
                return p ? { jy: parseInt(p[1], 10), jm: parseInt(p[2], 10), jd: parseInt(p[3], 10) } : null;
        }

        /** iso → متن شمسی خام (ارقام فارسی) — پوشش ابزار اپ */
        function jalToIso_(iso) { return TPP.app.isoToJal(iso) || ''; }

        /** شمسی (jy,jm,jd) → ISO */
        function jmToIso(jy, jm, jd) {
                const p2 = (n) => String(n).padStart(2, '0');
                return TPP.app.jalToIso(jy + '/' + p2(jm) + '/' + p2(jd));
        }

        /** تعداد روزهای ماه شمسی */
        function jalMonthLen(jy, jm) {
                if (jm <= 6) return 31;
                if (jm <= 11) return 30;
                return jmToIso(jy, 12, 30) ? 30 : 29; // اسفند: ۲۹ یا ۳۰ (کبیسه)
        }

        /* روز هفته (شنبه = ۰) */
        function weekIndex(iso) {
                const d = new Date(String(iso) + 'T00:00:00Z');
                return (d.getUTCDay() + 1) % 7;
        }

        function isoAdd(iso, days) { return TPP.app.isoShift(iso, days); }

        /* ============================================================
         * تقویم شمسی (۱.۱۹.۰) — گرید ماه + روزهای دارای گزارش (سبز) / فعالیت (نشان)
         * $opts: {iso, reportDays:{iso:count}, activityDays:[iso], selected, onPick, compact}
         * ============================================================ */

        const CAL_WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
        const CAL_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

        function calHtml(opts) {
                const iso = opts.iso || TPP.app.tehranTodayIso();
                const ym = jalYm(iso);
                if (!ym) return '<div class="alert err">تاریخ نامعتبر است.</div>';
                const today = TPP.app.tehranTodayIso();
                const reportDays = opts.reportDays || {};
                const actDays = opts.activityDays || [];
                const firstIso = jmToIso(ym.jy, ym.jm, 1);
                const len = jalMonthLen(ym.jy, ym.jm);
                const lead = weekIndex(firstIso); // خانه‌های خالی ابتدای گرید
                const rows = [];
                let day = 1 - lead;
                for (let r = 0; r < 6; r++) {
                        const cells = [];
                        for (let c = 0; c < 7; c++) {
                                if (day < 1 || day > len) {
                                        cells.push('<td class="cal-day off">&nbsp;</td>');
                                } else {
                                        const dIso = jmToIso(ym.jy, ym.jm, day);
                                        const cls = ['cal-day'];
                                        if (dIso === today) cls.push('today');
                                        if (reportDays[dIso]) cls.push('has-report');
                                        if (actDays.indexOf(dIso) !== -1 && !reportDays[dIso]) cls.push('has-activity');
                                        if (opts.selected && dIso === opts.selected) cls.push('selected');
                                        const title = [];
                                        if (reportDays[dIso]) title.push(faNum(reportDays[dIso]) + ' قلم گزارش');
                                        if (actDays.indexOf(dIso) !== -1) title.push('فعالیت ثبت‌شده');
                                        cells.push('<td class="' + cls.join(' ') + '"' + (title.length ? ' title="' + esc(title.join(' — ')) + '"' : '') +
                                                (opts.onPick ? ' data-cal="' + esc(dIso) + '"' : '') + '>' + faNum(day) +
                                                (reportDays[dIso] ? ' <b class="cal-n">' + faNum(reportDays[dIso]) + '</b>' : '') + '</td>');
                                }
                                day++;
                        }
                        rows.push('<tr>' + cells.join('') + '</tr>');
                        if (day > len) break;
                }
                return `
                <div class="wr-cal${opts.compact ? ' compact' : ''}" data-calmonth="${esc(ym.jy + '-' + ym.jm)}">
                        <div class="cal-head">
                                <button class="btn btn-sm" data-calnav="-1" title="ماه قبل">›</button>
                                <b class="cal-title">${esc(CAL_MONTHS[ym.jm - 1])} ${faNum(ym.jy)}</b>
                                <button class="btn btn-sm" data-calnav="1" title="ماه بعد">‹</button>
                        </div>
                        <table class="cal-grid"><thead><tr>${CAL_WEEKDAYS.map((w) => `<th>${w}</th>`).join('')}</tr></thead>
                        <tbody>${rows.join('')}</tbody></table>
                        <div class="cal-legend">
                                <span><i class="cal-dot report"></i> روز دارای گزارش</span>
                                <span><i class="cal-dot activity"></i> روز دارای فعالیت</span>
                                <span class="muted">— کلیک روی روز = انتخاب تاریخ</span>
                        </div>
                </div>`;
        }

        /** وصل‌کردن رویدادهای تقویم یک ظرف (ناوبری ماه + انتخاب روز) */
        function bindCal(container, opts) {
                if (!container) return;
                const getIso = () => opts.getIso();
                container.querySelectorAll('[data-cal]').forEach((td) => td.addEventListener('click', () => {
                        opts.onPick(td.getAttribute('data-cal'));
                }));
                container.querySelectorAll('[data-calnav]').forEach((b) => b.addEventListener('click', () => {
                        const ym = jalYm(getIso());
                        if (!ym) return;
                        let nm = ym.jm + parseInt(b.getAttribute('data-calnav'), 10);
                        let ny = ym.jy;
                        if (nm < 1) { nm = 12; ny--; }
                        if (nm > 12) { nm = 1; ny++; }
                        const first = jmToIso(ny, nm, 1);
                        if (first && opts.onNav) opts.onNav(first);
                }));
        }

        /* ============================================================
         * نمای اصلی
         * ============================================================ */

        TPP.views.workreport = async function () {
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">📴 «گزارش کار» به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                if (!wr.date) wr.date = TPP.app.tehranTodayIso();
                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>📝 گزارش کار</h3>
                        <p class="muted">گزارش اقدامات انجام‌شده هر روز برای ارائه به مدیران شرکت — از فعالیت‌های همان روز یا به‌صورت دستی ساخته می‌شود و قلم‌های آن قابل افزودن، ویرایش و حذف است. متن هر قلم ساده است: «اقدام (آدرس)، دایری سرویس تا مرحله (X)، مراحل باقیمانده بعدی از مرحله (Y)، خرابی اعلام‌شده».</p>
                        <div class="wr-period-row">
                                <button class="btn wr-period${wr.period === 'day' ? ' btn-primary' : ''}" data-period="day" title="گزارش یک روز خاص">📅 روزانه</button>
                                <button class="btn wr-period${wr.period === 'week' ? ' btn-primary' : ''}" data-period="week" title="گزارش هفته جاری (شنبه تا جمعه)">هفتگی</button>
                                <button class="btn wr-period${wr.period === 'month' ? ' btn-primary' : ''}" data-period="month" title="گزارش ماه شمسی جاری">ماهانه</button>
                                <button class="btn wr-period${wr.period === 'ndays' ? ' btn-primary' : ''}" data-period="ndays" title="گزارش n روز اخیر">n-روزه</button>
                                <span class="wr-ndays-box${wr.period === 'ndays' ? '' : ' hidden'}">
                                        <input type="text" id="wr-ndays" inputmode="numeric" class="btn" style="width:70px" value="${esc(String(wr.nDays))}" title="تعداد روز">
                                        <button class="btn btn-sm" id="wr-ndays-go">اعمال</button>
                                </span>
                                ${can('tpp_view_activity') ? '<select id="wr-user" class="btn"><option value="0">گزارش خودم</option></select>' : ''}
                        </div>
                        <div class="search-bar" style="flex-wrap:wrap">
                                <button class="btn" id="wr-today" title="برو به امروز">📅 امروز</button>
                                <input type="text" id="wr-date-text" class="btn cal-input" readonly placeholder="تاریخ شمسی — برای تغییر، تقویم را باز کنید" style="min-width:190px" value="${esc(currentLabel())}">
                                <button class="btn" id="wr-cal-toggle" title="باز/بسته‌کردن تقویم">🗓 تقویم</button>
                                <span class="wr-range-nav${wr.mode === 'range' ? '' : ' hidden'}">
                                        <button class="btn btn-sm" id="wr-range-prev" title="بازه قبلی">»</button>
                                        <button class="btn btn-sm" id="wr-range-next" title="بازه بعدی">«</button>
                                </span>
                                <button class="btn btn-primary" id="wr-load">نمایش</button>
                        </div>
                        <div id="wr-cal-box" class="wr-cal-box"></div>
                </div>
                <div id="wr-report-card"><div class="loading-block"><div class="spinner"></div></div></div>
                <div id="wr-activity-card"></div>`;

                document.querySelectorAll('.wr-period').forEach((b) => b.addEventListener('click', () => {
                        setPeriod(b.getAttribute('data-period'));
                }));
                const ndInput = document.getElementById('wr-ndays');
                const ndGo = document.getElementById('wr-ndays-go');
                if (ndGo) ndGo.addEventListener('click', () => {
                        const n = parseInt(faToEn(ndInput.value), 10);
                        if (!n || n < 1 || n > 366) { toast('عدد روزها باید بین ۱ تا ۳۶۶ باشد.', 'warn'); return; }
                        wr.nDays = n;
                        setPeriod('ndays');
                });
                if (ndInput) ndInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') ndGo.click(); });

                document.getElementById('wr-today').addEventListener('click', () => {
                        wr.date = TPP.app.tehranTodayIso();
                        if (wr.period !== 'day') { setPeriod(wr.period); return; }
                        syncDateText();
                        loadDay();
                });
                document.getElementById('wr-load').addEventListener('click', () => {
                        if (wr.period === 'day') { loadDay(); return; }
                        loadRange();
                });
                document.getElementById('wr-cal-toggle').addEventListener('click', () => {
                        const box = document.getElementById('wr-cal-box');
                        if (!box.classList.contains('hidden')) { box.classList.add('hidden'); return; }
                        renderCalendar();
                        box.classList.remove('hidden');
                        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
                // ۱.۲۰.۰ — با کلیک روی کادر تاریخِ فقط-خواندنی هم تقویم باز/بسته شود
                const dateText = document.getElementById('wr-date-text');
                if (dateText) dateText.addEventListener('click', () => document.getElementById('wr-cal-toggle').click());
                const prev = document.getElementById('wr-range-prev');
                const next = document.getElementById('wr-range-next');
                if (prev) prev.addEventListener('click', () => shiftRange(-1));
                if (next) next.addEventListener('click', () => shiftRange(1));
                const userSel = document.getElementById('wr-user');
                if (userSel) {
                        userSel.addEventListener('change', () => {
                                wr.userId = parseInt(userSel.value, 10) || 0;
                                wr.actDaysCache = {};
                                refresh();
                        });
                }
                await refresh();
        };

        /** برچسب نوار تاریخ بر اساس دوره */
        function currentLabel() {
                if (wr.mode === 'range') {
                        return jal(wr.from) + ' تا ' + jal(wr.to);
                }
                return jal(wr.date);
        }

        function syncDateText() {
                const el = document.getElementById('wr-date-text');
                if (el) el.value = currentLabel();
        }

        /** تنظیم دوره و محاسبه بازه */
        function setPeriod(p) {
                wr.period = p;
                document.querySelectorAll('.wr-period').forEach((b) => b.classList.toggle('btn-primary', b.getAttribute('data-period') === p));
                const ndBox = document.querySelector('.wr-ndays-box');
                if (ndBox) ndBox.classList.toggle('hidden', p !== 'ndays');
                const rangeNav = document.querySelector('.wr-range-nav');
                if (p === 'day') {
                        wr.mode = 'day';
                        const todayBtn = document.getElementById('wr-today');
                        if (rangeNav) rangeNav.classList.add('hidden');
                        syncDateText();
                        loadDay();
                        return;
                }
                wr.mode = 'range';
                if (rangeNav) rangeNav.classList.remove('hidden');
                computeRange();
                loadRange();
        }

        /** بازه دوره از روی wr.date (مرجع) */
        function computeRange() {
                if (wr.period === 'week') {
                        // هفته شمسی: شنبه تا جمعه
                        const back = weekIndex(wr.date);
                        wr.from = isoAdd(wr.date, -back);
                        wr.to = isoAdd(wr.from, 6);
                } else if (wr.period === 'month') {
                        const ym = jalYm(wr.date);
                        if (ym) {
                                wr.from = jmToIso(ym.jy, ym.jm, 1);
                                wr.to = jmToIso(ym.jy, ym.jm, jalMonthLen(ym.jy, ym.jm));
                        }
                } else { // ndays
                        const n = Math.min(366, Math.max(1, wr.nDays));
                        wr.to = wr.date;
                        wr.from = isoAdd(wr.to, -(n - 1));
                }
                if (!wr.from || !wr.to) { wr.mode = 'day'; }
        }

        /** جابه‌جایی بازه (هفته/ماه/n روز) */
        function shiftRange(dir) {
                if (wr.period === 'week') {
                        wr.date = isoAdd(wr.date, dir * 7);
                } else if (wr.period === 'month') {
                        const ym = jalYm(wr.date);
                        if (ym) {
                                let nm = ym.jm + dir, ny = ym.jy;
                                if (nm < 1) { nm = 12; ny--; }
                                if (nm > 12) { nm = 1; ny++; }
                                // ۱.۲۰.۰ — روز ماه از jalYm (رقم لاتین)؛ قبلاً parseInt روی ارقام فارسی NaN می‌شد
                                const day = Math.min(ym.jd || 1, jalMonthLen(ny, nm));
                                const iso = jmToIso(ny, nm, day);
                                if (iso) wr.date = iso;
                        }
                } else {
                        wr.date = isoAdd(wr.date, dir * Math.max(1, Math.min(366, wr.nDays)));
                        if (!wr.date) return;
                }
                computeRange();
                loadRange();
        }

        /** نوسازی کامل (تغییر کاربر/دوره) */
        function refresh() {
                if (wr.mode === 'range') { computeRange(); loadRange(); }
                else loadDay();
        }

        /* ============================================================
         * تقویم اصلی (روزهای دارای گزارش سبز + روزهای دارای فعالیت)
         * ============================================================ */

        function reportDaysMap() {
                const map = {};
                (wr.data && wr.data.days ? wr.data.days : []).forEach((d) => { map[d.date] = d.count; });
                return map;
        }

        function renderCalendar() {
                const box = document.getElementById('wr-cal-box');
                if (!box) return;
                const owner = wr.userId || (TPP.app.state().user ? TPP.app.state().user.id : 0);
                const ym = jalYm(wr.date);
                const monthKey = ym ? (ym.jy + '-' + ym.jm) : '';
                const render = (actDays) => {
                        box.innerHTML = calHtml({
                                iso: wr.date,
                                reportDays: reportDaysMap(),
                                activityDays: actDays || [],
                                selected: wr.date,
                                onPick: (iso) => {
                                        wr.date = iso;
                                        if (wr.mode === 'range') { computeRange(); loadRange(); }
                                        else loadDay();
                                        box.classList.add('hidden');
                                }
                        });
                        bindCal(box, {
                                getIso: () => wr.date,
                                onNav: (firstIso) => { wr.date = firstIso; renderCalendar(); }
                        });
                };
                const cached = wr.actDaysCache[monthKey];
                if (cached) { render(cached); return; }
                render([]);
                // واکشی روزهای فعالیت ماه نمایش‌داده‌شده
                const from = ym ? jmToIso(ym.jy, ym.jm, 1) : wr.date;
                const to = ym ? jmToIso(ym.jy, ym.jm, jalMonthLen(ym.jy, ym.jm)) : wr.date;
                if (!from || !to) return;
                TPP.api.request('GET', 'activity/days', null, {
                        from: from, to: to,
                        user_id: isManagerView() ? wr.userId : 0
                }).then((res) => {
                        wr.actDaysCache[monthKey] = res.days || [];
                        const ymNow = jalYm(wr.date);
                        if (ymNow && (ymNow.jy + '-' + ymNow.jm) === monthKey) render(res.days || []);
                }).catch(() => { /* تقویم بدون هایلایت فعالیت */ });
        }

        /* ============================================================
         * بارگذاری گزارش (روزانه / بازه‌ای)
         * ============================================================ */

        async function loadDay() {
                wr.mode = 'day';
                syncDateText();
                const card = document.getElementById('wr-report-card');
                if (!card) return;
                card.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                const params = { date: wr.date };
                if (wr.userId) params.user_id = wr.userId;
                try {
                        wr.data = await TPP.api.request('GET', 'workreport', null, params);
                } catch (e) {
                        card.innerHTML = '<div class="alert err">خطا در دریافت گزارش کار: ' + esc(e.message) + '</div>';
                        return;
                }
                renderUserFilter();
                renderItems();
                renderFeed();
                // ۱.۲۰.۰ — تقویم پیش‌فرض باز است: روزهای دارای گزارش سبز و روزهای فعالیت نشان‌دار داخل خود تقویم
                renderCalendar();
        }

        async function loadRange() {
                wr.mode = 'range';
                syncDateText();
                const card = document.getElementById('wr-report-card');
                if (!card) return;
                card.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                const act = document.getElementById('wr-activity-card');
                if (act) act.innerHTML = '';
                const params = { from: wr.from, to: wr.to };
                if (wr.userId) params.user_id = wr.userId;
                try {
                        wr.data = await TPP.api.request('GET', 'workreport', null, params);
                } catch (e) {
                        card.innerHTML = '<div class="alert err">خطا در دریافت گزارش بازه‌ای: ' + esc(e.message) + '</div>';
                        return;
                }
                renderUserFilter();
                renderRangeItems();
                renderCalendar(); // ۱.۲۰.۰ — تقویم باز
        }

        /** فیلتر کاربر (فقط مدیران) — از facets پاسخ سرور پر می‌شود */
        function renderUserFilter() {
                const sel = document.getElementById('wr-user');
                if (!sel || !wr.data) return;
                const users = wr.data.users || [];
                sel.innerHTML = '<option value="0">گزارش خودم</option>' + users.map((u) =>
                        '<option value="' + esc(String(u.id)) + '"' + (u.id === wr.userId ? ' selected' : '') + '>' + esc(u.name) + ' (' + faNum(u.days) + ' روز)</option>').join('');
        }

        /** ۱.۲۰.۰ — چیپ‌های تکی روزهای دارای گزارش حذف شدند (درخواست کاربر: نمایش داخل تقویم) */
        function renderDays() {
                const box = document.getElementById('wr-days');
                if (!box || !wr.data) return;
                const days = (wr.data.days || []).slice(0, 14);
                box.innerHTML = days.length
                        ? '<span class="muted" style="align-self:center">روزهای دارای گزارش:</span>' + days.map((d) =>
                                '<button class="btn btn-sm' + (d.date === wr.date ? ' btn-primary' : '') + '" data-wrday="' + esc(d.date) + '">' + esc(jal(d.date)) + ' <b>' + faNum(d.count) + '</b></button>').join('')
                        : '<span class="muted">هنوز گزارشی برای روزی ثبت نشده است.</span>';
                box.querySelectorAll('[data-wrday]').forEach((b) => b.addEventListener('click', () => {
                        wr.date = b.getAttribute('data-wrday');
                        wr.mode = 'day';
                        wr.period = 'day';
                        document.querySelectorAll('.wr-period').forEach((x) => x.classList.toggle('btn-primary', x.getAttribute('data-period') === 'day'));
                        const rangeNav = document.querySelector('.wr-range-nav');
                        if (rangeNav) rangeNav.classList.add('hidden');
                        const ndBox = document.querySelector('.wr-ndays-box');
                        if (ndBox) ndBox.classList.add('hidden');
                        loadDay();
                }));
        }

        /* ============================================================
         * اقلام گزارش
         * ============================================================ */

        function periodTitle() {
                if (wr.mode === 'range') {
                        if (wr.period === 'week') return 'گزارش هفتگی — ' + jal(wr.from) + ' تا ' + jal(wr.to);
                        if (wr.period === 'month') {
                                const ym = jalYm(wr.from);
                                return 'گزارش ماهانه — ' + esc(CAL_MONTHS[ym ? ym.jm - 1 : 0]) + ' ' + faNum(ym ? ym.jy : '');
                        }
                        return 'گزارش ' + faNum(Math.max(1, wr.nDays)) + '-روزه — ' + jal(wr.from) + ' تا ' + jal(wr.to);
                }
                return 'گزارش کار ' + jal(wr.data.date);
        }

        function renderItems() {
                const card = document.getElementById('wr-report-card');
                if (!card || !wr.data) return;
                const items = wr.data.items || [];
                const editable = canEdit();
                card.innerHTML = `
                <div class="card">
                        <h3>📝 ${esc(periodTitle())}${isManagerView() ? ' — ' + esc(wr.data.user_name || '') : ''}</h3>
                        ${retentionBanner()}
                        <div id="wr-items" class="wr-items">${items.length ? items.map(itemHtml).join('') :
                                '<div class="empty-state" style="padding:16px"><p class="muted">برای این روز هنوز قلمی ثبت نشده است.<br>از «فعالیت‌های این روز» یا دکمه «افزودن دستی» استفاده کنید.</p></div>'}</div>
                        <div class="actions-row" style="flex-wrap:wrap">
                                ${editable ? '<button class="btn btn-primary" id="wr-add-manual">➕ افزودن دستی گزارش</button>' : '<span class="muted">این گزارش متعلق به کاربر دیگری است — فقط مشاهده.</span>'}
                                <button class="btn" id="wr-copy" title="کپی متن کامل گزارش برای ارسال به مدیر">📋 کپی متن گزارش</button>
                                <span class="chip">${faNum(items.length)} قلم</span>
                        </div>
                </div>`;
                bindItemActions(card, items);
                const addBtn = document.getElementById('wr-add-manual');
                if (addBtn) addBtn.addEventListener('click', () => openManualAdd());
        }

        function renderRangeItems() {
                const card = document.getElementById('wr-report-card');
                if (!card || !wr.data) return;
                const days = wr.data.days || [];
                const editable = canEdit();
                const total = wr.data.total_items || 0;
                card.innerHTML = `
                <div class="card">
                        <h3>📝 ${esc(periodTitle())}${isManagerView() ? ' — ' + esc(wr.data.user_name || '') : ''}</h3>
                        ${retentionBanner()}
                        ${days.length ? days.map((d) => `
                        <div class="wr-range-day">
                                <div class="wr-range-day-head"><b>${esc(jal(d.date))}</b> <span class="chip">${faNum(d.items.length)} قلم</span></div>
                                <div class="wr-items">${d.items.map(itemHtml).join('')}</div>
                        </div>`).join('') : '<div class="empty-state" style="padding:16px"><p class="muted">در این بازه گزارشی ثبت نشده است.</p></div>'}
                        <div class="actions-row" style="flex-wrap:wrap">
                                ${editable && wr.mode === 'day' ? '<button class="btn btn-primary" id="wr-add-manual">➕ افزودن دستی گزارش</button>' : ''}
                                <button class="btn" id="wr-copy" title="کپی متن کامل گزارش بازه برای ارسال به مدیر">📋 کپی متن گزارش</button>
                                <span class="chip">${faNum(total)} قلم در ${faNum(days.length)} روز</span>
                        </div>
                </div>`;
                bindItemActions(card, days.flatMap((d) => d.items));
                const addBtn = document.getElementById('wr-add-manual');
                if (addBtn) addBtn.addEventListener('click', () => openManualAdd());
        }

        function bindItemActions(card, items) {
                card.querySelectorAll('[data-wr-svc]').forEach((b) => b.addEventListener('click', () => {
                        go('service/' + b.getAttribute('data-wr-svc'));
                }));
                card.querySelectorAll('[data-wr-edit]').forEach((b) => b.addEventListener('click', () => {
                        const it = items.find((x) => String(x.id) === b.getAttribute('data-wr-edit'));
                        if (it) openEditItem(it);
                }));
                card.querySelectorAll('[data-wr-del]').forEach((b) => b.addEventListener('click', async () => {
                        const id = parseInt(b.getAttribute('data-wr-del'), 10);
                        if (!id) return;
                        if (!await confirmBox('این قلم از گزارش کار حذف شود؟', 'حذف قلم')) return;
                        try {
                                await TPP.api.request('DELETE', 'workreport/' + id);
                                toast('قلم گزارش حذف شد.', 'success');
                                refresh();
                        } catch (e) { toast('خطا در حذف: ' + esc(e.message), 'error'); }
                }));
                const copyBtn = document.getElementById('wr-copy');
                if (copyBtn) copyBtn.addEventListener('click', async () => {
                        const text = reportText();
                        if (!text.trim()) { toast('قلمی برای کپی وجود ندارد.', 'warn'); return; }
                        const ok = await TPP.app.copyText(text);
                        toast(ok ? '📋 متن گزارش کپی شد.' : 'کپی ناموفق — دستی انتخاب کنید.', ok ? 'success' : 'warn');
                });
        }

        /** بنر اخطار نگهداشت تاریخچه فعالیت (۱.۱۹.۰ — درخواست کاربر) */
        function retentionBanner() {
                const r = wr.data && wr.data.retention;
                if (!r) return '';
                if (r.unlimited) return '<div class="alert info" style="margin-top:8px">ℹ️ تاریخچه فعالیت‌ها طبق تنظیمات به‌صورت نامحدود نگهداری می‌شوند.</div>';
                return '<div class="alert warn" style="margin-top:8px">⚠️ تاریخچه فعالیت‌ها به‌طور خودکار فقط برای <b>' + faNum(r.days) + ' روز</b> (طبق تنظیمات) نگهداری می‌شوند — اگر فعالیت‌ها را به گزارش کار تبدیل نکنید، پس از این مدت از دست خواهند رفت.</div>';
        }

        /** HTML یک قلم */
        function itemHtml(it) {
                return `
                <div class="wr-item">
                        <span class="wr-num">${faNum(it.sort)}</span>
                        <div class="wr-content">${esc(it.content)}</div>
                        <div class="wr-meta">
                                ${it.service_id ? '<button class="btn btn-sm" data-wr-svc="' + esc(String(it.service_id)) + '" title="بازکردن سرویس">🛰️ سرویس #' + faNum(it.service_id) + '</button>' : ''}
                                <span class="muted">${fmtDate(it.created_at)}</span>
                                ${canEdit() ? '<button class="btn btn-sm" data-wr-edit="' + esc(String(it.id)) + '" title="ویرایش متن این قلم">✏️ ویرایش</button>' +
                                        '<button class="btn btn-sm btn-danger" data-wr-del="' + esc(String(it.id)) + '" title="حذف این قلم">🗑</button>' : ''}
                        </div>
                </div>`;
        }

        /** متن کامل گزارش برای کپی (روزانه و بازه‌ای) */
        function reportText() {
                if (!wr.data) return '';
                const user = isManagerView() ? ' — ' + (wr.data.user_name || '') : '';
                const lines = [];
                if (wr.mode === 'range') {
                        lines.push(periodTitle() + user, '');
                        (wr.data.days || []).forEach((d) => {
                                lines.push('— ' + jal(d.date) + ':');
                                d.items.forEach((it) => { lines.push(faNum(it.sort) + '. ' + it.content); });
                                lines.push('');
                        });
                } else {
                        lines.push('گزارش کار — ' + jal(wr.data.date) + user, '');
                        (wr.data.items || []).forEach((it) => { lines.push(faNum(it.sort) + '. ' + it.content); });
                }
                return lines.join('\n');
        }

        /* ============================================================
         * فعالیت‌های روز (۱.۱۹.۰): فید سرور بدون جستجوها + آدرس کامل سرویس
         * ============================================================ */

        function renderFeed() {
                const card = document.getElementById('wr-activity-card');
                if (!card || !wr.data) return;
                const feed = wr.data.feed;
                if (!feed) { card.innerHTML = ''; return; }
                const rows = feed.rows || [];
                const total = feed.total || 0;
                const self = !wr.userId;
                card.innerHTML = `
                <div class="card">
                        <h3>🕘 فعالیت‌های این روز ${self ? '' : '(' + esc(wr.data.user_name || '') + ')'}</h3>
                        <p class="muted">تغییر / بازدید / ایجاد و پیامک‌های همین روز (جستجوها نمایش داده نمی‌شوند) — با دکمه «➕» هر ردیف به گزارش کار اضافه می‌شود (ایجاد → «تحویل سرویس»، سایر → «رفع مشکل»).</p>
                        ${rows.length ? '<div class="wr-acts">' + rows.map(feedRowHtml).join('') + '</div>' +
                                (total > rows.length ? '<p class="muted" style="text-align:center">' + faNum(total - rows.length) + ' فعالیت قدیمی‌تر این روز نمایش داده نشد — با افزودن دستی هم می‌توانید ثبت کنید.</p>' : '') :
                                '<div class="empty-state" style="padding:14px"><p class="muted">در این روز فعالیتی ثبت نشده است.</p></div>'}
                </div>`;

                card.querySelectorAll('[data-wr-add]').forEach((b) => b.addEventListener('click', () => {
                        addFromActivity(parseInt(b.getAttribute('data-wr-add'), 10), b.getAttribute('data-wr-src'));
                }));
        }

        /** ردیف فید — آدرس کامل سرویس + بلوک/پلاک/واحد (درخواست کاربر ۱.۱۹.۰) */
        function feedRowHtml(r) {
                const ICONS = { change: '📝', view: '👁', sms: '📨' };
                const ACT = { create: 'ایجاد', update: 'ویرایش', delete: 'حذف', merge: 'ادغام', restore: 'بازگردانی', view: 'بازدید', sms: 'پیامک' };
                const canAdd = (r.src === 'change' || r.src === 'view');
                const svc = r.svc || null;
                const addrParts = svc ? [svc.full_address, svc.block, svc.plate ? 'پلاک ' + svc.plate : '', svc.unit ? 'واحد ' + svc.unit : ''].filter(Boolean) : [];
                const addrLine = svc ? ('<div class="wr-act-addr">' + (addrParts.length ? esc(addrParts.join('، ')) : 'بدون آدرس') + (svc.virtual_number ? ' — شماره مجازی: ' + esc(svc.virtual_number) : '') + '</div>') : '';
                return `
                <div class="wr-act-row">
                        <div class="wr-act-main">
                                <span class="chip">${ICONS[r.src] || '•'} ${esc(ACT[r.action] || r.action)}</span>
                                <span class="wr-act-title">${esc(r.title || '—')}</span>
                                <span class="muted" style="white-space:nowrap">${fmtDate(r.ts)}</span>
                        </div>
                        ${addrLine}
                        ${canAdd && canEdit() ? '<button class="btn btn-sm btn-primary wr-act-add" data-wr-add="' + esc(String(r.id)) + '" data-wr-src="' + esc(r.src) + '" title="افزودن همین اقدام به گزارش کار">➕ افزودن به گزارش کار</button>' : ''}
                </div>`;
        }

        /** افزودن ردیف فعالیت به گزارش کار (ساخت خط در سرور) */
        async function addFromActivity(rowId, src) {
                if (!canEdit()) { toast('این گزارش متعلق به شما نیست.', 'warn'); return; }
                try {
                        const res = await TPP.api.request('POST', 'workreport/from_activity', {
                                src: src === 'view' ? 'view' : 'change',
                                row_id: rowId,
                                date: wr.date
                        });
                        toast('✅ به گزارش کار ' + esc(jal(wr.date)) + ' اضافه شد.', 'success', 5000);
                        loadDay();
                } catch (e) {
                        toast('خطا در افزودن: ' + esc(e.message), 'error', 7000);
                }
        }

        /* ============================================================
         * افزودن دستی (۱.۱۹.۰): تقویم + فهرست اقدامات + جستجوی کامل سرویس با فیلتر/صفحه‌بندی
         * ============================================================ */

        function actionsHtml() {
                const groups = (wr.data && wr.data.actions) || [];
                return groups.map((g) =>
                        '<optgroup label="' + esc(g.group) + '">' + g.items.map((a) => '<option value="' + esc(a) + '">' + esc(a) + '</option>').join('') + '</optgroup>').join('');
        }

        /** خط نمایش سرویس در نتایج جستجو — شناسه + آدرس کامل + بلوک/پلاک/واحد */
        function svcLine(row) {
                const a = row && row.address ? row.address : {};
                const parts = [a.f_full_address, a.f_block, a.f_plate ? 'پلاک ' + a.f_plate : '', a.f_unit ? 'واحد ' + a.f_unit : ''].filter(Boolean);
                return 'سرویس شماره #' + row.id + ' به ' + (parts.join('، ') || 'بدون آدرس') + (row.f_virtual_number ? ' و شماره مجازی ' + row.f_virtual_number : '');
        }

        function svcLineParts(row) {
                const a = row && row.address ? row.address : {};
                const parts = [a.f_full_address, a.f_block, a.f_plate ? 'پلاک ' + a.f_plate : '', a.f_unit ? 'واحد ' + a.f_unit : ''].filter(Boolean);
                return {
                        id: 'سرویس #' + row.id,
                        addr: parts.join('، ') || 'بدون آدرس',
                        virt: row.f_virtual_number || ''
                };
        }

        function openManualAdd() {
                if (!canEdit()) { toast('این گزارش متعلق به شما نیست.', 'warn'); return; }
                let picked = null; // سرویس انتخاب‌شده
                // وضعیت جستجوی سرویس (مثل صفحه سرویس‌ها: متن + فیلتر + صفحه‌بندی)
                const search = { q: '', filters: {}, prog: '', cat: '', tags: [], page: 1, total: 0, per: 10 };

                const schema = TPP.app.state().schema || { service: [], address: [] };
                const filterFields = (schema.address || []).concat(schema.service || []).filter((f) => f.is_searchable);

                const m = modal(
                        '<div class="modal-head"><h3>➕ افزودن دستی به گزارش کار — ' + esc(jal(wr.date)) + '</h3><button class="modal-close" data-close>×</button></div>' +
                        '<div class="modal-body wr-manual-body">' +
                        '<div class="field"><label>تاریخ گزارش <span class="muted">(کلیک برای انتخاب از تقویم)</span></label>' +
                        '<div class="wr-svc-search">' +
                        '<input type="text" id="wr-m-date" class="btn cal-input" readonly value="' + esc(jal(wr.date)) + '" style="min-width:160px">' +
                        '<button class="btn" id="wr-m-cal" title="بازکردن تقویم — روزهای دارای فعالیت هایلایت شده‌اند">🗓 تقویم</button>' +
                        '</div><div id="wr-m-calbox" class="wr-calbox hidden"></div></div>' +
                        '<div class="field"><label>انتخاب سرویس (اختیاری) — جستجو در همه فیلدها</label>' +
                        '<div class="wr-svc-search">' +
                        '<input type="text" id="wr-svc-q" class="btn" placeholder="جستجو در همه فیلدهای سرویس/آدرس — مثال: تلفن، آدرس، سریال…">' +
                        '<button class="btn" id="wr-svc-filter" title="جستجو/اعمال فیلترها">🔎 جستجو</button>' +
                        '<button class="btn" id="wr-svc-filter-toggle" title="فیلتر بر اساس مقادیر اختصاصی هر فیلد (مثل صفحه سرویس‌ها)">🎛 فیلتر</button>' +
                        '</div>' +
                        '<div id="wr-filter-panel" class="filters-panel hidden" style="margin-top:8px">' +
                        '<div class="filters-title"><span>فیلتر بر اساس فیلدها / وضعیت دایری / دسته‌بندی و تگ:</span><button class="btn btn-sm" id="wr-f-clear">پاک‌کردن فیلترها</button></div>' +
                        '<div class="prog-filter">' +
                        '<label class="pf-label">🚀 وضعیت دایری:</label>' +
                        '<select id="wr-f-prog" class="btn"><option value="">همه وضعیت‌ها</option><option value="progress">🚧 در حال دایری</option><option value="done">✅ دایری کامل</option><option value="none">⏳ شروع نشده</option><option value="fail">❌ خرابی (همه)</option><option value="fail_los">❌ LOS</option><option value="fail_phone">❌ قطع تلفن</option><option value="fail_internet">❌ قطع اینترنت</option><option value="fail_other">❌ سایر</option></select>' +
                        '</div>' +
                        '<div class="grid-3" id="wr-f-cats" style="margin-top:8px"></div>' +
                        '<div class="filters-grid" id="wr-filter-grid" style="margin-top:8px"></div>' +
                        '<div class="filters-actions"><button class="btn btn-primary" id="wr-f-apply">🔍 جستجو با فیلترها</button></div>' +
                        '</div></div>' +
                        '<div id="wr-svc-results" class="wr-svc-results"><p class="muted">عبارت جستجو را وارد کنید و «جستجو» را بزنید — یا با «فیلتر» بر اساس فیلدهای خاص جستجو کنید؛ نتایج صفحه‌بندی شده‌اند و با کلیک انتخاب می‌شوند.</p></div>' +
                        '<div class="field"><label>اقدام انجام شده <span class="muted">(از فهرست انتخاب کنید یا خودتان بنویسید/ویرایش کنید)</span></label>' +
                        '<select id="wr-action-sel" class="btn">' +
                        '<option value="">— انتخاب از فهرست اقدامات —</option>' +
                        actionsHtml() +
                        '<option value="__custom">✍️ سایر (نوشتن دستی)…</option>' +
                        '</select>' +
                        '<textarea id="wr-action" rows="2" style="width:100%;resize:vertical;margin-top:8px" placeholder="مثال: رفع مشکل / تحویل سرویس / نصب مودم / عیب‌یابی فیوژن …"></textarea>' +
                        '<div class="hint">با انتخاب سرویس، متن قلم ساده ساخته می‌شود: «اقدام (آدرس کامل، بلوک، پلاک، واحد) ، دایری سرویس تا مرحله (X) ، مراحل باقیمانده بعدی از مرحله (Y) ، خرابی اعلام‌شده». بدون سرویس فقط متن اقدام ثبت می‌شود.</div></div>' +
                        '<div id="wr-preview" class="wr-preview"></div>' +
                        '</div>' +
                        '<div class="modal-foot"><button class="btn" data-close>انصراف</button><button class="btn btn-primary" id="wr-submit">✅ ثبت در گزارش</button></div>',
                        { wide: true, static: true }
                );

                const el = m.el;
                const dateInput = el.querySelector('#wr-m-date');
                const calBox = el.querySelector('#wr-m-calbox');
                const idInput = null; // انتخاب فقط از نتایج (شناسه دستی حذف شد — درخواست جستجوی کامل)
                const results = el.querySelector('#wr-svc-results');
                const actionSel = el.querySelector('#wr-action-sel');
                const actionInput = el.querySelector('#wr-action');
                const preview = el.querySelector('#wr-preview');

                const renderPicked = () => {
                        if (!picked) { preview.innerHTML = ''; return; }
                        preview.innerHTML = '<div class="chip ok">سرویس انتخاب‌شده: ' + esc(svcLine(picked)) + '</div>';
                };

                /* ---- تقویم انتخاب تاریخ: روزهای دارای فعالیت هایلایت + اخطار نگهداشت ---- */
                const renderMiniCal = () => {
                        const r = wr.data && wr.data.retention;
                        const warn = r && !r.unlimited
                                ? '<div class="alert warn" style="margin:6px 0;font-size:12px">⚠️ تاریخچه فعالیت‌ها فقط ' + faNum(r.days) + ' روز نگهداری می‌شود — اگر به گزارش تبدیل نشود از دست می‌رود.</div>'
                                : '';
                        calBox.innerHTML = warn + calHtml({
                                iso: wr.date,
                                reportDays: reportDaysMap(),
                                activityDays: (wr.data && wr.data.activity_days) || [],
                                selected: wr.date,
                                compact: true
                        });
                        bindCal(calBox, {
                                getIso: () => wr.date,
                                onNav: () => renderMiniCal(),
                                onPick: (iso) => {
                                        wr.date = iso;
                                        dateInput.value = jal(wr.date);
                                        calBox.classList.add('hidden');
                                        el.querySelector('.modal-head h3').textContent = '➕ افزودن دستی به گزارش کار — ' + jal(wr.date);
                                }
                        });
                };
                el.querySelector('#wr-m-cal').addEventListener('click', () => {
                        if (!calBox.classList.contains('hidden')) { calBox.classList.add('hidden'); return; }
                        renderMiniCal();
                        calBox.classList.remove('hidden');
                });

                /* ---- فهرست اقدامات → پرکردن/ویرایش متن ---- */
                actionSel.addEventListener('change', () => {
                        if ('__custom' === actionSel.value) { actionInput.value = ''; actionInput.focus(); return; }
                        if ('' !== actionSel.value) actionInput.value = actionSel.value;
                });

                /* ---- فیلترها: گرید فیلدها + دسته/تگ ---- */
                const grid = el.querySelector('#wr-filter-grid');
                grid.innerHTML = filterFields.map((f) => `
                        <div class="field" style="margin:0">
                                <label>${esc(f.label)}</label>
                                <input type="text" class="btn" data-ff="${esc(f.slug)}" placeholder="مقدار ${esc(f.label)}…" autocomplete="off">
                        </div>`).join('');

                const catsBox = el.querySelector('#wr-f-cats');
                const renderCats = (cats) => {
                        if (!cats || !catsBox) return;
                        const catOpts = '<option value="">همه دسته‌ها</option>' + (cats.categories || []).map((c) =>
                                '<option value="' + esc(String(c.id)) + '">' + esc(c.label) + ' (' + faNum(c.usage) + ')</option>').join('');
                        const tagChips = (cats.tags || []).map((t) =>
                                '<label class="chip tag-chip' + (search.tags.indexOf(t.id) !== -1 ? ' on' : '') + '" data-tag="' + esc(String(t.id)) + '"><input type="checkbox"' + (search.tags.indexOf(t.id) !== -1 ? ' checked' : '') + '> ' + esc(t.label) + '</label>').join('');
                        catsBox.innerHTML = `
                        <div class="field"><label>دسته‌بندی پروژه</label><select id="wr-f-cat" class="btn">${catOpts}</select></div>
                        <div class="field" style="grid-column:span 2"><label>تگ‌ها (چندتایی)</label><div class="tag-chips">${tagChips || '<span class="muted">تگی تعریف نشده — مدیر کل می‌تواند از «دسته‌بندی پروژه‌ها» تعریف کند.</span>'}</div></div>`;
                        const catSel = catsBox.querySelector('#wr-f-cat');
                        if (catSel) catSel.addEventListener('change', () => { search.cat = catSel.value; });
                        catsBox.querySelectorAll('[data-tag]').forEach((lbl) => {
                                const tid = parseInt(lbl.getAttribute('data-tag'), 10);
                                lbl.querySelector('input').addEventListener('change', (e) => {
                                        const idx = search.tags.indexOf(tid);
                                        if (e.target.checked && idx === -1) search.tags.push(tid);
                                        if (!e.target.checked && idx !== -1) search.tags.splice(idx, 1);
                                        lbl.classList.toggle('on', e.target.checked);
                                });
                        });
                };
                if (!wr.cats) {
                        TPP.api.request('GET', 'categories').then((c) => { wr.cats = c; renderCats(c); }).catch(() => renderCats(null));
                } else renderCats(wr.cats);

                const progSel = el.querySelector('#wr-f-prog');
                if (progSel) progSel.addEventListener('change', () => { search.prog = progSel.value; });
                el.querySelector('#wr-f-clear').addEventListener('click', () => {
                        search.filters = {}; search.prog = ''; search.cat = ''; search.tags = [];
                        grid.querySelectorAll('[data-ff]').forEach((i) => { i.value = ''; });
                        if (progSel) progSel.value = '';
                        const catSel = el.querySelector('#wr-f-cat');
                        if (catSel) catSel.value = '';
                        el.querySelectorAll('.tag-chip.on').forEach((l) => { l.classList.remove('on'); const i = l.querySelector('input'); if (i) i.checked = false; });
                });
                el.querySelector('#wr-f-apply').addEventListener('click', () => { collectFilters(); search.page = 1; doSearch(); });
                el.querySelector('#wr-svc-filter-toggle').addEventListener('click', () => {
                        el.querySelector('#wr-filter-panel').classList.toggle('hidden');
                });

                const collectFilters = () => {
                        const f = {};
                        grid.querySelectorAll('[data-ff]').forEach((i) => { if (i.value.trim()) f[i.getAttribute('data-ff')] = i.value.trim(); });
                        search.filters = f;
                };

                /* ---- جستجوی سرویس (متن + فیلتر + صفحه‌بندی — مثل صفحه سرویس‌ها) ---- */
                const showResults = (rows, note) => {
                        if (!rows.length) {
                                results.innerHTML = '<p class="muted">' + esc(note || 'سرویسی یافت نشد — فیلترها را ساده‌تر کنید یا عبارت دیگری بزنید.') + '</p>';
                                return;
                        }
                        results.innerHTML = '<p class="muted">' + esc(note || '') + ' — برای انتخاب روی ردیف کلیک کنید:</p>' +
                                '<div class="wr-svc-list">' + rows.map((r) => {
                                        const p = svcLineParts(r);
                                        return `
                                <div class="wr-svc-row" data-svc="${esc(String(r.id))}">
                                        <span class="chip">#${faNum(r.id)}</span>
                                        <span class="wr-svc-main"><b>${esc(p.id)}</b> — ${esc(p.addr)}${p.virt ? ' <span class="muted">| شماره مجازی: ' + esc(p.virt) + '</span>' : ''}</span>
                                </div>`;
                                }).join('') + '</div>' + resultPagination();
                        results.querySelectorAll('[data-svc]').forEach((row) => row.addEventListener('click', () => {
                                picked = rows.find((r) => String(r.id) === row.getAttribute('data-svc')) || null;
                                results.querySelectorAll('.wr-svc-row').forEach((x) => x.classList.toggle('picked', x === row));
                                renderPicked();
                        }));
                        results.querySelectorAll('[data-wsp]').forEach((b) => b.addEventListener('click', () => {
                                search.page = parseInt(b.getAttribute('data-wsp'), 10) || 1;
                                doSearch();
                        }));
                };

                function resultPagination() {
                        const pages = Math.max(1, Math.ceil(search.total / search.per));
                        if (pages <= 1) return '<p class="muted" style="text-align:center">' + faNum(search.total) + ' نتیجه</p>';
                        let btns = '';
                        const from = Math.max(1, Math.min(search.page - 4, pages - 9));
                        const to = Math.min(pages, from + 9);
                        if (from > 1) btns += '<button class="btn btn-sm" data-wsp="1">۱</button><span class="muted">…</span>';
                        for (let i = from; i <= to; i++) btns += '<button class="btn btn-sm' + (i === search.page ? ' btn-primary' : '') + '" data-wsp="' + i + '">' + faNum(i) + '</button>';
                        if (to < pages) btns += '<span class="muted">…</span><button class="btn btn-sm" data-wsp="' + pages + '">' + faNum(pages) + '</button>';
                        return '<div class="pagination">' + btns + '<span class="page-info">صفحه ' + faNum(search.page) + ' از ' + faNum(pages) + ' — ' + faNum(search.total) + ' نتیجه</span></div>';
                }

                async function doSearch() {
                        const qEl = el.querySelector('#wr-svc-q');
                        search.q = qEl ? qEl.value.trim() : '';
                        const hasFilters = Object.keys(search.filters).length || search.prog || search.cat || search.tags.length;
                        if (!search.q && !hasFilters) { toast('عبارت جستجو را وارد کنید یا از «فیلتر» استفاده کنید.', 'warn'); return; }
                        const btn = el.querySelector('#wr-svc-filter');
                        btn.disabled = true;
                        results.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                        try {
                                const params = { page: search.page, per_page: search.per };
                                if (search.q) params.query = search.q;
                                if (Object.keys(search.filters).length) params.filters = JSON.stringify(search.filters);
                                if (search.prog) params.progress_status = search.prog;
                                if (search.cat) params.category = search.cat;
                                if (search.tags.length) params.tags = search.tags.join(',');
                                const data = await TPP.api.request('GET', 'search', null, params);
                                search.total = parseInt(data.total, 10) || 0;
                                showResults(data.rows || [], (parseInt(data.total, 10) || 0) + ' نتیجه');
                        } catch (e) {
                                results.innerHTML = '<div class="alert err">خطا در جستجو: ' + esc(e.message) + '</div>';
                        } finally { btn.disabled = false; }
                }

                el.querySelector('#wr-svc-filter').addEventListener('click', doSearch);
                el.querySelector('#wr-svc-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') doSearch(); });

                el.querySelector('#wr-submit').addEventListener('click', async () => {
                        const action = actionInput.value.trim();
                        if (!action) { toast('«اقدام انجام شده» را بنویسید یا از فهرست انتخاب کنید.', 'warn'); actionInput.focus(); return; }
                        const btn = el.querySelector('#wr-submit');
                        btn.disabled = true;
                        try {
                                const body = { date: wr.date, content: '' };
                                if (picked) {
                                        body.service_id = parseInt(picked.id, 10);
                                        body.prefix = action; // سرور قالب استاندارد را می‌سازد
                                } else {
                                        body.content = action;
                                }
                                const res = await TPP.api.request('POST', 'workreport', body);
                                toast('✅ «' + esc(action.slice(0, 40)) + (action.length > 40 ? '…' : '') + '» به گزارش کار ' + esc(jal(wr.date)) + ' اضافه شد.', 'success', 5000);
                                m.close();
                                refresh();
                        } catch (e) {
                                toast('خطا در ثبت: ' + esc(e.message), 'error', 7000);
                                btn.disabled = false;
                        }
                });
        }

        /* ============================================================
         * ویرایش قلم
         * ============================================================ */

        function openEditItem(it) {
                const m = modal(
                        '<div class="modal-head"><h3>✏️ ویرایش قلم گزارش #' + faNum(it.sort) + '</h3><button class="modal-close" data-close>×</button></div>' +
                        '<div class="modal-body">' +
                        '<textarea id="wr-edit-text" rows="7" style="width:100%;resize:vertical;direction:rtl">' + esc(it.content) + '</textarea>' +
                        '<div class="hint">متن قلم گزارش — تغییرات در همان روز گزارش (' + esc(jal(it.report_date)) + ') ذخیره می‌شود.</div></div>' +
                        '<div class="modal-foot"><button class="btn" data-close>انصراف</button><button class="btn btn-primary" id="wr-save">💾 ذخیره</button></div>',
                        { static: true, wide: true }
                );
                const ta = m.el.querySelector('#wr-edit-text');
                m.el.querySelector('#wr-save').addEventListener('click', async () => {
                        const val = ta.value.trim();
                        if (!val) { toast('متن خالی است.', 'warn'); return; }
                        try {
                                await TPP.api.request('PUT', 'workreport/' + it.id, { content: val });
                                toast('قلم گزارش به‌روز شد.', 'success');
                                m.close();
                                refresh();
                        } catch (e) { toast('خطا در ذخیره: ' + esc(e.message), 'error'); }
                });
        }
})();
