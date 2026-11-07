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
        const modal = TPP.app.modal;
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
                cats: null
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
         * تب ۱ — صف سرویس‌های ارجاعی
         * ============================================================ */

        async function loadQueue() {
                const body = document.getElementById('rev-body');
                if (!body) return;
                body.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                try {
                        st.queue = await TPP.api.request('GET', 'review/queue');
                } catch (e) {
                        body.innerHTML = '<div class="alert err">خطا در دریافت صف بازبینی: ' + esc(e.message) + '</div>';
                        return;
                }
                const q = st.queue;
                const items = q.items || [];
                st.cats = { categories: q.categories || [], tags: q.tags || [] };

                body.innerHTML = `
                <div class="alert info">سرویس‌هایی که ثبت‌کننده برای‌شان دسته‌بندی مناسب پیدا نکرده و با دسته «${esc(q.category_label || 'ثبت جهت بازبینی')}» ارجاع شده‌اند — با تعیین دسته/تگ درست، سرویس از این صف خارج و ثبت نهایی می‌شود.</div>
                <div class="actions-row">
                        <span class="chip ${items.length ? 'warn' : 'ok'}">${faNum(items.length)} سرویس در انتظار بازبینی</span>
                        <button class="btn" id="rq-refresh">🔄 به‌روزرسانی</button>
                </div>
                <div class="rev-list">${items.length ? items.map(queueRowHtml).join('') :
                        '<div class="empty-state" style="padding:16px"><p class="muted">صف خالی است — سرویسی در انتظار بازبینی نیست. ✅</p></div>'}</div>`;

                document.getElementById('rq-refresh').addEventListener('click', loadQueue);
                body.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => go('service/' + b.getAttribute('data-open'))));
                body.querySelectorAll('[data-assign]').forEach((b) => b.addEventListener('click', () => {
                        const row = items.find((x) => String(x.id) === b.getAttribute('data-assign'));
                        if (row) openAssign(row);
                }));
        }

        function queueRowHtml(r) {
                const addr = [r.full_address, r.block, r.plate ? 'پلاک ' + r.plate : '', r.unit ? 'واحد ' + r.unit : ''].filter(Boolean).join('، ');
                // ۲.۰.۱ — نشان وضعیت دایری از ردیف صف حذف شد
                return `
                <div class="rev-row">
                        <div class="rev-row-main">
                                <span class="chip">#${faNum(r.id)}</span>
                                <span class="rev-row-title"><b>سرویس ${faNum(r.id)}</b> — ${esc(addr || 'بدون آدرس')}${r.virtual_number ? ' <span class="muted">| شماره مجازی: ' + esc(r.virtual_number) + '</span>' : ''}</span>
                                <span class="muted" style="white-space:nowrap">${fmtDate(r.updated_at || r.created_at)}</span>
                        </div>
                        <div class="rev-row-meta">
                                ${r.created_by ? '<span class="muted">ثبت: ' + esc(r.created_by) + ' — ' + fmtDate(r.created_at) + '</span>' : ''}
                                <span style="flex:1"></span>
                                <button class="btn btn-sm" data-open="${esc(String(r.id))}" title="مشاهده کامل سرویس">🛰️ سرویس</button>
                                <button class="btn btn-sm btn-primary" data-assign="${esc(String(r.id))}">🛠 تعیین دسته‌بندی</button>
                        </div>
                </div>`;
        }

        /** مودال تعیین دسته/تگ سرویس ارجاعی — ۲.۰.۱:
         *  ۱) پیشنهاد هوشمند: هنگام کلیک روی «تعیین دسته‌بندی»، کلمات «آدرس کامل / بلوک / خیابان»
         *     در دسته‌های ثبت‌شده جستجو و بر اساس تعداد تطابق رتبه‌بندی می‌شوند (review/suggest)
         *  ۲) انتخاب آجاکسی دسته: جستجو و پیشنهاد دسته با تایپ بخشی از عنوان (categories?kind&q) */
        function openAssign(row) {
                const cats = st.cats || { categories: [], tags: [] };
                const tagChips = (cats.tags || []).map((t) =>
                        '<label class="chip tag-chip" data-tag="' + esc(String(t.id)) + '"><input type="checkbox"> ' + esc(t.label) + '</label>').join('');

                const m = modal(
                        '<div class="modal-head"><h3>🛠 تعیین دسته‌بندی — سرویس #' + faNum(row.id) + '</h3><button class="modal-close" data-close>×</button></div>' +
                        '<div class="modal-body">' +
                        '<p class="muted">' + esc([row.full_address, row.block, row.plate ? 'پلاک ' + row.plate : '', row.unit ? 'واحد ' + row.unit : ''].filter(Boolean).join('، ') || 'بدون آدرس') + (row.virtual_number ? ' — شماره مجازی: ' + esc(row.virtual_number) : '') + (row.created_by ? ' — ثبت‌کننده: ' + esc(row.created_by) : '') + '</p>' +

                        /* — پیشنهاد هوشمند دسته‌بندی (۲.۰.۱) — */
                        '<div class="bulk-section" id="ra-suggest-box">' +
                        '<div class="bulk-sec-title">🤖 پیشنهاد هوشمند دسته‌بندی</div>' +
                        '<p class="muted" style="margin:2px 0 8px">کلمات «آدرس کامل»، «نام بلوک» و «نام خیابان» این سرویس در دسته‌بندی‌های ثبت‌شده جستجو شد — ترتیب پیشنهادها بر اساس تعداد تطابق‌هاست.</p>' +
                        '<div id="ra-suggest" class="tag-chips"><span class="muted">در حال جستجوی دسته‌های مشابه…</span></div>' +
                        '</div>' +

                        '<div class="field"><label>دسته‌بندی پروژه <span class="muted">(الزامی — جستجوی آجاکسی: بخشی از عنوان را تایپ کنید)</span></label>' +
                        '<div class="tpp-combo" id="ra-cat-combo">' +
                        '<input type="text" class="combo-input" id="ra-cat-input" placeholder="تایپ کنید تا دسته‌بندی‌ها جستجو و پیشنهاد شوند…" autocomplete="off" role="combobox" aria-expanded="false">' +
                        '<button type="button" class="combo-clear hidden" id="ra-cat-clear" tabindex="-1" title="حذف انتخاب">✕</button>' +
                        '<div class="combo-list hidden" role="listbox"></div>' +
                        '</div>' +
                        '<input type="hidden" id="ra-cat" value="">' +
                        '<div id="ra-cat-chip" class="tag-chips" style="margin-top:6px"></div>' +
                        '<div class="hint">دسته «ثبت جهت بازبینی و ویرایش یا تأیید مدیریت» یعنی سرویس همچنان در صف بازبینی بماند.</div></div>' +
                        '<div class="field"><label>تگ‌ها (اختیاری — چندتایی)</label><div class="tag-chips">' + (tagChips || '<span class="muted">تگی تعریف نشده است.</span>') + '</div></div>' +
                        '</div>' +
                        '<div class="modal-foot"><button class="btn" data-close>انصراف</button><button class="btn btn-primary" id="ra-save" disabled>✅ ثبت و خروج از صف</button></div>',
                        { static: true, wide: true }
                );

                const el = m.el;
                const hidden = el.querySelector('#ra-cat');
                const input = el.querySelector('#ra-cat-input');
                const clearBtn = el.querySelector('#ra-cat-clear');
                const chipBox = el.querySelector('#ra-cat-chip');
                const list = el.querySelector('#ra-cat-combo .combo-list');
                const saveBtn = el.querySelector('#ra-save');

                /* ---- انتخاب/نمایش دسته انتخابی ---- */
                const setSelected = (id, label) => {
                        hidden.value = id ? String(id) : '';
                        input.value = id ? (label || '') : input.value; // لیبل برای جستجوی بعدی می‌ماند
                        clearBtn.classList.toggle('hidden', !id);
                        chipBox.innerHTML = id ? '<span class="chip tag-chip on">🏷 ' + esc(label || ('#' + id)) + '</span>' : '';
                        saveBtn.disabled = !id;
                };

                /* ---- ۱) پیشنهاد هوشمند — همان لحظه بازشدن مودال (کلیک «تعیین دسته‌بندی») ---- */
                (async () => {
                        const box = el.querySelector('#ra-suggest');
                        try {
                                const res = await TPP.api.request('GET', 'review/suggest', null, { service_id: parseInt(row.id, 10) });
                                const items = (res && res.suggestions) || [];
                                const words = (res && res.words) || [];
                                if (!items.length) {
                                        box.innerHTML = '<span class="muted">تطبیقی پیدا نشد' + (words.length ? ' — کلمات جستجوشده: ' + esc(words.join('، ')) : '') + ' — دسته را با جستجوی آجاکسی پایین انتخاب کنید.</span>';
                                        return;
                                }
                                box.innerHTML = items.map((s) => {
                                        const meta = 'تطبیق: ' + faNum(s.score) + ' — کلمات مشترک: ' + esc((s.matched || []).join('، ') || '—');
                                        return '<button type="button" class="chip tag-chip ra-sug' + (s.is_review ? ' rev-opt-review' : '') + '" data-sid="' + esc(String(s.id)) + '" data-slabel="' + esc(s.label) + '" title="' + esc(meta) + '">🏷 ' + esc(s.label) + ' <b>(' + faNum(s.score) + ' تطبیق)</b></button>';
                                }).join('') + '<div class="hint" style="flex-basis:100%">روی هر پیشنهاد کلیک کنید تا انتخاب شود — عدد داخل پرانتز = تعداد تطابق کلمات آدرس/بلوک/خیابان با عنوان دسته.</div>';
                                box.querySelectorAll('.ra-sug').forEach((b) => b.addEventListener('click', () => {
                                        setSelected(parseInt(b.getAttribute('data-sid'), 10) || 0, b.getAttribute('data-slabel') || '');
                                        box.querySelectorAll('.ra-sug').forEach((x) => x.classList.toggle('on', x === b));
                                }));
                        } catch (e) {
                                box.innerHTML = '<span class="muted">دریافت پیشنهادها ناموفق بود: ' + esc(e.message) + ' — دسته را با جستجوی آجاکسی پایین انتخاب کنید.</span>';
                        }
                })();

                /* ---- ۲) انتخاب آجاکسی — جستجو و پیشنهاد با تایپ بخشی از نام دسته ---- */
                let searchTimer = null;
                let searchSeq = 0;
                let open = false;
                const norm = (s) => String(s == null ? '' : s).trim();

                const fetchCats = async (q) => {
                        if (TPP.offline.state().online) {
                                try {
                                        const params = { kind: 'category', per_page: 30 };
                                        if (q) params.q = q;
                                        const res = await TPP.api.request('GET', 'categories', null, params);
                                        return (res && res.items) || [];
                                } catch (e) { /* ادامه با کش محلی */ }
                        }
                        const src = (cats.categories || []);
                        const qn = norm(q);
                        return src.filter((c) => !qn || norm(c.label).indexOf(qn) !== -1).slice(0, 30);
                };

                const renderList = (items) => {
                        if (!open) return;
                        if (!items.length) {
                                list.innerHTML = '<div class="combo-empty muted" style="padding:8px 12px">دسته‌ای با این عنوان یافت نشد.</div>';
                        } else {
                                list.innerHTML = items.map((c) =>
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
                        const q = input.value.trim();
                        const seq = ++searchSeq;
                        const items = await fetchCats(q);
                        if (seq !== searchSeq || !open || !document.contains(input)) return; // پاسخ قدیمی
                        renderList(items);
                };

                input.addEventListener('focus', () => { open = true; runSearch(); });
                input.addEventListener('input', () => {
                        clearBtn.classList.toggle('hidden', !input.value);
                        open = true;
                        if (searchTimer) clearTimeout(searchTimer);
                        searchTimer = setTimeout(runSearch, 250);
                });
                input.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') { open = false; list.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); }
                });
                clearBtn.addEventListener('click', () => {
                        setSelected(0, '');
                        input.value = '';
                        clearBtn.classList.add('hidden');
                        input.focus();
                });
                document.addEventListener('click', (e) => {
                        if (!document.contains(input)) return;
                        if (!e.target.closest('#ra-cat-combo')) {
                                list.classList.add('hidden');
                                input.setAttribute('aria-expanded', 'false');
                                open = false;
                        }
                });

                /* ---- تگ‌ها ---- */
                el.querySelectorAll('.tag-chip input').forEach((i) => i.addEventListener('change', () => {
                        i.closest('.tag-chip').classList.toggle('on', i.checked);
                }));

                el.querySelector('#ra-save').addEventListener('click', async () => {
                        const cat = hidden.value;
                        if (!cat) { toast('دسته‌بندی را انتخاب کنید (برای ماندن در صف، دسته «ثبت جهت بازبینی» را بگذارید).', 'warn'); return; }
                        const tags = [];
                        el.querySelectorAll('.tag-chip.on').forEach((l) => tags.push(parseInt(l.getAttribute('data-tag'), 10)));
                        const btn = el.querySelector('#ra-save');
                        btn.disabled = true;
                        try {
                                await TPP.api.request('POST', 'review/assign', { service_id: parseInt(row.id, 10), category: cat, tags: tags });
                                toast('✅ دسته‌بندی سرویس #' + faNum(row.id) + ' ثبت شد و از صف بازبینی خارج شد.', 'success', 5000);
                                m.close();
                                loadQueue();
                        } catch (e) {
                                toast('خطا در ثبت: ' + esc(e.message), 'error', 7000);
                                btn.disabled = false;
                        }
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
