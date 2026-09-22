/**
 * idb.js — پوشش سبک Promise برای IndexedDB (بدون وابستگی)
 */
'use strict';

const TPP_IDB = (function () {

        const DB_NAME = 'tpp_services_db';
        const DB_VERSION = 1;
        let dbPromise = null;

        function open() {
                if (dbPromise) return dbPromise;
                dbPromise = new Promise((resolve, reject) => {
                        const req = indexedDB.open(DB_NAME, DB_VERSION);
                        req.onupgradeneeded = (e) => {
                                const db = req.result;
                                if (!db.objectStoreNames.contains('services')) {
                                        const s = db.createObjectStore('services', { keyPath: 'id' });
                                        s.createIndex('address_id', 'address_id', { unique: false });
                                }
                                if (!db.objectStoreNames.contains('history')) {
                                        db.createObjectStore('history', { keyPath: 'key' });
                                }
                                if (!db.objectStoreNames.contains('outbox')) {
                                        db.createObjectStore('outbox', { keyPath: 'op_id' });
                                }
                                if (!db.objectStoreNames.contains('kv')) {
                                        db.createObjectStore('kv', { keyPath: 'k' });
                                }
                        };
                        req.onsuccess = () => resolve(req.result);
                        req.onerror = () => reject(req.error);
                });
                return dbPromise;
        }

        function tx(store, mode, fn) {
                return open().then((db) => new Promise((resolve, reject) => {
                        const t = db.transaction(store, mode);
                        const os = t.objectStore(store);
                        let result;
                        try { result = fn(os); } catch (err) { reject(err); return; }
                        t.oncomplete = () => resolve(result && result.__value !== undefined ? result.__value : result);
                        t.onerror = () => reject(t.error);
                        t.onabort = () => reject(t.error);
                }));
        }

        function reqToPromise(request) {
                return new Promise((resolve, reject) => {
                        request.onsuccess = () => resolve(request.result);
                        request.onerror = () => reject(request.error);
                });
        }

        const api = {
                /** دریافت مقدار kv */
                get: (store, key) => open().then((db) => reqToPromise(db.transaction(store).objectStore(store).get(key))),
                /** ذخیره مقدار kv */
                set: (store, key, value) => {
                        if (store === 'kv') return tx('kv', 'readwrite', (os) => os.put({ k: key, v: value }));
                        return tx(store, 'readwrite', (os) => os.put(value));
                },
                /** حذف */
                del: (store, key) => tx(store, 'readwrite', (os) => os.delete(key)),
                /** همه مقادیر یک store */
                all: (store) => open().then((db) => reqToPromise(db.transaction(store).objectStore(store).getAll())),
                /** تعداد */
                count: (store) => open().then((db) => reqToPromise(db.transaction(store).objectStore(store).count())),
                /**
                 * پیمایش استریم یک store با cursor — برای حجم بالا: رکوردها یکی‌یکی از دیسک خوانده می‌شوند
                 * و هرگز کل داده در RAM لود نمی‌شود. callback(row, key) مقدار false برگرداند → توقف زودهنگام.
                 * (همان «جستجو درون فایل کش» — IndexedDB روی دیسک ذخیره می‌شود، نه رم)
                 * ⚠️ ۱.۹.۲: رفع باگ بحرانی — continue() هرگز فراخوانی نمی‌شد، در نتیجه در مرورگر واقعی
                 * فقط «رکورد اول» پیمایش می‌شد و جستجوی آفلاین حداکثر یک نتیجه می‌داد (در mock تست‌ها
                 * خودبه‌خود پیش می‌رفت و خطا پنهان مانده بود).
                 */
                cursor: (store, callback) => open().then((db) => new Promise((resolve, reject) => {
                        const t = db.transaction(store, 'readonly');
                        const req = t.objectStore(store).openCursor();
                        let stopped = false;
                        req.onsuccess = () => {
                                const cur = req.result;
                                if (!cur || stopped) return;
                                try {
                                        if (callback(cur.value, cur.key) === false) {
                                                stopped = true;
                                                if (cur.close) cur.close();
                                        } else if (cur['continue']) {
                                                cur['continue'](); // پیشروی به رکورد بعدی
                                        }
                                } catch (err) { reject(err); stopped = true; }
                        };
                        t.oncomplete = () => resolve(true);
                        t.onerror = () => reject(t.error);
                        t.onabort = () => reject(t.error);
                })),
                /** پاک‌سازی store */
                clear: (store) => tx(store, 'readwrite', (os) => os.clear()),
                /** اجرای تراکنش سفارشی */
                run: (stores, mode, fn) => open().then((db) => new Promise((resolve, reject) => {
                        const t = db.transaction(stores, mode);
                        const result = fn(t);
                        t.oncomplete = () => resolve(result);
                        t.onerror = () => reject(t.error);
                })),
                /** بستن اتصال (برای خروج) */
                close: () => open().then((db) => db.close()).then(() => { dbPromise = null; })
        };

        return api;
})();
