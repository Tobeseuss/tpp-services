/**
 * tpp-views-api.js — مرکز API (۱.۱۱.۰):
 *  ۱) مدیریت توکن‌های API (صدور/ابطال) برای اتصال افزونه‌ها و اسکریپت‌های دیگر
 *  ۲) راهنمای احراز هویت (توکن / کوکی+nonce / admin-ajax) با نمونه‌های قابل‌کپی
 *  ۳) مستندات کامل همه مسیرها — از سرور (api/docs) — با جستجو/فیلتر متد/جزئیات پارامترها
 *  ۴) دانلود مستندات Markdown (همیشه همگام با همان منبع سرور)
 */
'use strict';

TPP.views = TPP.views || {};

(function () {

        const esc = (s) => TPP.app.esc(s);
        const can = TPP.app.can;
        const toast = TPP.app.toast;
        const confirmBox = TPP.app.confirmBox;
        const fmtDate = TPP.app.fmtDate;
        const modal = TPP.app.modal;
        const copyText = TPP.app.copyText;

        const apiState = { docs: null, q: '', method: 'all', open: {} };

        /* ============================================================
         * نمای اصلی
         * ============================================================ */

        TPP.views.api = async function () {
                if (!can('tpp_manage_settings')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">مرکز API فقط برای مدیران در دسترس است.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">📴 مرکز API به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                document.getElementById('content').innerHTML = '<div class="loading-block"><div class="spinner"></div><p>در حال دریافت مستندات API…</p></div>';
                await renderApiCenter();
        };

        async function renderApiCenter() {
                let docs;
                try {
                        docs = await TPP.api.request('GET', 'api/docs');
                } catch (e) {
                        document.getElementById('content').innerHTML = '<div class="card"><div class="alert err">خطا در دریافت مستندات: ' + esc(e.message) + '</div></div>';
                        return;
                }
                apiState.docs = docs;
                const base = docs.base || {};

                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>🔌 مرکز API</h3>
                        <p class="muted" style="line-height:2.1">
                                تمام اقداماتی که داخل برنامه قابل انجام است از طریق REST API هم در دسترس است — برای اتصال این افزونه به
                                افزونه‌های دیگر سایت، اسکریپت‌ها یا نرم‌افزارهای بیرونی. مستندات زیر از خود سرور تولید می‌شود و همیشه با نسخه نصب‌شده همگام است.
                        </p>
                        <div class="actions-row" style="flex-wrap:wrap">
                                <button class="btn" id="api-ping">📡 تست اتصال (ping)</button>
                                <button class="btn" id="api-md">📄 دانلود مستندات کامل (Markdown)</button>
                                <a class="btn" href="#/settings">⚙️ تنظیمات افزونه</a>
                        </div>
                        <div class="api-bases">
                                <div class="api-base"><span class="muted">آدرس پایه:</span> <code dir="ltr">${esc(base.pretty || '')}</code> <button class="btn btn-sm" data-copy="${esc(base.pretty || '')}">📋</button></div>
                                <div class="api-base"><span class="muted">جایگزین (پیوند یکتا خاموش):</span> <code dir="ltr">${esc(base.ugly || '')}</code> <button class="btn btn-sm" data-copy="${esc(base.ugly || '')}">📋</button></div>
                                <div class="api-base"><span class="muted">پشتیبان admin-ajax:</span> <code dir="ltr">${esc(base.ajax || '')}</code> <button class="btn btn-sm" data-copy="${esc(base.ajax || '')}">📋</button></div>
                        </div>
                </div>

                <div class="card" id="api-tokens-card">
                        <h3>🔑 توکن‌های API شما</h3>
                        <p class="muted">توکن را یک‌بار بسازید و در افزونه/اسکریپت مقصد ذخیره کنید؛ با هدر <code dir="ltr">X-TPP-Token</code> برای همه مسیرها معتبر است — حتی بدون کوکی وردپرس. مقدار خام فقط هنگام ساخت نمایش داده می‌شود و سرور فقط هش آن را نگه می‌دارد.</p>
                        <div class="search-bar" style="flex-wrap:wrap">
                                <input type="text" id="api-token-label" class="btn" placeholder="برچسب توکن (مثلاً: اتصال افزونه فروش)" style="min-width:240px">
                                <button class="btn btn-primary" id="api-token-new">➕ صدور توکن جدید</button>
                        </div>
                        <div id="api-tokens-list" style="margin-top:12px"></div>
                </div>

                <div class="card">
                        <h3>🛡 روش‌های احراز هویت</h3>
                        ${(docs.auth || []).map((a, i) => `
                        <div class="api-auth">
                                <b>${i + 1}. ${esc(a.title)}</b>
                                <p class="muted" style="margin:4px 0">${esc(a.desc)}</p>
                                <div class="code-box"><code dir="ltr">${esc(a.example)}</code><button class="btn btn-sm" data-copy="${esc(a.example)}">📋 کپی</button></div>
                        </div>`).join('')}
                        ${(docs.notes || []).length ? `<div class="api-notes"><ul class="muted">${docs.notes.map((n) => '<li>' + esc(n) + '</li>').join('')}</ul></div>` : ''}
                </div>

                <div class="card">
                        <h3>📚 مستندات کامل مسیرها (${(docs.groups || []).reduce((n, g) => n + (g.items || []).length, 0)} مسیر)</h3>
                        <div class="search-bar" style="flex-wrap:wrap">
                                <input type="text" id="api-doc-q" class="btn" placeholder="جستجو در مسیر/عنوان/توضیح…" value="${esc(apiState.q)}" style="min-width:260px">
                                <button class="btn btn-sm ${apiState.method === 'all' ? 'btn-primary' : ''}" data-api-m="all">همه</button>
                                ${['GET', 'POST', 'PUT', 'DELETE'].map((m) => `<button class="btn btn-sm ${apiState.method === m ? 'btn-primary' : ''}" data-api-m="${m}">${m}</button>`).join('')}
                        </div>
                        <div id="api-docs-list"></div>
                </div>`;

                document.getElementById('api-ping').addEventListener('click', async () => {
                        try {
                                const r = await TPP.api.request('GET', 'ping');
                                toast('اتصال برقرار است — نسخه افزونه: ' + esc(r.version || '?') + (r.authed ? ' — احراز هویت شما هم معتبر است ✅' : ' — ⚠️ احراز هویت نشده'), 'success', 6000);
                        } catch (e) { toast('خطا در اتصال: ' + esc(e.message), 'error'); }
                });
                document.getElementById('api-md').addEventListener('click', async () => {
                        try {
                                await TPP.api.download('api/docs', { format: 'md' }, 'API-DOCUMENTATION.md');
                                toast('مستندات Markdown دانلود شد — همین فایل در پوشه افزونه هم موجود است.', 'success', 5000);
                        } catch (e) { toast('خطا در دانلود: ' + esc(e.message), 'error'); }
                });
                document.getElementById('api-token-new').addEventListener('click', issueToken);
                const qEl = document.getElementById('api-doc-q');
                qEl.addEventListener('input', () => { apiState.q = qEl.value.trim(); renderDocsList(); });
                document.querySelectorAll('[data-api-m]').forEach((b) => b.addEventListener('click', () => {
                        apiState.method = b.getAttribute('data-api-m');
                        document.querySelectorAll('[data-api-m]').forEach((x) => x.classList.toggle('btn-primary', x === b));
                        renderDocsList();
                }));
                if (!copyBound) { copyBound = true; document.addEventListener('click', copyClickHandler); }
                renderDocsList();
                await loadTokens();
        }

        /* ---------- کپی دکمه‌های data-copy (یک‌بار ثبت می‌شود) ---------- */
        let copyBound = false;
        async function copyClickHandler(e) {
                const b = e.target.closest('[data-copy]');
                if (!b) return;
                const ok = await copyText(b.getAttribute('data-copy'));
                toast(ok ? 'کپی شد.' : 'کپی ناموفق بود — دستی انتخاب و کپی کنید.', ok ? 'success' : 'warn', 2500);
        }

        /* ============================================================
         * توکن‌ها
         * ============================================================ */

        async function loadTokens() {
                const box = document.getElementById('api-tokens-list');
                if (!box) return;
                box.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                try {
                        const data = await TPP.api.request('GET', 'api/tokens');
                        const tokens = data.tokens || [];
                        if (!tokens.length) {
                                box.innerHTML = '<div class="empty-state" style="padding:18px"><p class="muted">هنوز توکنی نساخته‌اید. توکن فعلی این دستگاه (از ورود شما) هم در فهرست هست — توکن جدید برای هر اتصال دلخواه بسازید.</p></div>';
                                return;
                        }
                        box.innerHTML = `<div class="table-wrap"><table class="tpp-table">
                                <thead><tr><th>برچسب</th><th>شناسه</th><th>ساخته</th><th>آخرین استفاده</th><th>دستگاه/برنامه</th><th></th></tr></thead>
                                <tbody>${tokens.map((t) => `<tr>
                                        <td>${esc(t.label || '—')}</td>
                                        <td><code dir="ltr">${esc(t.hash || '')}…</code></td>
                                        <td class="muted" style="white-space:nowrap">${fmtDate(t.created_at)}</td>
                                        <td class="muted" style="white-space:nowrap">${t.last_used ? fmtDate(t.last_used) : 'هرگز'}</td>
                                        <td class="muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(t.user_agent || '')}">${esc((t.user_agent || '—').slice(0, 46))}</td>
                                        <td><button class="btn btn-sm btn-danger" data-tok-revoke="${esc(String(t.idx))}">ابطال</button></td>
                                </tr>`).join('')}</tbody></table></div>
                                <p class="muted">${tokens.length} توکن فعال — ابطال توکن، اتصال مربوطه را بلافاصله قطع می‌کند.</p>`;
                        box.querySelectorAll('[data-tok-revoke]').forEach((b) => b.addEventListener('click', async () => {
                                const idx = parseInt(b.getAttribute('data-tok-revoke'), 10);
                                if (!await confirmBox('این توکن ابطال شود؟ هر افزونه/اسکریپتی که با آن متصل است دیگر کار نمی‌کند.', 'ابطال توکن')) return;
                                try {
                                        await TPP.api.request('DELETE', 'api/tokens', { idx });
                                        toast('توکن ابطال شد.', 'success');
                                        loadTokens();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        }));
                } catch (e) {
                        box.innerHTML = '<div class="alert err">خطا در دریافت توکن‌ها: ' + esc(e.message) + '</div>';
                }
        }

        async function issueToken() {
                const labelEl = document.getElementById('api-token-label');
                const label = labelEl ? labelEl.value.trim() : '';
                const btn = document.getElementById('api-token-new');
                btn.disabled = true;
                try {
                        const res = await TPP.api.request('POST', 'api/tokens', { label: label || ('API — ' + TPP.app.isoToJal(TPP.app.tehranTodayIso())) }); // ۱.۱۸.۰ — برچسب شمسی/تهران
                        const m = modal(`<div style="text-align:center">
                                <h3>🔑 توکن شما ساخته شد</h3>
                                <p class="muted">این مقدار را <b>همین حالا</b> در افزونه/اسکریپت مقصد ذخیره کنید — بعد از بستن این پنجره دیگر قابل مشاهته نیست (سرور فقط هش آن را نگه می‌دارد).</p>
                                <div class="code-box" style="text-align:left;direction:ltr"><code id="api-token-raw">${esc(res.token || '')}</code><button class="btn btn-sm" id="api-token-copy">📋 کپی</button></div>
                                <p class="muted" style="margin-top:10px">نحوه استفاده: هدر <code dir="ltr">X-TPP-Token: ${esc((res.token || '').slice(0, 12))}…</code></p>
                                <div style="margin-top:12px"><button class="btn btn-primary" id="api-token-done">ذخیره کردم — بستن</button></div>
                        </div>`, { static: true });
                        const copyBtn = m.el.querySelector('#api-token-copy');
                        if (copyBtn) copyBtn.addEventListener('click', async () => {
                                const ok = await copyText(res.token);
                                toast(ok ? 'توکن کپی شد.' : 'کپی ناموفق — دستی کپی کنید.', ok ? 'success' : 'warn');
                        });
                        const done = m.el.querySelector('#api-token-done');
                        if (done) done.addEventListener('click', () => m.close());
                        if (labelEl) labelEl.value = '';
                        loadTokens();
                } catch (e) {
                        toast('خطا در صدور توکن: ' + esc(e.message), 'error');
                }
                btn.disabled = false;
        }

        /* ============================================================
         * فهرست مسیرها
         * ============================================================ */

        function renderDocsList() {
                const box = document.getElementById('api-docs-list');
                if (!box || !apiState.docs) return;
                const q = (apiState.q || '').toLowerCase();
                const m = apiState.method || 'all';
                let total = 0;

                const html = (apiState.docs.groups || []).map((g) => {
                        const items = (g.items || []).filter((it) => {
                                if (m !== 'all' && it.m !== m) return false;
                                if (!q) return true;
                                return ((it.p || '') + ' ' + (it.t || '') + ' ' + (it.d || '')).toLowerCase().indexOf(q) !== -1;
                        });
                        total += items.length;
                        if (!items.length) return '';
                        return `
                        <div class="api-group">
                                <div class="api-group-title"><b>${esc(g.title)}</b> <span class="muted">(${items.length} مسیر)</span></div>
                                ${items.map((it) => apiEndpointHtml(it, apiState.docs.base)).join('')}
                        </div>`;
                }).join('');

                box.innerHTML = html || '<div class="empty-state" style="padding:20px"><p class="muted">مسیری با این جستجو پیدا نشد.</p></div>'
                        + (total ? '' : '');
                bindEndpointEvents();
        }

        function methodBadge(m) {
                const cls = { GET: 'ok', POST: 'warn', PUT: '', DELETE: 'err' }[m] || '';
                return `<span class="chip api-m ${cls}" style="min-width:58px;text-align:center;font-family:monospace">${esc(m)}</span>`;
        }

        function apiEndpointHtml(it, base) {
                const id = 'api-ep-' + (it.m + '-' + it.p).replace(/[^a-zA-Z0-9]/g, '_');
                const open = !!apiState.open[id];
                const curl = 'curl -X ' + it.m + ' -H "X-TPP-Token: $TPP_TOKEN" "' + (base ? base.pretty : '') + it.p.replace(/\{[^}]+\}/g, '1') + '"';
                const args = it.args || [];
                return `
                <div class="api-ep${open ? ' open' : ''}" id="${id}">
                        <div class="api-ep-head" data-ep-toggle="${id}">
                                ${methodBadge(it.m)}
                                <code dir="ltr" class="api-path">${esc(it.p)}</code>
                                <b>${esc(it.t)}</b>
                                <span class="chip" style="margin-right:auto">${esc(it.perm || '')}</span>
                                <span class="api-ep-arrow">${open ? '▾' : '▸'}</span>
                        </div>
                        ${open ? `
                        <div class="api-ep-body">
                                <p class="muted">${esc(it.d || '')}</p>
                                ${args.length ? `
                                <table class="tpp-table api-args"><thead><tr><th>پارامتر</th><th>نوع</th><th>توضیح</th></tr></thead>
                                <tbody>${args.map((a) => `<tr><td><code dir="ltr">${esc(a.name)}</code></td><td class="muted">${esc(a.type)}</td><td>${esc(a.desc)}</td></tr>`).join('')}</tbody></table>` : ''}
                                ${it.body ? `<div class="filters-title"><span>نمونه بدنه JSON:</span></div><div class="code-box"><code dir="ltr">${esc(JSON.stringify(it.body, null, 1))}</code><button class="btn btn-sm" data-copy="${esc(JSON.stringify(it.body))}">📋</button></div>` : ''}
                                <div class="filters-title"><span>نمونه فراخوانی:</span></div>
                                <div class="code-box"><code dir="ltr">${esc(curl)}</code><button class="btn btn-sm" data-copy="${esc(curl)}">📋 کپی</button></div>
                        </div>` : ''}
                </div>`;
        }

        function bindEndpointEvents() {
                document.querySelectorAll('[data-ep-toggle]').forEach((el) => el.addEventListener('click', () => {
                        const id = el.getAttribute('data-ep-toggle');
                        apiState.open[id] = !apiState.open[id];
                        renderDocsList();
                }));
        }
})();
