/**
 * tpp-views-review.js — بازبینی (۱.۲۰.۰)
 *
 * دو تب (هر کدام فقط برای دارندگان قابلیت خودش):
 *  ۱) «سرویس‌های ارجاعی» (tpp_review_queue): سرویس‌هایی که کاربر برای‌شان دسته‌بندی مناسب
 *     پیدا نکرده و با دسته پیش‌فرض «ثبت جهت بازبینی و ویرایش یا تأیید مدیریت» ثبت شده‌اند؛
 *     بازبین دسته/تگ درست را تعیین می‌کند و سرویس از صف خارج می‌شود.
 *  ۲) «اقدامات نصاب‌ها» (tpp_review_installer): همه ثبت/ویرایش/حذف کاربران غیرمدیر بر حسب روز
 *     با دکمه «نگه‌داشتن» (پیش‌فرض — تغییر باقی می‌ماند) و «بازگردانی به حالت قبل».
 */
'use strict';

TPP.views = TPP.views || {};

(function () {

        const esc = (s) => TPP.app.esc(s);
        const can = TPP.app.can;
        const go = TPP.app.go;
        const toast = TPP.app.toast;
        const confirmBox = TPP.app.confirmBox;
        const fmtDate = TPP.app.fmtDate;
        const faNum = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[+d]);
        const faToEn = (s) => String(s || '')
                .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));

        const st = {
                tab: '',            // 'queue' | 'changes'
                date: '',           // ISO روز جاری تب اقدامات
                range7: false,      // حالت ۷ روز اخیر
                userId: 0,
                status: '',
                data: null,
                queue: null,
                cats: null,
                qpage: 1,           // ۲.۱.۳ — صفحه جاری صف بازبینی
                qper: 10,           // ۲.۱.۳ — تعداد هر صفحه (از تنظیمات)
                qcatLabel: ''       // ۲.۱.۳ — برچسب دسته ارجاعی
        };

        const ACT = { create: 'ثبت سرویس', update: 'ویرایش', delete: 'حذف' };
        const SRC = { online: 'فرم', import: 'ایمپورت', bulk: 'تغییر گروهی', offline: 'همگام‌سازی آفلاین', api: 'API', review: 'بازبینی', revert: 'بازگردانی' };
        const STAT = { pending: 'در انتظار بازبینی', kept: 'نگه داشته شد', reverted: 'بازگردانی شد' };
        const STAT_CLS = { pending: 'warn', kept: 'ok', reverted: 'err' };

        function jal(iso) { return TPP.app.isoToJal(iso) || '—'; }

        /* ============================================================
         * نمای اصلی
         * ============================================================ */

        TPP.views.review = async function () {
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">📴 «بازبینی» به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                const canQueue = can('tpp_review_queue');
                const canChanges = can('tpp_review_installer');
                if (!canQueue && !canChanges) {
                        document.getElementById('content').innerHTML = '<div class="alert err">شما به بخش «بازبینی» دسترسی ندارید — قابلیت مربوطه از «نقش‌ها و دسترسی‌ها» قابل اعطاست.</div>';
                        return;
                }
                if (!st.tab) st.tab = canQueue ? 'queue' : 'changes';
                if (!st.date) st.date = TPP.app.tehranTodayIso();

                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>🔁 بازبینی</h3>
                        <p class="muted">سرویس‌های ارجاعی بدون دسته‌بندی مناسب + سجل روزانه اقدامات کاربران نصاب — تصمیم نگه‌داری یا بازگردانی با شماست (پیش‌فرض همه تغییرات باقی می‌مانند).</p>
                        <div class="wr-period-row">
                                ${canQueue ? '<button class="btn rev-tab' + (st.tab === 'queue' ? ' btn-primary' : '') + '" data-tab="queue">⏳ سرویس‌های ارجاعی</button>' : ''}
                                ${canChanges ? '<button class="btn rev-tab' + (st.tab === 'changes' ? ' btn-primary' : '') + '" data-tab="changes">👷 اقدامات نصاب‌ها</button>' : ''}
                        </div>
                        <div id="rev-body"><div class="loading-block"><div class="spinner"></div></div></div>
                </div>`;

                document.querySelectorAll('.rev-tab').forEach((b) => b.addEventListener('click', () => {
                        st.tab = b.getAttribute('data-tab');
                        document.querySelectorAll('.rev-tab').forEach((x) => x.classList.toggle('btn-primary', x === b));
                        loadTab();
                }));

                await loadTab();
        };

        async function loadTab() {
                if ('queue' === st.tab) await loadQueue();
                else await loadChanges();
        }

        /* ============================================================
         * تب ۱ — صف سرویس‌های ارجاعی (۲.۱.۳ — پنل درجا تعیین دسته‌بندی + صفحه‌بندی)
         *
         * درخواست کاربر: «نیازی نباشد روی دکمه تعیین دسته‌بندی کلیک کنم — برای هر
         * سرویس کلیه قابلیت‌ها در همان صفحه در دسترس باشد» → مودال حذف شد؛ هر ردیف
         * پنل درجای خودش را دارد: پیشنهاد هوشمند + کامبو آجاکسی + تگ‌ها + ثبت.
         * تعداد موارد هر صفحه از تنظیم «review_per_page» (⚙️ تنظیمات) می‌آید.
         * ============================================================ */

        let revDocClose = null; // شنونده بیرون‌کلیک کامبوها — با هر رندر جایگزین می‌شود

        /** انتخاب آجاکسی دسته‌ها (مشترک بین همه ردیف‌ها) — آنلاین: سرور؛ آفلاین: کش محلی */
        async function fetchCatOptions(q) {
                if (TPP.offline.state().online) {
                        try {
                                const params = { kind: 'category', per_page: 30 };
                                if (q) params.q = q;
                                const res = await TPP.api.request('GET', 'categories', null, params);
                                return (res && res.items) || [];
                        } catch (e) { /* ادامه با کش محلی */ }
                }
                const src = (st.cats || {}).categories || [];
                const qn = String(q == null ? '' : q).trim();
                return src.filter((c) => !qn || String(c.label || '').indexOf(qn) !== -1).slice(0, 30);
        }

        async function loadQueue() {
                const body = document.getElementById('rev-body');
                if (!body) return;
                body.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                const per = parseInt((TPP.app.state().settings || {}).review_per_page, 10) || 10;
                let q;
                try {
                        q = await TPP.api.request('GET', 'review/queue', null, { page: st.qpage || 1, per_page: per });
                } catch (e) {
                        body.innerHTML = '<div class="alert err">خطا در دریافت صف بازبینی: ' + esc(e.message) + '</div>';
                        return;
                }
                st.queue = q;
                st.qpage = parseInt(q.page, 10) || 1;
                st.qper = parseInt(q.per_page, 10) || per;
                st.qcatLabel = String(q.category_label || '');
                const items = q.items || [];
                st.cats = { categories: q.categories || [], tags: q.tags || [] };
                const pages = parseInt(q.pages, 10) || 0;

                /* صفحه‌بندی — همان الگوی .pagination بقیه بخش‌ها */
                let pager = '';
                if (pages > 1) {
                        let btns = '';
                        const from = Math.max(1, Math.min(st.qpage - 4, pages - 9));
                        const to = Math.min(pages, from + 9);
                        if (from > 1) btns += '<button class="btn btn-sm" data-rqp="1">' + faNum(1) + '</button><span class="muted">…</span>';
                        for (let i = from; i <= to; i++) btns += '<button class="btn btn-sm' + (i === st.qpage ? ' btn-primary' : '') + '" data-rqp="' + i + '">' + faNum(i) + '</button>';
                        if (to < pages) btns += '<span class="muted">…</span><button class="btn btn-sm" data-rqp="' + pages + '">' + faNum(pages) + '</button>';
                        pager = '<div class="pagination">' + btns + '<span class="page-info">صفحه ' + faNum(st.qpage) + ' از ' + faNum(pages) + ' — ' + faNum(q.total) + ' سرویس در صف</span></div>'
                                + '<div class="hint">تعداد موارد هر صفحه از «⚙️ تنظیمات ← تعداد موارد بازبینی در هر صفحه» قابل ویرایش است.</div>';
                }

                body.innerHTML = `
                <div class="alert info">سرویس‌هایی که ثبت‌کننده برای‌شان دسته‌بندی مناسب پیدا نکرده و با دسته «${esc(q.category_label || 'ثبت جهت بازبینی')}» ارجاع شده‌اند — پنل تعیین دسته‌بندی هر سرویس همین‌جا باز است؛ بدون بازکردن پنجره جدید، دسته/تگ را انتخاب و ثبت کنید تا سرویس از صف خارج و ثبت نهایی شود.</div>
                <div class="actions-row">
                        <span class="chip ${q.total ? 'warn' : 'ok'}">${faNum(q.total)} سرویس در انتظار بازبینی</span>
                        <button class="btn" id="rq-refresh">🔄 به‌روزرسانی</button>
                </div>
                <div class="rev-list">${items.length ? items.map(queueRowHtml).join('') :
                        '<div class="empty-state" style="padding:16px"><p class="muted">صف خالی است — سرویسی در انتظار بازبینی نیست. ✅</p></div>'}</div>
                ${pager}`;

                document.getElementById('rq-refresh').addEventListener('click', loadQueue);
                body.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => go('service/' + b.getAttribute('data-open'))));
                body.querySelectorAll('[data-rqp]').forEach((b) => b.addEventListener('click', () => {
                        st.qpage = parseInt(b.getAttribute('data-rqp'), 10) || 1;
                        loadQueue();
                }));
                bindQueueRows(body);
                loadRowSuggestions(body, items);
        }

        /** ردیف صف + پنل درجا تعیین دسته/تگ (۲.۱.۳ — همه قابلیت‌های مودال قبلی، بدون پنجره جدید) */
        function queueRowHtml(r) {
                const addr = [r.full_address, r.block, r.plate ? 'پلاک ' + r.plate : '', r.unit ? 'واحد ' + r.unit : ''].filter(Boolean).join('، ');
                const tags = (st.cats || {}).tags || [];
                const tagChips = tags.map((t) =>
                        '<label class="chip tag-chip" data-tag="' + esc(String(t.id)) + '"><input type="checkbox"> ' + esc(t.label) + '</label>').join('');
                return `
                <div class="rev-row" data-rid="${esc(String(r.id))}">
                        <div class="rev-row-main">
                                <span class="chip">#${faNum(r.id)}</span>
                                <span class="rev-row-title"><b>سرویس ${faNum(r.id)}</b> — ${esc(addr || 'بدون آدرس')}${r.virtual_number ? ' <span class="muted">| شماره مجازی: ' + esc(r.virtual_number) + '</span>' : ''}</span>
                                <span class="muted" style="white-space:nowrap">${fmtDate(r.updated_at || r.created_at)}</span>
                                <button class="btn btn-sm" data-open="${esc(String(r.id))}" title="مشاهده کامل سرویس">🛰️ سرویس</button>
                        </div>
                        <div class="rev-row-meta">${r.created_by ? '<span class="muted">ثبت: ' + esc(r.created_by) + ' — ' + fmtDate(r.created_at) + '</span>' : ''}</div>
                        <div class="rev-assign">
                                <div class="rev-assign-title">🛠 تعیین دسته‌بندی <span class="muted">— درجا، بدون پنجره جدید</span></div>
                                <div class="rev-suggest-row"><span class="muted" style="white-space:nowrap">🤖 پیشنهاد هوشمند:</span><div class="ra-suggest tag-chips"><span class="muted">در حال جستجوی دسته‌های مشابه…</span></div></div>
                                <div class="rev-assign-row">
                                        <div class="tpp-combo rev-cat-combo">
                                                <input type="text" class="combo-input rev-cat-input" placeholder="دسته را تایپ کنید یا از پیشنهادها انتخاب کنید…" autocomplete="off" role="combobox" aria-expanded="false">
                                                <button type="button" class="combo-clear hidden" tabindex="-1" title="حذف انتخاب">✕</button>
                                                <div class="combo-list hidden" role="listbox"></div>
                                        </div>
                                        <input type="hidden" class="rev-cat-id" value="">
                                        <button class="btn btn-sm btn-primary rev-save" disabled title="پس از انتخاب دسته فعال می‌شود">✅ ثبت</button>
                                </div>
                                <div class="rev-tags-row"><span class="muted" style="white-space:nowrap">🏷 تگ‌ها (اختیاری):</span><div class="tag-chips">${tagChips || '<span class="muted">تگی تعریف نشده است.</span>'}</div></div>
                                <div class="hint">دسته «${esc(st.qcatLabel || 'ثبت جهت بازبینی و ویرایش یا تأیید مدیریت')}» یعنی سرویس همچنان در صف بماند — پس از ثبت، سرویس از صف خارج می‌شود.</div>
                        </div>
                </div>`;
        }

        /** اتصال پنل درجا هر ردیف: کامبو آجاکسی + تگ‌ها + ثبت */
        function bindQueueRows(root) {
                root.querySelectorAll('.rev-row').forEach((rowEl) => {
                        const rid = rowEl.getAttribute('data-rid');
                        const input = rowEl.querySelector('.rev-cat-input');
                        const list = rowEl.querySelector('.combo-list');
                        const clearBtn = rowEl.querySelector('.combo-clear');
                        const hidden = rowEl.querySelector('.rev-cat-id');
                        const saveBtn = rowEl.querySelector('.rev-save');
                        let timer = null;
                        let seq = 0;
                        let open = false;

                        const setSelected = (id, label) => {
                                hidden.value = id ? String(id) : '';
                                if (id) input.value = label || input.value;
                                clearBtn.classList.toggle('hidden', !id);
                                saveBtn.disabled = !id;
                                rowEl.querySelectorAll('.ra-sug').forEach((x) => x.classList.toggle('on', !!id && x.getAttribute('data-sid') === String(id)));
                        };
                        rowEl._setSelected = setSelected;

                        const renderList = (opts) => {
                                if (!open) return;
                                if (!opts.length) {
                                        list.innerHTML = '<div class="combo-empty muted" style="padding:8px 12px">دسته‌ای با این عنوان یافت نشد.</div>';
                                } else {
                                        list.innerHTML = opts.map((c) =>
                                                '<button type="button" class="combo-item' + (String(c.id) === hidden.value ? ' active' : '') + '" data-cid="' + esc(String(c.id)) + '" data-clabel="' + esc(c.label) + '" role="option">' + esc(c.label) + (c.is_review ? ' <span class="muted">(صف بازبینی)</span>' : '') + '</button>').join('');
                                        list.querySelectorAll('.combo-item').forEach((b) => b.addEventListener('click', () => {
                                                setSelected(parseInt(b.getAttribute('data-cid'), 10) || 0, b.getAttribute('data-clabel') || '');
                                                list.classList.add('hidden');
                                                input.setAttribute('aria-expanded', 'false');
                                                open = false;
                                        }));
                                }
                                list.classList.remove('hidden');
                                input.setAttribute('aria-expanded', 'true');
                        };
                        const runSearch = async () => {
                                const qq = input.value.trim();
                                const sq = ++seq;
                                const opts = await fetchCatOptions(qq);
                                if (sq !== seq || !open || !document.contains(input)) return;
                                renderList(opts);
                        };
                        input.addEventListener('focus', () => { open = true; runSearch(); });
                        input.addEventListener('input', () => {
                                clearBtn.classList.toggle('hidden', !input.value);
                                open = true;
                                if (timer) clearTimeout(timer);
                                timer = setTimeout(runSearch, 250);
                        });
                        input.addEventListener('keydown', (e) => {
                                if (e.key === 'Escape') { open = false; list.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); }
                                if (e.key === 'Enter' && !saveBtn.disabled) saveBtn.click();
                        });
                        clearBtn.addEventListener('click', () => { setSelected(0, ''); input.value = ''; clearBtn.classList.add('hidden'); input.focus(); });

                        rowEl.querySelectorAll('.rev-tags-row .tag-chip input').forEach((i) => i.addEventListener('change', () => {
                                i.closest('.tag-chip').classList.toggle('on', i.checked);
                        }));

                        saveBtn.addEventListener('click', async () => {
                                const cat = hidden.value;
                                if (!cat) { toast('دسته‌بندی را انتخاب کنید (برای ماندن در صف، دسته «ثبت جهت بازبینی» را بگذارید).', 'warn'); return; }
                                const tags = [];
                                rowEl.querySelectorAll('.rev-tags-row .tag-chip.on').forEach((l) => tags.push(parseInt(l.getAttribute('data-tag'), 10)));
                                saveBtn.disabled = true;
                                try {
                                        await TPP.api.request('POST', 'review/assign', { service_id: parseInt(rid, 10), category: cat, tags: tags });
                                        toast('✅ دسته‌بندی سرویس #' + faNum(rid) + ' ثبت شد و از صف بازبینی خارج شد.', 'success', 5000);
                                        loadQueue();
                                } catch (e) {
                                        toast('خطا در ثبت: ' + esc(e.message), 'error', 7000);
                                        saveBtn.disabled = false;
                                }
                        });
                });

                /* بیرون‌کلیک — بستن همه لیست‌های باز (با هر رندر جایگزین می‌شود) */
                if (revDocClose) document.removeEventListener('click', revDocClose);
                revDocClose = (e) => {
                        root.querySelectorAll('.rev-row').forEach((rowEl) => {
                                if (rowEl.contains(e.target)) return;
                                const l = rowEl.querySelector('.combo-list');
                                const inp = rowEl.querySelector('.rev-cat-input');
                                if (l) l.classList.add('hidden');
                                if (inp) inp.setAttribute('aria-expanded', 'false');
                        });
                };
                document.addEventListener('click', revDocClose);
        }

        /** پیشنهاد هوشمند هر ردیف (review/suggest) — بارگذاری پلکانی (۱۵۰ms فاصله) برای جلوگیری از انفجار درخواست */
        function loadRowSuggestions(root, items) {
                (items || []).forEach((row, idx) => {
                        setTimeout(async () => {
                                const rowEl = root.querySelector('.rev-row[data-rid="' + String(row.id) + '"]');
                                if (!rowEl || !document.contains(rowEl)) return;
                                const box = rowEl.querySelector('.ra-suggest');
                                if (!box) return;
                                try {
                                        const res = await TPP.api.request('GET', 'review/suggest', null, { service_id: parseInt(row.id, 10) });
                                        const sugs = (res && res.suggestions) || [];
                                        const words = (res && res.words) || [];
                                        if (!sugs.length) {
                                                box.innerHTML = '<span class="muted">تطبیقی پیدا نشد' + (words.length ? ' — کلمات جستجوشده: ' + esc(words.join('، ')) : '') + ' — دسته را با جستجوی آجاکسی انتخاب کنید.</span>';
                                                return;
                                        }
                                        box.innerHTML = sugs.map((s) => {
                                                const meta = 'تطبیق: ' + faNum(s.score) + ' — کلمات مشترک: ' + esc((s.matched || []).join('، ') || '—');
                                                return '<button type="button" class="chip tag-chip ra-sug' + (s.is_review ? ' rev-opt-review' : '') + '" data-sid="' + esc(String(s.id)) + '" data-slabel="' + esc(s.label) + '" title="' + esc(meta) + '">🏷 ' + esc(s.label) + ' <b>(' + faNum(s.score) + ' تطبیق)</b></button>';
                                        }).join('');
                                        box.querySelectorAll('.ra-sug').forEach((b) => b.addEventListener('click', () => {
                                                if (rowEl._setSelected) rowEl._setSelected(parseInt(b.getAttribute('data-sid'), 10) || 0, b.getAttribute('data-slabel') || '');
                                        }));
                                } catch (e) {
                                        box.innerHTML = '<span class="muted">دریافت پیشنهادها ناموفق بود — دسته را با جستجوی آجاکسی انتخاب کنید.</span>';
                                }
                        }, idx * 150);
                });
        }
        /* ============================================================
         * تب ۲ — اقدامات نصاب‌ها (بر حسب روز)
         * ============================================================ */

        async function loadChanges() {
                const body = document.getElementById('rev-body');
                if (!body) return;
                body.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                const params = {};
                if (st.range7) {
                        params.from = TPP.app.isoShift(st.date, -6);
                        params.to = st.date;
                } else {
                        params.date = st.date;
                }
                if (st.userId) params.user_id = st.userId;
                if (st.status) params.status = st.status;
                try {
                        st.data = await TPP.api.request('GET', 'review/changes', null, params);
                } catch (e) {
                        body.innerHTML = '<div class="alert err">خطا در دریافت سجل اقدامات: ' + esc(e.message) + '</div>';
                        return;
                }
                const d = st.data;
                const days = d.days || [];
                const users = d.users || [];

                body.innerHTML = `
                <div class="search-bar" style="flex-wrap:wrap">
                        <button class="btn" id="rc-today" title="امروز">📅 امروز</button>
                        <button class="btn btn-sm" id="rc-prev" title="روز قبل">»</button>
                        <input type="text" id="rc-date-text" class="btn cal-input" readonly value="${esc(st.range7 ? jal(params.from) + ' تا ' + jal(params.to) : jal(st.date))}" style="min-width:170px">
                        <button class="btn btn-sm" id="rc-next" title="روز بعد">«</button>
                        <button class="btn${st.range7 ? ' btn-primary' : ''}" id="rc-week" title="هفت روز اخیر تا امروز">۷ روز اخیر</button>
                        <select id="rc-user" class="btn"><option value="0">همه کاربران</option>${users.map((u) =>
                                '<option value="' + esc(String(u.id)) + '"' + (u.id === st.userId ? ' selected' : '') + '>' + esc(u.name) + ' (' + faNum(u.count) + ')</option>').join('')}</select>
                        <select id="rc-status" class="btn"><option value="">همه وضعیت‌ها</option>${Object.keys(STAT).map((k) =>
                                '<option value="' + k + '"' + (k === st.status ? ' selected' : '') + '>' + STAT[k] + '</option>').join('')}</select>
                </div>
                <div class="actions-row">
                        <span class="chip">${faNum(d.total || 0)} تغییر در بازه</span>
                        <span class="chip ${d.pending_total ? 'warn' : 'ok'}">${faNum(d.pending_total || 0)} در انتظار بازبینی</span>
                        <span class="muted">پیش‌فرض همه تغییرات باقی می‌مانند — «بازگردانی» فقط با تصمیم شما.</span>
                </div>
                <div class="rev-list">${days.length ? days.map(dayHtml).join('') :
                        '<div class="empty-state" style="padding:16px"><p class="muted">در این بازه تغییری از کاربران نصاب ثبت نشده است.</p></div>'}</div>`;

                document.getElementById('rc-today').addEventListener('click', () => { st.date = TPP.app.tehranTodayIso(); st.range7 = false; loadChanges(); });
                document.getElementById('rc-prev').addEventListener('click', () => { st.date = TPP.app.isoShift(st.date, -1); loadChanges(); });
                document.getElementById('rc-next').addEventListener('click', () => { st.date = TPP.app.isoShift(st.date, 1); loadChanges(); });
                document.getElementById('rc-week').addEventListener('click', () => { st.range7 = !st.range7; loadChanges(); });
                document.getElementById('rc-user').addEventListener('change', (e) => { st.userId = parseInt(e.target.value, 10) || 0; loadChanges(); });
                document.getElementById('rc-status').addEventListener('change', (e) => { st.status = e.target.value; loadChanges(); });

                body.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => go('service/' + b.getAttribute('data-open'))));
                body.querySelectorAll('[data-keep]').forEach((b) => b.addEventListener('click', () => keepChange(parseInt(b.getAttribute('data-keep'), 10))));
                body.querySelectorAll('[data-revert]').forEach((b) => b.addEventListener('click', () => revertChange(parseInt(b.getAttribute('data-revert'), 10), b.getAttribute('data-act'))));
        }

        function dayHtml(day) {
                return `
                <div class="rev-day">
                        <div class="rev-day-head"><b>${esc(jal(day.date))}</b> <span class="chip">${faNum(day.rows.length)} مورد</span></div>
                        ${day.rows.map(changeRowHtml).join('')}
                </div>`;
        }

        function changeRowHtml(r) {
                const canDecide = r.status !== 'reverted';
                return `
                <div class="rev-row${r.status !== 'pending' ? ' reviewed' : ''}">
                        <div class="rev-row-main">
                                <span class="chip">${ICONS[r.action] || '•'} ${esc(ACT[r.action] || r.action)}</span>
                                <span class="rev-row-title">${esc(r.summary || '—')}</span>
                                <span class="muted" style="white-space:nowrap">${esc(faToEn(String(r.created_at || '').split(' ')[1] || '').slice(0, 5))}</span>
                        </div>
                        <div class="rev-row-meta">
                                <span class="chip">👷 ${esc(r.user_name)}</span>
                                ${r.source ? '<span class="chip muted-chip">' + esc(SRC[r.source] || r.source) + '</span>' : ''}
                                ${r.service_id ? (r.exists
                                        ? '<button class="btn btn-sm" data-open="' + esc(String(r.service_id)) + '">🛰️ سرویس #' + faNum(r.service_id) + (r.service_virtual ? ' — ' + esc(r.service_virtual) : '') + '</button>'
                                        : '<span class="chip err">سرویس #' + faNum(r.service_id) + ' حذف شده</span>') : ''}
                                ${r.service_addr ? '<span class="muted rev-addr">' + esc(r.service_addr) + '</span>' : ''}
                                <span class="chip ${STAT_CLS[r.status] || ''}">${STAT[r.status] || r.status}${r.reviewed_by ? ' — ' + esc(r.reviewed_by) : ''}</span>
                                <span style="flex:1"></span>
                                ${canDecide && r.status === 'pending' ? '<button class="btn btn-sm" data-keep="' + esc(String(r.id)) + '" title="تغییر باقی بماند (پیش‌فرض)">✅ نگه‌داشتن</button>' : ''}
                                ${canDecide ? '<button class="btn btn-sm btn-danger" data-revert="' + esc(String(r.id)) + '" data-act="' + esc(r.action) + '" title="وضعیت قبل از این تغییر بازگردد">↩️ بازگردانی</button>' : ''}
                        </div>
                </div>`;
        }

        const ICONS = { create: '➕', update: '✏️', delete: '🗑' };

        async function keepChange(id) {
                try {
                        await TPP.api.request('POST', 'review/keep', { id: id });
                        toast('✅ علامت «نگه داشته شد» — تغییر باقی می‌ماند.', 'success');
                        loadChanges();
                } catch (e) { toast('خطا: ' + esc(e.message), 'error', 7000); }
        }

        async function revertChange(id, action) {
                const what = ACT[action] || 'این تغییر';
                const msg = {
                        create: 'این «ثبت سرویس» بازگردانی شود؟ (سرویس ثبت‌شده حذف خواهد شد — وضعیت قبل از ثبت)',
                        update: 'این «ویرایش» به حالت قبل بازگردد؟ (مقادیر سرویس/آدرس دقیقاً به قبل از ویرایش برمی‌گردند)',
                        delete: 'این «حذف» بازگردانی شود؟ (سرویس حذف‌شده با همان شناسه دوباره ساخته می‌شود — تاریخچه قدیمی آن قابل بازیابی نیست)'
                }[action] || 'این تغییر به حالت قبل بازگردد؟';
                if (!await confirmBox(msg, 'بازگردانی ' + what)) return;
                try {
                        const res = await TPP.api.request('POST', 'review/revert', { id: id });
                        toast('↩️ بازگردانی شد — ' + esc(res && res.note ? res.note : 'وضعیت قبل از ' + what + ' برقرار شد.'), 'success', 7000);
                        loadChanges();
                } catch (e) { toast('خطا در بازگردانی: ' + esc(e.message), 'error', 8000); }
        }
})();
