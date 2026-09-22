/**
 * tpp-views-form.js — فرم ثبت/ویرایش سرویس + انتخابگر آدرس هوشمند + نمای آدرس
 * فرم کاملاً از روی اسکیمای فیلدهای داینامیک ساخته می‌شود (با تغییر فیلدها، فرم هم تغییر می‌کند).
 * ذخیره‌سازی از طریق صف همگان‌سازی (outbox) انجام می‌شود تا آنلاین/آفلاین رفتار یکسان داشته باشد.
 */
'use strict';

TPP.views = TPP.views || {};

(function () {

        const esc = (s) => TPP.app.esc(s);
        const fmtDate = TPP.app.fmtDate;
        const can = TPP.app.can;
        const go = TPP.app.go;
        const toast = TPP.app.toast;
        const confirmBox = TPP.app.confirmBox;
        const modal = TPP.app.modal;
        const faNum = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[+d]); // ۱.۱۹.۰ — ارقام فارسی
        const faToEn = (s) => TPP.app.faToEnDigits(s);

        /* ۱.۱۹.۰ — دسته/تگ فعلی ردیف (حالت فقط-مشاهده یا فرم بدون کنترل) */
        function curCatId(row) { return row && row.category ? (parseInt(row.category.id, 10) || 0) : 0; }
        function curTagIds(row) { return (row && Array.isArray(row.tags) ? row.tags : []).map((t) => parseInt(t.id, 10) || 0).filter(Boolean); }
        function hasCatsNow() {
                const cats = TPP.app.state().cats;
                return !!(cats && cats.categories && cats.categories.length);
        }

        /* ==================== فرم سرویس ==================== */

        /* ترتیب دقیق فیلدهای بخش «اطلاعات اصلی» (درخواست کاربر) */
        const MAIN_ORDER = [
                'f_modem_model',   // مدل مودم (اجباری)
                'f_modem_serial',  // سریال مودم (اجباری)
                'f_virtual_number',// شماره مجازی (اجباری)
                'f_internet_pass', // پسورد اینترنت (اجباری)
                'f_phone',         // شماره تلفن ثابت (اجباری — بدون تلفن: 0)
                'f_sip_pass',      // Sip Pass (اجباری — بدون تلفن/پسورد: 0)
                'f_sip_ip',        // Sip IP (اجباری — کشویی DHCP / آیپی استاتیک)
                'f_subnet',        // Subnet Mask (اجباری فقط در حالت آیپی استاتیک)
                'f_gateway',       // Gateway (اجباری فقط در حالت آیپی استاتیک)
                'f_sbc',           // SBC (همواره نمایان — کشویی خودکار توسط یارا / تنظیم دستی)
                'f_standby_proxy', // StandBy Proxy (همواره نمایان — کشویی خودکار توسط یارا / تنظیم دستی)
        ];

        /* فیلدهایی که فقط در حالت «آیپی استاتیک» معتبر/نمایان هستند */
        const SIP_STATIC_ONLY = ['f_subnet', 'f_gateway'];

        /* فیلدهای حالت‌دار SBC / StandBy Proxy — همیشه نمایان، انتخاب «خودکار توسط یارا» یا «تنظیم دستی» + آدرس.
           مقدار ذخیره‌شده: «AUTO» (خودکار) یا خودِ آدرس (دستی). مقدار خالی = حالت خودکار. */
        const MODE_FIELDS = ['f_sbc', 'f_standby_proxy'];
        const MODE_AUTO_VALUE = 'AUTO';

        /* اعلان داخل کادر (placeholder + راهنمای زیر فیلد) */
        const FIELD_NOTES = {
                f_phone:   'در صورتی که مشترک شماره تلفن ندارد، عدد 0 تایپ شود',
                f_sip_pass: 'در صورتی که مشترک شماره تلفن ندارد یا پسورد موجود نیست، عدد 0 تایپ شود',
        };

        /* ==================== پیشرفت دایری سرویس (۱.۱۲.۰) ====================
           ۱۶ مرحله دایری + ۴ وضعیت خرابی — کارت آن بالای «اطلاعات اصلی» می‌نشیند و
           با تیک‌زدن مراحل نوار پیشرفت کامل می‌شود؛ قبل از ذخیره از کاربر پرسیده
           می‌شود که این بخش را بررسی/تکمیل کند و تغییرات در تاریخچه/گزارش فعالیت ثبت می‌شود. */
        const PROGRESS_STEPS_FALLBACK = {
                infra: 'زیرساخت موجود است (پیوستگی دارد)',
                fat: 'FAT و اسپلیترهای آورنده آن نصب شده',
                drop: 'دراپ‌کشی انجام شده',
                fusion_fat: 'فیوژن سمت FAT انجام شده',
                atb: 'فیوژن و نصب ATB داخل واحد انجام شده',
                patch: 'پچ‌کورد دارد یا داده می‌شود',
                modem: 'مودم دارد یا داده می‌شود',
                registered: 'ثبت‌نام شده است',
                ready: 'سرویس آماده تحویل می‌باشد',
                sip_info: 'اطلاعات مربوط به سیپ‌فون از مرکز دریافت شده یا در یارا موجود می‌باشد',
                inet_config: 'تنظیمات اینترنت انجام شده است',
                inet_omc: 'اینترنت جهت دایری به OMC ارسال شده است یا دکمه ZTP زده شده است',
                inet_connected: 'اینترنت متصل می‌باشد',
                sip_config: 'تنظیمات سیپ‌فون انجام شده است',
                sip_omc: 'اطلاعات سیپ‌فون جهت دایری به OMC ارسال شده است یا دکمه ZTP زده شده است',
                phone_connected: 'تلفن متصل می‌باشد'
        };
        const PROGRESS_FAILURES_FALLBACK = {
                los: 'مشترک LOS می‌باشد (اینترنت و تلفن قطع می‌باشد)',
                phone: 'تلفن مشترک قطع می‌باشد',
                internet: 'اینترنت مشترک قطع می‌باشد',
                other: 'سایر'
        };

        /** کاتالوگ مراحل/خرابی‌ها — از bootstrap سرور؛ در نبود آن (نسخه قدیم سرور) مقادیر جاری */
        function progressCatalog() {
                const st = TPP.app.state();
                const p = st && st.progress;
                const steps = (p && p.steps && Object.keys(p.steps).length) ? p.steps : PROGRESS_STEPS_FALLBACK;
                const failures = (p && p.failures && Object.keys(p.failures).length) ? p.failures : PROGRESS_FAILURES_FALLBACK;
                return { steps, failures };
        }

        /** وضعیت فعلی پیشرفت از DOM (برای ذخیره/بررسی) — ۱.۱۳.۰: شامل مراحل ردشده؛ ۱.۱۴.۰: خرابی‌های چندتایی */
        function collectProgress() {
                const card = document.getElementById('prog-card');
                const steps = [];
                const skipped = [];
                const failures = [];
                if (card) {
                        card.querySelectorAll('input[data-step]').forEach((el) => {
                                if (el.checked) steps.push(el.dataset.step);
                                else {
                                        const lbl = el.closest('label.prog-step');
                                        if (lbl && lbl.classList.contains('skipped')) skipped.push(el.dataset.step);
                                }
                        });
                        // ۱.۱۴.۰ — خرابی‌ها مستقل از هم تیک می‌خورند (مثلاً «سایر» + «اینترنت قطع است» با هم)
                        card.querySelectorAll('input[data-fail]').forEach((el) => { if (el.checked) failures.push(el.dataset.fail); });
                }
                return { steps, skipped, failures, failure: failures[0] || '' };
        }

        /** خلاصه پیشرفت برای ردیف محلی (کش IDB آفلاین) — معادل خروجی progress سرور — ۱.۱۴.۰: خرابی چندتایی */
        function localProgressSummary(prog) {
                const { steps, failures } = progressCatalog();
                const keys = Object.keys(steps);
                const valid = (prog.steps || []).filter((k) => keys.indexOf(k) !== -1);
                const excluded = (prog.excluded || prog.skipped || []).filter((k) => keys.indexOf(k) !== -1);
                const failKeys = (prog.failures && prog.failures.length) ? prog.failures : (prog.failure ? [prog.failure] : []);
                const failLabels = failKeys.map((k) => failures[k] || k);
                const done = valid.length;
                const total = keys.length;
                const last = done ? valid[done - 1] : '';
                return {
                        steps: valid,
                        excluded,
                        excluded_count: excluded.length,
                        failures: failKeys,
                        failures_labels: failLabels,
                        failure: failKeys[0] || '',
                        failure_label: failLabels[0] || '',
                        done: done,
                        total: total,
                        pct: total ? Math.round(done * 100 / total) : 0,
                        status: (done >= total && total > 0) ? 'done' : (done > 0 ? 'progress' : 'none'),
                        last_step: last,
                        last_label: last ? steps[last] : ''
                };
        }

        /** HTML کارت پیشرفت دایری — بالای اطلاعات اصلی (canEdit=false → فقط-مشاهده) */
        function progressCardHtml(row, canEdit, descField) {
                const { steps, failures } = progressCatalog();
                const p = (row && row.progress) || {};
                const checked = new Set(p.steps || []);
                const skippedSet = new Set(p.excluded || p.skipped || []); // ۱.۱۳.۰ — مراحل ردشده توسط کاربر
                const total = Object.keys(steps).length;
                // ۱.۱۴.۰ — خرابی‌ها آرایه‌اند؛ برای سازگاری با داده قدیمی failure تکی هم پشتیبانی می‌شود
                const failSet = new Set((p.failures && p.failures.length) ? p.failures : (p.failure ? [p.failure] : []));
                const dis = canEdit ? '' : ' disabled';
                const stepHtml = Object.entries(steps).map(([key, label]) => `
                        <label class="prog-step${checked.has(key) ? ' done' : ''}${skippedSet.has(key) ? ' skipped' : ''}" title="${skippedSet.has(key) ? 'ردشده توسط کاربر — آبشار از این مرحله می‌پرد (تیک دوباره = رفع ردشدگی)' : ''}">
                                <input type="checkbox" id="prog-step-${esc(key)}" data-step="${esc(key)}"${checked.has(key) ? ' checked' : ''}${dis}>
                                <span class="prog-step-box"></span>
                                <span class="prog-step-label">${esc(label)}</span>
                        </label>`).join('');
                const failHtml = Object.entries(failures).map(([key, label]) => `
                        <label class="prog-fail-opt${failSet.has(key) ? ' on' : ''}${key === 'other' ? ' other' : ''}" title="۱.۱۴.۰ — چند خرابی را می‌توان همزمان انتخاب کرد">
                                <input type="checkbox" id="prog-fail-${esc(key)}" data-fail="${esc(key)}"${failSet.has(key) ? ' checked' : ''}${dis}>
                                <span class="prog-step-box"></span>
                                <span class="prog-step-label">${esc(label)}</span>
                        </label>`).join('');
                // «توضیحات» (درخواست کاربر) به بخش خرابی «سایر» منتقل شد
                const descHtml = descField ? `
                        <div class="prog-desc${failSet.has('other') ? ' show' : ''}" id="prog-desc-wrap">
                                ${fieldHtml(descField, row ? row.f_description : '', 'svc-', !canEdit)}
                        </div>` : '';
                return `
                <div class="card prog-card" id="prog-card">
                        <h3>🚀 پیشرفت دایری سرویس</h3>
                        <div class="prog-summary">
                                <div class="prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="prog-bar">
                                        <div class="prog-fill" id="prog-fill"></div>
                                </div>
                                <div class="prog-meta">
                                        <span class="prog-count" id="prog-count"></span>
                                        <span class="chip" id="prog-chip"></span>
                                </div>
                        </div>
                        <div class="prog-steps" id="prog-steps">${stepHtml}</div>
                        <div class="prog-fail">
                                <div class="prog-fail-head">⚠️ مشترک اعلام خرابی نموده است <span class="muted">— با انتخاب، سرویس در فهرست سرویس‌ها به رنگ قرمز نمایش داده می‌شود؛ ۱.۱۴.۰: می‌توان چند خرابی را همزمان علامت زد</span></div>
                                <div class="prog-fail-opts">${failHtml}</div>
                                ${descHtml}
                                <div class="hint">«سایر» انتخاب شود، شرح خرابی در همین بخش «توضیحات» نوشته می‌شود.</div>
                        </div>
                </div>`;
        }

        /** اتصال رفتار کارت پیشرفت — خروجی تابع refresh (برای پس از اعمال پیش‌نویس) */
        function bindProgress() {
                const card = document.getElementById('prog-card');
                if (!card) return null;
                const { steps } = progressCatalog();
                const total = Object.keys(steps).length;
                const fill = () => document.getElementById('prog-fill');
                const count = () => document.getElementById('prog-count');
                const chip = () => document.getElementById('prog-chip');
                const refresh = () => {
                        const prog = collectProgress();
                        const done = prog.steps.length;
                        const pct = total ? Math.round(done * 100 / total) : 0;
                        const f = fill();
                        if (f) { f.style.width = pct + '%'; }
                        const bar = document.getElementById('prog-bar');
                        if (bar) bar.setAttribute('aria-valuenow', String(pct));
                        const c = count();
                        if (c) {
                                let txt = done.toLocaleString('fa-IR') + ' از ' + total.toLocaleString('fa-IR') + ' مرحله (' + pct.toLocaleString('fa-IR') + '٪)';
                                if (prog.skipped.length) txt += ' — ' + prog.skipped.length.toLocaleString('fa-IR') + ' مرحله ردشده';
                                c.textContent = txt;
                        }
                        const ch = chip();
                        if (ch) {
                                let cls = 'chip', txt = '⏳ دایری شروع نشده';
                                const failCount = prog.failures.length;
                                if (failCount) { cls = 'chip err'; txt = failCount > 1 ? ('❌ خرابی اعلام‌شده (' + failCount.toLocaleString('fa-IR') + ' مورد)') : '❌ خرابی اعلام‌شده'; }
                                else if (done >= total && total > 0) { cls = 'chip ok'; txt = '✅ دایری کامل'; }
                                else if (done > 0) { cls = 'chip warn'; txt = '🚧 در حال دایری'; }
                                ch.className = cls;
                                ch.textContent = txt;
                        }
                        // کلاس done روی هر مرحله + حالت قرمز کارت + نمایش توضیحات «سایر»
                        card.querySelectorAll('label.prog-step').forEach((l) => {
                                const inp = l.querySelector('input[data-step]');
                                l.classList.toggle('done', !!(inp && inp.checked));
                        });
                        card.classList.toggle('has-failure', !!prog.failures.length);
                        card.querySelectorAll('label.prog-fail-opt').forEach((l) => {
                                const inp = l.querySelector('input[data-fail]');
                                l.classList.toggle('on', !!(inp && inp.checked));
                        });
                        const dw = document.getElementById('prog-desc-wrap');
                        if (dw) dw.classList.toggle('show', prog.failures.indexOf('other') !== -1);
                };
                // ۱.۱۳.۰ — منطق آبشاری مراحل وابسته (معادل TPP_Progress::apply سرور):
                // تیک مرحله N → مراحل قبل خودکار تیک می‌خورند (به‌جز «ردشده‌ها» — پرش آبشار)؛
                // تیک مستقیم مرحله ردشده = رفع ردشدگی؛ برداشتن تیک با وجود مرحله بعدی تیک‌دار = «ردشده»؛
                // برداشتن تیک آخرین مرحله = عقب‌گرد طبیعی (بدون ردشدگی).
                let cascading = false;
                const stepInput = (k) => card.querySelector('input[data-step="' + k + '"]');
                const markSkipped = (k, on) => {
                        const inp = stepInput(k);
                        const lbl = inp ? inp.closest('label.prog-step') : null;
                        if (lbl) lbl.classList.toggle('skipped', !!on);
                };
                const onStepChange = (el) => {
                        if (cascading) return; // تغییر آبشاری برنامه‌ای — ردشدگی دست‌نخورده می‌ماند
                        const keys = Object.keys(steps);
                        const idx = keys.indexOf(el.dataset.step);
                        if (idx < 0) { refresh(); return; }
                        if (el.checked) {
                                markSkipped(el.dataset.step, false); // تیک دوباره = رفع ردشدگی
                                cascading = true;
                                for (let i = 0; i < idx; i++) {
                                        const k = keys[i];
                                        const prev = stepInput(k);
                                        if (prev && !prev.checked && !prev.closest('label.prog-step').classList.contains('skipped')) {
                                                prev.checked = true; // آبشار — مراحل قبلی غیرردشده
                                        }
                                }
                                cascading = false;
                        } else {
                                const later = keys.slice(idx + 1).some((k) => { const c = stepInput(k); return c && c.checked; });
                                markSkipped(el.dataset.step, !!later);
                        }
                        refresh();
                };
                card.querySelectorAll('input[data-step]').forEach((el) => el.addEventListener('change', () => onStepChange(el)));
                // ۱.۱۴.۰ — خرابی‌ها مستقل از هم انتخاب می‌شوند (چند خرابی همزمان، مثلاً «سایر» + «اینترنت قطع است»);
                // برداشتن تیک هر گزینه فقط همان را رفع می‌کند
                card.querySelectorAll('input[data-fail]').forEach((el) => el.addEventListener('change', () => {
                        if (el.checked && el.dataset.fail === 'other') {
                                const t = document.getElementById('svc-f_description');
                                if (t) { try { t.focus({ preventScroll: true }); } catch (e) {} }
                        }
                        refresh();
                }));
                refresh();
                return refresh;
        }

        /** آیا پیش از ذخیره باید از کاربر درباره پیشرفت دایری پرسید؟ */
        function progressNeedsReview(prog) {
                const { steps } = progressCatalog();
                const total = Object.keys(steps).length;
                // ۱.۱۴.۰ — خرابی‌های چندتایی هم بررسی می‌شوند
                const failKeys = (prog.failures && prog.failures.length) ? prog.failures : (prog.failure ? [prog.failure] : []);
                if (prog.steps.length >= total && !failKeys.length) return false; // کامل و بدون خرابی
                if (failKeys.indexOf('other') !== -1) {
                        const d = document.getElementById('svc-f_description');
                        if (!d || !String(d.value || '').trim()) return true; // سایر بدون شرح
                }
                return true; // ناقص یا خرابی اعلام‌شده → بررسی شود
        }

        /** پرسش قبل از ذخیره — ۱.۱۴.۰: دکمه‌ها به درخواست کاربر «✍️ تکمیل فرم» (بازگشت به کارت) و
           «✅ تکمیل کرده‌ام، ذخیره کن» (ادامه ذخیره) تغییر کردند. */
        function confirmProgress(prog) {
                return new Promise((resolve) => {
                        const { steps } = progressCatalog();
                        const total = Object.keys(steps).length;
                        const done = prog.steps.length;
                        const { failures } = progressCatalog();
                        // ۱.۱۴.۰ — خرابی‌ها می‌توانند چندتایی باشند
                        const failKeys = (prog.failures && prog.failures.length) ? prog.failures : (prog.failure ? [prog.failure] : []);
                        const failTxt = failKeys.length ? (' — خرابی: ' + failKeys.map((k) => (failures[k] || k)).join('، ')) : '';
                        const m = modal(
                                '<div class="modal-head"><h3>🚀 بررسی پیشرفت دایری سرویس</h3><button class="modal-close" data-close>×</button></div>' +
                                '<div class="modal-body"><p style="line-height:2">پیش از ذخیره، بخش «پیشرفت دایری سرویس» را بررسی و تکمیل کنید.<br>' +
                                '<b>' + done.toLocaleString('fa-IR') + ' از ' + total.toLocaleString('fa-IR') + ' مرحله</b> تکمیل شده است' + esc(failTxt) + '.</p>' +
                                '<p class="muted">وضعیت دایری در فهرست سرویس‌ها (سبز/قرمز)، فیلترها و گزارش فعالیت‌ها نمایش داده می‌شود.</p></div>' +
                                '<div class="modal-foot"><button class="btn btn-primary" id="prog-review-btn">✍️ تکمیل فرم</button>' +
                                '<button class="btn" id="prog-save-anyway">✅ تکمیل کرده‌ام، ذخیره کن</button></div>',
                                { static: true }
                        );
                        m.el.querySelector('#prog-review-btn').addEventListener('click', () => { m.close(); resolve(false); });
                        m.el.querySelector('#prog-save-anyway').addEventListener('click', () => { m.close(); resolve(true); });
                        const x = m.el.querySelector('[data-close]');
                        if (x) x.addEventListener('click', () => { m.close(); resolve(false); });
                });
        }

        /* ==================== پیش‌نویس خودکار (۱.۱۰.۰) ====================
           همزمان با درج اطلاعات، مقادیر فرم در حافظه دستگاه (localStorage) ذخیره می‌شود؛
           اگر پنجره مرورگر یا برنامه در میانه کار بسته شود، دفعه بعد از همان‌جا ادامه می‌یابد. */
        const Drafts = (function () {
                const KEY_PREFIX = 'tpp_draft_';
                let scopeKey = null;
                let timer = null;
                let chipEl = null;
                let cleanups = [];

                function uid() {
                        const u = TPP.app.state().user;
                        return u && u.id ? String(u.id) : '0';
                }
                function storageKey(scope) {
                        return KEY_PREFIX + 'u' + uid() + '_' + String(scope).replace(/[^a-zA-Z0-9_]/g, '');
                }

                function read(scope) {
                        try {
                                const raw = localStorage.getItem(storageKey(scope));
                                if (!raw) return null;
                                const d = JSON.parse(raw);
                                return (d && typeof d === 'object' && d.vals) ? d : null;
                        } catch (e) { return null; }
                }
                function write(scope, draft) {
                        try { localStorage.setItem(storageKey(scope), JSON.stringify(draft)); return true; }
                        catch (e) { return false; } // حافظه پر / حالت خصوصی — بی‌صدا نادیده گرفته می‌شود
                }
                function clear(scope) {
                        try { localStorage.removeItem(storageKey(scope)); } catch (e) {}
                }

                /** مقدارهای فعلی همه فیلدهای فرم + جستجوی آدرس + آدرس انتخاب‌شده */
                function collect() {
                        const form = document.getElementById('service-form');
                        if (!form) return null;
                        const vals = {};
                        form.querySelectorAll('input[id], textarea[id], select[id]').forEach((el) => {
                                if (el.type === 'button' || el.type === 'submit') return;
                                if (el.type === 'checkbox') { vals[el.id] = el.checked ? '1' : ''; return; }
                                vals[el.id] = el.value;
                        });
                        const search = document.getElementById('addr-search');
                        return { at: Date.now(), vals, addrSearch: search ? search.value : '' };
                }

                function save() {
                        if (!scopeKey) return;
                        const d = collect();
                        if (d && write(scopeKey, d)) updateChip(d.at);
                }
                function schedule() {
                        clearTimeout(timer);
                        timer = setTimeout(save, 400); // تأخیر کوتاه برای سبک بودن
                }

                /** اعمال پیش‌نویس روی فرم — خروجی: تعداد فیلد اعمال‌شده */
                function apply(draft) {
                        const form = document.getElementById('service-form');
                        if (!form || !draft) return 0;
                        let applied = 0;
                        const MODE_IDS = ['svc-f_sip_ip-mode', 'svc-f_sbc-mode', 'svc-f_standby_proxy-mode'];
                        // ۱) کشویی‌های حالت‌دار اول (رفتار نمایش/مخفی و readonly همگام شود)
                        MODE_IDS.forEach((id) => {
                                const el = document.getElementById(id);
                                if (el && draft.vals[id] !== undefined && el.value !== draft.vals[id]) { el.value = draft.vals[id]; applied++; }
                        });
                        MODE_IDS.forEach((id) => {
                                const el = document.getElementById(id);
                                if (el) { try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {} }
                        });
                        // ۲) بقیه فیلدها
                        Object.entries(draft.vals).forEach(([id, v]) => {
                                if (MODE_IDS.indexOf(id) !== -1) return; // قبلاً اعمال شد
                                const el = document.getElementById(id);
                                if (!el || el.type === 'button' || el.type === 'submit') return;
                                if (el.type === 'checkbox') { el.checked = v === '1'; applied++; return; }
                                if (el.value !== v) { el.value = v; applied++; }
                                try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {} // رشد خودکار کادر
                        });
                        // ۳) جستجوی آدرس + وضعیت آدرس انتخاب‌شده
                        const search = document.getElementById('addr-search');
                        if (search && draft.addrSearch) { search.value = draft.addrSearch; applied++; }
                        const hidden = document.getElementById('selected-address-id');
                        const suggest = document.getElementById('addr-suggest');
                        if (hidden && hidden.value && !hidden.dataset.fixed && suggest) {
                                suggest.innerHTML = '<span class="chip ok">آدرس انتخاب‌شده: #' + esc(hidden.value) + ' — با ذخیره، سرویس به این آدرس متصل می‌شود</span>';
                        }
                        return applied;
                }

                function timeStr(ts) {
                        try {
                                const d = new Date(ts);
                                if (isNaN(d.getTime())) return '';
                                const t = new Date(d.getTime() + 3.5 * 3600000); // ۱.۱۸.۰ — ساعت تهران (+03:30)
                                const FD = '۰۱۲۳۴۵۶۷۸۹';
                                const p2 = (n) => String(n).padStart(2, '0');
                                const fa = (s) => String(s).replace(/[0-9]/g, (dg) => FD[+dg]);
                                return fa(p2(t.getUTCHours()) + ':' + p2(t.getUTCMinutes()));
                        }
                        catch (e) { return ''; }
                }
                function updateChip(at) {
                        if (!chipEl) return;
                        chipEl.textContent = at ? ('📝 پیش‌نویس ذخیره شد: ' + timeStr(at)) : '📝 پیش‌نویس خودکار فعال — با بستن برنامه از بین نمی‌رود';
                }

                /** اتصال به فرم فعلی — scope: 'new' یا 'svc_<id>' */
                function bind(scope, editing, row) {
                        teardown();
                        scopeKey = null;
                        const canSave = can(editing ? 'tpp_edit_services' : 'tpp_create_services');
                        if (!canSave) return; // فرم فقط-مشاهده → پیش‌نویس معنا ندارد
                        scopeKey = scope;

                        // بازیابی خودکار پیش‌نویس موجود
                        const draft = read(scope);
                        let restoredAt = null;
                        if (draft && draft.vals) {
                                // ویرایش: پیش‌نویس قدیمی‌تر از آخرین بروزرسانی سرویس خودکار دور ریخته می‌شود
                                let stale = false;
                                if (row && row.updated_at) {
                                        // ۱.۱۸.۰ — updated_at سرور زمان دیوار تهران است؛ با پسوند +03:30 درست تجزیه می‌شود
                                        const t1 = new Date(draft.at).getTime();
                                        const t2 = Date.parse(String(row.updated_at).replace(' ', 'T') + (/[+-]\d{2}:?\d{2}|Z$/.test(String(row.updated_at)) ? '' : '+03:30'));
                                        if (!isNaN(t1) && !isNaN(t2) && t1 <= t2) stale = true;
                                }
                                if (!stale) {
                                        const n = apply(draft);
                                        if (n > 0) {
                                                restoredAt = draft.at;
                                                const banner = document.createElement('div');
                                                banner.className = 'alert info draft-banner';
                                                banner.innerHTML = '🗂 پیش‌نویس ذخیره‌شده این دستگاه بازیابی شد (' + timeStr(draft.at) + ') — از همان‌جا ادامه دهید.' +
                                                        ' <button type="button" class="btn btn-sm" data-draft-discard>شروع مجدد فرم</button>';
                                                const form = document.getElementById('service-form');
                                                if (form && form.parentNode) form.parentNode.insertBefore(banner, form);
                                                const discardBtn = banner.querySelector('[data-draft-discard]');
                                                if (discardBtn) discardBtn.addEventListener('click', () => {
                                                        clear(scope);
                                                        banner.remove();
                                                        TPP.app.refreshRoute(); // فرم تمیز
                                                });
                                        }
                                } else {
                                        clear(scope); // پیش‌نویس کهنه
                                }
                        }

                        // نشانگر وضعیت پیش‌نویس
                        const actions = document.querySelector('#service-form .actions-row');
                        if (actions) {
                                chipEl = document.createElement('span');
                                chipEl.className = 'draft-chip';
                                updateChip(restoredAt);
                                actions.appendChild(chipEl);
                        }

                        // ذخیره خودکار با هر تغییر (با تأخیر کوتاه)
                        const form = document.getElementById('service-form');
                        if (form) {
                                form.addEventListener('input', schedule);
                                form.addEventListener('change', schedule);
                        }
                        // بستن پنجره/تب → ذخیره فوری بدون تأخیر
                        const flushNow = () => { clearTimeout(timer); save(); };
                        const onVis = () => { if (document.hidden) flushNow(); };
                        window.addEventListener('pagehide', flushNow);
                        document.addEventListener('visibilitychange', onVis);
                        // ذخیره دوره‌ای (هر ۲۰ ثانیه)
                        const interval = setInterval(() => {
                                if (!document.getElementById('service-form')) { teardown(); return; }
                                save();
                        }, 20000);
                        cleanups.push(() => {
                                window.removeEventListener('pagehide', flushNow);
                                document.removeEventListener('visibilitychange', onVis);
                                clearInterval(interval);
                                clearTimeout(timer);
                        });
                        save(); // ذخیره اولیه
                }

                function teardown() {
                        cleanups.forEach((fn) => { try { fn(); } catch (e) {} });
                        cleanups = [];
                        chipEl = null;
                        clearTimeout(timer);
                }

                /** پاک‌سازی پیش‌نویس بعد از ذخیره موفق */
                function clearFor(editing, row) {
                        clear(editing && row ? ('svc_' + String(row.id).replace(/[^a-zA-Z0-9_]/g, '')) : 'new');
                        teardown();
                }

                return { bind, clearFor, save };
        })();

        TPP.views.service = async function (params) {
                const isNew = !params.id || params.id === 'new';
                const isTmp = String(params.id || '').indexOf('tmp_') === 0;
                const addrId = params.query ? params.query.get('address') : new URLSearchParams(location.hash.split('?')[1] || '').get('address');

                let row = null;
                if (!isNew) {
                        if (isTmp) {
                                row = await TPP_IDB.get('services', params.id);
                        } else if (TPP.offline.state().online) {
                                try { row = await TPP.api.request('GET', 'services/' + params.id); }
                                catch (e) {
                                        row = await TPP_IDB.get('services', parseInt(params.id, 10));
                                        if (!row) throw e;
                                }
                        } else {
                                row = await TPP_IDB.get('services', parseInt(params.id, 10));
                                if (!row) {
                                        renderOfflineMissing();
                                        return;
                                }
                        }
                        // حالت آفلاین: اگر تاریخچه در کش سرویس نیست، از کش تاریخچه جداگانه بخوان
                        if (row && can('tpp_view_history') && !row.history) {
                                try { row.history = await TPP.offline.historyForService(params.id); } catch (e) {}
                        }
                }

                const svcFields = TPP.app.state().schema.service || [];
                const addrFields = TPP.app.state().schema.address || [];

                /* تقسیم فیلدهای سرویس به «اطلاعات اصلی» (ترتیب دقیق درخواستی) و «سایر اطلاعات» */
                const mainFields = [];
                MAIN_ORDER.forEach((slug) => {
                        const f = svcFields.find((x) => x.slug === slug);
                        if (f) mainFields.push(f);
                });
                // ۱.۱۲.۰ — «توضیحات» به بخش خرابی «سایر» کارت پیشرفت دایری منتقل شد
                const descField = svcFields.find((f) => f.slug === 'f_description') || null;
                const otherFields = svcFields.filter((f) => MAIN_ORDER.indexOf(f.slug) === -1 && f.slug !== 'f_description');

                const editing = !!row;
                const address = row && row.address ? row.address : (addrId ? { id: parseInt(addrId, 10) } : null);

                /* ۱.۱۹.۰ — دسته‌بندی/تگ‌ها: واکشی (با کش در state اپ برای حالت آفلاین) */
                let cats = TPP.app.state().cats || null;
                if (!cats && TPP.offline.state().online) {
                        try { cats = await TPP.api.request('GET', 'categories'); TPP.app.state().cats = cats; } catch (e) { /* فرم بدون دسته رندر می‌شود */ }
                }
                const hasCats = !!(cats && cats.categories && cats.categories.length);
                const curCat = row && row.category ? parseInt(row.category.id, 10) || 0 : 0;
                const curTags = (row && Array.isArray(row.tags) ? row.tags : []).map((t) => parseInt(t.id, 10) || 0);
                const catEditable = can(editing ? 'tpp_edit_services' : 'tpp_create_services');
                const catCard = `
                <div class="card">
                        <h3>🏷 دسته‌بندی پروژه ${hasCats ? '<span class="req">*</span>' : ''}</h3>
                        <div class="grid-2">
                                <div class="field">
                                        <label>دسته‌بندی ${hasCats ? '<span class="req">*</span>' : ''}</label>
                                        ${catEditable ? `
                                        <select id="svc-category" class="btn">
                                                <option value="">— انتخاب دسته‌بندی —</option>
                                                ${(cats && cats.categories ? cats.categories : []).map((c) => `<option value="${esc(String(c.id))}"${c.id === curCat ? ' selected' : ''}>${esc(c.label)}${c.is_review ? ' ⏳' : ''} (${faNum(c.usage)})</option>`).join('')}
                                        </select>` : (curCat ? `<div class="alert info">${esc((row.category && row.category.label) || '')}</div>` : '<span class="muted">—</span>')}
                                        <div class="hint">${hasCats ? 'فیلد اجباری — از فهرست دسته‌بندی‌های تعریف‌شده (بخش «دسته‌بندی پروژه‌ها») انتخاب کنید. اگر دسته مناسب پیدا نکردید گزینه «⏳ ثبت جهت بازبینی…» را انتخاب کنید تا سرویس به بازبینان ارجاع شود.' : 'هنوز دسته‌بندی‌ای تعریف نشده — مدیر کل می‌تواند از بخش «دسته‌بندی پروژه‌ها» دسته و تگ تعریف کند.'}</div>
                                </div>
                                <div class="field">
                                        <label>تگ‌ها (چندتایی — اختیاری)</label>
                                        ${catEditable ? `
                                        <div class="tag-chips" id="svc-tags">
                                                ${(cats && cats.tags ? cats.tags : []).map((t) => `
                                                <label class="chip tag-chip${curTags.indexOf(t.id) !== -1 ? ' on' : ''}"><input type="checkbox" value="${esc(String(t.id))}"${curTags.indexOf(t.id) !== -1 ? ' checked' : ''}> ${esc(t.label)}</label>`).join('') || '<span class="muted">تگی تعریف نشده است.</span>'}
                                        </div>` : `<div class="tag-chips">${curTags.length ? curTags.map((id) => { const t = (cats && cats.tags ? cats.tags : []).find((x) => x.id === id); return t ? `<span class="chip tag-chip on">${esc(t.label)}</span>` : ''; }).join('') : '<span class="muted">—</span>'}</div>`}
                                </div>
                        </div>
                </div>`;

                const addressLocked = !!(address && address.id);
                // اگر آدرس مشخص است (ثبت سرویس دیگر برای همان آدرس) داده‌های آن را بگیر و فیلدها را پر کن
                if (addressLocked && !row && TPP.offline.state().online) {
                        try {
                                const full = await TPP.api.request('GET', 'addresses/' + address.id);
                                Object.assign(address, full);
                                address.services = undefined;
                                address.history = undefined;
                        } catch (e) {}
                }
                // اگر آدرس مشخص است (سرویس دیگر همان آدرس) داده‌های آن را بگیر و فیلدها را پر کن
                if (addressLocked && !row && TPP.offline.state().online) {
                        try {
                                const full = await TPP.api.request('GET', 'addresses/' + address.id);
                                Object.assign(address, full);
                                address.services = undefined;
                                address.history = undefined;
                        } catch (e) {}
                }

                document.getElementById('page-title').textContent = editing ? 'سرویس #' + (row && row.id) : 'ثبت سرویس جدید';

                document.getElementById('content').innerHTML = `
                ${!TPP.offline.state().online ? '<div class="alert warn">📴 حالت آفلاین — ذخیره در دستگاه انجام و پس از اتصال اینترنت به‌صورت خودکار همگام‌سازی می‌شود.</div>' : ''}
                ${row && row._pending ? '<div class="alert info">این سرویس در صف همگان‌سازی است و پس از اتصال به سرور ثبت نهایی می‌شود.</div>' : ''}

                <form id="service-form" autocomplete="off">
                        <input type="hidden" id="selected-address-id" value="${addressLocked ? address.id : ''}" ${addressLocked ? 'data-fixed="1"' : ''}>
                        ${progressCardHtml(row, can(editing ? 'tpp_edit_services' : 'tpp_create_services'), descField)}
                        ${catCard}
                        ${mainFields.length ? `<div class="card">
                                <h3>⭐ اطلاعات اصلی</h3>
                                <div class="grid-2">
                                        ${mainFields.map((f) => fieldHtml(f, row ? row[f.slug] : '', 'svc-')).join('')}
                                </div>
                        </div>` : ''}

                        <div class="card">
                                <h3>📍 اطلاعات آدرس</h3>
                                <div id="address-picker">
                                        ${!addressLocked ? `
                                                <div class="field">
                                                        <label>جستجوی آدرس موجود (کد پستی، آدرس، بلوک…)</label>
                                                        <div class="main-search" style="position:relative">
                                                                <input type="text" id="addr-search" placeholder="برای استفاده از آدرس ثبت‌شده تایپ کنید…">
                                                                <span class="search-spinner hidden" id="addr-spinner" style="position:absolute;left:10px;top:10px"><span class="spinner"></span></span>
                                                        </div>
                                                        <div id="addr-suggest" class="muted" style="margin-top:8px"></div>
                                                        <div class="hint">اگر خالی بگذارید و فیلدهای آدرس را پر کنید، آدرس جدید ثبت می‌شود؛ اگر آدرس تکراری باشد به همان آدرس متصل می‌شود.</div>
                                                </div>` : `
                                                <div class="alert info">این سرویس به آدرس ثبت‌شده زیر متصل است (شناسه آدرس: ${address.id}). با ویرایش فیلدها، آدرس هم بروزرسانی می‌شود.</div>`}
                                </div>
                                <div class="grid-2">
                                        ${addrFields.map((f) => fieldHtml(f, address ? address[f.slug] : '', 'addr-', !canEditAddress(editing))).join('')}
                                </div>
                                ${addressLocked && can('tpp_view_history') && row ? `<button type="button" class="btn btn-sm" id="addr-history-btn">🕘 تاریخچه کامل این آدرس</button>` : ''}
                        </div>

                        ${otherFields.length ? `<div class="card">
                                <h3>📋 سایر اطلاعات</h3>
                                <div class="grid-2">
                                        ${otherFields.map((f) => fieldHtml(f, row ? row[f.slug] : '', 'svc-')).join('')}
                                </div>
                        </div>` : ''}

                        <div class="card actions-row">
                                ${can(editing ? 'tpp_edit_services' : 'tpp_create_services') ? '<button type="submit" class="btn btn-primary" id="save-btn">💾 ذخیره</button>' : '<div class="alert warn">شما اجازه ویرایش این سرویس را ندارید (فقط مشاهده).</div>'}
                                <button type="button" class="btn" id="cancel-btn">بازگشت</button>
                                ${editing && !isTmp && can('tpp_send_sms') ? '<button type="button" class="btn btn-success" id="sms-btn">📲 ارسال پیامک</button>' : ''}
                                ${editing ? '<button type="button" class="btn" id="copy-omc-btn" title="کپی مشخصات این سرویس بر اساس قالب تنظیم‌شده">📋 کپی مشخصات جهت ارسال پیام برای OMC</button>' : ''}
                                ${editing && can('tpp_delete_services') && !isTmp ? '<button type="button" class="btn btn-danger" id="delete-btn" style="margin-right:auto">🗑 حذف سرویس</button>' : ''}
                        </div>
                </form>

                ${editing ? siblingsHtml(row) : ''}
                ${editing && row.history ? historyCard(row.history) : ''}
                ${editing && row.recent_views ? recentViewsCard(row.recent_views) : ''}
                `;

                bindAddressSuggest();
                bindSipIp();
                bindModeControls();
                bindAutoGrow();
                bindPassToggles();
                // ۱.۱۲.۰ — کارت پیشرفت دایری (نوار + تیک مراحل + خرابی‌ها)
                const progRefresh = bindProgress();
                // ۱.۱۰.۰ — پیش‌نویس خودکار: بازیابی/ذخیره همزمان با تایپ در حافظه دستگاه
                Drafts.bind(editing ? ('svc_' + String(row ? row.id : params.id).replace(/[^a-zA-Z0-9_]/g, '')) : 'new', editing, row);
                // پس از بازیابی پیش‌نویس (چک‌باکس‌ها بدون رویداد change اعمال می‌شوند) نوار پیشرفت تازه شود
                if (progRefresh) progRefresh();
                document.getElementById('cancel-btn').addEventListener('click', () => history.back());

                const form = document.getElementById('service-form');
                form.addEventListener('submit', (e) => { e.preventDefault(); saveService(editing, row, isTmp); });

                const del = document.getElementById('delete-btn');
                if (del) del.addEventListener('click', async () => {
                        if (!await confirmBox('سرویس حذف شود؟ <br><span class="muted">تاریخچه این سرویس هم به‌صورت کامل حذف می‌شود و این عمل قابل بازگشت نیست.</span>', 'حذف سرویس')) return;
                        Drafts.clearFor(true, row); // پیش‌نویس این فرم دیگر لازم نیست
                        await TPP.offline.enqueue('service.delete', { id: parseInt(params.id, 10) });
                        toast('سرویس به‌همراه تاریخچه آن حذف شد (در صف همگام‌سازی).', 'success');
                        go('services');
                });

                // دکمه حذف هر رکورد تاریخچه این سرویس (در صورت دسترسی مدیر)
                document.querySelectorAll('button[data-hdel]').forEach((b) => {
                        b.addEventListener('click', async () => {
                                const hid = parseInt(b.getAttribute('data-hdel'), 10);
                                if (!hid) return;
                                if (!await confirmBox('این رکورد تاریخچه حذف شود؟<br><span class="muted">رکورد حذف‌شده قابل بازگشت نیست و امکان «بازگردانی مقادیر قبلی» آن از بین می‌رود.</span>', 'حذف رکورد')) return;
                                try {
                                        await TPP.offline.deleteHistoryEntries([hid]);
                                        toast('رکورد تاریخچه حذف شد.', 'success');
                                        TPP.app.refreshRoute(); // نوسازی صفحه سرویس
                                } catch (e) { toast('خطا در حذف: ' + esc(e.message), 'error'); }
                        });
                });

                const ah = document.getElementById('addr-history-btn');
                if (ah) ah.addEventListener('click', () => go('address/' + address.id));

                const smsBtn = document.getElementById('sms-btn');
                if (smsBtn) smsBtn.addEventListener('click', async () => {
                        try { await openSmsModal(row); }
                        catch (e) { console.error('SMS modal', e); toast('خطا در باز کردن فرم پیامک: ' + esc(e.message), 'error', 7000); }
                });

                const copyBtn = document.getElementById('copy-omc-btn');
                if (copyBtn) copyBtn.addEventListener('click', async () => {
                        try { await copyOmcDetails(row); }
                        catch (e) { console.error('copyOmc', e); toast('خطا در کپی مشخصات: ' + esc(e.message), 'error', 7000); }
                });

                bindSiblings();
        };

        /* ==================== پیامک + کپی مشخصات ==================== */

        /** کپی متن — نسخه مشترک از TPP.app (با fallback مرورگرهای قدیمی) */
        async function copyText(text) {
                const ok = await TPP.app.copyText(text);
                if (!ok) throw new Error('کپی خودکار در این مرورگر ممکن نشد');
                return true;
        }

        /** دکمه «کپی مشخصات جهت ارسال پیام برای OMC» */
        async function copyOmcDetails(row) {
                const st = TPP.app.state();
                const tpl = (st.sms && st.sms.copy_template) || '';
                const text = TPP.app.renderTemplate(tpl, row);
                if (!String(text).trim()) {
                        toast('قالب کپی مشخصات خالی است — از تنظیمات افزونه بخش «قالب‌ها» آن را تنظیم کنید.', 'warn', 6000);
                        return;
                }
                try {
                        await copyText(text);
                        toast('✅ مشخصات سرویس کپی شد — اکنون در پیام OMC جای‌گذاری کنید.', 'success', 5000);
                } catch (e) {
                        // نمایش دستی برای کپی
                        const m = modal(
                                '<div class="modal-head"><h3>کپی دستی مشخصات</h3><button class="modal-close" data-close>×</button></div>' +
                                '<div class="modal-body"><p class="muted">متن زیر را انتخاب و کپی کنید:</p>' +
                                '<textarea rows="12" style="width:100%;direction:rtl" readonly>' + esc(text) + '</textarea></div>' +
                                '<div class="modal-foot"><button class="btn" data-close>بستن</button></div>'
                        );
                        const ta = m.el.querySelector('textarea');
                        setTimeout(() => { ta.focus(); ta.select(); }, 80);
                }
        }

        /** شمارش‌گر پیامک: هر ۷۰ کاراکتر فارسی = یک پیامک */
        function smsParts(text) {
                const len = String(text || '').length;
                if (!len) return { len: 0, parts: 0 };
                return { len, parts: Math.ceil(len / 70) };
        }

        /**
         * مودال ارسال پیامک برای سرویس — همیشه فرم را نشان می‌دهد:
         * قالب (انتخاب خودکار قالب اول) + متن قابل ویرایش + شماره موبایل قابل ویرایش + شمارنده
         * حتی اگر دریافت قالب‌ها خطا بخورد، فرم با امکان تایپ دستی باز می‌ماند.
         */
        async function openSmsModal(row) {
                const st = TPP.app.state();
                const mobileField = (st.sms && st.sms.mobile_field) || 'f_mobile';
                const initialMobile = (row && row[mobileField]) || '';
                const online = TPP.offline.state().online;
                const smsConfigured = !!(st.sms && st.sms.configured);

                // فرم فوراً باز می‌شود — قالب‌ها در پس‌زمینه بارگذاری می‌شوند
                const m = modal(
                        '<div class="modal-head"><h3>📲 ارسال پیامک برای سرویس #' + esc(row && row.id) + '</h3><button class="modal-close" data-close>×</button></div>' +
                        '<div class="modal-body">' +
                        (!online ? '<div class="alert warn">📴 اتصال اینترنت برقرار نیست — متن را آماده و کپی کنید؛ برای ارسال مستقیم اتصال لازم است.</div>' : '') +
                        (!smsConfigured && online ? '<div class="alert warn">⚠️ پنل پیامک تنظیم نشده است (کلید API در تنظیمات) — می‌توانید متن را کپی و دستی ارسال کنید.</div>' : '') +
                        '<div class="field"><label>قالب پیامک</label>' +
                        '<select id="sms-tpl" class="btn"><option value="">در حال دریافت قالب‌ها…</option></select>' +
                        '<div class="hint" id="sms-tpl-hint"></div></div>' +
                        '<div class="field"><label>شماره موبایل مقصد (قابل ویرایش)</label>' +
                        '<input type="tel" id="sms-mobile" dir="ltr" inputmode="tel" style="width:100%" value="' + esc(initialMobile) + '" placeholder="09xxxxxxxxx">' +
                        '<div class="hint">' + (initialMobile ? 'پیش‌فرض: شماره موبایل ثبت‌شده این سرویس — در صورت نیاز اصلاح کنید' : 'شماره موبایلی برای این سرویس ثبت نشده — شماره مقصد را وارد کنید') + '</div></div>' +
                        '<div class="field"><label>متن پیامک (قابل ویرایش)</label>' +
                        '<textarea id="sms-text" rows="7" style="width:100%;resize:vertical" placeholder="با انتخاب قالب، متن به‌صورت خودکار ساخته می‌شود؛ می‌توانید آن را ویرایش کنید…"></textarea>' +
                        '<div class="hint" id="sms-count"></div></div>' +
                        '</div>' +
                        '<div class="modal-foot">' +
                        '<button class="btn btn-primary" id="sms-send"' + (!online ? ' disabled title="نیازمند اتصال اینترنت"' : '') + '>📤 ارسال پیامک</button>' +
                        '<button class="btn" id="sms-copy">📋 کپی متن</button>' +
                        '<button class="btn" data-close>انصراف</button>' +
                        '</div>',
                        { wide: true }
                );

                const tplSel = m.el.querySelector('#sms-tpl');
                const tplHint = m.el.querySelector('#sms-tpl-hint');
                const txt = m.el.querySelector('#sms-text');
                const counter = m.el.querySelector('#sms-count');
                const mobileInput = m.el.querySelector('#sms-mobile');
                let templates = [];

                function updateCount() {
                        const p = smsParts(txt.value);
                        counter.textContent = p.len ? (p.len + ' کاراکتر ≈ ' + p.parts + ' پیامک') : '';
                }

                function applyTemplate(tpl) {
                        txt.value = TPP.app.renderTemplate((tpl && tpl.body) || '', row);
                        updateCount();
                }

                function fillTemplates(list) {
                        templates = Array.isArray(list) ? list : [];
                        if (!templates.length) {
                                tplSel.innerHTML = '<option value="">— بدون قالب — متن دلخواه</option>';
                                tplHint.textContent = 'قالبی ثبت نشده است؛ می‌توانید متن را مستقیم تایپ کنید یا از تنظیمات افزونه قالب بسازید.';
                                txt.placeholder = 'متن پیامک را اینجا بنویسید…';
                                return;
                        }
                        tplSel.innerHTML = templates.map((t) =>
                                '<option value="' + esc(t.id) + '" data-body="' + esc(t.body) + '">' + esc(t.title) + '</option>'
                        ).join('');
                        tplSel.insertAdjacentHTML('afterbegin', '<option value="">— متن دلخواه (بدون قالب) —</option>');
                        // انتخاب خودکار قالب اول + رندر فوری متن
                        tplSel.selectedIndex = 1;
                        applyTemplate(templates[0]);
                        tplHint.textContent = 'قالب اول به‌صورت خودکار انتخاب و متن آن ساخته شد — قابل ویرایش است.';
                }

                // دریافت قالب‌ها در پس‌زمینه (شکست آن فرم را نمی‌بندد)
                if (online) {
                        TPP.api.request('GET', 'sms/templates').then(fillTemplates).catch((e) => {
                                tplSel.innerHTML = '<option value="">— بدون قالب — متن دلخواه</option>';
                                tplHint.textContent = 'دریافت قالب‌ها ناموفق بود: ' + esc(e.message) + ' — متن را مستقیم تایپ کنید.';
                                txt.placeholder = 'متن پیامک را اینجا بنویسید…';
                        });
                } else {
                        fillTemplates([]);
                }

                tplSel.addEventListener('change', () => {
                        const id = tplSel.value;
                        const tpl = templates.find((t) => String(t.id) === String(id));
                        if (tpl) applyTemplate(tpl);
                        else { txt.value = ''; updateCount(); }
                });
                txt.addEventListener('input', updateCount);

                m.el.querySelector('#sms-copy').addEventListener('click', async () => {
                        if (!txt.value.trim()) { toast('متنی برای کپی وجود ندارد.', 'warn'); return; }
                        try {
                                await TPP.app.copyText(txt.value);
                                toast('متن پیامک کپی شد.', 'success');
                        } catch (e) { toast(e.message, 'error'); }
                });

                const sendBtn = m.el.querySelector('#sms-send');
                sendBtn.addEventListener('click', async () => {
                        const mobile = mobileInput.value.trim();
                        const text = txt.value.trim();
                        if (!mobile) { toast('شماره موبایل مقصد را وارد کنید.', 'warn'); mobileInput.focus(); return; }
                        if (!text) { toast('متن پیامک خالی است.', 'warn'); txt.focus(); return; }
                        sendBtn.disabled = true; sendBtn.textContent = 'در حال ارسال…';
                        try {
                                const res = await TPP.api.request('POST', 'sms/send', {
                                        service_id: parseInt(row.id, 10),
                                        template_id: tplSel.value ? parseInt(tplSel.value, 10) : 0,
                                        mobile,
                                        text
                                });
                                toast('✅ پیامک به ' + esc(res.mobile || mobile) + ' ارسال شد.' + (res.credit !== undefined && res.credit !== null ? ' (موجودی باقیمانده: ' + esc(res.credit) + ')' : ''), 'success', 7000);
                                m.close();
                        } catch (e) {
                                toast('خطا در ارسال پیامک: ' + esc(e.message), 'error', 8000);
                        } finally {
                                sendBtn.disabled = false; sendBtn.textContent = '📤 ارسال پیامک';
                        }
                });
        }

        function canEditAddress(editing) {
                // آدرس در حالت جدید قابل درج است؛ در حالت ویرایش هم قابل ویرایش (با تاریخچه)
                return true;
        }

        /**
         * کنترل ویژه Sip IP — لیست کشویی دوگزینه‌ای (DHCP / آیپی استاتیک) + کادر ورود آیپی در همان جعبه.
         * مقدار ذخیره‌شده: «DHCP» یا خودِ آدرس آیپی استاتیک.
         */
        function sipIpHtml(val, dis) {
                const v   = String(val === undefined || val === null ? '' : val).trim();
                const isDhcp = ('' === v) || ('DHCP' === v.toUpperCase());
                return `<div class="sipip-wrap">
                        <select id="svc-f_sip_ip-mode" class="sipip-mode" ${dis} title="نوع اتصال SIP">
                                <option value="dhcp" ${isDhcp ? 'selected' : ''}>DHCP</option>
                                <option value="static" ${!isDhcp ? 'selected' : ''}>آیپی استاتیک</option>
                        </select>
                        <input type="text" id="svc-f_sip_ip" name="f_sip_ip" dir="ltr" class="sipip-value${isDhcp ? ' is-dhcp' : ''}" value="${esc(isDhcp ? 'DHCP' : v)}" ${isDhcp ? 'readonly' : ''} ${dis} placeholder="آدرس آیپی استاتیک را وارد کنید">
                </div>`;
        }

        /** اتصال رفتار کشویی DHCP/استاتیک + نمایش/پنهان‌سازی فیلدهای شرطی */
        function bindSipIp() {
                const mode  = document.getElementById('svc-f_sip_ip-mode');
                const input = document.getElementById('svc-f_sip_ip');
                if (!mode || !input) return;

                const toggleFields = (dhcp) => {
                        document.querySelectorAll('.sip-static-only').forEach((el) => el.classList.toggle('hidden', dhcp));
                };

                mode.addEventListener('change', () => {
                        const dhcp = mode.value === 'dhcp';
                        if (dhcp) {
                                input.value = 'DHCP';
                                input.readOnly = true;
                                input.classList.add('is-dhcp');
                        } else {
                                if ('DHCP' === String(input.value || '').trim().toUpperCase()) input.value = '';
                                input.readOnly = false;
                                input.classList.remove('is-dhcp');
                                input.focus();
                        }
                        toggleFields(dhcp);
                });

                // وضعیت اولیه بر اساس مقدار بارگذاری‌شده
                toggleFields(mode.value === 'dhcp');
        }

        /**
         * فیلدهای حالت‌دار SBC / StandBy Proxy — همواره نمایان، مستقل از Sip IP:
         * کشویی دوگزینه‌ای (خودکار توسط یارا / میبایست دستی تنظیم شود) + کادر آدرس در همان جعبه.
         * «خودکار» → کادر آدرس پنهان و مقدار AUTO ذخیره می‌شود؛ «دستی» → آدرس اجباری است.
         */
        function modeFieldHtml(f, val, dis) {
                const v = String(val === undefined || val === null ? '' : val).trim();
                const isAuto = ('' === v) || (MODE_AUTO_VALUE === v.toUpperCase());
                const ph = f.slug === 'f_sbc' ? 'آدرس SBC را وارد کنید' : 'آدرس StandBy Proxy را وارد کنید';
                return `<div class="sipip-wrap wide-mode">
                        <select id="svc-${esc(f.slug)}-mode" class="sipip-mode" ${dis} title="نحوه تنظیم ${esc(f.label)}">
                                <option value="auto" ${isAuto ? 'selected' : ''}>خودکار توسط یارا تنظیم می‌شود</option>
                                <option value="manual" ${!isAuto ? 'selected' : ''}>میبایست دستی تنظیم شود</option>
                        </select>
                        <input type="text" id="svc-${esc(f.slug)}" name="${esc(f.slug)}" dir="ltr" class="sipip-value" value="${esc(isAuto ? '' : v)}" ${isAuto ? 'hidden' : ''} ${dis} placeholder="${esc(ph)}">
                </div>`;
        }

        /** اتصال رفتار کشویی‌های خودکار/دستی SBC و StandBy Proxy */
        function bindModeControls() {
                MODE_FIELDS.forEach((slug) => {
                        const mode = document.getElementById('svc-' + slug + '-mode');
                        const input = document.getElementById('svc-' + slug);
                        if (!mode || !input) return;
                        mode.addEventListener('change', () => {
                                if (mode.value === 'auto') {
                                        input.value = '';
                                        input.hidden = true;
                                        input.classList.remove('input-error');
                                } else {
                                        input.hidden = false;
                                        input.focus();
                                }
                        });
                });
        }

        /**
         * رشد خودکار ارتفاع همه فیلدهای متنی — حداقل ۲ خط نمایش داده می‌شود
         * و با زیاد شدن محتوا، ارتفاع کادر به‌صورت خودکار زیاد می‌شود.
         */
        function bindAutoGrow() {
                document.querySelectorAll('textarea.auto-grow').forEach((ta) => {
                        const grow = () => {
                                ta.style.height = 'auto';
                                const min = parseInt(ta.getAttribute('data-min-height') || '0', 10);
                                ta.style.height = Math.max(ta.scrollHeight, min || ta.scrollHeight) + 'px';
                        };
                        ta.addEventListener('input', grow);
                        grow();
                });
        }

        function fieldHtml(f, value, prefix, disabled) {
                const val = value === undefined || value === null ? '' : String(value);
                const req = f.is_required ? '<span class="req">*</span>' : '';
                const dis = disabled ? 'disabled' : '';
                const note = FIELD_NOTES[f.slug] || '';
                const ph = note ? ` placeholder="${esc(note)}"` : '';
                const noteHtml = note ? `<div class="hint">${esc(note)}</div>` : '';
                // فیلدهای شرطی (فقط در حالت آیپی استاتیک)
                const cond = (prefix === 'svc-' && SIP_STATIC_ONLY.indexOf(f.slug) !== -1) ? ' sip-static-only' : '';
                let input = '';

                if (f.slug === 'f_sip_ip' && prefix === 'svc-') {
                        // کشویی DHCP/آیپی استاتیک + ورود آیپی در همان کادر
                        input = sipIpHtml(val, dis);
                } else if (MODE_FIELDS.indexOf(f.slug) !== -1 && prefix === 'svc-') {
                        // کشویی خودکار توسط یارا / تنظیم دستی + کادر آدرس — همواره نمایان
                        input = modeFieldHtml(f, val, dis);
                } else if (f.type === 'select') {
                        input = `<select id="${prefix}${esc(f.slug)}" name="${esc(f.slug)}" ${dis}>
                                <option value="">— انتخاب کنید —</option>
                                ${(f.options || []).map((o) => `<option value="${esc(o)}" ${o === val ? 'selected' : ''}>${esc(o)}</option>`).join('')}
                        </select>`;
                } else if (f.type === 'checkbox') {
                        return `<div class="field${cond}">
                                <label>${esc(f.label)} ${req}</label>
                                <label class="checkbox-row"><input type="checkbox" id="${prefix}${esc(f.slug)}" name="${esc(f.slug)}" ${val === '1' ? 'checked' : ''} ${dis}> بله</label>
                        </div>`;
                } else {
                        /* همه فیلدهای متنی (متن کوتاه/بلند/عدد/تلفن/ایمیل/تاریخ): متن بلند چندخطی
                           با حداقل ۲ خط و رشد خودکار ارتفاع با زیاد شدن اطلاعات */
                        const im = (f.type === 'tel' || f.type === 'number') ? ' inputmode="' + (f.type === 'number' ? 'numeric' : 'tel') + '"' : '';
                        if (f.is_sensitive) {
                                input = `<div class="pass-wrap"><textarea id="${prefix}${esc(f.slug)}" name="${esc(f.slug)}" class="auto-grow masked" rows="2" data-min-height="72"${im}${ph} ${dis}>${esc(val)}</textarea><button type="button" class="pass-toggle" data-toggle="${prefix}${esc(f.slug)}" title="نمایش/مخفی‌کردن مقدار">👁</button></div>`;
                        } else {
                                input = `<textarea id="${prefix}${esc(f.slug)}" name="${esc(f.slug)}" class="auto-grow" rows="2" data-min-height="72"${im}${ph} ${dis}>${esc(val)}</textarea>`;
                        }
                }
                return `<div class="field${cond}"><label>${esc(f.label)} ${req}${f.is_sensitive ? ' <span class="chip">حساس</span>' : ''}</label>${input}${noteHtml}</div>`;
        }

        function bindPassToggles() {
                document.querySelectorAll('.pass-toggle').forEach((b) => {
                        b.addEventListener('click', () => {
                                const inp = document.getElementById(b.getAttribute('data-toggle'));
                                if (!inp) return;
                                if (inp.tagName === 'TEXTAREA') {
                                        inp.classList.toggle('masked'); // ماسک متن بلند
                                } else {
                                        inp.type = inp.type === 'password' ? 'text' : 'password';
                                }
                        });
                });
        }

        /* ---------- پیشنهاد آدرس هنگام تایپ ---------- */

        function bindAddressSuggest() {
                const input = document.getElementById('addr-search');
                if (!input) return;
                const spinner = document.getElementById('addr-spinner');
                const box = document.getElementById('addr-suggest');

                const run = TPP.app.debounce(async () => {
                        const q = input.value.trim();
                        if (q.length < 2) { box.innerHTML = ''; return; }
                        // با پاک‌شدن جستجو، انتخاب آدرس قبلی برداشته می‌شود
                        const hiddenEl = document.getElementById('selected-address-id');
                        if (hiddenEl && !hiddenEl.dataset.fixed) hiddenEl.value = '';
                        spinner.classList.remove('hidden');
                        try {
                                let list = [];
                                if (TPP.offline.state().online) {
                                        try { list = await TPP.api.request('GET', 'addresses/suggest', null, { query: q }); }
                                        catch (e) { list = await localSuggest(q); }
                                } else {
                                        list = await localSuggest(q);
                                }
                                box.innerHTML = list.length
                                        ? list.map((a) => {
                                                const addrText = (TPP.app.state().schema.address || []).map((f) => a[f.slug]).filter(Boolean).join('، ');
                                                return `<button type="button" class="btn btn-sm" style="margin:3px" data-addr="${a.id}" title="${esc(addrText)}">📍 ${esc(addrText).slice(0, 90)}</button>`;
                                        }).join('')
                                        : '<span class="muted">آدرسی یافت نشد — با پر کردن فیلدها آدرس جدید ثبت می‌شود.</span>';
                                box.querySelectorAll('button[data-addr]').forEach((b) => {
                                        b.addEventListener('click', () => {
                                                const a = list.find((x) => String(x.id) === b.getAttribute('data-addr'));
                                                if (!a) return;
                                                (TPP.app.state().schema.address || []).forEach((f) => {
                                                        const el = document.getElementById('addr-' + f.slug);
                                                        if (el) {
                                                                el.value = a[f.slug] !== undefined ? a[f.slug] : '';
                                                                // رشد خودکار کادر با مقدار تازه
                                                                try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
                                                        }
                                                });
                                                const hiddenEl = document.getElementById('selected-address-id');
                                                if (hiddenEl) hiddenEl.value = a.id;
                                                box.innerHTML = '<span class="chip ok">آدرس انتخاب‌شده: #' + esc(a.id) + ' — با ذخیره، سرویس به این آدرس متصل می‌شود</span>';
                                        });
                                });
                        } finally { spinner.classList.add('hidden'); }
                }, 300);

                input.addEventListener('input', run);
        }

        async function localSuggest(q) {
                const rows = await TPP_IDB.all('services');
                const nq = TPP.offline.norm(q);
                const seen = {};
                const out = [];
                for (const r of rows) {
                        if (!r.address) continue;
                        const key = r.address.id || JSON.stringify(r.address);
                        if (seen[key]) continue;
                        const text = (TPP.app.state().schema.address || []).map((f) => r.address[f.slug] || '').join(' ');
                        if (TPP.offline.norm(text).indexOf(nq) !== -1) {
                                seen[key] = 1;
                                out.push(r.address);
                                if (out.length >= 8) break;
                        }
                }
                return out;
        }

        /* ---------- اعتبارسنجی و ذخیره ---------- */

        /**
         * اعتبارسنجی فیلدهای الزامی فرم (سمت کلاینت):
         *  • فیلدهای اجباری اسکیما (اطلاعات اصلی + آدرس کامل و نام مرکز)
         *  • در حالت «آیپی استاتیک»: آدرس آیپی + Subnet + Gateway اجباری‌اند
         *  • SBC و StandBy Proxy همیشه نمایان‌اند: انتخاب «دستی» بدون درج آدرس مجاز نیست
         * اولین فیلد مشکل‌دار هایلایت و متمرکز می‌شود — بدون ریدایرکت.
         */
        function validateForm(schema) {
                const problems = [];
                const mark = (el, msg) => {
                        if (el) {
                                el.classList.add('input-error');
                                if (!problems.length) {
                                        try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
                                        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
                                }
                        }
                        problems.push(msg);
                };
                document.querySelectorAll('#service-form .input-error').forEach((el) => el.classList.remove('input-error'));

                const check = (fields, prefix) => {
                        (fields || []).forEach((f) => {
                                const el = document.getElementById(prefix + f.slug);
                                if (!el) return;
                                const v = String(el.value || '').trim();
                                if (f.is_required && '' === v) {
                                        mark(el, 'فیلد «' + f.label + '» الزامی است.');
                                        return;
                                }
                                if (f.slug === 'f_sip_ip' && prefix === 'svc-') {
                                        const mode = document.getElementById('svc-f_sip_ip-mode');
                                        if (mode && mode.value === 'static' && '' === v) {
                                                mark(el, 'حالت «آیپی استاتیک» انتخاب شده — آدرس آیپی را وارد کنید.');
                                        }
                                }
                        });
                };
                check(schema.service || [], 'svc-');
                check(schema.address || [], 'addr-');

                // فیلدهای شرطی حالت آیپی استاتیک (Subnet/Gateway اجباری)
                const mode = document.getElementById('svc-f_sip_ip-mode');
                if (mode && mode.value === 'static') {
                        ['f_subnet', 'f_gateway'].forEach((slug) => {
                                const el = document.getElementById('svc-' + slug);
                                if (!el) return; // فیلد در اسکیما نیست (حذف شده)
                                if (el.classList.contains('hidden')) return; // مخفی نباید باشد، ولی محض احتیاط
                                if ('' === String(el.value || '').trim()) {
                                        mark(el, 'در حالت آیپی استاتیک، فیلد «' + TPP.app.fieldLabel(slug) + '» الزامی است.');
                                }
                        });
                }

                // SBC / StandBy Proxy: حالت «میبایست دستی تنظیم شود» بدون آدرس مجاز نیست
                MODE_FIELDS.forEach((slug) => {
                        const sel = document.getElementById('svc-' + slug + '-mode');
                        const inp = document.getElementById('svc-' + slug);
                        if (!sel || !inp) return; // فیلد در اسکیما نیست (حذف شده)
                        if (sel.value === 'manual' && '' === String(inp.value || '').trim()) {
                                mark(inp.hidden ? sel : inp, 'برای «' + TPP.app.fieldLabel(slug) + '» گزینه «دستی» انتخاب شده — آدرس آن را وارد کنید.');
                        }
                });

                if (problems.length) {
                        toast('⚠️ ' + problems[0] + (problems.length > 1 ? ' (و ' + (problems.length - 1) + ' خطای دیگر)' : ''), 'error', 9000);
                        return false;
                }
                return true;
        }

        async function saveService(editing, row, isTmp) {
                const schema = TPP.app.state().schema;
                const collect = (prefix, fields) => {
                        const out = {};
                        fields.forEach((f) => {
                                const el = document.getElementById(prefix + f.slug);
                                if (!el) return;
                                if (f.type === 'checkbox') { out[f.slug] = el.checked ? '1' : ''; return; }
                                // ۱.۹.۳: سطرهای جدید حفظ و CRLF نرمال می‌شود (نمایش چندخطی پس از ذخیره)
                                out[f.slug] = el.value.trim().replace(/\r\n?/g, '\n');
                        });
                        return out;
                };
                const serviceData = collect('svc-', schema.service || []);
                const addressData = collect('addr-', schema.address || []);

                // فیلدهای حالت‌دار (SBC / StandBy Proxy): «خودکار توسط یارا» → مقدار AUTO ذخیره می‌شود؛
                // «دستی» → خودِ آدرس. کادر آدرس در حالت خودکار مخفی است و مقدار آن نادیده گرفته می‌شود.
                MODE_FIELDS.forEach((slug) => {
                        const sel = document.getElementById('svc-' + slug + '-mode');
                        if (!sel) return; // فیلد در اسکیما نیست (حذف شده)
                        serviceData[slug] = (sel.value === 'manual') ? String(serviceData[slug] || '').trim() : MODE_AUTO_VALUE;
                });

                /* ۱.۱۹.۰ — دسته‌بندی/تگ‌ها: اعتبارسنجی اجباری + جمع‌آوری */
                const catSel = document.getElementById('svc-category');
                const catId = catSel ? (parseInt(faToEn(String(catSel.value || '')), 10) || 0) : (curCatId(row));
                const tagsBox = document.getElementById('svc-tags');
                const tagIds = tagsBox ? Array.from(tagsBox.querySelectorAll('input:checked')).map((i) => parseInt(faToEn(String(i.value)), 10) || 0).filter(Boolean) : (curTagIds(row));
                if (catSel && hasCatsNow() && catId <= 0) {
                        toast('فیلد الزامی «دسته‌بندی پروژه» خالی است — از فهرست انتخاب کنید.', 'error', 7000);
                        catSel.classList.add('invalid');
                        catSel.focus();
                        catSel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                }

                // اعتبارسنجی قبل از ذخیره — در صورت خطا کاربر در همین صفحه می‌ماند و مقادیر حفظ می‌شود
                if (!validateForm(schema)) return;

                // ۱.۱۲.۰ — پیش از ذخیره، بررسی/تکمیل «پیشرفت دایری سرویس» از کاربر پرسیده می‌شود
                const progressData = collectProgress();
                if (progressNeedsReview(progressData)) {
                        const proceed = await confirmProgress(progressData);
                        if (!proceed) {
                                const card = document.getElementById('prog-card');
                                if (card) {
                                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        card.classList.add('prog-flash');
                                        setTimeout(() => card.classList.remove('prog-flash'), 2000);
                                        const first = card.querySelector('input[data-step]:not(:checked)');
                                        if (first) { try { first.focus({ preventScroll: true }); } catch (e) {} }
                                }
                                return;
                        }
                }

                const btn = document.getElementById('save-btn');
                btn.disabled = true; btn.textContent = 'در حال ذخیره…';

                try {
                        if (!editing) {
                                const hidden = document.getElementById('selected-address-id');
                                const addressId = hidden && hidden.value ? parseInt(hidden.value, 10) : 0;
                                const op = await TPP.offline.enqueue('service.create', {
                                        address_id: addressId || null,
                                        address: addressId ? null : addressData,
                                        service: serviceData,
                                        progress: progressData,
                                        category: catId || null, // ۱.۱۹.۰ — دسته‌بندی
                                        tags: tagIds // ۱.۱۹.۰ — تگ‌ها
                                });
                                // بدون ریدایرکت به فهرست — کاربر در همان صفحه فرم می‌ماند
                                const applied = op && op.result;
                                if (applied && applied.status === 'error') {
                                        toast('ذخیره ناموفق بود: ' + esc(applied.message || 'خطای سرور'), 'error', 9000);
                                        return;
                                }
                                const newId = (applied && applied.data && applied.data.id !== undefined && applied.data.id !== null) ? String(applied.data.id) : 'tmp_' + op.op_id;
                                Drafts.clearFor(false, null); // ثبت موفق — پیش‌نویس فرم جدید پاک شد
                                toast(applied ? '✅ سرویس ثبت شد — در همین صفحه می‌مانید.' : '✅ سرویس ثبت شد (در صف همگام‌سازی) — در همین صفحه می‌مانید.', 'success', 6000);
                                go('service/' + newId);
                        } else {
                                const hidden = document.getElementById('selected-address-id');
                                const addressId = hidden && hidden.value ? parseInt(hidden.value, 10) : (row.address ? row.address.id : 0);
                                const payload = {
                                        id: isTmp ? null : parseInt(row.id, 10),
                                        address_id: addressId || null,
                                        service: serviceData,
                                        address: addressData,
                                        progress: progressData,
                                        category: catId || null, // ۱.۱۹.۰ — دسته‌بندی
                                        tags: tagIds // ۱.۱۹.۰ — تگ‌ها
                                };
                                if (isTmp) {
                                        // هنوز در صف است — جایگزینی عملیات قبلی: عملیات جدید با همان op_id قبلی
                                        const ops = await TPP_IDB.all('outbox');
                                        const prev = ops.find((o) => 'tmp_' + o.op_id === row.id);
                                        if (prev) {
                                                payload.id = null;
                                                prev.payload.service = { ...prev.payload.service, ...serviceData };
                                                prev.payload.address = { ...(prev.payload.address || {}), ...addressData };
                                                prev.payload.progress = progressData; // ۱.۱۲.۰ — پیشرفت دایری هم در عملیات صف به‌روز می‌شود
                                                prev.payload.category = catId || null; // ۱.۱۹.۰
                                                prev.payload.tags = tagIds; // ۱.۱۹.۰
                                                await TPP_IDB.set('outbox', prev.op_id, prev);
                                                // اعمال محلی
                                                const localRow = await TPP_IDB.get('services', row.id);
                                                if (localRow) {
                                                        Object.assign(localRow, serviceData);
                                                        localRow.address = { ...(localRow.address || {}), ...addressData };
                                                        localRow.progress = localProgressSummary(progressData); // ۱.۱۲.۰
                                                        await TPP_IDB.set('services', localRow.id, localRow);
                                                }
                                                toast('✅ تغییرات ذخیره شد (به عملیات در صف اضافه شد).', 'success', 6000);
                                                Drafts.clearFor(true, row);
                                                TPP.offline.flush().catch(() => {});
                                                TPP.app.refreshRoute(); // ماندن در همین صفحه
                                                return;
                                        }
                                }
                                const op = await TPP.offline.enqueue('service.update', payload, {
                                        base_version: parseInt(row.version, 10) || 0
                                });
                                // بدون ریدایرکت — صفحه همانجا با داده ذخیره‌شده نوسازی می‌شود
                                const applied = op && op.result;
                                if (applied && applied.status === 'error') {
                                        toast('ذخیره ناموفق بود: ' + esc(applied.message || 'خطای سرور'), 'error', 9000);
                                        return;
                                }
                                toast('✅ تغییرات ذخیره شد.' + (applied && applied.conflict ? ' (تغییر همزمان کاربر دیگر ثبت شده — به‌عنوان آخرین بازبینی)' : ''), 'success', 6000);
                                Drafts.clearFor(true, row); // ذخیره موفق — پیش‌نویس پاک شد
                                TPP.app.refreshRoute();
                        }
                } catch (e) {
                        toast('خطا در ذخیره: ' + esc(e.message), 'error');
                } finally {
                        btn.disabled = false; btn.textContent = '💾 ذخیره';
                }
        }

        /* ---------- سرویس‌های دیگر همین آدرس ---------- */

        function siblingsHtml(row) {
                if (!row.siblings || !row.siblings.length) return '';
                const schema = TPP.app.state().schema;
                const nameF = (schema.service || []).find((f) => f.slug === 'f_owner_name') || (schema.service || [])[0];
                const phoneF = (schema.service || []).find((f) => f.slug === 'f_phone');
                return `
                <div class="card">
                        <h3>🏢 سرویس‌های دیگر این آدرس (${row.siblings.length})</h3>
                        <div class="table-wrap"><table class="tpp-table">
                                <thead><tr><th>#</th>${nameF ? '<th>' + esc(nameF.label) + '</th>' : ''}${phoneF ? '<th>' + esc(phoneF.label) + '</th>' : ''}<th>بروزرسانی</th></tr></thead>
                                <tbody>
                                ${row.siblings.map((s) => `<tr data-sib="${s.id}">
                                        <td>${esc(s.id)}</td>
                                        ${nameF ? '<td>' + esc(s[nameF.slug] || '') + '</td>' : ''}
                                        ${phoneF ? '<td class="num-cell">' + esc(s[phoneF.slug] || '') + '</td>' : ''}
                                        <td class="muted">${fmtDate(s.updated_at)}</td>
                                </tr>`).join('')}
                                </tbody>
                        </table></div>
                        <div class="actions-row">
                                ${can('tpp_create_services') ? `<button class="btn" id="add-sibling">➕ افزودن سرویس دیگر برای این آدرس</button>` : ''}
                        </div>
                </div>`;
        }

        function bindSiblings() {
                document.querySelectorAll('tr[data-sib]').forEach((tr) => {
                        tr.addEventListener('click', () => go('service/' + tr.getAttribute('data-sib')));
                });
                const add = document.getElementById('add-sibling');
                if (add) add.addEventListener('click', () => {
                        const hiddenEl = document.getElementById('selected-address-id');
                        const addrId2 = hiddenEl ? hiddenEl.value : '';
                        go('service/new' + (addrId2 ? '?address=' + addrId2 : ''));
                });
        }

        function historyCard(history) {
                const canDel = can('tpp_delete_history');
                return `
                <div class="card">
                        <h3>🕘 تاریخچه تغییرات این سرویس</h3>
                        <p class="muted">تغییرات هر روز در یک رکورد جمع می‌شوند — ساعت هر تغییر داخل رکورد مشخص است. «بازگردانی مقادیر قبلی» وضعیت قبل از اولین تغییرِ آن روز را برمی‌گرداند.</p>
                        <div class="timeline">
                        ${history.map((h) => `
                                <div class="tl-item ${h.is_conflict ? 'conflict' : ''}">
                                        <div class="tl-head"><b>${esc(h.user_name)}</b>
                                                <span class="chip">${actionFa(h.action)}</span>
                                                ${(h.changes && h.changes.fmt === 2 && Array.isArray(h.changes.events) && h.changes.events.length > 1) ? '<span class="chip ok">' + h.changes.events.length + ' تغییر امروز</span>' : ''}
                                                ${h.is_conflict ? '<span class="chip warn">تعارض</span>' : ''}
                                                ${h.source === 'offline' ? '<span class="chip">آفلاین</span>' : ''}
                                                ${canDel ? `<button class="btn btn-sm btn-danger tl-del" data-hdel="${h.id}" title="حذف این رکورد تاریخچه">🗑</button>` : ''}
                                        </div>
                                        <div class="tl-meta">${fmtDate(h.changed_at)} — بازبینی ${h.revision}</div>
                                        <div class="tl-changes">${changesTable(h.changes)}</div>
                                </div>`).join('')}
                        </div>
                        ${canDel ? '<p class="muted" style="margin-top:8px">برای حذف هر رکورد تاریخچه از دکمه 🗑 همان ردیف استفاده کنید.</p>' : ''}
                </div>`;
        }

        /** کارت بازدیدهای اخیر این سرویس (۱.۱۰.۰ — برای دارندگان قابلیت گزارش فعالیت) */
        function recentViewsCard(views) {
                if (!views || !views.length) return '';
                return `
                <div class="card">
                        <h3>👁 بازدیدهای اخیر این سرویس</h3>
                        <div class="table-wrap"><table class="tpp-table">
                                <thead><tr><th>کاربر</th><th>تعداد بازدید</th><th>اولین بازدید</th><th>آخرین بازدید</th></tr></thead>
                                <tbody>
                                ${views.map((v) => `<tr>
                                        <td>${esc(v.user_name || '')}</td>
                                        <td class="num-cell">${esc(v.views || 1)}</td>
                                        <td class="muted">${fmtDate(v.first_at)}</td>
                                        <td class="muted">${fmtDate(v.last_at)}</td>
                                </tr>`).join('')}
                                </tbody>
                        </table></div>
                        <p class="muted">بازدیدهای پیوسته یک کاربر در بازه ۱۵ دقیقه یکجا شمرده می‌شوند. گزارش کامل در «گزارش فعالیت».</p>
                </div>`;
        }

        function actionFa(a) {
                return { create: 'ثبت', update: 'ویرایش', import: 'ایمپورت', delete: 'حذف', restore: 'بازگردانی', sync: 'همگام‌سازی', merge: 'ادغام' }[a] || a;
        }

        function changesTable(changes) {
                if (!changes) return '<span class="muted">—</span>';
                // ۱.۱۰.۰ — فرمت تجمیعی روزانه: رویدادهای همان روز با ساعت
                if (changes.fmt === 2 && Array.isArray(changes.events)) {
                        if (!changes.events.length) return '<span class="muted">—</span>';
                        return changes.events.map((ev) => {
                                const c = ev.c || {};
                                const rows = Object.entries(c).map(([slug, pair]) => {
                                        const p = (pair && typeof pair === 'object') ? pair : {};
                                        const oldV = (p.old === null || p.old === undefined || p.old === '') ? '—' : p.old;
                                        const newV = (p.new === null || p.new === undefined || p.new === '') ? '—' : p.new;
                                        return `<tr><td style="width:150px">${esc(TPP.app.fieldLabel(slug))}</td>
                                        <td><span class="old-val">${esc(oldV)}</span><span class="arrow">→</span><span class="new-val">${esc(newV)}</span></td></tr>`;
                                }).join('');
                                return `<div class="ev-item">
                                        <div class="ev-head"><span class="ev-time">${esc(String(ev.t || '').slice(0, 5))}</span>
                                        <span class="chip">${actionFa(ev.a)}</span>
                                        ${ev.y === 'address' ? '<span class="chip">آدرس</span>' : ''}
                                        ${ev.s === 'offline' ? '<span class="chip">آفلاین</span>' : ''}</div>
                                        ${rows ? '<table>' + rows + '</table>' : '<span class="muted">بدون تغییر فیلد</span>'}
                                </div>`;
                        }).join('');
                }
                return '<table>' + Object.entries(changes).map(([slug, pair]) => `
                        <tr><td style="width:150px">${esc(TPP.app.fieldLabel(slug))}</td>
                        <td><span class="old-val">${esc(pair.old || '—')}</span><span class="arrow">→</span><span class="new-val">${esc(pair.new || '—')}</span></td></tr>`).join('') + '</table>';
        }

        function renderOfflineMissing() {
                document.getElementById('content').innerHTML = `
                        <div class="card"><div class="empty-state">
                                <div class="big">📴</div>
                                <p>این سرویس در داده‌های آفلاین دستگاه شما نیست.</p>
                                <p class="muted">با اتصال اینترنت دوباره باز کنید یا از جستجو استفاده کنید.</p>
                        </div></div>`;
        }

        /* ==================== نمای آدرس (همه سرویس‌های یک آدرس + تاریخچه کل) ==================== */

        TPP.views.address = async function (params) {
                const id = parseInt(params.id, 10);
                if (!id) { go('services'); return; }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">برای مشاهده صفحه آدرس، اتصال اینترنت لازم است.</div>';
                        return;
                }
                const addr = await TPP.api.request('GET', 'addresses/' + id);
                const schema = TPP.app.state().schema;

                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>📍 مشخصات آدرس #${id}</h3>
                        <table class="kv-table">
                                ${(schema.address || []).map((f) => `<tr><td>${esc(f.label)}</td><td>${esc(addr[f.slug] || '—')}</td></tr>`).join('')}
                        </table>
                </div>
                <div class="card">
                        <h3>🛰️ سرویس‌های این آدرس (${(addr.services || []).length})</h3>
                        <div class="table-wrap"><table class="tpp-table">
                                <thead><tr><th>#</th>${(schema.service || []).slice(0, 4).map((f) => '<th>' + esc(f.label) + '</th>').join('')}<th>بروزرسانی</th></tr></thead>
                                <tbody>${(addr.services || []).map((s) => `
                                        <tr data-service="${s.id}">
                                                <td>${esc(s.id)}</td>
                                                ${(schema.service || []).slice(0, 4).map((f) => '<td>' + esc(f.is_sensitive ? (s[f.slug] ? '••••' : '') : (s[f.slug] || '')) + '</td>').join('')}
                                                <td class="muted">${fmtDate(s.updated_at)}</td>
                                        </tr>`).join('')}
                                </tbody>
                        </table></div>
                        <div class="actions-row">
                                ${can('tpp_create_services') ? '<button class="btn btn-primary" id="addr-add-service">➕ افزودن سرویس به این آدرس</button>' : ''}
                        </div>
                </div>
                ${addr.history ? `
                <div class="card">
                        <h3>🕘 تاریخچه کامل تغییرات این آدرس</h3>
                        <div class="timeline">
                        ${addr.history.slice(0, 50).map((h) => `
                                <div class="tl-item ${h.is_conflict ? 'conflict' : ''}">
                                        <div class="tl-head"><b>${esc(h.user_name)}</b><span class="chip">${actionFa(h.action)}</span>
                                        ${h.entity === 'service' ? '<span class="chip">سرویس #' + h.entity_id + '</span>' : '<span class="chip">آدرس</span>'}
                                        ${h.is_conflict ? '<span class="chip warn">تعارض</span>' : ''}</div>
                                        <div class="tl-meta">${fmtDate(h.changed_at)}</div>
                                        <div class="tl-changes">${changesTable(h.changes)}</div>
                                </div>`).join('')}
                        </div>
                </div>` : ''}`;

                document.querySelectorAll('tr[data-service]').forEach((tr) => {
                        tr.addEventListener('click', () => go('service/' + tr.getAttribute('data-service')));
                });
                const addBtn = document.getElementById('addr-add-service');
                if (addBtn) addBtn.addEventListener('click', () => go('service/new?address=' + id));
        };

})();
