/**
 * tpp-views-admin.js — نماهای مدیریتی:
 * ایمپورت اکسل (۳ مرحله‌ای)، خروجی/پشتیبان، مدیریت فیلدهای داینامیک،
 * نقش‌ها و دسترسی‌ها (با دسترسی سطح فیلد)، تنظیمات و صف همگام‌سازی
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
        const modal = TPP.app.modal;

        /* ============================================================
         * ایمپورت اکسل — ۱) آپلود  ۲) نگاشت و تنظیمات  ۳) پیش‌نمایش و ثبت
         * ============================================================ */

        const imp = { session: null, dups: null, mapping: null, mode: null, matchKey: null };

        TPP.views.import = async function () {
                if (!can('tpp_import')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">شما اجازه ایمپورت ندارید.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">📥 ایمپورت گروهی به اتصال اینترنت نیاز دارد. سایر امکانات (ثبت/ویرایش/جستجو) در حالت آفلاین هم کار می‌کنند.</div>';
                        return;
                }
                imp.session = null;
                imp.dups = null;
                imp.mapping = null; // ۱.۸.۱ — وضعیت مرحله ۲ (نگاشت/تنظیمات) نیز ریست شود
                imp.mode = null;
                imp.matchKey = null;
                document.getElementById('content').innerHTML = `
                <div class="import-steps">
                        <div class="step active" data-step="1">۱. دریافت فایل نمونه / آپلود</div>
                        <div class="step" data-step="2">۲. نگاشت ستون‌ها و تنظیمات</div>
                        <div class="step" data-step="3">۳. بررسی تشابه‌ها</div>
                        <div class="step" data-step="4">۴. ثبت و گزارش</div>
                </div>

                <div class="card">
                        <h3>📥 ایمپورت گروهی از فایل اکسل (xlsx)</h3>
                        <div class="actions-row" style="margin-top:0;margin-bottom:18px">
                                <button class="btn" id="get-template">⬇️ دریافت فایل نمونه (به‌روز)</button>
                                <span class="muted">فایل نمونه بر اساس فیلدهای فعلی ساخته می‌شود؛ با تغییر فیلدها، دفعه بعد فایل جدید خودکار شامل ستون‌های جدید است.</span>
                        </div>
                        <div class="dropzone" id="dropzone">
                                <div class="big">📄</div>
                                <p><b>فایل اکسل را اینجا رها کنید</b> یا برای انتخاب کلیک کنید</p>
                                <p class="muted">حداکثر حجم مجاز: ${imp.maxSizeLabel ? esc(imp.maxSizeLabel) : '۱۰'} مگابایت — سطر اول باید عنوان ستون‌ها باشد</p>
                                <input type="file" id="import-file" accept=".xlsx" class="hidden">
                        </div>
                        <div id="import-progress" class="hidden" style="margin-top:16px"><div class="spinner"></div> در حال پردازش فایل…</div>
                </div>
                <div id="import-workspace"></div>`;

                document.getElementById('get-template').addEventListener('click', async () => {
                        try { await TPP.api.download('template', null, 'tpp-template.xlsx'); }
                        catch (e) { toast('خطا در دریافت فایل نمونه: ' + esc(e.message), 'error'); }
                });

                const dz = document.getElementById('dropzone');
                const fileInput = document.getElementById('import-file');
                dz.addEventListener('click', () => fileInput.click());
                dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('drag'); });
                dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
                dz.addEventListener('drop', (e) => {
                        e.preventDefault(); dz.classList.remove('drag');
                        if (e.dataTransfer.files.length) handleUpload(e.dataTransfer.files[0]);
                });
                fileInput.addEventListener('change', () => { if (fileInput.files.length) handleUpload(fileInput.files[0]); });
        };

        async function handleUpload(file) {
                const prog = document.getElementById('import-progress');
                prog.classList.remove('hidden');
                try {
                        const res = await TPP.api.upload('import/preview', file, 'file');
                        if (!res || !Array.isArray(res.fields) || !res.session_id) {
                                throw new Error('پاسخ پیش‌نمایش معتبر نبود — فایل را دوباره آپلود کنید.');
                        }
                        imp.session = res;
                        imp.dups = null;
                        imp.mapping = null; // ۱.۸.۱ — فایل جدید = نگاشت جدید
                        imp.mode = null;
                        imp.matchKey = null;
                        renderMappingStep();
                } catch (e) {
                        toast('خطا: ' + esc(e.message), 'error', 9000);
                } finally {
                        prog.classList.add('hidden');
                }
        }

        function setStep(n) {
                document.querySelectorAll('.import-steps .step').forEach((s) => {
                        const k = parseInt(s.getAttribute('data-step'), 10);
                        s.classList.toggle('active', k === n);
                        s.classList.toggle('done', k < n);
                });
        }

        function renderMappingStep() {
                setStep(2);
                const ws = document.getElementById('import-workspace');
                const s = imp.session;
                if (!s || !Array.isArray(s.fields)) {
                        ws.innerHTML = '<div class="alert err">پاسخ پیش‌نمایش نامعتبر است — فایل را دوباره آپلود کنید.</div>';
                        return;
                }
                const fieldOptions = [{ slug: '', label: '— نادیده گرفته شود —' }]
                        .concat((s.fields || []).map((f) => ({ slug: f.slug, label: (f.group_key === 'address' ? 'آدرس: ' : 'سرویس: ') + f.label })))
                        /* ۱.۱۹.۰ — شبه‌فیلدها (دایری/خرابی/دسته/تگ) هم در فهرست نگاشت قابل انتخاب‌اند
                           (رفع شکاف قدیمی: نگاشت خودکارِ این ستون‌ها در جدول نمایش داده نمی‌شد) */
                        .concat((s.pseudo_fields || []).map((f) => ({ slug: f.slug, label: '◆ ' + f.label })));

                ws.innerHTML = `
                <div class="card">
                        <h3>۲) نگاشت ستون‌ها به فیلدها</h3>
                        <p class="muted">فایل: ${esc(s.file)} — ${s.row_count} ردیف داده تشخیص داده شد${s.empty_rows ? ' (' + s.empty_rows + ' ردیف کاملاً خالی نادیده گرفته شد)' : ''}. نگاشت خودکار انجام شده؛ در صورت نیاز اصلاح کنید.</p>
                        <div id="map-status"></div>
                        <div class="table-wrap mapping-table"><table class="tpp-table">
                                <thead><tr><th>ستون فایل</th><th>فیلد مربوطه</th><th>نمونه مقدار</th></tr></thead>
                                <tbody>
                                ${s.headers.map((h, col) => `
                                        <tr${s.mapping[col] ? '' : ' class="unmapped-row"'}>
                                                <td><b>${esc(h)}</b></td>
                                                <td><select data-col="${col}">${fieldOptions.map((o) => `<option value="${esc(o.slug)}" ${s.mapping[col] === o.slug ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select></td>
                                                <td class="muted">${esc(((s.preview_rows || [])[0] || [])[col] || '—')}</td>
                                        </tr>`).join('')}
                                </tbody>
                        </table></div>
                </div>
                <div class="card">
                        <h3>تنظیمات ثبت</h3>
                        <div class="grid-3">
                                <div class="field">
                                        <label>کلید تشخیص سرویس تکراری</label>
                                        <select id="imp-match">
                                                ${(s.match_options || []).map((o) => `<option value="${esc(o.value)}" ${o.value === s.default_match ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}
                                        </select>
                                        <div class="hint">اگر ردیفی با این مقدار موجود باشد → به‌روزرسانی؛ در غیر این صورت → ثبت جدید. مقدار خالی یا «0» تکراری تلقی نمی‌شود.</div>
                                </div>
                                <div class="field">
                                        <label>حالت ثبت</label>
                                        <select id="imp-mode">
                                                <option value="upsert">هوشمند (به‌روزرسانی + ثبت جدید)</option>
                                                <option value="create">فقط ثبت جدید (ردیف تکراری رد شود)</option>
                                                <option value="update">فقط به‌روزرسانی موجود‌ها</option>
                                        </select>
                                </div>
                                <div class="field">
                                        <label>&nbsp;</label>
                                        <button class="btn btn-primary btn-block" id="imp-next">✅ مرحله بعد: بررسی تشابه‌ها</button>
                                </div>
                        </div>
                        <div class="alert info" style="margin-top:10px">
                                نکته: سلول‌های خالی رد نمی‌شوند — فقط مقدار همان فیلد خالی ثبت می‌شود (در حالت به‌روزرسانی، مقدار قبلی حفظ می‌شود).
                                ردیف‌های کاملاً خالی خودکار رد می‌شوند. همه تغییرات در تاریخچه با نوع «ایمپورت» ثبت می‌شوند.
                        </div>
                </div>`;

                // ۱.۸.۰ — وضعیت نگاشت: هشدار زنده + قفل دکمه ادامه وقتی هیچ ستونی نگاشت نشده است
                const mappingTable = ws.querySelector('.mapping-table');
                if (mappingTable) {
                        mappingTable.addEventListener('change', updateMapStatus);
                }
                updateMapStatus();
                document.getElementById('imp-next').addEventListener('click', runAnalyze);
        }

        /** ۱.۸.۰ — ارزیابی زنده کیفیت نگاشت + فعال/غیرفعال‌سازی دکمه ادامه */
        function updateMapStatus() {
                const box = document.getElementById('map-status');
                const btn = document.getElementById('imp-next');
                if (!box || !btn) { return; }
                const total = document.querySelectorAll('select[data-col]').length;
                let mapped = 0;
                document.querySelectorAll('select[data-col]').forEach((sel) => { if (sel.value) mapped++; });
                document.querySelectorAll('.mapping-table tbody tr').forEach((tr) => {
                        const sel = tr.querySelector('select[data-col]');
                        if (sel) tr.classList.toggle('unmapped-row', !sel.value);
                });
                if (0 === mapped) {
                        box.innerHTML = '<div class="alert err"><b>هیچ ستونی از فایل شناسایی نشد!</b> عنوان‌های سطر اول فایل باید نام فیلدها باشند (مطابق فایل نمونه). برای هر ستون از جدول بالا فیلد مربوطه را انتخاب کنید تا دکمه ادامه فعال شود.</div>';
                        btn.disabled = true;
                } else if (mapped < total) {
                        box.innerHTML = `<div class="alert warn"><b>${total - mapped} ستون از ${total} ستون فایل شناسایی نشد</b> (ردیف‌های زرد جدول بالا). اگر این ستون‌ها نباید ایمپورت شوند مشکلی نیست؛ در غیر این صورت فیلد مربوطه را انتخاب کنید.</div>`;
                        btn.disabled = false;
                } else {
                        box.innerHTML = '<div class="alert ok" style="display:flex;align-items:center;gap:6px">✅ همه ستون‌ها به فیلدها نگاشت شدند.</div>';
                        btn.disabled = false;
                }
        }

        /** جمع‌آوری نگاشت فعلی از جدول
         * ۱.۸.۱ — در مرحله ۳ (تشابه‌ها) و ۴ (گزارش) جدول نگاشت از DOM حذف شده است؛
         * در آن حالت وضعیت ذخیره‌شده مرحله ۲ برگردانده می‌شود تا «ثبت نهایی» گارد صفر-نگاشت را بی‌دلیل فعال نکند */
        function currentMapping() {
                const sels = document.querySelectorAll('select[data-col]');
                if (!sels.length) {
                        return imp.mapping || {};
                }
                const mapping = {};
                sels.forEach((sel) => {
                        mapping[sel.getAttribute('data-col')] = sel.value;
                });
                imp.mapping = mapping;
                return mapping;
        }

        /** ۱.۸.۱ — ذخیره تنظیمات ثبت (کلید تطبیق/حالت) هنگام حضور در مرحله ۲ — برای استفاده بعد از حذف DOM */
        function persistImportSettings() {
                const modeSel = document.getElementById('imp-mode');
                const matchSel = document.getElementById('imp-match');
                if (modeSel) { imp.mode = modeSel.value; }
                if (matchSel) { imp.matchKey = matchSel.value; }
        }

        /** مرحله ۳ — تحلیل تشابه ردیف‌ها با داده‌های ثبت‌شده (کد پستی/آدرس/کد ملی) */
        async function runAnalyze() {
                const btn = document.getElementById('imp-next');
                btn.disabled = true; btn.textContent = 'در حال بررسی تشابه‌ها…';
                try {
                        persistImportSettings(); // ۱.۸.۱ — قبل از ترک مرحله ۲، تنظیمات را ذخیره کن
                        const res = await TPP.api.request('POST', 'import/analyze', {
                                session_id: imp.session.session_id,
                                mapping: currentMapping()
                        });
                        imp.dups = res && Array.isArray(res.duplicates) ? res : { duplicates: [], count: 0, total_rows: imp.session.row_count };
                        if (!imp.dups.count) {
                                // موردی مشابه نیست → مستقیم ثبت
                                await commitImport();
                                return;
                        }
                        renderDuplicatesStep();
                } catch (e) {
                        toast('خطا در بررسی تشابه‌ها: ' + esc(e.message), 'error', 8000);
                        // شکست تحلیل نباید ثبت را بلاک کند — با سوال عمومی ادامه بده
                        imp.dups = null;
                        if (await confirmBox('بررسی تشابه‌ها ناموفق بود. بدون بررسی تشابه‌ها ادامه داده شود؟ <br><span class="muted">در این حالت ردیف‌های مشابه طبق قاعده پیش‌فرض (اتصال به آدرس موجود) ثبت می‌شوند.</span>', 'ادامه')) {
                                await commitImport();
                        }
                } finally {
                        const b = document.getElementById('imp-next');
                        if (b) { b.disabled = false; b.textContent = '✅ مرحله بعد: بررسی تشابه‌ها'; }
                }
        }

        function renderDuplicatesStep() {
                setStep(3);
                const ws = document.getElementById('import-workspace');
                const dups = imp.dups.duplicates || [];
                ws.innerHTML = `
                <div class="card">
                        <h3>۳) ردیف‌های مشابه با اطلاعات ثبت‌شده (${imp.dups.count} مورد از ${imp.dups.total_rows} ردیف)</h3>
                        <div class="alert warn">
                                برای ردیف‌های زیر، آدرس یا مالک مشابهی در سیستم ثبت شده است. تعیین کنید هر مورد چگونه ثبت شود:
                        </div>
                        <div class="grid-2" style="margin-bottom:14px">
                                <label class="checkbox-row"><input type="radio" name="dup-global" value="link" checked> <b>سرویس جدید برای آدرس موجود</b> <span class="muted">— اطلاعات این ردیف به‌عنوان سرویس جدید روی همان آدرس قبلی ثبت شود</span></label>
                                <label class="checkbox-row"><input type="radio" name="dup-global" value="new"> <b>آدرس جدید مستقل</b> <span class="muted">— شباهت نادیده گرفته شود و آدرس و سرویس جدید ثبت شود</span></label>
                        </div>
                        <div class="table-wrap" style="max-height:380px;overflow-y:auto"><table class="tpp-table">
                                <thead><tr><th>ردیف</th><th>آدرس مشابه ثبت‌شده</th><th>علت تشابه</th><th>اطمینان</th><th>تصمیم این ردیف</th></tr></thead>
                                <tbody>
                                ${dups.map((d) => `
                                        <tr data-dup="${d.row}">
                                                <td><b>${d.row}</b></td>
                                                <td>#${esc(d.address_id)} — ${esc(d.label || '')}</td>
                                                <td class="muted">${(d.reasons || []).map(esc).join(' + ')}</td>
                                                <td>${'high' === d.confidence ? '<span class="chip ok">قابل اتکا</span>' : '<span class="chip warn">احتمالی</span>'}</td>
                                                <td><select class="dup-decision" data-row="${d.row}">
                                                        <option value="">طبق انتخاب کلی</option>
                                                        <option value="link">اتصال به آدرس موجود</option>
                                                        <option value="new">آدرس جدید مستقل</option>
                                                </select></td>
                                        </tr>`).join('')}
                                </tbody>
                        </table></div>
                        <div class="hint" style="margin-top:10px">«قابل اتکا» یعنی کد پستی یا آدرس دقیقاً یکسان است؛ «احتمالی» یعنی فقط کد ملی مالک (خط/سرویس) مشابه است — مثلاً همان شخص در آدرس دیگر.</div>
                        <div class="actions-row">
                                <button class="btn btn-primary" id="imp-commit">✅ ثبت نهایی ایمپورت</button>
                        </div>
                </div>`;
                document.getElementById('imp-commit').addEventListener('click', commitImport);
        }

        async function commitImport() {
                const btn = document.getElementById('imp-commit') || document.getElementById('imp-next');
                if (!imp.session || !imp.session.session_id) {
                        toast('نشست ایمپورت معتبر نیست — فایل را دوباره آپلود کنید.', 'error');
                        return;
                }
                const mapping = currentMapping();
                // ۱.۸.۰ — قفل سمت کلاینت: ارسال درخواست ثبت بدون هیچ ستون نگاشت‌شده بی‌معناست
                const mappedCount = Object.values(mapping).filter((v) => v && v !== '').length;
                if (0 === mappedCount) {
                        toast('هیچ ستونی به فیلد نگاشت نشده است — ابتدا در جدول «نگاشت ستون‌ها» فیلدها را انتخاب کنید.', 'error', 8000);
                        return;
                }
                persistImportSettings(); // ۱.۸.۱ — اگر هنوز در مرحله ۲ هستیم مقادیر تازه خوانده می‌شود؛ در مرحله ۳ no-op
                const payload = {
                        session_id: imp.session.session_id,
                        mapping,
                        // ۱.۸.۱ — در مرحله ۳ انتخاب‌های #imp-mode/#imp-match از DOM حذف شده‌اند؛ از وضعیت ذخیره‌شده استفاده می‌شود
                        mode: imp.mode || 'upsert',
                        match_key: imp.matchKey || ''
                };
                // تصمیم ردیف‌های مشابه (اگر مرحله تشابه‌ها نمایش داده شده)
                const globalChoice = document.querySelector('input[name="dup-global"]:checked');
                if (imp.dups && imp.dups.count) {
                        payload.dup_mode = globalChoice ? globalChoice.value : 'link';
                        const dupRows = {};
                        document.querySelectorAll('.dup-decision').forEach((sel) => {
                                if (sel.value) dupRows[sel.getAttribute('data-row')] = sel.value;
                        });
                        payload.dup_rows = dupRows;
                }
                if (btn) { btn.disabled = true; btn.textContent = 'در حال ثبت…'; }
                try {
                        const report = await TPP.api.request('POST', 'import/commit', payload);
                        renderReport(report);
                } catch (e) {
                        toast('خطا در ثبت: ' + esc(e.message), 'error', 8000);
                        if (btn) { btn.disabled = false; btn.textContent = '✅ ثبت نهایی ایمپورت'; }
                }
        }

        function renderReport(report) {
                setStep(4);
                const ws = document.getElementById('import-workspace');
                const totalErr = (report.errors || []).length;
                const imported = (report.created || 0) + (report.updated || 0) + (report.linked || 0) + (report.new_address || 0);
                const emptyRows = report.empty_rows || 0;
                /* ۱.۱۵.۰ — بنر پشتیبان خودکار (قبل از این ایمپورت گرفته شده) */
                const bk = report.backup || null;
                let bkBanner = '';
                if (bk && bk.filename) {
                        bkBanner = `<div class="alert ok" style="margin-bottom:14px">
                                🛡 <b>پشتیبان کامل قبل از این ایمپورت گرفته شد</b> — ${esc(bk.filename)}<br>
                                <span class="muted">تاریخ: ${esc(fmtDate(bk.created_at))}${bk.counts && bk.counts.services !== undefined ? ' — شامل ' + esc(String(bk.counts.services)) + ' سرویس و ' + esc(String(bk.counts.addresses || 0)) + ' آدرس' : ''} — اگر این ایمپورت مشکلی ایجاد کرد، از بخش «خروجی و پشتیبان» → «پشتیبان‌های ذخیره‌شده روی سرور» با <b>یک کلیک</b> بازگردانی کنید.</span>
                        </div>`;
                } else if (report.backup_auto) {
                        bkBanner = `<div class="alert warn" style="margin-bottom:14px">⚠️ پشتیبان خودکار این ایمپورت در گزارش ثبت نشد — برای اطمینان، از بخش «خروجی و پشتیبان» یک پشتیبان بگیرید.</div>`;
                } else {
                        bkBanner = `<div class="alert warn" style="margin-bottom:14px">⚠️ «پشتیبان خودکار قبل از ایمپورت» در تنظیمات خاموش است — این ایمپورت بدون نسخه پشتیبان انجام شد.</div>`;
                }
                let diag = '';
                if (0 === imported && ((report.skipped || 0) + emptyRows) > 0) {
                        diag = `<div class="alert err" style="margin-bottom:14px">
                                <b>هیچ ردیفی ثبت نشد.</b> شایع‌ترین علت‌ها:
                                <ul style="margin:6px 18px 0 0">
                                        <li>عنوان‌های سطر اول فایل با نام فیلدها مطابقت ندارند → نگاشت ستون‌ها (مرحله ۲) را اصلاح کنید؛ از «فایل نمونه» برای ساخت فایل استفاده کنید.</li>
                                        <li>سطر اول فایل عنوان نیست (مثلاً عنوان گزارش یا سطر داده است).</li>
                                        <li>مقادیر «کلید تشخیص تکراری» در همه ردیف‌ها یکسان است (مثل تلفن 0) → کلید را تغییر دهید یا «بدون تطبیق» را انتخاب کنید.</li>
                                </ul>
                        </div>`;
                }
                ws.innerHTML = `
                <div class="card">
                        <h3>گزارش ایمپورت</h3>
                        ${bkBanner}
                        ${diag}
                        <div class="stat-grid">
                                <div class="stat ok"><div class="num">${report.created}</div><div class="lbl">ثبت جدید</div></div>
                                <div class="stat"><div class="num">${report.updated}</div><div class="lbl">به‌روزرسانی</div></div>
                                <div class="stat ok"><div class="num">${report.linked || 0}</div><div class="lbl">متصل به آدرس موجود</div></div>
                                <div class="stat"><div class="num">${report.new_address || 0}</div><div class="lbl">آدرس جدید مستقل</div></div>
                                ${emptyRows ? `<div class="stat"><div class="num">${emptyRows}</div><div class="lbl">ردیف خالی (بدون داده)</div></div>` : ''}
                                <div class="stat warn"><div class="num">${report.skipped}</div><div class="lbl">رد شده</div></div>
                                <div class="stat warn"><div class="num">${report.conflicts}</div><div class="lbl">تعارض</div></div>
                        </div>
                        ${totalErr ? `
                        <h4>جزئیات ردیف‌های ردشده (${totalErr} مورد${totalErr >= 100 ? ' — ۱۰۰ مورد اول' : ''})</h4>
                        <div class="table-wrap" style="max-height:320px;overflow-y:auto"><table class="tpp-table">
                                <thead><tr><th>ردیف</th><th>علت</th></tr></thead>
                                <tbody>${report.errors.map((e) => `<tr><td>${e.row}</td><td>${esc(e.message)}</td></tr>`).join('')}</tbody>
                        </table></div>` : ''}
                        <div class="actions-row">
                                <button class="btn btn-primary" onclick="location.hash='#/services'">مشاهده سرویس‌ها</button>
                                <button class="btn" onclick="location.reload()">ایمپورت فایل دیگر</button>
                        </div>
                </div>`;
                toast(imported > 0
                        ? 'ایمپورت انجام شد: ' + report.created + ' ثبت، ' + report.updated + ' به‌روزرسانی'
                        : 'هیچ ردیفی از فایل ثبت نشد — گزارش را بررسی کنید',
                        imported > 0 ? 'success' : 'error', 7000);
        }

        /* ============================================================
         * خروجی و پشتیبان
         * ============================================================ */

        TPP.views.export = async function () {
                const manager = can('tpp_manage_settings');
                document.getElementById('content').innerHTML = `
                ${!can('tpp_export') ? '<div class="alert err">شما اجازه خروجی گرفتن ندارید.</div>' : ''}
                <div class="card">
                        <h3>📤 خروجی اکسل (Excel)</h3>
                        <p class="muted">خروجی بر اساس فیلدهای قابل مشاهده شما ساخته می‌شود و ستون‌ها دقیقاً مطابق فیلدهای فعلی است.</p>
                        <div class="field">
                                <label>محدوده</label>
                                <select id="exp-scope" class="btn">
                                        <option value="">همه سرویس‌ها</option>
                                        <option value="filtered">فقط نتیجه آخرین جستجو</option>
                                </select>
                        </div>
                        <div class="actions-row">
                                <button class="btn btn-primary" id="exp-xlsx" ${can('tpp_export') ? '' : 'disabled'}>⬇️ دریافت فایل اکسل</button>
                        </div>
                </div>
                <div class="card">
                        <h3>📄 خروجی PDF</h3>
                        <div class="backup-grid">
                                <div class="backup-item">
                                        <h4>🖥 روش ۱ — تولید در خود سایت</h4>
                                        <p class="muted">فایل PDF آماده دانلود، مستقیماً روی سرور با کتابخانه TCPDF تولید می‌شود؛ فونت فارسی داخل فایل تعبیه شده و روی همه دستگاه‌ها (موبایل/کامپیوتر/چاپگر) بدون تغییر نمایش داده می‌شود.</p>
                                        <button class="btn btn-primary" id="exp-pdf" ${can('tpp_export') ? '' : 'disabled'}>⬇️ دریافت فایل PDF</button>
                                </div>
                                <div class="backup-item">
                                        <h4>🖨 روش ۲ — چاپ از مرورگر</h4>
                                        <p class="muted">صفحه گزارش در پنجره جدید باز می‌شود و پنجره چاپ مرورگر خودکار ظاهر می‌شود؛ گزینه «Save as PDF / ذخیره به‌عنوان PDF» را انتخاب کنید. فونت از خود دستگاه خوانده می‌شود — ساده و مطمئن.</p>
                                        <button class="btn" id="exp-print" ${can('tpp_export') ? '' : 'disabled'}>🖨 بازکردن صفحه چاپ</button>
                                </div>
                        </div>
                </div>
                ${manager ? `
                <div class="card">
                        <h3>💾 پشتیبان‌گیری کامل — مدیر</h3>
                        <div id="bk-info" class="bk-info"><span class="muted">در حال دریافت اطلاعات پشتیبان…</span></div>
                        <div class="field" style="margin-top:10px">
                                <label>محتوای پشتیبان JSON</label>
                                <label class="checkbox-row"><input type="checkbox" id="bk-with-history" checked> شامل تاریخچه تغییرات</label>
                                <label class="checkbox-row"><input type="checkbox" id="bk-with-activity"> شامل گزارش بازدید/جستجو</label>
                                <label class="checkbox-row"><input type="checkbox" id="bk-with-sms"> شامل گزارش پیامک‌ها</label>
                                <div class="hint">هرچه خاموش‌تر، فایل کوچک‌تر و سریع‌تر. پشتیبان SQL و ZIP همیشه کامل هستند.</div>
                        </div>
                        <div class="backup-grid">
                                <div class="backup-item">
                                        <h4>🗄 پشتیبان SQL (قابل انتقال)</h4>
                                        <p class="muted">فایل SQL کامل شامل ساختار و داده همه جداول — <b>روی هر سرور و پیشوند دیگری</b> قابل درج مستقیم در phpMyAdmin (پیشوند جداول مقصد خودکار تشخیص می‌شود؛ توکن‌ها منتقل نمی‌شوند).</p>
                                        <button class="btn" id="exp-backup-sql">⬇️ دریافت SQL</button>
                                </div>
                                <div class="backup-item">
                                        <h4>🗜 پشتیبان ZIP (همه‌چیز)</h4>
                                        <p class="muted">بسته کامل: دیتابیس SQL + پشتیبان JSON + <b>کل فایل‌های افزونه</b> + راهنمای بازیابی — مناسب انتقال یا آرشیو کامل.</p>
                                        <button class="btn" id="exp-backup-zip">⬇️ دریافت ZIP</button>
                                </div>
                                <div class="backup-item">
                                        <h4>📋 پشتیبان JSON</h4>
                                        <p class="muted">داده‌ها به‌صورت JSON — قابل بازیابی از داخل خود افزونه روی هر سایت دیگری (انتخاب محتوا با تیک‌های بالا).</p>
                                        <button class="btn" id="exp-backup">⬇️ دریافت JSON</button>
                                </div>
                        </div>
                        <div class="divider"></div>
                        <h4>بازیابی از پشتیبان — روی هر سرور/دامنه</h4>
                        <div class="alert warn">⚠️ بازیابی، همه داده‌های فعلی (فیلدها، سرویس‌ها، تاریخچه و تنظیمات) را با محتوای فایل پشتیبان جایگزین می‌کند. قبل از بازیابی، یک پشتیبان تازه بگیرید.</div>
                        <div class="actions-row">
                                <input type="file" id="restore-file" accept=".json,application/json" class="btn">
                                <button class="btn btn-danger" id="restore-btn">بازیابی از فایل JSON</button>
                        </div>
                        <div id="restore-progress" class="hidden">
                                <div class="progress"><div class="progress-bar" id="restore-bar" style="width:0%"></div></div>
                                <div class="muted" id="restore-status"></div>
                        </div>
                        <div id="restore-result"></div>
                        <p class="muted">فایل‌های بزرگ به‌صورت خودکار <b>تکه‌تکه</b> آپلود می‌شوند — محدودیت حجم آپلود سرور (upload_max_filesize / post_max_size) دیگر مشکلی ایجاد نمی‌کند و پیشرفت کار لحظه‌ای نمایش داده می‌شود. برای بازیابی از بسته ZIP، فایل <code>data.json</code> داخل آن را جدا کرده و همین‌جا انتخاب کنید.</p>
                </div>
                <div class="card">
                        <h3>🗄 پشتیبان‌های ذخیره‌شده روی سرور (پوشه افزونه)</h3>
                        <div class="alert info">🔄 <b>قبل از هر ایمپورت گروهی</b>، به‌صورت خودکار یک پشتیبان کامل از کل داده‌ها (فیلدها، سرویس‌ها، آدرس‌ها، تاریخچه و تنظیمات) گرفته و روی سرور ذخیره می‌شود — اگر ایمپورت مشکلی ایجاد کرد، با <b>یک کلیک</b> وضعیتِ دقیقِ قبل از ایمپورت بازگردانی می‌شود. هر نسخه با تاریخ/زمان و نام کاربر آغازگر مشخص است.</div>
                        <div id="sb-info"><span class="muted">در حال دریافت فهرست…</span></div>
                        <div class="actions-row">
                                <button class="btn" id="sb-refresh">🔄 نوسازی فهرست</button>
                                <button class="btn btn-primary" id="sb-create">➕ گرفتن پشتیبان تازه و ذخیره همین‌جا</button>
                        </div>
                        <div id="sb-list"></div>
                        <div id="sb-progress" class="hidden" style="margin-top:12px"><div class="spinner"></div> <span class="muted" id="sb-status">در حال بازیابی…</span></div>
                        <div id="sb-result"></div>
                        <p class="muted">تعداد نسخه‌های نگهداری‌شده و روشن/خاموش بودن پشتیبان خودکار، از بخش «تنظیمات» قابل تغییر است. محل ذخیره پیش‌فرض پوشه افزونه (<code dir="ltr">backups/</code>) است؛ اگر میزبان اجازه نوشتن نداد، خودکار به پوشه uploads منتقل می‌شود. <b>نکته مهم:</b> هنگام حذف افزونه یا به‌روزرسانی با FTP، پوشه پشتیبان‌ها را جداگانه نگه دارید — بسته ZIP همیشه شامل کل فایل‌های افزونه است.</p>
                </div>` : ''}`;

                document.getElementById('exp-xlsx').addEventListener('click', async () => {
                        const params = exportParams();
                        try { await TPP.api.download('export/xlsx', params, 'tpp-services.xlsx'); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
                document.getElementById('exp-pdf').addEventListener('click', async () => {
                        const params = exportParams();
                        toast('در حال ساخت فایل PDF…');
                        try { await TPP.api.download('export/pdf', params, 'tpp-report.pdf'); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
                document.getElementById('exp-print').addEventListener('click', async () => {
                        const params = exportParams();
                        toast('در حال آماده‌سازی صفحه چاپ…');
                        try { await TPP.api.openHtml('export/print', params); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error', 8000); }
                });
                const bSql = document.getElementById('exp-backup-sql');
                if (bSql) bSql.addEventListener('click', async () => {
                        toast('در حال ساخت فایل SQL…');
                        try { await TPP.api.download('backup/sql', null, 'tpp-database.sql'); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
                const bZip = document.getElementById('exp-backup-zip');
                if (bZip) bZip.addEventListener('click', async () => {
                        toast('در حال ساخت بسته کامل ZIP… (ممکن است چند ثانیه طول بکشد)');
                        try { await TPP.api.download('backup/zip', null, 'tpp-backup.zip'); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
                const bk = document.getElementById('exp-backup');
                if (bk) bk.addEventListener('click', async () => {
                        const params = backupFlags();
                        try { await TPP.api.download('backup', params, 'tpp-backup.json'); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error', 8000); }
                });
                const rb = document.getElementById('restore-btn');
                if (rb) rb.addEventListener('click', () => runRestore());

                // ۱.۱۵.۰ — پشتیبان‌های ذخیره‌شده روی سرور (پوشه افزونه)
                const sbRefresh = document.getElementById('sb-refresh');
                if (sbRefresh) sbRefresh.addEventListener('click', loadStoredBackups);
                const sbCreate = document.getElementById('sb-create');
                if (sbCreate) sbCreate.addEventListener('click', async () => {
                        sbCreate.disabled = true;
                        sbCreate.textContent = '⏳ در حال گرفتن پشتیبان…';
                        try {
                                const meta = await TPP.api.request('POST', 'backup/stored', {});
                                toast('پشتیبان تازه گرفته و ذخیره شد: ' + (meta.filename || ''), 'success', 6000);
                                await loadStoredBackups();
                        } catch (e) {
                                toast('خطا در پشتیبان‌گیری: ' + esc(e.message), 'error', 8000);
                        } finally {
                                sbCreate.disabled = false;
                                sbCreate.textContent = '➕ گرفتن پشتیبان تازه و ذخیره همین‌جا';
                        }
                });
                if (manager) loadStoredBackups();

                // اطلاعات پشتیبان (تعداد/حجم تقریبی/محدودیت‌های PHP)
                if (manager) loadBackupInfo();
        };

        /** پارامترهای انتخاب محتوای پشتیبان JSON */
        function backupFlags() {
                const params = {};
                const h = document.getElementById('bk-with-history');
                const a = document.getElementById('bk-with-activity');
                const m = document.getElementById('bk-with-sms');
                if (h) params.with_history = h.checked ? 1 : 0;
                if (a) params.with_activity = a.checked ? 1 : 0;
                if (m) params.with_sms_log = m.checked ? 1 : 0;
                return params;
        }

        /** پنل اطلاعات پشتیبان — تعداد رکوردها + حجم تقریبی + محدودیت‌های سرور */
        async function loadBackupInfo() {
                const box = document.getElementById('bk-info');
                if (!box) return;
                try {
                        const info = await TPP.api.request('GET', 'backup/info');
                        const t = info.tables || {};
                        const est = info.estimate_mb || {};
                        const php = info.php || {};
                        const tableNames = { services: 'سرویس', addresses: 'آدرس', history: 'تاریخچه تغییرات', view_log: 'گزارش بازدید', search_log: 'گزارش جستجو', sms_log: 'گزارش پیامک', sms_templates: 'قالب پیامک', fields: 'فیلد' };
                        const rows = Object.entries(tableNames).filter(([k]) => t[k] !== undefined).map(([k, label]) =>
                                '<span class="chip">' + label + ': ' + esc(t[k].toLocaleString('fa-IR')) + '</span>').join(' ');
                        box.innerHTML = '<div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px">' + rows + '</div>' +
                                '<p class="muted" style="margin-top:8px">حجم تقریبی پشتیبان: JSON ≈ ' + esc(String(est.default || 0)) + ' مگابایت (با همه بخش‌ها ≈ ' + esc(String(est.full || 0)) + ') — SQL ≈ ' + esc(String(est.sql || 0)) + ' مگابایت.</p>' +
                                '<p class="muted">سرور: PHP ' + esc(php.version || '') + ' — سقف آپلود ' + esc(php.upload_max_filesize || '?') + ' / POST ' + esc(php.post_max_size || '?') + ' — حافظه ' + esc(php.memory_limit || '?') + ' — زمان اجرا ' + esc(php.max_execution_time || '?') + ' ثانیه' +
                                (info.chunked ? ' — آپلود تکه‌ای فعال (محدودیت آپلود دور زده می‌شود).' : '') + '</p>';
                } catch (e) {
                        box.innerHTML = '<span class="muted">اطلاعات پشتیبان در دسترس نیست: ' + esc(e.message) + '</span>';
                }
        }

        /* ---------- ۱.۱۵.۰ — پشتیبان‌های ذخیره‌شده روی سرور (پوشه افزونه) ---------- */

        /** فهرست پشتیبان‌های ذخیره‌شده + رندر جدول با دکمه‌های یک‌کلیکی */
        async function loadStoredBackups() {
                const info = document.getElementById('sb-info');
                const list = document.getElementById('sb-list');
                if (!info || !list) return;
                try {
                        const res = await TPP.api.request('GET', 'backup/list');
                        const st = res.info || {};
                        const items = res.items || [];
                        info.innerHTML = '<div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px">' +
                                '<span class="chip">' + 'محل ذخیره: ' + esc(st.dir_label || '') + '</span>' +
                                '<span class="chip">' + (st.writable ? 'قابل نوشتن ✓' : 'غیرقابل نوشتن ✗') + '</span>' +
                                '<span class="chip">' + 'پشتیبان خودکار ایمپورت: ' + (st.auto ? 'روشن' : 'خاموش') + '</span>' +
                                '<span class="chip">' + 'نگهداری: ' + esc(String(st.keep || 0)) + ' نسخه' + (st.keep === 0 ? ' (نامحدود)' : '') + '</span>' +
                                '<span class="chip">' + 'موجود: ' + esc(String(st.count || 0)) + ' نسخه</span>' +
                                '</div>';
                        if (!st.writable) {
                                info.innerHTML += '<div class="alert err" style="margin-top:8px">پوشه پشتیبان‌ها قابل نوشتن نیست — دسترسی نوشتن پوشه افزونه یا uploads را بررسی کنید؛ تا آن زمان پشتیبان‌گیری ذخیره‌ای انجام نمی‌شود.</div>';
                        }
                        if (!items.length) {
                                list.innerHTML = '<p class="muted" style="margin-top:10px">هنوز هیچ پشتیبانی ذخیره نشده است — با اولین ایمپورت گروهی (یا دکمه «گرفتن پشتیبان تازه») ساخته می‌شود.</p>';
                                return;
                        }
                        const ctxLabel = { import: 'خودکار (قبل از ایمپورت)', manual: 'دستی', numfix: 'خودکار (قبل از اصلاح اعداد)' }; // 1.18.0
                        list.innerHTML = '<div class="table-wrap" style="margin-top:10px"><table class="tpp-table"><thead><tr>' +
                                '<th>فایل</th><th>تاریخ</th><th>نوع</th><th>کاربر</th><th>حجم</th><th>محتوا</th><th>عملیات</th>' +
                                '</tr></thead><tbody>' +
                                items.map((it) => `
                                        <tr data-sb="${esc(it.filename)}">
                                                <td><code dir="ltr" style="font-size:11px">${esc(it.filename)}</code></td>
                                                <td>${esc(fmtDate(it.created_at))}</td>
                                                <td>${it.context === 'import' || it.context === 'numfix' ? '<span class="chip ok">' + esc(ctxLabel[it.context] || it.context) + '</span>' : '<span class="chip">' + esc(ctxLabel.manual || it.context) + '</span>'}</td>
                                                <td class="muted">${esc(it.user || '—')}</td>
                                                <td>${it.size ? Math.max(1, Math.round((it.size || 0) / 1024)) + ' KB' : '—'}</td>
                                                <td class="muted">${it.counts ? esc(String(it.counts.services || 0)) + ' سرویس / ' + esc(String(it.counts.addresses || 0)) + ' آدرس' : '—'}</td>
                                                <td class="num-cell" style="white-space:nowrap">
                                                        <button class="btn btn-sm btn-danger" data-sb-restore="${esc(it.filename)}" title="جایگزینی کل داده‌های فعلی با همین نسخه">♻️ بازگردانی</button>
                                                        <button class="btn btn-sm" data-sb-download="${esc(it.filename)}" title="دانلود این نسخه">⬇️</button>
                                                        <button class="btn btn-sm" data-sb-delete="${esc(it.filename)}" title="حذف این نسخه">🗑</button>
                                                </td>
                                        </tr>`).join('') +
                                '</tbody></table></div>';
                        list.querySelectorAll('[data-sb-restore]').forEach((b) => b.addEventListener('click', () => restoreStored(b.getAttribute('data-sb-restore'))));
                        list.querySelectorAll('[data-sb-download]').forEach((b) => b.addEventListener('click', async () => {
                                try { await TPP.api.download('backup/stored/download', { filename: b.getAttribute('data-sb-download') }, b.getAttribute('data-sb-download')); }
                                catch (e) { toast('خطا در دانلود: ' + esc(e.message), 'error'); }
                        }));
                        list.querySelectorAll('[data-sb-delete]').forEach((b) => b.addEventListener('click', async () => {
                                const fn = b.getAttribute('data-sb-delete');
                                if (!await confirmBox('این نسخه پشتیبان حذف شود؟<br><code dir="ltr">' + esc(fn) + '</code>', 'حذف')) return;
                                try {
                                        await TPP.api.request('POST', 'backup/stored/delete', { filename: fn });
                                        toast('نسخه پشتیبان حذف شد.', 'success');
                                        await loadStoredBackups();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        }));
                } catch (e) {
                        info.innerHTML = '<span class="muted">فهرست پشتیبان‌ها در دسترس نیست: ' + esc(e.message) + '</span>';
                        list.innerHTML = '';
                }
        }

        /** بازگردانی یک‌کلیکی از پشتیبان ذخیره‌شده روی سرور (بدون آپلود فایل) */
        async function restoreStored(filename) {
                if (!await confirmBox('همه داده‌های فعلی با این نسخه پشتیبان جایگزین شود؟<br><span class="muted">فایل: <code dir="ltr">' + esc(filename) + '</code><br>این عمل قابل بازگشت نیست — برای ادامه، «بازگردانی» را بزنید.</span>', 'بازگردانی')) return;
                const prog = document.getElementById('sb-progress');
                const status = document.getElementById('sb-status');
                const result = document.getElementById('sb-result');
                if (prog) prog.classList.remove('hidden');
                if (status) status.textContent = 'در حال بازیابی از ' + filename + ' … (نسخه‌های بزرگ چند لحظه طول می‌کشد)';
                if (result) result.innerHTML = '';
                try {
                        const res = await TPP.api.request('POST', 'backup/stored/restore', { filename });
                        if (status) status.textContent = 'بازیابی کامل شد.';
                        renderRestoreSummary(res, 'sb-result');
                        await loadStoredBackups();
                } catch (e) {
                        toast('خطا در بازیابی: ' + esc(e.message), 'error', 10000);
                        if (result) result.innerHTML = '<div class="alert err">' + esc(e.message) + '</div>';
                } finally {
                        if (prog) prog.classList.add('hidden');
                }
        }

        /* ---------- بازیابی با آپلود تکه‌ای خودکار + نوار پیشرفت + خلاصه نتیجه ---------- */

        /** برش امن متن در مرز کاراکتر (جفت‌های جایگزین ایموجی نصف نمی‌شوند) */
        function sliceTextSafe(str, start, len) {
                let end = Math.min(str.length, start + len);
                if (end < str.length) {
                        const code = str.charCodeAt(end - 1);
                        if (code >= 0xD800 && code <= 0xDBFF) end--; // نیمه بالایی جفت جایگزین → کامل در تکه بعدی
                }
                return str.slice(start, end);
        }

        async function runRestore() {
                const f = document.getElementById('restore-file').files[0];
                if (!f) { toast('ابتدا فایل پشتیبان را انتخاب کنید.', 'warn'); return; }
                if (!await confirmBox('همه داده‌های فعلی با فایل پشتیبان جایگزین شود؟ <br><span class="muted">این عمل قابل بازگشت نیست — از پشتیبان تازه مطمئن شوید.</span>', 'بازیابی')) return;

                const prog = document.getElementById('restore-progress');
                const bar = document.getElementById('restore-bar');
                const status = document.getElementById('restore-status');
                const result = document.getElementById('restore-result');
                const btn = document.getElementById('restore-btn');
                result.innerHTML = '';
                btn.disabled = true;
                prog.classList.remove('hidden');

                const setStatus = (pct, txt) => {
                        if (bar) bar.style.width = Math.round(pct) + '%';
                        if (status) status.textContent = txt;
                };

                try {
                        const text = await f.text();
                        let res;
                        const CH = 300000; // اندازه تکه (کاراکتر) — کوچک‌تر از محدودیت‌های معمول سرور
                        if (text.length <= CH) {
                                setStatus(20, 'در حال ارسال فایل (' + Math.ceil(text.length / 1024) + ' کیلوبایت)…');
                                res = await TPP.api.request('POST', 'restore', { json: text });
                        } else {
                                setStatus(2, 'در حال شروع آپلود تکه‌ای…');
                                const beg = await TPP.api.request('POST', 'restore/begin', { size: text.length, name: f.name });
                                const chunkSize = (int => int > 1000 ? int : CH)(parseInt(beg && beg.chunk_size, 10));
                                let pos = 0, idx = 0;
                                const total = Math.ceil(text.length / chunkSize);
                                while (pos < text.length) {
                                        const piece = sliceTextSafe(text, pos, chunkSize);
                                        await TPP.api.request('POST', 'restore/chunk', { token: beg.token, index: idx, data: piece });
                                        pos += piece.length; idx++;
                                        setStatus(5 + 85 * (idx / total), 'در حال آپلود تکه ' + idx + ' از ' + total + ' — ' + Math.ceil(pos / 1024) + ' از ' + Math.ceil(text.length / 1024) + ' کیلوبایت');
                                }
                                setStatus(92, 'آپلود کامل شد — در حال بازیابی روی سرور… (فایل‌های بزرگ چند لحظه طول می‌کشد)');
                                res = await TPP.api.request('POST', 'restore/finish', { token: beg.token });
                        }
                        setStatus(100, 'بازیابی کامل شد.');
                        renderRestoreSummary(res);
                } catch (e) {
                        setStatus(0, '');
                        prog.classList.add('hidden');
                        toast('خطا در بازیابی: ' + esc(e.message), 'error', 10000);
                } finally {
                        btn.disabled = false;
                }
        }

        /** نمایش خلاصه نتیجه بازیابی (شمارش هر جدول + هشدارها) — boxId: عنصر مقصد */
        function renderRestoreSummary(res, boxId) {
                const box = document.getElementById(boxId || 'restore-result');
                if (!box) return;
                const sum = (res && res.summary) || {};
                const tables = sum.tables || {};
                const names = { fields: 'فیلدها', addresses: 'آدرس‌ها', services: 'سرویس‌ها', history: 'تاریخچه تغییرات', view_log: 'گزارش بازدید', search_log: 'گزارش جستجو', sms_templates: 'قالب‌های پیامک', sms_log: 'گزارش پیامک', work_reports: 'گزارش‌های کار' }; // 1.18.0
                let html = '<div class="alert success">✅ بازیابی کامل شد' +
                        (sum.elapsed_ms !== undefined ? ' — مدت: ' + esc(String(Math.round(sum.elapsed_ms / 100) / 10)) + ' ثانیه' : '') +
                        (sum.backup_from ? ' — مبدأ: ' + esc(sum.backup_from) : '') + '</div>';
                html += '<div class="table-wrap"><table class="tpp-table"><thead><tr><th>بخش</th><th>درج‌شده</th><th>در جدول</th><th>ردشده</th><th>خطا</th></tr></thead><tbody>';
                Object.entries(tables).forEach(([k, t]) => {
                        html += '<tr><td>' + esc(names[k] || k) + '</td><td class="num-cell">' + esc(String(t.inserted)) + '</td><td class="num-cell">' + esc(String(t.actual)) + '</td><td class="num-cell">' + esc(String(t.skipped || 0)) + '</td><td class="num-cell">' + (t.failed ? String(t.failed) : '—') + '</td></tr>';
                });
                html += '</tbody></table></div>';
                (sum.warnings || []).forEach((w) => { html += '<div class="alert warn" style="margin-top:6px">⚠️ ' + esc(w) + '</div>'; });
                (sum.errors || []).forEach((er) => { html += '<div class="alert err" style="margin-top:6px">' + esc(er) + '</div>'; });
                html += '<div class="actions-row" style="margin-top:10px"><button class="btn btn-primary" id="restore-reload">🔄 بارگذاری مجدد برنامه</button></div>';
                box.innerHTML = html;
                const rl = document.getElementById('restore-reload');
                if (rl) rl.addEventListener('click', () => location.reload());
                toast('بازیابی کامل شد — برای اعمال کامل، برنامه را بارگذاری مجدد کنید.', 'success', 8000);
        };

        function exportParams() {
                const scope = document.getElementById('exp-scope').value;
                if (scope === 'filtered' && TPP.app.searchState) {
                        /* ۱.۲۱.۰ — همه فیلترهای آخرین جستجو (مثل صفحه سرویس‌ها): متن + فیلتر فیلدها +
                         * بازه ویرایش + وضعیت دایری + دسته/تگ — قبلاً فقط متن/فیلتر رد می‌شد */
                        const st = TPP.app.searchState;
                        const params = {};
                        if (st.query) params.query = st.query;
                        if (Object.keys(st.filters || {}).length) params.filters = JSON.stringify(st.filters);
                        if (st.updFrom) params.upd_from = st.updFrom;
                        if (st.updTo) params.upd_to = st.updTo;
                        if (st.prog && st.prog.status) params.progress_status = st.prog.status;
                        if (st.prog && st.prog.step) {
                                params.progress_step = st.prog.step;
                                params.progress_step_state = st.prog.stepState || 'done';
                        }
                        if (st.cat) params.category = st.cat;
                        if (st.tags && st.tags.length) params.tags = st.tags.join(',');
                        return params;
                }
                return {};
        }

        /* ============================================================
         * فیلدهای داینامیک
         * ============================================================ */

        /* ============================================================
         * گزارش فعالیت (۱.۱۰.۰) — تاریخچه بازدید سرویس‌ها + تاریخچه جستجو
         * ============================================================ */

        const activityState = { tab: 'all', page: 1, userId: 0, q: '', action: '', range: 'all' };

        /* محدوده‌های سریع تاریخ → from/to میلادی */
        function actRangeDates(range) {
                if (range === 'all') return { from: '', to: '' };
                const days = { today: 0, d7: 6, d30: 29 }[range];
                if (days === undefined) return { from: '', to: '' };
                const to = TPP.app.tehranTodayIso(); // ۱.۱۸.۰ — بازه‌ها به وقت تهران
                const from = TPP.app.isoShift(to, -days);
                return { from: from || '', to };
        }

        const actRangeLabel = { all: 'همه زمان‌ها', today: 'امروز', d7: '۷ روز اخیر', d30: '۳۰ روز اخیر' };

        TPP.views.activity = async function () {
                if (!can('tpp_view_activity')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">دسترسی ندارید.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">📴 گزارش فعالیت به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>📋 گزارش فعالیت کاربران</h3>
                        <p class="muted">چه کسی چه سرویسی را <b>ایجاد/ویرایش/حذف</b> کرد، چه سرویسی را <b>بازدید</b> کرد و چه چیزی <b>جستجو</b> کرد — همه در یک گزارش. جستجوهای در حال تایپ (آجاکس) در پنجره زمانی تنظیم‌شده به‌صورت یک جستجوی واحد ثبت می‌شوند. مدت نگهداری از تنظیمات قابل تغییر است.</p>
                        <div id="act-stats" class="act-stats"></div>
                        <div class="search-bar" style="flex-wrap:wrap">
                                <button class="btn ${activityState.tab === 'all' ? 'btn-primary' : ''}" data-act-tab="all">🗂 همه</button>
                                <button class="btn ${activityState.tab === 'change' ? 'btn-primary' : ''}" data-act-tab="change">📝 تغییرات</button>
                                <button class="btn ${activityState.tab === 'view' ? 'btn-primary' : ''}" data-act-tab="view">👁 بازدیدها</button>
                                <button class="btn ${activityState.tab === 'search' ? 'btn-primary' : ''}" data-act-tab="search">🔍 جستجوها</button>
                                <select id="act-user" class="btn"><option value="0">همه کاربران</option></select>
                                <select id="act-action" class="btn">
                                        <option value="">همه عملیات</option>
                                        <option value="create">ایجاد</option><option value="update">ویرایش</option>
                                        <option value="delete">حذف</option><option value="merge">ادغام</option>
                                        <option value="restore">بازگردانی</option>
                                </select>
                                <select id="act-range" class="btn">
                                        <option value="all">همه زمان‌ها</option>
                                        <option value="today">امروز</option>
                                        <option value="d7">۷ روز اخیر</option>
                                        <option value="d30">۳۰ روز اخیر</option>
                                </select>
                                <input type="text" id="act-q" class="btn" placeholder="جستجو در شرح/عبارت…" value="${esc(activityState.q)}">
                                <button class="btn btn-primary" id="act-refresh">نمایش</button>
                                <button class="btn" id="act-csv" title="دانلود خروجی CSV با همین فیلترها">📊 CSV</button>
                        </div>
                        <div id="act-users-chips" class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px"></div>
                </div>
                <div id="act-area"><div class="loading-block"><div class="spinner"></div></div></div>`;

                document.querySelectorAll('[data-act-tab]').forEach((b) => b.addEventListener('click', () => {
                        activityState.tab = b.getAttribute('data-act-tab');
                        activityState.page = 1;
                        TPP.views.activity();
                }));
                document.getElementById('act-refresh').addEventListener('click', () => {
                        activityState.userId = parseInt(document.getElementById('act-user').value, 10) || 0;
                        activityState.action = document.getElementById('act-action').value;
                        activityState.range = document.getElementById('act-range').value;
                        activityState.q = document.getElementById('act-q').value.trim();
                        activityState.page = 1;
                        loadActivity();
                });
                document.getElementById('act-q').addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') document.getElementById('act-refresh').click();
                });
                document.getElementById('act-csv').addEventListener('click', async () => {
                        const btn = document.getElementById('act-csv');
                        btn.disabled = true;
                        try {
                                await TPP.api.download('activity/export', actQueryParams(), 'tpp-activity-' + Date.now() + '.csv');
                                toast('خروجی CSV دانلود شد.', 'success');
                        } catch (e) {
                                toast('خطا در خروجی CSV: ' + esc(e.message), 'error');
                        }
                        btn.disabled = false;
                });
                // مقادیر فعلی فیلترها در UI
                const selUser = document.getElementById('act-user');
                const selAction = document.getElementById('act-action');
                const selRange = document.getElementById('act-range');
                if (activityState.action) selAction.value = activityState.action;
                selRange.value = activityState.range;
                await Promise.all([loadActivityStats(), loadActivity()]);
        };

        function actQueryParams() {
                const { from, to } = actRangeDates(activityState.range);
                const params = { page: activityState.page, per_page: 50 };
                if (activityState.userId) params.user_id = activityState.userId;
                if (activityState.q) params.q = activityState.q;
                if (from) { params.from = from; params.to = to; }
                if (activityState.tab === 'all') {
                        if (activityState.action) params.action = activityState.action;
                } else {
                        params.type = activityState.tab;
                        if (activityState.tab === 'change' && activityState.action) params.action = activityState.action;
                }
                return params;
        }

        async function loadActivityStats() {
                const box = document.getElementById('act-stats');
                if (!box) return;
                try {
                        const st = await TPP.api.request('GET', 'activity/stats');
                        const fa = (n) => (n || 0).toLocaleString('fa-IR');
                        box.innerHTML = `
                        <div class="act-stat-cards">
                                <div class="act-stat"><span class="n">${fa(st.ranges && st.ranges.today ? st.ranges.today.changes : 0)}</span><span class="l">تغییر امروز</span></div>
                                <div class="act-stat"><span class="n">${fa(st.ranges && st.ranges.today ? st.ranges.today.views : 0)}</span><span class="l">بازدید امروز</span></div>
                                <div class="act-stat"><span class="n">${fa(st.ranges && st.ranges.today ? st.ranges.today.searches : 0)}</span><span class="l">جستجو امروز</span></div>
                                <div class="act-stat"><span class="n">${fa(st.ranges && st.ranges.d7 ? st.ranges.d7.changes : 0)}</span><span class="l">تغییر ۷ روز</span></div>
                                <div class="act-stat"><span class="n">${fa(st.ranges && st.ranges.d7 ? st.ranges.d7.views : 0)}</span><span class="l">بازدید ۷ روز</span></div>
                                <div class="act-stat"><span class="n">${fa(st.ranges && st.ranges.d7 ? st.ranges.d7.searches : 0)}</span><span class="l">جستجو ۷ روز</span></div>
                        </div>
                        ${(st.top_queries || []).length ? `
                        <div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">
                                <span class="muted" style="align-self:center">جستجوهای پرتکرار:</span>
                                ${st.top_queries.slice(0, 8).map((q) => `<span class="chip" title="آخرین تعداد نتایج: ${fa(q.results)}">🔍 ${esc(q.query)} <b>${fa(q.count)}</b></span>`).join('')}
                        </div>` : ''}
                        ${(st.top_services || []).length ? `
                        <div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                                <span class="muted" style="align-self:center">پربازدیدترین‌ها:</span>
                                ${st.top_services.slice(0, 8).map((s) => `<span class="chip" data-act-svc="${esc(String(s.id))}" style="cursor:pointer" title="بازکردن سرویس">👁 سرویس #${esc(String(s.id))} <b>${fa(s.views)}</b></span>`).join('')}
                        </div>` : ''}`;
                        box.querySelectorAll('[data-act-svc]').forEach((c) => c.addEventListener('click', () => go('service/' + c.getAttribute('data-act-svc'))));
                } catch (e) { box.innerHTML = ''; }
        }

        async function loadActivity() {
                const area = document.getElementById('act-area');
                if (!area) return;
                try {
                        let data;
                        if (activityState.tab === 'view') {
                                const params = { page: activityState.page, per_page: 50 };
                                if (activityState.userId) params.user_id = activityState.userId;
                                const { from, to } = actRangeDates(activityState.range);
                                if (from) { params.from = from; params.to = to; }
                                if (activityState.q) params.q = activityState.q;
                                data = await TPP.api.request('GET', 'activity/views', null, params);
                        } else if (activityState.tab === 'search') {
                                const params = { page: activityState.page, per_page: 50 };
                                if (activityState.userId) params.user_id = activityState.userId;
                                if (activityState.q) params.q = activityState.q;
                                data = await TPP.api.request('GET', 'activity/searches', null, params);
                        } else {
                                data = await TPP.api.request('GET', 'activity/log', null, actQueryParams());
                        }
                        renderActivityUserFilter(data.users || []);
                        area.innerHTML = activityHtml(data) + activityPagination(data);
                        area.querySelectorAll('button[data-page]').forEach((b) => {
                                b.addEventListener('click', () => { activityState.page = parseInt(b.getAttribute('data-page'), 10); loadActivity(); });
                        });
                        area.querySelectorAll('tr[data-svc]').forEach((tr) => {
                                tr.addEventListener('click', () => go('service/' + tr.getAttribute('data-svc')));
                        });
                } catch (e) {
                        area.innerHTML = '<div class="alert err">خطا در دریافت گزارش: ' + esc(e.message) + '</div>';
                }
        }

        function renderActivityUserFilter(users) {
                const sel = document.getElementById('act-user');
                if (!sel) return;
                const cur = activityState.userId;
                sel.innerHTML = '<option value="0">همه کاربران</option>' + users.map((u) =>
                        '<option value="' + esc(u.id) + '"' + (u.id === cur ? ' selected' : '') + '>' + esc(u.name) + ' (' + u.count + ')</option>').join('');
                const chips = document.getElementById('act-users-chips');
                if (chips) {
                        chips.innerHTML = users.slice(0, 12).map((u) => '<span class="chip">' + esc(u.name) + ': ' + esc(String(u.count)) + ' رکورد</span>').join('') || '<span class="muted">کاربری در گزارش نیست.</span>';
                }
        }

        const ACT_SRC_FA = { change: 'تغییر', view: 'بازدید', search: 'جستجو', sms: 'پیامک' };
        const ACT_ACTION_FA = { create: 'ایجاد', update: 'ویرایش', delete: 'حذف', merge: 'ادغام', restore: 'بازگردانی', view: 'بازدید', search: 'جستجو', sms: 'پیامک' };
        const ACT_SRC_ICON = { change: '📝', view: '👁', search: '🔍', sms: '📨' };

        function activityHtml(data) {
                const rows = data.rows || [];
                if (!rows.length) return '<div class="card"><div class="empty-state"><div class="big">📭</div><p>فعالیتی در این فیلتر ثبت نشده است.</p><p class="muted">با باز کردن صفحه سرویس‌ها، جستجو و ثبت/ویرایش داده، گزارش اینجا نمایش داده می‌شود.</p></div></div>';

                if (activityState.tab === 'view') {
                        return `<div class="card"><div class="table-wrap"><table class="tpp-table">
                                <thead><tr><th>کاربر</th><th>سرویس</th><th>تعداد بازدید</th><th>اولین بازدید</th><th>آخرین بازدید</th></tr></thead>
                                <tbody>${rows.map((r) => `<tr data-svc="${esc(r.entity_id)}" style="cursor:pointer" title="بازکردن صفحه سرویس">
                                        <td>${esc(r.user_name || '')}</td>
                                        <td class="num-cell">#${esc(r.entity_id)}</td>
                                        <td class="num-cell">${esc(String(r.views || 1))}</td>
                                        <td class="muted">${fmtDate(r.first_at)}</td>
                                        <td class="muted">${fmtDate(r.last_at)}</td>
                                </tr>`).join('')}</tbody></table></div>
                                <p class="muted">برای مشاهده سرویس روی ردیف کلیک کنید — بازدیدهای پیوسته هر کاربر (۱۵ دقیقه) یکجا شمرده می‌شوند.</p></div>`;
                }
                if (activityState.tab === 'search') {
                        return `<div class="card"><div class="table-wrap"><table class="tpp-table">
                                <thead><tr><th>کاربر</th><th>عبارت جستجو</th><th>فیلترها</th><th>نتایج</th><th>تایپ‌های تجمیع‌شده</th><th>آخرین جستجو</th></tr></thead>
                                <tbody>${rows.map((r) => `<tr>
                                        <td>${esc(r.user_name || '')}</td>
                                        <td>${esc(r.query || '—')}</td>
                                        <td class="muted">${activityFiltersText(r.filters)}</td>
                                        <td class="num-cell">${esc(String(r.results || 0))}</td>
                                        <td class="num-cell" title="عبارت‌های تایپ‌شده پیوسته در یک رکورد جمع شده‌اند">${esc(String(r.searches || 1))}</td>
                                        <td class="muted">${fmtDate(r.last_at)}</td>
                                </tr>`).join('')}</tbody></table></div>
                                <p class="muted">جستجوهای در حال تایپ (مثلاً حرف‌به‌حرف در جستجوی آجاکسی) در پنجره زمانی تنظیم‌شده به یک رکورد با عبارت نهایی تبدیل می‌شوند.</p></div>`;
                }
                // تب «همه» و «تغییرات» — فید یکپارچه
                return `<div class="card"><div class="table-wrap"><table class="tpp-table">
                        <thead><tr><th></th><th>عملیات</th><th>کاربر</th><th>هدف</th><th>شرح</th><th>زمان</th></tr></thead>
                        <tbody>${rows.map((r) => {
                                const svc = r.src === 'search' ? 0 : r.target_id;
                                const actionChip = `<span class="chip ${r.action === 'delete' ? 'err' : (r.action === 'create' ? 'ok' : '')}">${ACT_SRC_ICON[r.src] || ''} ${esc(ACT_ACTION_FA[r.action] || r.action)}</span>`;
                                let desc = esc(r.title || '—');
                                if (r.src === 'search' && r.extra && typeof r.extra === 'object') {
                                        desc = `${esc(r.title || '—')} <span class="muted">(${esc(String(r.extra.n || 1))} تایپ — ${esc(String(r.extra.r || 0))} نتیجه)</span>`;
                                }
                                if (r.src === 'view' && r.extra && typeof r.extra === 'object') {
                                        desc = `${esc(r.title || '—')}`;
                                }
                                if (r.src === 'change' && r.extra && r.extra.s) {
                                        desc += ` <span class="muted">• منبع: ${esc(String(r.extra.s))}</span>`;
                                }
                                return `<tr ${svc ? `data-svc="${esc(String(svc))}" style="cursor:pointer" title="بازکردن صفحه سرویس"` : ''}>
                                        <td style="white-space:nowrap">${actionChip}</td>
                                        <td class="muted" style="white-space:nowrap">${esc(ACT_SRC_FA[r.src] || r.src)}</td>
                                        <td>${esc(r.user_name || '')}</td>
                                        <td class="num-cell">${svc ? '#' + esc(String(svc)) : '—'}</td>
                                        <td>${desc}</td>
                                        <td class="muted" style="white-space:nowrap">${fmtDate(r.ts)}</td>
                                </tr>`;
                        }).join('')}</tbody></table></div>
                        <p class="muted">فیلتر فعلی: ${esc(actRangeLabel[activityState.range] || 'همه زمان‌ها')}${activityState.userId ? ' + کاربر خاص' : ''}${activityState.action ? ' + عملیات ' + esc(ACT_ACTION_FA[activityState.action] || activityState.action) : ''} — برای مشاهده سرویس روی ردیف کلیک کنید.</p></div>`;
        }

        function activityFiltersText(filters) {
                if (!filters || typeof filters !== 'object') return '—';
                const entries = Object.entries(filters).filter(([, v]) => v !== '' && v !== null && v !== undefined);
                if (!entries.length) return '—';
                return entries.map(([k, v]) => esc(TPP.app.fieldLabel(k) + ': ' + v)).join('، ');
        }

        function activityPagination(data) {
                const total = parseInt(data.total, 10) || 0;
                const pages = Math.max(1, Math.ceil(total / 50));
                if (pages <= 1) return '<p class="muted" style="text-align:center">' + total.toLocaleString('fa-IR') + ' رکورد</p>';
                let btns = '';
                const from = Math.max(1, Math.min(activityState.page - 4, pages - 11));
                const to = Math.min(pages, from + 11);
                if (from > 1) btns += `<button class="btn btn-sm" data-page="1">۱</button><span class="muted">…</span>`;
                for (let i = from; i <= to; i++) {
                        btns += `<button class="btn btn-sm ${i === activityState.page ? 'btn-primary' : ''}" data-page="${i}">${i}</button>`;
                }
                if (to < pages) btns += `<span class="muted">…</span><button class="btn btn-sm" data-page="${pages}">${pages}</button>`;
                return `<div class="pagination">${btns}<span class="page-info">صفحه ${activityState.page} از ${pages} — ${total.toLocaleString('fa-IR')} رکورد</span></div>`;
        }

        TPP.views.fields = async function () {
                if (!can('tpp_manage_fields')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">دسترسی ندارید.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">مدیریت فیلدها به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                const fields = await TPP.api.request('GET', 'fields');

                const renderGroups = () => {
                        const groups = { address: [], service: [] };
                        fields.forEach((f) => groups[f.group_key].push(f));

                        const groupHtml = (key, title) => `
                        <div class="card">
                                <h3>${title} (${groups[key].length} فیلد)</h3>
                                <div class="fields-list" data-group="${key}">
                                        ${groups[key].map((f) => `
                                        <div class="frow" data-id="${f.id}">
                                                <span class="f-drag" title="جابجایی">⠿</span>
                                                <span class="f-label">${esc(f.label)}</span>
                                                <span class="f-slug">${esc(f.slug)}</span>
                                                <div class="f-flags">
                                                        <span class="chip">${typeFa(f.field_type)}</span>
                                                        ${f.is_required ? '<span class="chip err">الزامی</span>' : ''}
                                                        ${f.is_searchable ? '<span class="chip ok">قابل جستجو</span>' : ''}
                                                        ${f.is_sensitive ? '<span class="chip warn">حساس</span>' : ''}
                                                        ${(f.options || []).length ? '<span class="chip">' + (f.options.length) + ' گزینه</span>' : ''}
                                                </div>
                                                <div class="f-actions">
                                                        <button class="btn btn-sm" data-edit="${f.id}">ویرایش</button>
                                                        ${groups[key].indexOf(f) > 0 ? '<button class="btn btn-sm" data-up="' + f.id + '">↑</button>' : ''}
                                                        ${groups[key].indexOf(f) < groups[key].length - 1 ? '<button class="btn btn-sm" data-down="' + f.id + '">↓</button>' : ''}
                                                        <button class="btn btn-sm btn-danger" data-del="${f.id}">حذف</button>
                                                </div>
                                        </div>`).join('') || '<div class="empty-state" style="padding:20px">فیلدی نیست</div>'}
                                </div>
                                <div class="actions-row">
                                        <button class="btn btn-primary" data-add="${key}">➕ افزودن فیلد به ${title}</button>
                                </div>
                        </div>`;

                        document.getElementById('fields-area').innerHTML = groupHtml('address', 'فیلدهای آدرس') + groupHtml('service', 'فیلدهای سرویس');
                        bindFieldEvents();
                };

                document.getElementById('content').innerHTML = `
                <div class="alert info">
                        🧩 فیلدها ستون واقعی جدول‌های داده هستند. با افزودن/حذف فیلد:
                        فرم‌ها، جدول نتایج، جستجوی زنده، فایل نمونه اکسل و خروجی‌ها به‌صورت خودکار به‌روز می‌شوند.
                        با حذف فیلد، مقادیر فعلی آن بایگانی می‌شود و تاریخچه تغییرات دست‌نخورده می‌ماند.
                </div>
                <div id="fields-area"></div>`;
                renderGroups();

                function typeFa(t) {
                        return { text: 'متن', textarea: 'متن بلند', number: 'عدد', tel: 'تلفن', email: 'ایمیل', date: 'تاریخ', select: 'لیست', checkbox: 'چک‌باکس' }[t] || t;
                }

                function bindFieldEvents() {
                        document.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => fieldModal(b.getAttribute('data-add'))));
                        document.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => fieldModal(null, fields.find((f) => String(f.id) === b.getAttribute('data-edit')))));
                        document.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
                                const f = fields.find((x) => String(x.id) === b.getAttribute('data-del'));
                                if (!await confirmBox('فیلد «' + esc(f.label) + '» و ستون آن حذف شود؟<br><span class="muted">مقادیر فعلی بایگانی می‌شوند و تاریخچه حفظ می‌شود.</span>', 'حذف فیلد')) return;
                                try {
                                        await TPP.api.request('DELETE', 'fields/' + f.id, { archive: 1 });
                                        toast('فیلد حذف شد.', 'success');
                                        TPP.views.fields();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        }));
                        document.querySelectorAll('[data-up],[data-down]').forEach((b) => b.addEventListener('click', async () => {
                                const id = b.getAttribute('data-up') || b.getAttribute('data-down');
                                const dir = b.getAttribute('data-up') ? -1 : 1;
                                const group = fields.find((f) => String(f.id) === id).group_key;
                                const list = fields.filter((f) => f.group_key === group);
                                const idx = list.findIndex((f) => String(f.id) === id);
                                const swap = list[idx + dir];
                                if (!swap) return;
                                list[idx + dir] = list[idx];
                                list[idx] = swap;
                                try {
                                        await TPP.api.request('POST', 'fields/reorder', { group, order: list.map((f) => f.id) });
                                        TPP.views.fields();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        }));
                }

                function fieldModal(group, existing) {
                        const types = [
                                ['text', 'متن کوتاه'], ['textarea', 'متن بلند'], ['number', 'عدد'], ['tel', 'شماره تلفن'],
                                ['email', 'ایمیل'], ['date', 'تاریخ (متنی)'], ['select', 'لیست کشویی'], ['checkbox', 'چک‌باکس']
                        ];
                        const m = TPP.app.modal(`
                        <div class="modal-head"><h3>${existing ? 'ویرایش فیلد' : 'افزودن فیلد جدید'}</h3><button class="modal-close" data-close>×</button></div>
                        <div class="modal-body">
                                <div class="field"><label>عنوان فارسی <span class="req">*</span></label>
                                        <input type="text" id="fm-label" value="${existing ? esc(existing.label) : ''}" placeholder="مثلاً: نام شرکت مخابراتی"></div>
                                ${!existing ? `
                                <div class="field"><label>نام‌کد لاتین (اختیاری)</label>
                                        <input type="text" id="fm-slug" dir="ltr" placeholder="مثلاً: isp_name (خالی = خودکار)">
                                        <div class="hint">نام ستون در جدول داده — فقط حروف انگلیسی، عدد و _</div></div>
                                <div class="field"><label>نوع فیلد</label>
                                        <select id="fm-type">${types.map((t) => `<option value="${t[0]}">${t[1]}</option>`).join('')}</select></div>
                                <div class="field" id="fm-options-wrap" style="display:none"><label>گزینه‌های لیست (هر خط یک گزینه)</label>
                                        <textarea id="fm-options" placeholder="فعال&#10;غیرفعال&#10;معلق"></textarea></div>
                                ` : ''}
                                <div class="checkbox-row" style="margin-bottom:8px"><input type="checkbox" id="fm-required" ${existing && existing.is_required ? 'checked' : ''}> <label for="fm-required">الزامی</label></div>
                                <div class="checkbox-row" style="margin-bottom:8px"><input type="checkbox" id="fm-searchable" ${!existing || existing.is_searchable ? 'checked' : ''}> <label for="fm-searchable">در جستجوی زنده شرکت کند</label></div>
                                <div class="checkbox-row"><input type="checkbox" id="fm-sensitive" ${existing && existing.is_sensitive ? 'checked' : ''}> <label for="fm-sensitive">حساس (پیش‌فرض برای نقش‌های بدون دسترسی مخفی)</label></div>
                                ${existing && existing.field_type === 'select' ? `
                                <div class="field" style="margin-top:10px"><label>گزینه‌های لیست (هر خط یک گزینه)</label>
                                        <textarea id="fm-options">${esc((existing.options || []).join('\n'))}</textarea></div>` : ''}
                        </div>
                        <div class="modal-foot">
                                <button class="btn btn-primary" id="fm-save">ذخیره</button>
                                <button class="btn" data-close>انصراف</button>
                        </div>`);

                        const typeSel = m.el.querySelector('#fm-type');
                        if (typeSel) {
                                typeSel.addEventListener('change', () => {
                                        m.el.querySelector('#fm-options-wrap').style.display = typeSel.value === 'select' ? '' : 'none';
                                });
                        }

                        m.el.querySelector('#fm-save').addEventListener('click', async () => {
                                const label = m.el.querySelector('#fm-label').value.trim();
                                if (!label) { toast('عنوان الزامی است.', 'error'); return; }
                                const body = { label };
                                if (existing) {
                                        body.is_required = m.el.querySelector('#fm-required').checked;
                                        body.is_searchable = m.el.querySelector('#fm-searchable').checked;
                                        body.is_sensitive = m.el.querySelector('#fm-sensitive').checked;
                                        if (existing.field_type === 'select') {
                                                body.options = m.el.querySelector('#fm-options').value.split('\n').map((s) => s.trim()).filter(Boolean);
                                        }
                                } else {
                                        body.group = group;
                                        body.slug = m.el.querySelector('#fm-slug').value.trim();
                                        body.field_type = typeSel.value;
                                        body.is_required = m.el.querySelector('#fm-required').checked;
                                        body.is_searchable = m.el.querySelector('#fm-searchable').checked;
                                        body.is_sensitive = m.el.querySelector('#fm-sensitive').checked;
                                        if (body.field_type === 'select') {
                                                body.options = m.el.querySelector('#fm-options').value.split('\n').map((s) => s.trim()).filter(Boolean);
                                                if (!body.options.length) { toast('برای لیست کشویی حداقل یک گزینه لازم است.', 'error'); return; }
                                        }
                                }
                                try {
                                        if (existing) await TPP.api.request('PUT', 'fields/' + existing.id, body);
                                        else await TPP.api.request('POST', 'fields', body);
                                        m.close();
                                        toast('فیلد ذخیره شد. فرم‌ها و فایل نمونه اکسل به‌روز شدند.', 'success');
                                        TPP.views.fields();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        });
                }
        };

        /* ============================================================
         * نقش‌ها و دسترسی‌ها
         * ============================================================ */

        TPP.views.roles = async function () {
                if (!can('tpp_manage_roles')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">دسترسی ندارید.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">مدیریت نقش‌ها به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                const [roles, fields] = await Promise.all([
                        TPP.api.request('GET', 'roles'),
                        TPP.api.request('GET', 'fields')
                ]);
                const caps = {
                        tpp_view: 'دسترسی به افزونه',
                        tpp_view_services: 'مشاهده سرویس‌ها و جستجو',
                        tpp_create_services: 'ثبت سرویس جدید',
                        tpp_edit_services: 'ویرایش سرویس‌ها',
                        tpp_quick_edit: 'ویرایش سریع پیشرفت/وضعیت (⚡/🚀)',
                        tpp_delete_services: 'حذف سرویس',
                        tpp_import: 'ایمپورت گروهی اکسل',
                        tpp_export: 'خروجی اکسل/PDF و پشتیبان',
                        tpp_view_history: 'مشاهده تاریخچه تغییرات',
                        tpp_view_all_history: 'تاریخچه همه کاربران',
                        tpp_view_sensitive: 'مشاهده فیلدهای حساس (رمزها)',
                        tpp_manage_fields: 'مدیریت فیلدها',
                        tpp_manage_categories: 'دسته‌بندی پروژه‌ها و تگ‌ها (تعریف دسته/تگ سیستمی)',
                        tpp_manage_roles: 'مدیریت نقش‌ها',
                        tpp_manage_settings: 'مدیریت تنظیمات',
                        tpp_send_sms: 'ارسال پیامک از پنل پیامک',
                        tpp_review_queue: 'بازبینی سرویس‌های ارجاعی (دسته «ثبت جهت بازبینی»)',
                        tpp_review_installer: 'بازبینی اقدامات نصاب‌ها (نگه‌داری/بازگردانی تغییرات)'
                };

                document.getElementById('content').innerHTML = `
                <div class="alert info">
                        🛡️ تخصیص نقش به کاربران از صفحه <a href="../../../../wp-admin/users.php" target="_blank">کاربران وردپرس</a> انجام می‌شود (نقش‌های با پیشوند tpp_).
                        مدیر سایت همیشه دسترسی کامل دارد. «نمایش فیلد» یعنی نقش اجازه دیدن/ویرایش آن فیلد را دارد؛ فیلدهای حساس فقط با قابلیت «مشاهده فیلدهای حساس» دیده می‌شوند.
                        <br>⚡ «ویرایش سریع پیشرفت/وضعیت» (دکمه‌های ⚡ و 🚀 در صفحه سرویس‌ها) به‌طور پیش‌فرض <b>فقط برای مدیر کل سایت</b> فعال است — برای نقش‌های دیگر این تیک را روشن کنید.
                        <br>🏷 «دسته‌بندی پروژه‌ها» (تعریف دسته/تگ سیستمی برای سرویس‌ها) هم به‌طور پیش‌فرض <b>فقط برای مدیر کل سایت</b> است.
                        <br>🔁 «بازبینی» (۱.۲۰.۰): دو قابلیت — «بازبینی سرویس‌های ارجاعی» (تعیین دسته سرویس‌های ارجاع‌شده با دسته «ثبت جهت بازبینی») و «بازبینی اقدامات نصاب‌ها» (نگه‌داری/بازگردانی تغییرات کاربران غیرمدیر) — به‌طور پیش‌فرض <b>فقط برای مدیر کل سایت</b>؛ با این تیک‌ها برای اپراتور ثبت/گزارش‌گیر/… هم فعال می‌شوند.
                </div>
                <div id="roles-area">
                        ${roles.map((r) => roleCardHtml(r)).join('')}
                </div>
                <div class="card">
                        <h3>➕ ساخت نقش جدید</h3>
                        <div class="grid-3">
                                <div class="field"><label>نام نقش (فارسی) *</label><input type="text" id="nr-label" placeholder="مثلاً: پشتیبان فنی"></div>
                                <div class="field"><label>نام‌کد (لاتین، اختیاری)</label><input type="text" id="nr-slug" dir="ltr" placeholder="support_tech"></div>
                                <div class="field"><label>&nbsp;</label><button class="btn btn-primary btn-block" id="nr-add">ایجاد نقش</button></div>
                        </div>
                </div>`;

                function roleCardHtml(r) {
                        const isDefault = ['tpp_manager', 'tpp_installer', 'tpp_operator', 'tpp_reporter'].indexOf(r.slug) !== -1;
                        return `
                        <div class="role-card" data-role="${esc(r.slug)}">
                                <div class="role-head">
                                        <div><b>${esc(r.label)}</b> <span class="f-slug">${esc(r.slug)}</span></div>
                                        <div>
                                                <span class="chip">${r.users} کاربر</span>
                                                <button class="btn btn-sm" data-save="${esc(r.slug)}">💾 ذخیره تغییرات</button>
                                                ${!isDefault ? `<button class="btn btn-sm btn-danger" data-rdel="${esc(r.slug)}">حذف نقش</button>` : ''}
                                        </div>
                                </div>
                                <div class="role-body">
                                        <h4 style="margin-top:0">قابلیت‌ها</h4>
                                        <div class="caps-grid">
                                                ${Object.entries(caps).map(([c, lbl]) => `
                                                <div class="cap-item"><input type="checkbox" data-cap="${c}" id="cap-${esc(r.slug)}-${c}" ${r.caps && r.caps[c] ? 'checked' : ''}><label for="cap-${esc(r.slug)}-${c}">${lbl}</label></div>`).join('')}
                                        </div>
                                        <h4>نمایش/عدم نمایش فیلدها <span class="muted">(اگر انتخاب نشود فیلد برای این نقش مخفی است)</span></h4>
                                        <div class="field-vis-grid">
                                                ${fields.map((f) => `
                                                <div class="fv-item"><input type="checkbox" data-field="${esc(f.slug)}" id="fv-${esc(r.slug)}-${esc(f.slug)}" ${fieldVisDefault(r, f)}><label for="fv-${esc(r.slug)}-${esc(f.slug)}">${esc(f.label)}${f.is_sensitive ? ' 🔒' : ''}</label></div>`).join('')}
                                        </div>
                                </div>
                        </div>`;
                }

                function fieldVisDefault(role, f) {
                        if (role.fields && f.slug in role.fields) return role.fields[f.slug] ? 'checked' : '';
                        return 'checked'; // پیش‌فرض: همه نمایان
                }

                document.querySelectorAll('[data-save]').forEach((b) => b.addEventListener('click', async () => {
                        const slug = b.getAttribute('data-save');
                        const card = document.querySelector(`[data-role="${CSS.escape(slug)}"]`);
                        const capsOn = {};
                        card.querySelectorAll('input[data-cap]').forEach((i) => (capsOn[i.getAttribute('data-cap')] = i.checked));
                        const fieldsOn = {};
                        card.querySelectorAll('input[data-field]').forEach((i) => (fieldsOn[i.getAttribute('data-field')] = i.checked));
                        try {
                                await TPP.api.request('PUT', 'roles/' + slug, { caps: capsOn, fields: fieldsOn });
                                toast('دسترسی‌های نقش ذخیره شد.', 'success');
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                }));

                document.querySelectorAll('[data-rdel]').forEach((b) => b.addEventListener('click', async () => {
                        const slug = b.getAttribute('data-rdel');
                        if (!await confirmBox('نقش حذف شود؟ کاربران این نقش دسترسی افزونه را از دست می‌دهند.', 'حذف نقش')) return;
                        try { await TPP.api.request('DELETE', 'roles/' + slug); TPP.views.roles(); }
                        catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                }));

                document.getElementById('nr-add').addEventListener('click', async () => {
                        const label = document.getElementById('nr-label').value.trim();
                        if (!label) { toast('نام نقش الزامی است.', 'error'); return; }
                        try {
                                await TPP.api.request('POST', 'roles', { label, slug: document.getElementById('nr-slug').value.trim() });
                                toast('نقش ساخته شد. اکنون دسترسی‌ها را تنظیم کنید.', 'success');
                                TPP.views.roles();
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
        };

        /* ============================================================
         * دسته‌بندی پروژه‌ها و تگ‌های سیستمی (۱.۱۹.۰) — فقط مدیر کل (پیش‌فرض)
         * ============================================================ */

        /* ============================================================
         * دسته‌بندی پروژه‌ها — ۱.۲۱.۰ بازطراحی (درخواست کاربر):
         * فرم‌های افزودن بالای فهرست‌ها + جستجوی زنده برای ویرایش/حذف + صفحه‌بندی سرور-سمت
         * ============================================================ */

        TPP.views.categories = async function () {
                if (!can('tpp_manage_categories')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">این بخش فقط برای مدیر کل سایت (یا نقش دارای قابلیت «دسته‌بندی پروژه‌ها») در دسترس است.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">مدیریت دسته‌بندی‌ها به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                const faNum2 = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[+d]);
                const PER = 20; // تعداد در هر صفحه
                const st = { catQ: '', catPage: 1, tagQ: '', tagPage: 1 };

                const itemRow = (c, kind) => `
                        <div class="cat-item" data-id="${esc(String(c.id))}" data-kind="${esc(kind)}">
                                <b class="cat-label">${esc(c.label)}${c.is_review ? ' <span class="chip" title="دسته پیش‌فرض ارجاع به بازبینی">⏳ پیش‌فرض بازبینی</span>' : ''}</b>
                                <span class="chip">${faNum2(c.usage)} سرویس</span>
                                <button class="btn btn-sm" data-cedit="${esc(String(c.id))}" title="ویرایش نام">✏️</button>
                                <button class="btn btn-sm btn-danger" data-cdel="${esc(String(c.id))}" data-kind="${esc(kind)}" data-label="${esc(c.label)}" title="حذف">🗑</button>
                        </div>`;

                /** فهرست صفحه‌بندی‌شده یک نوع (category|tag) از سرور */
                const fetchKind = async (kind, q, page) => {
                        const params = { kind: kind, page: page, per_page: PER };
                        if (q) params.q = q;
                        return await TPP.api.request('GET', 'categories', null, params);
                };

                /** بلوک ناوبری صفحه‌بندی */
                const pagerHtml = (page, per, total) => {
                        const pages = Math.max(1, Math.ceil(total / per));
                        if (pages <= 1) return '<p class="muted" style="text-align:center">' + faNum2(total) + ' مورد</p>';
                        let btns = '';
                        const from = Math.max(1, Math.min(page - 4, pages - 9));
                        const to = Math.min(pages, from + 9);
                        if (from > 1) btns += '<button class="btn btn-sm" data-pg="1">۱</button><span class="muted">…</span>';
                        for (let i = from; i <= to; i++) btns += '<button class="btn btn-sm' + (i === page ? ' btn-primary' : '') + '" data-pg="' + i + '">' + faNum2(i) + '</button>';
                        if (to < pages) btns += '<span class="muted">…</span><button class="btn btn-sm" data-pg="' + pages + '">' + faNum2(pages) + '</button>';
                        return '<div class="pagination">' + btns + '<span class="page-info">صفحه ' + faNum2(page) + ' از ' + faNum2(pages) + ' — ' + faNum2(total) + ' مورد</span></div>';
                };

                /** رندر یک کارت (دسته‌بندی‌ها یا تگ‌ها) */
                const renderKind = async (kind) => {
                        const isTag = kind === 'tag';
                        const q = isTag ? st.tagQ : st.catQ;
                        const page = isTag ? st.tagPage : st.catPage;
                        const listEl = document.getElementById(isTag ? 'tag-list' : 'cat-list');
                        const countEl = document.getElementById(isTag ? 'tag-count' : 'cat-count');
                        const pagerEl = document.getElementById(isTag ? 'tag-pager' : 'cat-pager');
                        if (!listEl) return;
                        listEl.innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                        let res;
                        try { res = await fetchKind(kind, q, page); }
                        catch (e) { listEl.innerHTML = '<div class="alert err">خطا: ' + esc(e.message) + '</div>'; return; }
                        const items = res.items || [];
                        const total = parseInt(res.total, 10) || 0;
                        if (countEl) countEl.textContent = faNum2(total);
                        listEl.innerHTML = items.length ? items.map((c) => itemRow(c, kind)).join('')
                                : (q ? '<p class="muted">موردی با عبارت «' + esc(q) + '» یافت نشد.</p>' : (isTag ? '<p class="muted">هنوز تگی تعریف نشده است.</p>' : '<p class="muted">هنوز دسته‌بندی‌ای تعریف نشده است.</p>'));
                        if (pagerEl) {
                                pagerEl.innerHTML = pagerHtml(page, PER, total);
                                pagerEl.querySelectorAll('[data-pg]').forEach((b) => b.addEventListener('click', () => {
                                        if (isTag) st.tagPage = parseInt(b.getAttribute('data-pg'), 10) || 1;
                                        else st.catPage = parseInt(b.getAttribute('data-pg'), 10) || 1;
                                        renderKind(kind);
                                }));
                        }
                        // اتصال دکمه‌های ویرایش/حذف همین صفحه
                        listEl.querySelectorAll('[data-cedit]').forEach((b) => b.addEventListener('click', async () => {
                                const rowEl = b.closest('.cat-item');
                                const label = rowEl.querySelector('.cat-label').textContent.replace('⏳ پیش‌فرض بازبینی', '').trim();
                                const nu = await new Promise((resolve) => {
                                        const mm = modal(
                                                '<div class="modal-head"><h3>✏️ ویرایش نام</h3><button class="modal-close" data-close>×</button></div>' +
                                                '<div class="modal-body"><div class="field"><label>نام جدید:</label>' +
                                                '<input type="text" id="cat-edit-inp" class="btn" value="' + esc(label) + '"></div></div>' +
                                                '<div class="modal-foot"><button class="btn" data-close>انصراف</button><button class="btn btn-primary" id="cat-edit-ok">💾 ذخیره</button></div>',
                                                { static: true }
                                        );
                                        const inp = mm.el.querySelector('#cat-edit-inp');
                                        const done = (v) => { mm.close(); resolve(v); };
                                        mm.el.querySelector('#cat-edit-ok').addEventListener('click', () => done(inp.value));
                                        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') done(inp.value); });
                                        mm.el.querySelector('[data-close]').addEventListener('click', () => resolve(null));
                                        setTimeout(() => { try { inp.focus(); inp.select(); } catch (e2) {} }, 50);
                                });
                                if (nu === null || !nu.trim() || nu.trim() === label) return;
                                try {
                                        await TPP.api.request('PUT', 'categories/' + b.getAttribute('data-cedit'), { label: nu.trim() });
                                        toast('به‌روز شد.', 'success');
                                        renderKind(kind);
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error', 7000); }
                        }));
                        listEl.querySelectorAll('[data-cdel]').forEach((b) => b.addEventListener('click', async () => {
                                const kind2 = b.getAttribute('data-kind');
                                const label = b.getAttribute('data-label');
                                if (!await confirmBox((kind2 === 'tag' ? 'تگ' : 'دسته‌بندی') + ' «' + label + '» حذف شود؟' +
                                        (kind2 === 'tag' ? '<br><span class="muted">تگ از سرویس‌های دارای آن جدا و سپس حذف می‌شود.</span>' : '<br><span class="muted">دسته در حال استفاده حذف نمی‌شود؛ دسته پیش‌فرض بازبینی قابل حذف نیست.</span>'), 'حذف')) return;
                                try {
                                        await TPP.api.request('DELETE', 'categories/' + b.getAttribute('data-cdel'));
                                        toast('حذف شد.', 'success');
                                        renderKind(kind2);
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error', 7000); }
                        }));
                };

                document.getElementById('content').innerHTML = `
                        <div class="alert info">
                                🏷 دسته‌بندی‌ها و تگ‌های سیستمی که در فرم ثبت/ویرایش سرویس و فیلترهای جستجو به‌کار می‌روند.
                                دسته‌بندی برای هر سرویس <b>اجباری</b> است (وقتی حداقل یک دسته تعریف شود)؛ تگ‌ها اختیاری و چندتایی‌اند.
                                حذف دسته در حال استفاده ممکن نیست؛ حذف تگ آن را از سرویس‌ها جدا می‌کند. فهرست‌ها صفحه‌بندی شده‌اند و با جستجو می‌توانید مورد دلخواه را برای ویرایش/حذف پیدا کنید.
                        </div>
                        <div class="card">
                                <h3>📂 دسته‌بندی‌ها (<span id="cat-count">…</span>)</h3>
                                <div class="actions-row" style="flex-wrap:wrap">
                                        <input type="text" id="cat-new" class="btn" placeholder="نام دسته‌بندی جدید — مثال: پروژه سازمانی" style="min-width:240px">
                                        <button class="btn btn-primary" id="cat-add">➕ افزودن دسته‌بندی</button>
                                </div>
                                <div class="divider"></div>
                                <div class="actions-row" style="flex-wrap:wrap">
                                        <input type="text" id="cat-q" class="btn" placeholder="🔍 جستجوی دسته‌بندی برای ویرایش/حذف…" value="${esc(st.catQ)}" style="min-width:240px">
                                        <button class="btn btn-sm" id="cat-q-clear" title="پاک‌کردن جستجو">✕</button>
                                </div>
                                <div id="cat-list" style="margin-top:10px"></div>
                                <div id="cat-pager"></div>
                        </div>
                        <div class="card">
                                <h3>🏷 تگ‌ها (<span id="tag-count">…</span>)</h3>
                                <div class="actions-row" style="flex-wrap:wrap">
                                        <input type="text" id="tag-new" class="btn" placeholder="نام تگ جدید — مثال: اولویت بالا" style="min-width:240px">
                                        <button class="btn btn-primary" id="tag-add">➕ افزودن تگ</button>
                                </div>
                                <div class="divider"></div>
                                <div class="actions-row" style="flex-wrap:wrap">
                                        <input type="text" id="tag-q" class="btn" placeholder="🔍 جستجوی تگ برای ویرایش/حذف…" value="${esc(st.tagQ)}" style="min-width:240px">
                                        <button class="btn btn-sm" id="tag-q-clear" title="پاک‌کردن جستجو">✕</button>
                                </div>
                                <div id="tag-list" style="margin-top:10px"></div>
                                <div id="tag-pager"></div>
                        </div>`;

                const doAdd = async (kind, inputId) => {
                        const inp = document.getElementById(inputId);
                        const label = inp.value.trim();
                        if (!label) { toast('نام را وارد کنید.', 'warn'); inp.focus(); return; }
                        try {
                                await TPP.api.request('POST', 'categories', { kind: kind, label: label });
                                toast((kind === 'tag' ? 'تگ' : 'دسته‌بندی') + ' «' + label + '» ساخته شد.', 'success');
                                inp.value = '';
                                // افزودن موفق → صفحه اول همان نوع با جستجوی خالی رفرش شود تا مورد جدید دیده شود
                                if (kind === 'tag') { st.tagQ = ''; st.tagPage = 1; document.getElementById('tag-q').value = ''; }
                                else { st.catQ = ''; st.catPage = 1; document.getElementById('cat-q').value = ''; }
                                renderKind(kind);
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error', 7000); }
                };
                document.getElementById('cat-add').addEventListener('click', () => doAdd('category', 'cat-new'));
                document.getElementById('tag-add').addEventListener('click', () => doAdd('tag', 'tag-new'));
                document.getElementById('cat-new').addEventListener('keydown', (e) => { if (e.key === 'Enter') doAdd('category', 'cat-new'); });
                document.getElementById('tag-new').addEventListener('keydown', (e) => { if (e.key === 'Enter') doAdd('tag', 'tag-new'); });

                /* جستجوی زنده (debounce) + دکمه پاک‌کردن */
                const bindSearch = (kind) => {
                        const isTag = kind === 'tag';
                        const qEl = document.getElementById(isTag ? 'tag-q' : 'cat-q');
                        const clr = document.getElementById(isTag ? 'tag-q-clear' : 'cat-q-clear');
                        let t = null;
                        qEl.addEventListener('input', () => {
                                if (t) clearTimeout(t);
                                t = setTimeout(() => {
                                        if (isTag) { st.tagQ = qEl.value.trim(); st.tagPage = 1; }
                                        else { st.catQ = qEl.value.trim(); st.catPage = 1; }
                                        renderKind(kind);
                                }, 300);
                        });
                        clr.addEventListener('click', () => {
                                qEl.value = '';
                                if (isTag) { st.tagQ = ''; st.tagPage = 1; } else { st.catQ = ''; st.catPage = 1; }
                                renderKind(kind);
                        });
                };
                bindSearch('category');
                bindSearch('tag');

                await renderKind('category');
                await renderKind('tag');
        };

        /* ============================================================
         * تنظیمات
         * ============================================================ */

        /** اعمال فوری تنظیمات تازه روی state اپ + کش آفلاین (تا بدون رفرش در همه بخش‌ها اعمال شود) */
        function applySettings(s) {
                if (!s || typeof s !== 'object') return;
                const st = TPP.app.state();
                st.settings = Object.assign({}, st.settings, {
                        rows_per_page: parseInt(s.rows_per_page, 10) || st.settings.rows_per_page || 25,
                        default_match: s.default_match_key || st.settings.default_match,
                        heartbeat_min: parseInt(s.heartbeat_minutes, 10) || st.settings.heartbeat_min || 5,
                        offline_cache_size: parseInt(s.offline_cache_size, 10) || st.settings.offline_cache_size || 5000
                });
                TPP.app.restartTimers(); // بازه پینگ هم بلافاصله اعمال شود
                // کش آفلاین (بوت آفلاین) هم تازه شود
                try {
                        TPP_IDB.get('kv', 'user_state').then((rec) => {
                                if (rec && rec.v) TPP_IDB.set('kv', 'user_state', { ...rec, v: { ...rec.v, settings: st.settings } });
                        }).catch(() => {});
                } catch (e) {}
        }

        TPP.views.settings = async function () {
                if (!can('tpp_manage_settings')) {
                        document.getElementById('content').innerHTML = '<div class="alert err">دسترسی ندارید.</div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="alert warn">تنظیمات به اتصال اینترنت نیاز دارد.</div>';
                        return;
                }
                const s = await TPP.api.request('GET', 'settings');
                const schema = TPP.app.state().schema || { address: [], service: [] };
                const allFields = [].concat(schema.address || [], schema.service || []);

                /** چیپ‌های جای‌نگهدار فیلدها برای درج سریع در قالب‌ها */
                function chipsHtml(prefix) {
                        return '<div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">' +
                                allFields.map((f) => '<button type="button" class="btn btn-sm tpl-chip" data-target="' + prefix + '" data-slug="' + esc(f.slug) + '" title="' + esc(f.label) + '">{{' + esc(f.slug) + '}}</button>').join('') +
                                '<button type="button" class="btn btn-sm tpl-chip" data-target="' + prefix + '" data-slug="service_id">{{service_id}}</button>' +
                                '<button type="button" class="btn btn-sm tpl-chip" data-target="' + prefix + '" data-slug="site_name">{{site_name}}</button>' +
                                '</div>';
                }

                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>⚙️ تنظیمات افزونه</h3>
                        <div class="grid-2">
                                <div class="field">
                                        <label>مدت اعتبار نشست (جلوگیری از خروج اجباری)</label>
                                        <select id="st-session-mode" class="btn">
                                                <option value="unlimited" ${s.session_mode === 'unlimited' ? 'selected' : ''}>نامحدود — تا خروج دستی (پیشنهادی شما)</option>
                                                <option value="days" ${s.session_mode === 'days' ? 'selected' : ''}>محدود به تعداد روز مشخص (امن‌تر)</option>
                                        </select>
                                        <div class="hint">در حالت نامحدود، کوکی نشست ۱۰ ساله می‌شود و همراه با پینگ‌های دوره‌ای اپ تمدید خودکار دارد؛ توکن اپ نیز تا خروج دستی معتبر است.</div>
                                </div>
                                <div class="field">
                                        <label>مدت نشست در حالت محدود (روز)</label>
                                        <input type="number" id="st-session-days" value="${s.session_days}" min="1" max="3650">
                                </div>
                                <div class="field">
                                        <label>تعداد ردیف در هر صفحه</label>
                                        <input type="number" id="st-rows" value="${s.rows_per_page}" min="5" max="200">
                                </div>
                                <div class="field">
                                        <label>بازه پینگ حفظ نشست (دقیقه)</label>
                                        <input type="number" id="st-heartbeat" value="${s.heartbeat_minutes}" min="2" max="60">
                                </div>
                                <div class="field">
                                        <label>سقف رکوردهای کش آفلاین (تاریخچه)</label>
                                        <input type="number" id="st-offline-size" value="${s.offline_cache_size || 5000}" min="500" max="50000">
                                        <div class="hint">سرویس‌ها همیشه کامل کش می‌شوند؛ این سقف فقط تعداد رکوردهای تاریخچه‌ای است که برای حالت آفلاین روی دستگاه ذخیره می‌شود. داده‌ها در IndexedDB (حافظه دیسک مرورگر) نگهداری می‌شوند نه رم.</div>
                                </div>
                                <div class="field">
                                        <label>سقف ردیف‌های ایمپورت</label>
                                        <input type="number" id="st-imp-rows" value="${s.import_max_rows}" min="100" max="100000">
                                </div>
                                <div class="field">
                                        <label>سقف حجم فایل ایمپورت (مگابایت)</label>
                                        <input type="number" id="st-imp-size" value="${s.import_max_size_mb}" min="1" max="50">
                                </div>
                                <div class="field">
                                        <label>کلید پیش‌فرض تشخیص سرویس تکراری در ایمپورت</label>
                                        <input type="text" id="st-match" dir="ltr" value="${esc(s.default_match_key)}">
                                        <div class="hint">نام‌کد فیلد سرویس (مثل f_phone) — در هر ایمپورت قابل تغییر است</div>
                                </div>
                        </div>
                        <div class="divider"></div>
                        <h3>🕘 تاریخچه‌ها (۱.۱۰.۰ / ۱.۱۱.۰)</h3>
                        <div class="grid-2">
                                <div class="field">
                                        <label class="checkbox-row"><input type="checkbox" id="st-history-daily" ${s.history_daily ? 'checked' : ''}> تجمیع روزانه تاریخچه تغییرات</label>
                                        <div class="hint">همه تغییرات یک سرویس/آدرس توسط یک کاربر در هر روز در یک رکورد جمع می‌شود و تغییرات آدرس هم در رکورد سرویس ثبت می‌گردد — به‌جای یک رکورد جدا برای هر ذخیره. پیشنهادی: روشن.</div>
                                </div>
                                <div class="field">
                                        <label>مدت نگهداری تاریخچه تغییرات (روز — ۰ = نامحدود)</label>
                                        <input type="number" id="st-history-days" value="${s.history_days}" min="0" max="3650">
                                </div>
                                <div class="field">
                                        <label>مدت نگهداری تاریخچه بازدید سرویس‌ها (روز — ۰ = نامحدود)</label>
                                        <input type="number" id="st-view-days" value="${s.view_history_days}" min="0" max="3650">
                                        <div class="hint">هر بار کاربری صفحه یک سرویس را باز کند ثبت می‌شود (بازدیدهای پیوسته ۱۵ دقیقه‌ای یکجا شمرده می‌شوند).</div>
                                </div>
                                <div class="field">
                                        <label>مدت نگهداری تاریخچه جستجو (روز — ۰ = نامحدود)</label>
                                        <input type="number" id="st-search-days" value="${s.search_history_days}" min="0" max="3650">
                                        <div class="hint">عبارت/فیلتر جستجوی هر کاربر + تعداد نتایج (جستجوی تکراری پیوسته ۵ دقیقه‌ای یکجا شمرده می‌شود).</div>
                                </div>
                                <div class="field">
                                        <label>پنجره تجمیع جستجوهای در حال تایپ (ثانیه — ۰ = هر جستجو جداگانه)</label>
                                        <input type="number" id="st-search-dedupe" value="${s.search_dedupe_seconds !== undefined ? s.search_dedupe_seconds : 15}" min="0" max="120">
                                        <div class="hint">جستجوی آجاکسی با تایپ هر حرف اجرا می‌شود؛ عبارت‌هایی که در این پنجره (پیشنهادی ۱۰ تا ۲۰ ثانیه) و در یک جلسه تایپ وارد شوند، به‌صورت یک جستجوی واحد با عبارت نهایی ثبت می‌شوند.</div>
                                </div>
                        </div>
                        <div class="divider"></div>
                        <h3>🗄 پشتیبان خودکار ایمپورت (۱.۱۵.۰)</h3>
                        <div class="grid-2">
                                <div class="field">
                                        <label class="checkbox-row"><input type="checkbox" id="st-auto-backup" ${s.import_auto_backup !== undefined && !parseInt(s.import_auto_backup, 10) ? '' : 'checked'}> پشتیبان‌گیری خودکار قبل از هر ایمپورت گروهی</label>
                                        <div class="hint">قبل از ثبت هر ایمپورت، یک پشتیبان کامل در پوشه افزونه ذخیره می‌شود تا در صورت بروز مشکل، با یک کلیک از بخش «خروجی و پشتیبان» بازگردانی شود. اگر پشتیبان‌گیری ناموفق باشد، ایمپورت انجام نمی‌شود (حفظ زنجیره اطمینان) — با خاموش کردن این گزینه می‌توان بدون پشتیبان ایمپورت کرد.</div>
                                </div>
                                <div class="field">
                                        <label>تعداد نسخه‌های پشتیبان نگهداری‌شده روی سرور (۰ = نامحدود)</label>
                                        <input type="number" id="st-auto-keep" value="${s.auto_backup_keep !== undefined ? s.auto_backup_keep : 10}" min="0" max="100">
                                        <div class="hint">نسخه‌های قدیمی‌تر خودکار حذف می‌شوند تا پوشه پشتیبان‌ها پر نشود.</div>
                                </div>
                        </div>
                        <div class="divider"></div>
                        <h3>🔁 بازبینی اقدامات نصاب‌ها (۱.۲۱.۰)</h3>
                        <div class="grid-2">
                                <div class="field">
                                        <label>تعداد روز تایید خودکار اقدامات نصاب‌ها (۰ = غیرفعال)</label>
                                        <input type="number" id="st-review-auto-days" value="${s.review_auto_days !== undefined ? s.review_auto_days : 7}" min="0" max="3650">
                                        <div class="hint">تغییرات ثبت/ویرایش/حذف نصاب‌ها پس از این تعداد روز به‌طور خودکار تایید نهایی می‌شوند و دیگر در صف بازبینی نمی‌مانند (کرون روزانه). <b>سرویس‌هایی که دسته‌بندی‌شان «ثبت جهت بازبینی و ویرایش یا تأیید مدیریت» است مستثنا هستند</b> و تا بازبینی دستی در صف می‌مانند.</div>
                                </div>
                                <div class="field">
                                        <label>&nbsp;</label>
                                        <div class="alert info" style="margin:0">جریان قبلی (نگه‌داشتن/بازگردانی دستی از بخش «🔁 بازبینی») بدون تغییر کار می‌کند؛ این تنظیم فقط «تایید نهایی خودکار» را مدیریت می‌کند.</div>
                                </div>
                        </div>
                        <div class="actions-row">
                                <button class="btn btn-primary" id="st-save">💾 ذخیره تنظیمات</button>
                        </div>
                </div>

                <div class="card">
                        <h3>🧮 بروزآوری دیتابیس (۱.۲۱.۰)</h3>
                        <p class="muted">فیلدهای فعال تعریف‌شده در سیستم با ستون‌های واقعی جدول دیتابیس مقایسه می‌شود. هر ستونی که دیگر به هیچ فیلد تعریف‌شده‌ای تعلق ندارد (مثل فیلدهای حذف‌شده وضعیت اینترنت/تلفن و وای‌فای‌ها) پیدا می‌شود، <b>محتوای همه سرویس‌هایش به‌صورت قالب‌بندی‌شده («🔹 عنوان: مقدار») به فیلد «توضیحات متفرقه» همان سرویس اضافه می‌شود</b> و در پایان، ستون به‌طور کامل از دیتابیس حذف می‌گردد. <b>پیش از اجرا یک پشتیبان کامل خودکار روی سرور گرفته می‌شود.</b></p>
                        <div class="actions-row" style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn btn-primary" id="dbupd-run">🧮 بروزآوری دیتابیس</button>
                        </div>
                        <div id="dbupd-result" style="margin-top:10px"></div>
                </div>

                <div class="card">
                        <h3>🔢 اصلاح اعداد فارسی به انگلیسی (۱.۱۸.۰)</h3>
                        <p class="muted">همه فیلدهای سرویس/آدرس و متن گزارش‌های کار اسکن می‌شوند و هر کاراکتر عددی فارسی (۰-۹) یا عربی (٠-٩) به همتای انگلیسی (0-9) تبدیل می‌شود — برای جستجوی یکدست با کیبورد انگلیسی. <b>پیش از اجرا یک پشتیبان کامل به‌طور خودکار گرفته و روی سرور ذخیره می‌شود</b> تا در صورت نیاز با یک کلیک بازگردانی شود.</p>
                        <div class="actions-row" style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn" id="nf-dry">🔍 شمارش بدون تغییر</button>
                                <button class="btn btn-primary" id="nf-run">🔧 اصلاح اعداد فارسی به انگلیسی</button>
                        </div>
                        <div id="nf-result" style="margin-top:10px"></div>
                </div>

                <div class="card">
                        <h3>📱 پنل پیامک (SMS.ir)</h3>
                        <p class="muted">اتصال به سرویس پیامک <a href="https://sms.ir/rest-api/" target="_blank" rel="noopener">sms.ir</a> برای نمایش موجودی و ارسال مشخصات سرویس با پیامک. کلید API را از پنل کاربری sms.ir بخش «کلیدهای دسترسی» (منوی توسعه‌دهندگان) دریافت کنید.</p>
                        <div class="grid-2">
                                <div class="field">
                                        <label>کلید API (X-API-KEY)</label>
                                        <input type="text" id="st-sms-key" dir="ltr" style="width:100%" value="${esc(s.sms_api_key)}" placeholder="مثال: N2Y…کلید شما…">
                                </div>
                                <div class="field">
                                        <label>شماره خط ارسال (اختیاری)</label>
                                        <input type="text" id="st-sms-line" dir="ltr" style="width:100%" value="${esc(s.sms_line_number)}" placeholder="مثال: 30007482095733">
                                        <div class="hint">اگر خالی بگذارید، ارسال سریع (بدون شماره خط) انجام می‌شود؛ در صورت وارد کردن، ارسال گروهی با همان خط انجام می‌شود.</div>
                                </div>
                        </div>
                        <div class="actions-row">
                                <button class="btn btn-primary" id="st-sms-save">💾 ذخیره کلید</button>
                                <button class="btn btn-success" id="st-sms-check">🔄 بررسی اتصال و نمایش موجودی</button>
                        </div>
                        <div id="st-sms-status" style="margin-top:12px"></div>
                        <div class="divider"></div>
                        <h4>📊 آخرین ارسال‌های پیامک</h4>
                        <div id="st-sms-log"><p class="muted">برای مشاهده گزارش، اتصال را بررسی کنید یا صفحه را نوسازی کنید.</p></div>
                </div>

                <div class="card">
                        <h3>📝 قالب‌های پیامک</h3>
                        <p class="muted">قالب آماده پیامک‌ها را با عنوان دلخواه بسازید. در متن قالب، برای نمایش محتوای هر فیلد از جای‌نگهدار آن استفاده کنید (مثال: <code dir="ltr">{{f_owner_name}}</code> = نام و نام خانوادگی). موقع ارسال پیامک از صفحه سرویس، قالب انتخاب و متن به‌صورت خودکار ساخته می‌شود.</p>
                        <div id="st-tpl-list"></div>
                        <div class="actions-row">
                                <button class="btn btn-primary" id="st-tpl-new">➕ قالب جدید</button>
                        </div>
                        <div class="divider"></div>
                        <h4>📋 قالب کپی مشخصات (دکمه «کپی مشخصات جهت ارسال پیام برای OMC»)</h4>
                        <p class="muted">این قالب مشخص می‌کند با کلیک روی دکمه کپی در صفحه سرویس، چه متنی (و با چه چیدمانی) در کلیپ‌بورد کپی شود. قالب پیش‌فرض ۱.۹.۰ الگوی استاندارد OMC است: «بلوک … واحد… / دایری سیپ / سریال مودم / شماره مجازی / تلفن / Sip Pass / Sip IP / sbc / آدرس کامل / نام مرکز».</p>
                        <div class="field">
                                <label>متن قالب کپی</label>
                                <textarea id="st-copy-tpl" rows="12" style="width:100%;resize:vertical;direction:rtl">${esc(s.sms_copy_template || '')}</textarea>
                                ${chipsHtml('st-copy-tpl')}
                                <div class="hint">اگر خالی بگذارید، قالب پیش‌فرض (الگوی استاندارد OMC) استفاده می‌شود — برای دیدن محتوایش دکمه «بازگردانی قالب پیش‌فرض» را بزنید.</div>
                        </div>
                        <div class="actions-row">
                                <button class="btn btn-primary" id="st-copy-save">💾 ذخیره قالب کپی</button>
                                <button class="btn" id="st-copy-default">بازگردانی قالب پیش‌فرض</button>
                        </div>
                </div>

                <div class="card">
                        <h3>⚠️ منطقه خطر</h3>
                        <div class="checkbox-row" style="margin-bottom:12px">
                                <input type="checkbox" id="st-uninstall" ${s.delete_on_uninstall ? 'checked' : ''}>
                                <label for="st-uninstall">هنگام حذف افزونه، همه جدول‌ها و داده‌ها هم حذف شوند</label>
                        </div>
                        <p class="muted">اگر این گزینه خاموش باشد (پیش‌فرض)، با حذف و نصب مجدد افزونه داده‌ها حفظ می‌شوند.</p>
                </div>`;

                /* ---------- ۱.۱۸.۰ — اصلاح اعداد فارسی به انگلیسی ---------- */
                const nfBox = () => document.getElementById('nf-result');
                function nfNum(n) { return (n || 0).toLocaleString('fa-IR'); }
                function nfTable(res, converted) {
                        const cols = (res && res.columns) || [];
                        if (!cols.length) return '<div class="alert ' + (converted ? 'warn' : 'success') + '">' + (converted ? 'هیچ عدد فارسی/عربی برای اصلاح یافت نشد.' : 'هیچ عدد فارسی/عربی در فیلدها یافت نشد — همه چیز انگلیسی است. ✅') + '</div>';
                        let html = '<div class="table-wrap"><table class="tpp-table"><thead><tr><th>بخش</th><th>فیلد</th><th class="num-cell">ردیف</th><th class="num-cell">رقم تبدیل‌شده</th><th>نمونه</th></tr></thead><tbody>';
                        cols.forEach((c) => {
                                const smp = (c.samples || []).map((s) => esc(String(s.before)) + ' ← ' + esc(String(s.after))).join('<br>');
                                html += '<tr><td>' + esc(c.table_label) + '</td><td>' + esc(c.label) + '</td><td class="num-cell">' + nfNum(c.rows) + '</td><td class="num-cell">' + nfNum(c.chars) + '</td><td class="muted" style="max-width:340px">' + smp + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                        return html;
                }
                document.getElementById('nf-dry').addEventListener('click', async () => {
                        const btn = document.getElementById('nf-dry');
                        btn.disabled = true;
                        nfBox().innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                        try {
                                const res = await TPP.api.request('POST', 'numbers/fix', { dry_run: true });
                                nfBox().innerHTML = '<div class="alert warn">🔍 پیش‌نمایش — بدون هیچ تغییری:</div>' + nfTable(res, false) +
                                        (res && res.rows ? '<p class="muted">مجموع: ' + nfNum(res.rows) + ' ردیف و ' + nfNum(res.chars) + ' رقم — برای اعمال، دکمه «اصلاح اعداد فارسی به انگلیسی» را بزنید.</p>' : '');
                        } catch (e) { nfBox().innerHTML = '<div class="alert err">خطا: ' + esc(e.message) + '</div>'; }
                        btn.disabled = false;
                });
                document.getElementById('nf-run').addEventListener('click', async () => {
                        const ok = await confirmBox(
                                'همه ارقام فارسی (۰-۹) و عربی (٠-٩) داخل فیلدها به انگلیسی (0-9) تبدیل شوند؟<br>' +
                                '<span class="muted">پیش از اجرا، <b>یک پشتیبان کامل خودکار</b> گرفته و روی سرور ذخیره می‌شود و بعد از پایان، همین‌جا با یک کلیک قابل بازگردانی است.<br>تاریخچه تغییرات (لاگ حسابرسی) دست‌نخورده می‌ماند.</span>',
                                'اصلاح اعداد');
                        if (!ok) return;
                        const btn = document.getElementById('nf-run');
                        btn.disabled = true;
                        nfBox().innerHTML = '<div class="loading-block"><div class="spinner"></div></div>';
                        try {
                                const res = await TPP.api.request('POST', 'numbers/fix', { dry_run: false });
                                const bk = res && res.backup ? res.backup : null;
                                let html = '<div class="alert success">✅ اصلاح اعداد انجام شد' +
                                        (res && res.rows ? ' — ' + nfNum(res.rows) + ' ردیف و ' + nfNum(res.chars) + ' رقم تبدیل شد' : '') +
                                        (res && res.ran_at_jalali ? ' — ' + esc(res.ran_at_jalali) : '') + '</div>';
                                if (bk && bk.filename) {
                                        html += '<div class="alert warn">🛡 پشتیبان کامل پیش از اجرا گرفته شد: <code dir="ltr">' + esc(bk.filename) + '</code>' +
                                                (bk.created_at_jalali ? ' — ' + esc(bk.created_at_jalali) : '') +
                                                ' <button class="btn btn-sm" id="nf-restore">♻️ بازگردانی همین پشتیبان</button></div>';
                                }
                                html += nfTable(res, true);
                                nfBox().innerHTML = html;
                                const rb = document.getElementById('nf-restore');
                                if (rb) rb.addEventListener('click', async () => {
                                        const ok2 = await confirmBox('همه داده‌ها به وضعیتِ قبل از اصلاح اعداد بازگردانی شود؟<br><span class="muted">پشتیبان: <code dir="ltr">' + esc(bk.filename) + '</code></span>', 'بازگردانی');
                                        if (!ok2) return;
                                        rb.disabled = true;
                                        try {
                                                const rres = await TPP.api.request('POST', 'backup/stored/restore', { filename: bk.filename });
                                                renderRestoreSummary(rres, 'nf-result');
                                                toast('بازگردانی کامل شد.', 'success');
                                        } catch (e) { nfBox().innerHTML += '<div class="alert err">خطا در بازگردانی: ' + esc(e.message) + '</div>'; rb.disabled = false; }
                                });
                        } catch (e) { nfBox().innerHTML = '<div class="alert err">خطا: ' + esc(e.message) + '</div>'; }
                        btn.disabled = false;
                });

                /* ---------- ذخیره تنظیمات عمومی ---------- */
                document.getElementById('st-save').addEventListener('click', async () => {
                        const body = {
                                session_mode: document.getElementById('st-session-mode').value,
                                session_days: document.getElementById('st-session-days').value,
                                rows_per_page: document.getElementById('st-rows').value,
                                heartbeat_minutes: document.getElementById('st-heartbeat').value,
                                offline_cache_size: document.getElementById('st-offline-size').value,
                                import_max_rows: document.getElementById('st-imp-rows').value,
                                import_max_size_mb: document.getElementById('st-imp-size').value,
                                default_match_key: document.getElementById('st-match').value.trim(),
                                delete_on_uninstall: document.getElementById('st-uninstall').checked ? 1 : 0,
                                /* ۱.۱۰.۰ — تاریخچه‌ها */
                                history_daily: document.getElementById('st-history-daily').checked ? 1 : 0,
                                history_days: document.getElementById('st-history-days').value,
                                view_history_days: document.getElementById('st-view-days').value,
                                search_history_days: document.getElementById('st-search-days').value,
                                /* ۱.۱۱.۰ — پنجره تجمیع جستجوهای در حال تایپ */
                                search_dedupe_seconds: document.getElementById('st-search-dedupe').value,
                                /* ۱.۱۵.۰ — پشتیبان خودکار ایمپورت */
                                import_auto_backup: document.getElementById('st-auto-backup').checked ? 1 : 0,
                                auto_backup_keep: document.getElementById('st-auto-keep').value,
                                /* ۱.۲۱.۰ — تایید خودکار اقدامات نصاب‌ها */
                                review_auto_days: document.getElementById('st-review-auto-days').value
                        };
                        try {
                                const res = await TPP.api.request('PUT', 'settings', body);
                                toast('تنظیمات ذخیره شد و از همین لحظه اعمال می‌شود.', 'success');
                                // اعمال فوری تنظیمات جدید روی state اپ + کش آفلاین (بدون نیاز به رفرش)
                                applySettings(res && res.settings ? res.settings : body);
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });

                /* ---------- ۱.۲۱.۰ — بروزآوری دیتابیس ---------- */
                document.getElementById('dbupd-run').addEventListener('click', async () => {
                        const ok = await confirmBox(
                                'بروزآوری دیتابیس اجرا شود؟<br>' +
                                '<span class="muted">ستون‌های بدون فیلد فعال پیدا می‌شوند، محتوایشان به «توضیحات متفرقه» سرویس‌ها اضافه می‌شود و سپس ستون‌ها حذف می‌گردند.<br>پیش از اجرا <b>یک پشتیبان کامل خودکار</b> روی سرور گرفته می‌شود.</span>',
                                'بروزآوری دیتابیس');
                        if (!ok) return;
                        const btn = document.getElementById('dbupd-run');
                        const box = document.getElementById('dbupd-result');
                        btn.disabled = true;
                        box.innerHTML = '<div class="loading-block"><div class="spinner"></div><p class="muted">پشتیبان‌گیری و انتقال داده‌ها… ممکن است چند دقیقه طول بکشد.</p></div>';
                        try {
                                const res = await TPP.api.request('POST', 'tools/db-update');
                                const sv = (res && res.services_table) || [];
                                const ad = (res && res.addresses_table) || [];
                                const orphanAfter = res && res.orphan_after ? res.orphan_after : {};
                                const orphanCount = Object.keys(orphanAfter.service || {}).length + Object.keys(orphanAfter.address || {}).length;
                                let html = '';
                                if (!sv.length && !ad.length) {
                                        html = '<div class="alert success">✅ ستون اضافه‌ای یافت نشد — دیتابیس هم‌اکنون با فیلدهای تعریف‌شده هماهنگ است.</div>';
                                } else {
                                        html = '<div class="alert success">✅ بروزآوری کامل شد — ' + (res.services_updated || 0).toLocaleString('fa-IR') + ' سرویس به‌روزرسانی و ' + (res.dropped || []).length + ' ستون حذف شد.</div>';
                                        html += '<div class="table-wrap"><table class="tpp-table"><thead><tr><th>جدول</th><th>ستون حذف‌شده</th><th>عنوان فیلد</th><th class="num-cell">سرویس به‌روزشده</th></tr></thead><tbody>';
                                        sv.forEach((c) => { html += '<tr><td>سرویس‌ها</td><td dir="ltr">' + esc(c.column) + '</td><td>' + esc(c.label) + '</td><td class="num-cell">' + (c.rows || 0).toLocaleString('fa-IR') + '</td></tr>'; });
                                        ad.forEach((c) => { html += '<tr><td>آدرس‌ها</td><td dir="ltr">' + esc(c.column) + '</td><td>' + esc(c.label) + '</td><td class="num-cell">' + (c.rows || 0).toLocaleString('fa-IR') + '</td></tr>'; });
                                        html += '</tbody></table></div>';
                                }
                                const bk = res && res.backup ? res.backup : null;
                                if (bk && bk.filename) {
                                        html += '<div class="alert warn">🛡 پشتیبان کامل پیش از اجرا: <code dir="ltr">' + esc(bk.filename) + '</code>' +
                                                ' <button class="btn btn-sm" id="dbupd-restore">♻️ بازگردانی همین پشتیبان</button></div>';
                                }
                                if (orphanCount) {
                                        html += '<div class="alert warn">⚠️ هنوز ستون‌های بدون فیلد فعال باقی مانده — دکمه را دوباره اجرا کنید یا فیلدها را از بخش «فیلدها» بررسی کنید.</div>';
                                }
                                box.innerHTML = html;
                                const rb = document.getElementById('dbupd-restore');
                                if (rb) rb.addEventListener('click', async () => {
                                        if (!await confirmBox('همه داده‌ها به وضعیت قبل از بروزآوری بازگردانی شود؟ (ساختار ستون‌های حذف‌شده هم از پشتیبان بازمی‌گردد)', 'بازگردانی')) return;
                                        rb.disabled = true;
                                        try {
                                                await TPP.api.request('POST', 'backup/stored/restore', { filename: bk.filename });
                                                toast('بازگردانی کامل شد.', 'success');
                                        } catch (e) { toast('خطا در بازگردانی: ' + esc(e.message), 'error', 8000); rb.disabled = false; }
                                });
                                toast('بروزآوری دیتابیس کامل شد.', 'success', 6000);
                        } catch (e) {
                                box.innerHTML = '<div class="alert err">خطا: ' + esc(e.message) + '</div>';
                        }
                        btn.disabled = false;
                });

                /* ---------- چیپ‌های درج جای‌نگهدار ---------- */
                document.querySelectorAll('.tpl-chip').forEach((chip) => {
                        chip.addEventListener('click', () => {
                                const ta = document.getElementById(chip.getAttribute('data-target'));
                                if (!ta) return;
                                const ph = '{{' + chip.getAttribute('data-slug') + '}}';
                                const p0 = ta.selectionStart || 0, p1 = ta.selectionEnd || 0;
                                ta.value = ta.value.slice(0, p0) + ph + ta.value.slice(p1);
                                ta.focus();
                                ta.selectionStart = ta.selectionEnd = p0 + ph.length;
                        });
                });

                /* ---------- پنل پیامک ---------- */
                const smsStatus = document.getElementById('st-sms-status');
                async function checkSms(fresh) {
                        smsStatus.innerHTML = '<span class="muted">در حال بررسی اتصال…</span>';
                        try {
                                const res = await TPP.api.request('GET', 'sms/status', null, fresh ? { fresh: 1 } : null);
                                if (!res.configured) {
                                        smsStatus.innerHTML = '<div class="alert warn">کلید API تنظیم نشده است. کلید را وارد و ذخیره کنید، سپس دوباره بررسی کنید.</div>';
                                        return;
                                }
                                smsStatus.innerHTML = '<div class="alert ok">✅ اتصال برقرار است — موجودی پیامک‌های باقیمانده: <b>' + esc(res.credit) + '</b>' + (res.line ? ' | خط ارسال: <span dir="ltr">' + esc(res.line) + '</span>' : ' | ارسال سریع (بدون خط اختصاصی)') + '</div>';
                                loadSmsLog();
                        } catch (e) {
                                smsStatus.innerHTML = '<div class="alert err">❌ ' + esc(e.message) + '</div>';
                        }
                }
                document.getElementById('st-sms-save').addEventListener('click', async () => {
                        try {
                                await TPP.api.request('PUT', 'settings', {
                                        sms_api_key: document.getElementById('st-sms-key').value.trim(),
                                        sms_line_number: document.getElementById('st-sms-line').value.trim()
                                });
                                toast('تنظیمات پیامک ذخیره شد.', 'success');
                                checkSms(true);
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
                document.getElementById('st-sms-check').addEventListener('click', () => checkSms(true));

                async function loadSmsLog() {
                        const box = document.getElementById('st-sms-log');
                        try {
                                const rows = await TPP.api.request('GET', 'sms/log');
                                if (!rows.length) { box.innerHTML = '<p class="muted">هنوز پیامکی ارسال نشده است.</p>'; return; }
                                box.innerHTML = '<div style="overflow-x:auto"><table class="tpp-table"><thead><tr><th>زمان</th><th>سرویس</th><th>شماره</th><th>قالب</th><th>وضعیت</th><th>ارسال‌کننده</th></tr></thead><tbody>' +
                                        rows.map((r) => '<tr><td>' + fmtDate(r.created_at) + '</td><td>' + (r.service_id ? '<a href="#/service/' + r.service_id + '">#' + r.service_id + '</a>' : '—') + '</td><td dir="ltr">' + esc(r.mobile) + '</td><td>' + esc(r.template_title || '—') + '</td><td>' + (r.status === 'sent' ? '✅ ارسال شد' : '❌ ' + esc(r.error || 'ناموفق')) + '</td><td>' + esc(r.user_name || '—') + '</td></tr>').join('') +
                                        '</tbody></table></div>';
                        } catch (e) {
                                box.innerHTML = '<p class="muted">دریافت گزارش ممکن نشد: ' + esc(e.message) + '</p>';
                        }
                }

                /* ---------- قالب‌های پیامک ---------- */
                async function loadTemplates() {
                        const box = document.getElementById('st-tpl-list');
                        let rows = [];
                        try { rows = await TPP.api.request('GET', 'sms/templates'); } catch (e) {}
                        if (!rows.length) {
                                box.innerHTML = '<p class="muted">هنوز قالبی ساخته نشده است.</p>';
                                return;
                        }
                        box.innerHTML = '<div style="overflow-x:auto"><table class="tpp-table"><thead><tr><th>عنوان</th><th>متن قالب</th><th>عملیات</th></tr></thead><tbody>' +
                                rows.map((t) => '<tr><td style="white-space:nowrap"><b>' + esc(t.title) + '</b></td><td class="muted" style="max-width:420px;white-space:pre-wrap">' + esc(String(t.body).slice(0, 220)) + (String(t.body).length > 220 ? '…' : '') + '</td><td style="white-space:nowrap"><button class="btn btn-sm" data-tpl-edit="' + t.id + '">ویرایش</button> <button class="btn btn-sm btn-danger" data-tpl-del="' + t.id + '">حذف</button></td></tr>').join('') +
                                '</tbody></table></div>';
                        box.querySelectorAll('[data-tpl-edit]').forEach((b) => b.addEventListener('click', () => {
                                const t = rows.find((x) => String(x.id) === b.getAttribute('data-tpl-edit'));
                                if (t) templateModal(t);
                        }));
                        box.querySelectorAll('[data-tpl-del]').forEach((b) => b.addEventListener('click', async () => {
                                const t = rows.find((x) => String(x.id) === b.getAttribute('data-tpl-del'));
                                if (!t) return;
                                if (!await confirmBox('قالب «' + esc(t.title) + '» حذف شود؟', 'حذف قالب')) return;
                                try {
                                        await TPP.api.request('DELETE', 'sms/templates/' + t.id);
                                        toast('قالب حذف شد.', 'success');
                                        loadTemplates();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        }));
                }

                function templateModal(existing) {
                        const m = modal(
                                '<div class="modal-head"><h3>' + (existing ? 'ویرایش قالب پیامک' : 'قالب پیامک جدید') + '</h3><button class="modal-close" data-close>×</button></div>' +
                                '<div class="modal-body">' +
                                '<div class="field"><label>عنوان قالب (مثال: ارسال مشخصات به کاربر)</label><input type="text" id="tpl-title" style="width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:8px" value="' + esc(existing ? existing.title : '') + '"></div>' +
                                '<div class="field"><label>متن قالب — از دکمه‌های زیر برای درج فیلدها استفاده کنید</label>' +
                                '<textarea id="tpl-body" rows="9" style="width:100%;resize:vertical;direction:rtl">' + esc(existing ? existing.body : '') + '</textarea>' +
                                chipsHtml('tpl-body') +
                                '<div class="hint">مثال: «{{f_owner_name}} عزیز، وضعیت اینترنت شما: {{f_internet_status}}»</div></div>' +
                                '</div>' +
                                '<div class="modal-foot"><button class="btn btn-primary" id="tpl-save">💾 ذخیره</button><button class="btn" data-close>انصراف</button></div>',
                                { wide: true }
                        );
                        m.el.querySelectorAll('.tpl-chip').forEach((chip) => {
                                chip.addEventListener('click', () => {
                                        const ta = m.el.querySelector('#tpl-body');
                                        const ph = '{{' + chip.getAttribute('data-slug') + '}}';
                                        const p0 = ta.selectionStart || 0, p1 = ta.selectionEnd || 0;
                                        ta.value = ta.value.slice(0, p0) + ph + ta.value.slice(p1);
                                        ta.focus();
                                        ta.selectionStart = ta.selectionEnd = p0 + ph.length;
                                });
                        });
                        m.el.querySelector('#tpl-save').addEventListener('click', async () => {
                                const title = m.el.querySelector('#tpl-title').value.trim();
                                const body = m.el.querySelector('#tpl-body').value;
                                if (!title) { toast('عنوان قالب الزامی است.', 'warn'); return; }
                                if (!body.trim()) { toast('متن قالب الزامی است.', 'warn'); return; }
                                try {
                                        if (existing) await TPP.api.request('PUT', 'sms/templates/' + existing.id, { title, body });
                                        else await TPP.api.request('POST', 'sms/templates', { title, body });
                                        toast('قالب ذخیره شد.', 'success');
                                        m.close();
                                        loadTemplates();
                                } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                        });
                }

                document.getElementById('st-tpl-new').addEventListener('click', () => templateModal(null));

                /* ---------- قالب کپی OMC ---------- */
                document.getElementById('st-copy-save').addEventListener('click', async () => {
                        try {
                                await TPP.api.request('PUT', 'settings', { sms_copy_template: document.getElementById('st-copy-tpl').value });
                                toast('قالب کپی ذخیره شد.', 'success');
                                // به‌روزرسانی کش محلی برای استفاده آفلاین
                                const st = TPP.app.state();
                                if (st.sms) st.sms.copy_template = document.getElementById('st-copy-tpl').value;
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });
                document.getElementById('st-copy-default').addEventListener('click', () => {
                        document.getElementById('st-copy-tpl').value = s.sms_copy_default || '';
                        toast('قالب پیش‌فرض بازگردانی شد — برای اعمال، ذخیره کنید.', 'info');
                });

                loadTemplates();
                if (s.sms_api_key) checkSms(false); else loadSmsLog();
        };

        /* ============================================================
         * بررسی موارد تکراری (۱.۹.۰) — فقط مدیر کل سایت
         * ============================================================ */

        const dup = { fields: null, showDismissed: false, statusFilter: 'all', data: null };

        TPP.views.duplicates = async function () {
                const st = TPP.app.state();
                if (!st.isWPAdmin) {
                        document.getElementById('content').innerHTML = '<div class="card"><div class="alert err">این بخش فقط برای <b>مدیر کل سایت</b> (مدیر وردپرس با دسترسی کامل) در دسترس است.</div></div>';
                        return;
                }
                if (!TPP.offline.state().online) {
                        document.getElementById('content').innerHTML = '<div class="card"><div class="alert warn">📴 بررسی موارد تکراری به اتصال اینترنت نیاز دارد. پس از اتصال دوباره وارد این صفحه شوید.</div></div>';
                        return;
                }
                document.getElementById('content').innerHTML = '<div class="loading-block"><div class="spinner"></div><p>در حال بررسی موارد تکراری…</p></div>';
                await loadDuplicates();
        };

        async function loadDuplicates() {
                const params = {};
                if (dup.fields) params.fields = dup.fields;
                if (dup.showDismissed) params.include_dismissed = 1;
                let data;
                try {
                        data = await TPP.api.request('GET', 'duplicates', null, params);
                } catch (e) {
                        document.getElementById('content').innerHTML = '<div class="card"><div class="alert err">خطا در بررسی موارد تکراری: ' + esc(e.message) + '</div></div>';
                        return;
                }
                dup.data = data;
                renderDuplicates(data);
        }

        function renderDuplicates(data) {
                const fieldLabel = TPP.app.fieldLabel;
                const allGroups = data.groups || [];
                const selected = data.selected || [];
                const sum = data.summary || {};
                const statusFilter = dup.statusFilter || 'all';
                const groups = statusFilter === 'all' ? allGroups : allGroups.filter((g) => (g.status || 'clean') === statusFilter);
                const fa = (n) => (n || 0).toLocaleString('fa-IR');

                const cleanG = fa(sum.clean_groups), confG = fa(sum.conflict_groups);
                const cleanS = fa(sum.clean_services), allS = fa(sum.services_involved);
                const savings = fa(sum.merge_savings);

                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>👥 بررسی موارد تکراری و تناقض</h3>
                        <p class="muted" style="line-height:2.1">
                                سرویس‌هایی که در فیلدهای شناسایی مقدار <b>یکسان</b> دارند گروه می‌شوند و علاوه بر فیلدهای مشابه،
                                <b>دلایل تناقض</b> (فیلدهایی که مقدار متفاوت معنادار دارند) و فیلدهای <b>مکمل</b> (فقط در برخی اعضا پر) هم نمایش داده می‌شود.
                                گروه‌های <b>بدون تناقض</b> بی‌خطر ادغام می‌شوند (هیچ داده‌ای از دست نمی‌رود)؛ برای گروه‌های متناقض می‌توانید مقدار برنده هر فیلد را انتخاب کنید.
                                مقادیر خالی/صفر/AUTO در تشخیص بی‌ارزش‌اند.
                        </p>
                        <div class="dup-summary" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
                                <span class="chip" style="font-weight:700">👥 ${fa(sum.total_groups)} گروه مشابه (${allS} سرویس)</span>
                                <span class="chip ok">✅ ${cleanG} گروه بدون تناقض (${cleanS} سرویس)</span>
                                <span class="chip warn">⚠️ ${confG} گروه دارای تناقض</span>
                                ${sum.clean_groups ? `<span class="chip">📥 صرفه‌جویی پس از ادغام موارد بدون تناقض: ${savings} رکورد</span>` : ''}
                        </div>
                        ${sum.clean_groups ? `
                        <div class="alert success" style="margin-top:10px">
                                <b>${cleanS} سرویس</b> در <b>${cleanG} گروه</b> هیچ تناقضی با هم ندارند (فیلدهایشان یکسان یا مکمل یکدیگر است) — ادغام آن‌ها هیچ داده‌ای از بین نمی‌برد.
                                <div style="margin-top:8px">
                                        <button class="btn btn-primary" id="dup-merge-safe">🔗 ادغام همه گروه‌های بدون تناقض (${cleanG} گروه / ${savings} رکورد اضافی)</button>
                                </div>
                        </div>` : ''}
                        <div class="filters-title" style="margin-top:10px"><span>فیلدهای شناسایی (حداقل یکی):</span></div>
                        <div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px">
                                ${(data.fields || []).map((f) => `<button type="button" class="btn btn-sm dup-field-tgl${selected.indexOf(f.slug) !== -1 ? ' btn-primary' : ''}" data-slug="${esc(f.slug)}" title="فعال/غیرفعال کردن این فیلد در تشخیص تکراری">${esc(f.label)}</button>`).join('')}
                        </div>
                        <div class="actions-row" style="margin-top:12px;flex-wrap:wrap">
                                <button class="btn btn-sm ${statusFilter === 'all' ? 'btn-primary' : ''}" data-dup-filter="all">همه (${fa(allGroups.length)})</button>
                                <button class="btn btn-sm ${statusFilter === 'clean' ? 'btn-primary' : ''}" data-dup-filter="clean">✅ فقط بدون تناقض</button>
                                <button class="btn btn-sm ${statusFilter === 'conflict' ? 'btn-primary' : ''}" data-dup-filter="conflict">⚠️ فقط دارای تناقض</button>
                                <label class="checkbox-row"><input type="checkbox" id="dup-show-dismissed" ${dup.showDismissed ? 'checked' : ''}> نمایش موارد بررسی‌شده (${data.dismissed_count || 0})</label>
                                <button class="btn" id="dup-refresh">🔄 بررسی مجدد</button>
                                ${data.dismissed_count ? '<button class="btn" id="dup-restore-all">↩ بازگردانی همه علامت‌خورده‌ها</button>' : ''}
                        </div>
                </div>
                <div id="dup-groups">
                        ${groups.length ? groups.map((g) => dupGroupHtml(g, fieldLabel)).join('') : `
                        <div class="card"><div class="empty-state"><div class="big">✅</div><p>هیچ سرویس ${statusFilter === 'all' ? 'تکراری' : (statusFilter === 'clean' ? 'بدون تناقض' : 'متناقض')} پیدا نشد.${dup.showDismissed ? '' : '<br><span class="muted">موارد علامت‌خورده «باقی می‌ماند» نمایش داده نمی‌شوند.</span>'}</p></div></div>`}
                </div>`;

                // چیپ‌های انتخاب فیلد شناسایی
                document.querySelectorAll('.dup-field-tgl').forEach((b) => b.addEventListener('click', () => {
                        const slug = b.getAttribute('data-slug');
                        const cur = dup.fields ? dup.fields.split(',').map((s) => s.trim()).filter(Boolean) : (dup.data ? dup.data.selected.slice() : []);
                        const idx = cur.indexOf(slug);
                        if (idx !== -1) cur.splice(idx, 1); else cur.push(slug);
                        dup.fields = cur.join(',');
                        loadDuplicates();
                }));
                document.querySelectorAll('[data-dup-filter]').forEach((b) => b.addEventListener('click', () => {
                        dup.statusFilter = b.getAttribute('data-dup-filter');
                        renderDuplicates(dup.data);
                }));
                const ms = document.getElementById('dup-merge-safe');
                if (ms) ms.addEventListener('click', async () => {
                        const ok = await confirmBox(
                                '<b>' + fa(sum.clean_groups) + ' گروه بدون تناقض</b> (شامل <b>' + fa(sum.clean_services) + ' سرویس</b>) در سرویسِ غنی‌ترین عضو هر گروه ادغام شوند؟<br>' +
                                '<span class="muted">در هر گروه، سرویس اصلیِ پیشنهادی (غنی‌ترین رکورد) باقی می‌ماند، فیلدهای خالی از بقیه پر می‌شود، تاریخچه‌ها منتقل و ' + fa(sum.merge_savings) + ' رکورد اضافی حذف می‌شود. هیچ داده‌ای از بین نمی‌رود.</span>', 'ادغام گروهی بی‌خطر');
                        if (!ok) return;
                        ms.disabled = true;
                        try {
                                const res = await TPP.api.request('POST', 'duplicates/merge_safe', {});
                                const bits = [fa(res.merged_groups) + ' گروه ادغام شد'];
                                bits.push(fa(res.merged_services) + ' سرویس حذف اضافی');
                                if (res.filled_fields) bits.push(fa(res.filled_fields) + ' فیلد خالی پر شد');
                                if (res.errors && res.errors.length) bits.push('⚠️ ' + fa(res.errors.length) + ' خطا');
                                toast(bits.join(' — '), res.errors && res.errors.length ? 'warn' : 'success');
                                loadDuplicates();
                        } catch (e) { ms.disabled = false; toast('خطا در ادغام گروهی: ' + esc(e.message), 'error'); }
                });
                document.getElementById('dup-refresh').addEventListener('click', loadDuplicates);
                const sd = document.getElementById('dup-show-dismissed');
                sd.addEventListener('change', (e) => { dup.showDismissed = e.target.checked; loadDuplicates(); });
                const ra = document.getElementById('dup-restore-all');
                if (ra) ra.addEventListener('click', async () => {
                        if (!await confirmBox('همه گروه‌های علامت‌خورده «باقی می‌ماند به حالت فعلی» به فهرست بررسی بازگردانده شوند؟', 'بازگردانی همه')) return;
                        try {
                                await TPP.api.request('POST', 'duplicates/restore', { key: 'all' });
                                toast('همه موارد بازگردانی شدند.', 'success');
                                loadDuplicates();
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                });

                bindDupGroupEvents();
        }

        function dupConflictHtml(g) {
                const conflicts = g.conflicts || [];
                if (!conflicts.length) return '';
                const rows = conflicts.map((c) => {
                        const cells = (g.ids || []).map((id) => {
                                const v = c.values && c.values[String(id)] !== undefined ? c.values[String(id)] : (c.values && c.values[id] !== undefined ? c.values[id] : '');
                                const has = v !== '' && v !== null && v !== undefined && String(v).toUpperCase() !== 'AUTO' && String(v) !== '0' && String(v) !== '-';
                                return `<span class="dup-cval${has ? '' : ' empty'}">${has ? '<b>#' + esc(String(id)) + '</b>: ' + esc(String(v).slice(0, 24)) : '<span class="muted">#' + esc(String(id)) + ': —</span>'}</span>`;
                        }).join(' <span class="dup-cneq">≠</span> ');
                        // انتخاب مقدار برنده (pick) — کشویی اعضای دارای مقدار
                        const opts = (g.ids || []).filter((id) => {
                                const v = c.values && c.values[String(id)] !== undefined ? c.values[String(id)] : '';
                                return v !== '' && v !== null && v !== undefined && String(v).toUpperCase() !== 'AUTO' && String(v) !== '0' && String(v) !== '-';
                        });
                        const sel = `<select class="btn btn-sm dup-pick" data-slug="${esc(c.slug)}" title="مقدار برنده این فیلد هنگام ادغام">` +
                                opts.map((id) => `<option value="${esc(String(id))}" ${id === (g.recommended_primary || g.ids[0]) ? '' : ''}>از #${esc(String(id))}</option>`).join('') +
                                '</select>';
                        return `<div class="dup-conflict-row">
                                <span class="chip warn" style="min-width:120px">✖ ${esc(c.label)}</span>
                                <span class="dup-cvals">${cells}</span>
                                ${sel}
                        </div>`;
                }).join('');
                return `
                        <div class="dup-conflicts">
                                <div class="filters-title"><span>⚠️ دلایل تناقض (${conflicts.length} فیلد) — مقدار برنده هر فیلد را انتخاب کنید:</span></div>
                                ${rows}
                        </div>`;
        }

        function dupComplementHtml(g) {
                const comp = (g.complementary || []).filter((c) => (c.filled_in || []).length);
                if (!comp.length) return '';
                const chips = comp.map((c) => `<span class="chip ok" title="این فیلد فقط در برخی اعضا پر است — هنگام ادغام در سرویس اصلی پر می‌شود">＋ ${esc(c.label)} <span class="muted">(${(c.filled_in || []).map((i) => '#' + esc(String(i))).join('، ')})</span></span>`).join(' ');
                return `<div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px"><span class="muted" style="align-self:center">فیلدهای مکمل:</span>${chips}</div>`;
        }

        function dupGroupHtml(g, fieldLabel) {
                const showSlugs = Object.keys((g.services && g.services[0] && g.services[0].fields) || {});
                const sharedBadges = (g.shared || []).map((s) =>
                        `<span class="chip ok" title="${esc(s.label)}: ${esc(s.value)}">✔ ${esc(s.label)}: ${esc(String(s.value).slice(0, 30))}${String(s.value).length > 30 ? '…' : ''}</span>`
                ).join(' ');
                const isClean = (g.status || 'clean') === 'clean';
                const nConf = (g.conflicts || []).length;
                const rec = g.recommended_primary || (g.ids || [])[0];
                const rows = (g.services || []).map((svc) => `
                        <tr>
                                <td style="white-space:nowrap"><b>#${svc.id}</b>${svc.id === rec ? ' <span class="chip ok" style="font-size:10px;padding:1px 6px">پیشنهادی</span>' : ''}</td>
                                <td style="text-align:center"><input type="radio" name="dup-primary-${esc(g.key)}" value="${svc.id}" title="این سرویس اصلی بماند" ${svc.id === rec ? 'checked' : ''}></td>
                                ${showSlugs.map((slug) => `<td>${esc(svc.fields[slug] || '—')}</td>`).join('')}
                                <td style="white-space:nowrap" class="muted">${fmtDate(svc.created_at)}</td>
                                <td style="white-space:nowrap" class="muted">${esc(svc.created_by_name || '—')}</td>
                                <td style="white-space:nowrap">
                                        <button class="btn btn-sm" data-dup-open="${svc.id}" title="مشاهده سرویس">👁</button>
                                        <button class="btn btn-sm btn-danger" data-dup-del="${svc.id}" title="حذف این سرویس">🗑</button>
                                </td>
                        </tr>`).join('');
                return `
                <div class="card dup-group${g.dismissed ? ' dismissed' : ''}${isClean ? '' : ' has-conflict'}" data-group="${esc(g.key)}">
                        <div class="dup-head">
                                <b>👥 ${g.ids.length} سرویس با مشخصات مشابه</b>
                                ${g.dismissed ? '<span class="chip warn">بررسی‌شده — باقی می‌ماند به حالت فعلی</span>' : (isClean
                                        ? '<span class="chip ok">✅ بدون تناقض — ادغام بی‌خطر</span>'
                                        : `<span class="chip warn">⚠️ دارای تناقض در ${nConf} فیلد</span>`)}
                                <div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">${sharedBadges || '<span class="muted">فیلد مشترک معناداری پیدا نشد</span>'}</div>
                                ${dupComplementHtml(g)}
                        </div>
                        ${isClean ? '' : dupConflictHtml(g)}
                        <div style="overflow-x:auto;margin-top:10px">
                                <table class="tpp-table">
                                        <thead><tr><th>سرویس</th><th title="سرویسی که بقیه در آن ادغام می‌شوند">اصلی</th>${showSlugs.map((slug) => '<th>' + esc(fieldLabel(slug)) + '</th>').join('')}<th>ثبت</th><th>ثبت‌کننده</th><th>عملیات</th></tr></thead>
                                        <tbody>${rows}</tbody>
                                </table>
                        </div>
                        <div class="actions-row">
                                ${g.dismissed
                                        ? `<button class="btn" data-dup-restore="${esc(g.key)}">↩ بازگردانی به فهرست بررسی</button>`
                                        : `<button class="btn btn-primary" data-dup-merge="${esc(g.key)}">🔗 ادغام در سرویس انتخاب‌شده${isClean ? '' : ' (با مقادیر برنده بالا)'}</button>
                                           <button class="btn" data-dup-keep="${esc(g.key)}">✋ باقی می‌ماند به حالت فعلی</button>`}
                        </div>
                </div>`;
        }

        function bindDupGroupEvents() {
                document.querySelectorAll('[data-dup-open]').forEach((b) => b.addEventListener('click', () => go('service/' + b.getAttribute('data-dup-open'))));
                // حذف تکی یکی از سرویس‌های گروه
                document.querySelectorAll('[data-dup-del]').forEach((b) => b.addEventListener('click', async () => {
                        const id = parseInt(b.getAttribute('data-dup-del'), 10);
                        if (!id) return;
                        if (!await confirmBox('سرویس <b>#' + id + '</b> حذف شود؟<br><span class="muted">تاریخچه این سرویس هم به‌طور کامل حذف می‌شود و این عمل قابل بازگشت نیست.</span>', 'حذف قطعی')) return;
                        b.disabled = true;
                        try {
                                await TPP.api.request('DELETE', 'services/' + id);
                                toast('سرویس #' + id + ' حذف شد.', 'success');
                                loadDuplicates();
                        } catch (e) { b.disabled = false; toast('خطا در حذف: ' + esc(e.message), 'error'); }
                }));
                // ادغام گروه در سرویس اصلی انتخاب‌شده
                document.querySelectorAll('[data-dup-merge]').forEach((b) => b.addEventListener('click', async () => {
                        const key = b.getAttribute('data-dup-merge');
                        const card = document.querySelector('.dup-group[data-group="' + key + '"]');
                        const g = (dup.data && dup.data.groups ? dup.data.groups : []).find((x) => x.key === key);
                        if (!g || !card) return;
                        const radio = card.querySelector('input[name="dup-primary-' + key + '"]:checked');
                        const primaryId = radio ? parseInt(radio.value, 10) : (g.recommended_primary || g.ids[0]);
                        const others = g.ids.filter((i) => i !== primaryId);
                        if (!others.length) { toast('سرویس دیگری برای ادغام نیست.', 'warn'); return; }
                        // مقدار برنده فیلدهای متناقض (pick) از کشویی‌های بالای کارت
                        const pick = {};
                        card.querySelectorAll('.dup-pick').forEach((sel) => {
                                const v = parseInt(sel.value, 10);
                                if (v) pick[sel.getAttribute('data-slug')] = v;
                        });
                        const nConf = (g.conflicts || []).length;
                        const ok = await confirmBox(
                                '<b>' + others.length + ' سرویس</b> در سرویس <b>#' + primaryId + '</b> ادغام شود؟<br>' +
                                (nConf ? '<span class="muted">' + nConf + ' فیلد متناقض با مقادیر انتخاب‌شده حل می‌شود؛ فیلدهای خالی از بقیه پر می‌شود؛ </span>' : '<span class="muted">') +
                                'تاریخچه همه سرویس‌ها به سرویس اصلی منتقل می‌شود (حذف نمی‌شود) و خود سرویس‌های دیگر (' +
                                others.map((i) => '#' + i).join('، ') + ') حذف می‌شوند.</span>', 'ادغام');
                        if (!ok) return;
                        b.disabled = true;
                        try {
                                const res = await TPP.api.request('POST', 'duplicates/merge', { primary_id: primaryId, ids: g.ids, pick });
                                const pickedN = (res && res.picked_fields && res.picked_fields.length) ? res.picked_fields.length : 0;
                                toast('ادغام انجام شد — ' + ((res && res.filled_fields && res.filled_fields.length) ? res.filled_fields.length + ' فیلد خالی پر شد' : 'بدون تغییر مقدار') + (pickedN ? ' + ' + pickedN + ' تعارض حل‌شده' : '') + '.', 'success');
                                loadDuplicates();
                        } catch (e) { b.disabled = false; toast('خطا در ادغام: ' + esc(e.message), 'error'); }
                }));
                // باقی می‌ماند به حالت فعلی (علامت‌گذاری)
                document.querySelectorAll('[data-dup-keep]').forEach((b) => b.addEventListener('click', async () => {
                        const key = b.getAttribute('data-dup-keep');
                        const g = (dup.data && dup.data.groups ? dup.data.groups : []).find((x) => x.key === key);
                        if (!g) return;
                        try {
                                await TPP.api.request('POST', 'duplicates/dismiss', { ids: g.ids });
                                toast('گروه علامت خورد — باقی می‌ماند به حالت فعلی.', 'success');
                                loadDuplicates();
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                }));
                // بازگردانی گروه علامت‌خورده
                document.querySelectorAll('[data-dup-restore]').forEach((b) => b.addEventListener('click', async () => {
                        try {
                                await TPP.api.request('POST', 'duplicates/restore', { key: b.getAttribute('data-dup-restore') });
                                toast('گروه به فهرست بررسی بازگشت.', 'success');
                                loadDuplicates();
                        } catch (e) { toast('خطا: ' + esc(e.message), 'error'); }
                }));
        }

        /* ============================================================
         * صف همگان‌سازی (outbox)
         * ============================================================ */

        TPP.views.outbox = async function () {
                const ops = await TPP_IDB.all('outbox');
                const last = await TPP.offline.lastSync();
                TPP.app.setTopbar(`<button class="btn btn-primary btn-sm" id="ob-flush">🔄 همگام‌سازی الان</button>`);

                const kindFa = (k) => ({ 'service.create': 'ثبت سرویس', 'service.update': 'ویرایش سرویس', 'service.delete': 'حذف سرویس' }[k] || k);

                document.getElementById('content').innerHTML = `
                <div class="card">
                        <h3>☁️ وضعیت همگان‌سازی</h3>
                        <table class="kv-table">
                                <tr><td>وضعیت شبکه</td><td>${TPP.offline.state().online ? '✅ آنلاین' : '📴 آفلاین'}</td></tr>
                                <tr><td>عملیات در انتظار</td><td>${ops.length} مورد</td></tr>
                                <tr><td>آخرین همگان‌سازی</td><td>${last ? fmtDate(last) : '—'}</td></tr>
                        </table>
                        <p class="muted" style="line-height:2.1">
                                عملیات‌های این صفحه به‌صورت خودکار با اتصال اینترنت ارسال می‌شوند.
                                هر عملیات با شناسه یکتا ارسال می‌شود و حتی در صورت قطع‌شدن شبکه وسط کار، دوبار ثبت نمی‌شود.
                        </p>
                </div>
                <div class="card">
                        <h3>عملیات‌های صف (${ops.length})</h3>
                        ${ops.length ? ops.map((op) => `
                        <div class="sync-row">
                                <span class="chip">${kindFa(op.kind)}</span>
                                <span>${op.payload && op.payload.id ? 'سرویس #' + esc(op.payload.id) : (op.payload.service && (op.payload.service.f_phone || op.payload.service.f_owner_name)) ? esc(op.payload.service.f_owner_name || '') + ' — ' + esc(op.payload.service.f_phone || '') : 'جدید'}</span>
                                <span class="muted">${fmtDate(op.created_at)}</span>
                                <button class="btn btn-sm btn-danger" data-discard="${esc(op.op_id)}" style="margin-right:auto">دورریز</button>
                        </div>`).join('') : '<div class="empty-state" style="padding:24px"><div class="big">✅</div><p>صف خالی است — همه چیز همگام است.</p></div>'}
                </div>`;

                const fl = document.getElementById('ob-flush');
                if (fl) fl.addEventListener('click', async () => {
                        fl.disabled = true;
                        await TPP.offline.flush(true);
                        await TPP.offline.cacheAll().catch(() => {});
                        toast('همگان‌سازی انجام شد.', 'success');
                        TPP.views.outbox();
                });

                document.querySelectorAll('[data-discard]').forEach((b) => b.addEventListener('click', async () => {
                        const opId = b.getAttribute('data-discard');
                        if (!await confirmBox('این عملیات از صف حذف شود؟ (اعمال نمی‌شود)', 'دورریز')) return;
                        await TPP_IDB.del('outbox', opId);
                        TPP.offline.refreshPendingBadge();
                        TPP.views.outbox();
                }));
        };

})();
