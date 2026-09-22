# TPP Services — راهنمای توسعه‌دهندگان

این افزونه به‌عنوان یک **زیرسیاده داده سرویس‌ها** طراحی شده تا افزونه‌های دیگر شما بتوانند از داده‌ها و امکانات آن استفاده کنند. دو مسیر در اختیار دارید:
> **۱.۱۱.۰ — مستندات کامل REST:** فایل `API-DOCUMENTATION.md` در ریشه پوشه افزونه، مستندات کامل همه مسیرها (احراز هویت/پارامترها/نمونه‌ها) است و از همان منبعی تولید می‌شود که «مرکز API» داخل برنامه و مسیر `GET api/docs` (با `?format=md` برای دانلود) نمایش می‌دهند — همیشه همگام با نسخه نصب‌شده. برای اتصال افزونه‌های دیگر، ساخت توکن API با `POST api/tokens {label}` را ببینید.

---

## ۱. PHP API (داخل افزونه‌ها)

### پنل پیامک (از ۱.۱)

```php
// موجودی پیامک‌های باقیمانده (float یا WP_Error)
$credit = tpp()->sms()->credit();            // کش ۵ دقیقه‌ای
$credit = tpp()->sms()->credit( true );      // بدون کش

// ارسال پیامک ساده
$result = tpp()->sms()->send( '09121234567', 'متن پیامک' ); // یا آرایه‌ای از شماره‌ها

// ارسال برای یک سرویس با قالب (رندر سمت سرور + رعایت دسترسی فیلد کاربر)
$result = tpp()->sms()->send_for_service( $service_id, $template_id, $mobile_or_empty, $custom_text_or_empty, $user_id );

// نرمال‌سازی شماره موبایل (ارقام فارسی/عربی، +98، 0098 → 09xxxxxxxxx)
$mobile = TPP_SMS::normalize_mobile( '+98 912 1234567' ); // 09121234567

// رندر قالب با مقادیر سرویس (جای‌نگهدار {{slug}} + {{service_id}} + {{site_name}})
$text = TPP_SMS::render( $template_body, $service_row, $address_row, $visible_fields_or_null );

// قالب‌ها
$templates = tpp()->sms()->templates();                    // فهرست
tpp()->sms()->save_template( array( 'title' => '...', 'body' => '...' ) ); // ایجاد/ویرایش
tpp()->sms()->delete_template( $id );
tpp()->sms()->log( 50 );                                    // گزارش ارسال‌ها

// هوک بعد از ارسال موفق
add_action( 'tpp_sms_sent', function ( $result, $service_id, $user_id ) { ... }, 10, 3 );
```

### شورت‌کد

```php
// نمایش برنامه در هر محتایی — فقط کاربران واردشده دارای نقش tpp_* اپ را می‌بینند
echo do_shortcode( '[tpp_services]' );
// ارتفاع ثابت: [tpp_services height="1200"]
```

تابع سراسری `tpp()` یک کانتینر از ماژول‌ها برمی‌گرداند:

```php
// جستجوی سرویس‌ها
$results = tpp()->services()->search( array(
    'query'    => 'کد پستی یا هر متن',
    'filters'  => array( 'f_phone' => '021' ), // فیلتر اختصاصی فیلد
    'page'     => 1,
    'per_page' => 50,
    'group'    => false, // true = گروه‌بندی بر اساس آدرس
), $user_id /* اختیاری: برای اعمال دسترسی فیلد */ );

// ثبت سرویس جدید (تاریخچه خودکار ثبت می‌شود)
$result = tpp()->services()->create( array(
    'address' => array( 'f_full_address' => 'تهران...', 'f_postal_code' => '12345' ),
    'service' => array( 'f_owner_name' => 'علی', 'f_phone' => '02144', 'f_internet_status' => 'فعال' ),
), array( 'user_id' => get_current_user_id(), 'source' => 'api' ) );
// → array( 'status' => 'created', 'id' => 12, 'address_id' => 5, 'version' => 1 )

// ویرایش (با تشخیص تعارض نسخه)
$result = tpp()->services()->update( 12, array(
    'service' => array( 'f_internet_status' => 'قطع' ),
), array( 'user_id' => get_current_user_id(), 'source' => 'api', 'base_version' => 1 ) );

// حذف (تاریخچه حفظ می‌شود)
tpp()->services()->delete( 12, array( 'user_id' => get_current_user_id() ) );

// دریافت یک سرویس / سرویس‌های یک آدرس
$row    = tpp()->services()->get( 12 );
$orders = tpp()->services()->address_services( 5 );

// مقادیر یکتای یک فیلد (برای کشویی جستجوی اجاکسی — ۱.۹.۰؛ q = جستجوی سراسری LIKE روی کل دیتابیس — ۱.۹.۱)
$values = tpp()->services()->distinct_values( 'f_center_name', 'برز', 100, $user_id );

// بررسی موارد تکراری (۱.۹.۰ — دسترسی در لایه REST به مدیر کل محدود است؛ در PHP خودتان چک کنید)
$groups = tpp()->services()->find_duplicates( array( 'fields' => 'f_phone,f_modem_serial', 'include_dismissed' => false ) );
// → array( 'total', 'groups' => [ ['key','ids','dismissed','shared','services'] ], 'fields', 'selected', 'dismissed_count' )
$merged = tpp()->services()->merge_duplicates( $primary_id, array( $id2, $id3 ), array( 'user_id' => get_current_user_id() ) );
tpp()->services()->dismiss_duplicate_group( array( $id1, $id2 ) ); // باقی می‌ماند به حالت فعلی
tpp()->services()->restore_duplicate_groups( 'all' );              // بازگردانی علامت‌خورده‌ها

// هوک بعد از ادغام گروه تکراری (۱.۹.۰)
add_action( 'tpp_duplicates_merged', function ( $primary_id, $merged_ids, $changes ) { ... }, 10, 3 );

// فیلدها
$fields = tpp()->fields()->all( 'service' );   // یا 'address'
$field  = tpp()->fields()->get( 'f_phone' );
$new    = tpp()->fields()->add( array(
    'group' => 'service', 'label' => 'نام ISP', 'field_type' => 'text',
    'is_required' => false, 'is_searchable' => true,
) ); // ← ستون جدید در جدول ساخته می‌شود

// تاریخچه
$timeline = TPP_History::for_entity( 'service', 12 );   // تاریخچه یک سرویس
$timeline = TPP_History::address_timeline( 5 );          // کل تغییرات یک آدرس
$log      = TPP_History::global_log( array( 'user_id' => 3 ), 1, 50 );

// دسترسی‌ها
tpp()->user_can( 'tpp_edit_services', $user_id );          // bool
tpp()->caps()->visible_fields( $user_id );                 // [slug => true]
tpp()->caps()->user_caps( $user_id );                      // [cap => true]

// خروجی‌ها
tpp()->export()->xlsx( array( 'query' => '' ), $user_id ); // ['filename'=>..,'content'=>..]
tpp()->export()->backup();                                 // JSON کامل
tpp()->export()->template();                               // اکسل نمونه
tpp()->export()->pdf( $args, $user_id, $title );           // PDF سرور (TCPDF؛ در نبودش TPP_PDF)
tpp()->export()->print_html( $args, $user_id, $title );    // HTML صفحه چاپ مرورگر
tpp()->export()->sql_dump();                               // پشتیبان SQL کامل
tpp()->export()->backup_zip();                             // پشتیبان ZIP کامل (همه‌چیز)

// موتور PDF سرور — TCPDF بسته‌بندی‌شده (پشتیبانی کامل فارسی/RTL)
TPP_PDF_Report::available();                               // bool — کتابخانه موجود است؟
$content = TPP_PDF_Report::build( $title, $metaLines, $headers, $rows, array( 'font_size' => 8.5 ) );

// موتور PDF جایگزین (خالص PHP بدون وابستگی) — شکل‌دهی حروف فارسی + فونت تعبیه‌شده
$pdf = new TPP_PDF( 'L' );                                 // 'L' افقی / 'P' عمودی
$pdf->set_title( 'عنوان گزارش', array( 'خط فراداده ۱', 'خط ۲' ) );
$pdf->set_footer( 'پاصفحه' );
$pdf->table( $headers, $rows, array( 'font_size' => 8.5 ) );
$content = $pdf->output();                                 // رشته باینری PDF

// آمار
tpp()->services()->stats(); // services, addresses, changes, today, conflicts, last_change
```

### هوک‌ها (Hooks)

```php
// اکشن‌ها
do_action( 'tpp_service_created', $service_id, $data, $args );
do_action( 'tpp_service_updated', $service_id, $changes, $args );
do_action( 'tpp_service_deleted', $service_id, $args );
do_action( 'tpp_import_done',    $report, $user_id );
do_action( 'tpp_backup_restored' );
do_action( 'tpp_booted', $plugin );

// فیلترها
add_filter( 'tpp_search_args', function ( $args, $request ) {
    $args['per_page'] = 100; // مثلاً محدودسازی
    return $args;
}, 10, 2 );
```

### جدول‌های دیتابیس

| جدول | کاربرد |
|---|---|
| `{prefix}tpp_services` | سرویس‌ها — ستون‌های سیستمی + ستون داینامیک هر فیلد (`f_*`) |
| `{prefix}tpp_addresses` | آدرس‌ها (هر آدرس چند سرویس) |
| `{prefix}tpp_fields` | تعریف فیلدها (گروه/نوع/پرچم‌ها/گزینه‌ها) |
| `{prefix}tpp_history` | تاریخچه کامل تغییرات (changes به‌صورت JSON قدیم/جدید) |
| `{prefix}tpp_op_log` | عملیات‌های همگام‌سازی آفلاین (idempotency) |
| `{prefix}tpp_sync_log` | گزارش‌های همگام‌سازی |
| `{prefix}tpp_field_archives` | بایگانی مقادیر فیلدهای حذف‌شده |

مقدار هر فیلد در ستون هم‌نام فیلد است: `f_phone`, `f_owner_name`, ...

---

## ۲. REST API (namespace: `tpp/v1`)

> از نسخه ۱.۱ همه مسیرهای زیر علاوه بر REST، از طریق **پشتیبان admin-ajax** هم در دسترس‌اند:
> `POST /wp-admin/admin-ajax.php` با فیلدهای `action=tpp_api`، `route`، `method`، `args` (JSON پارامترها)، `body` (JSON بدنه) و اختیاری `token` — برای وقتی که افزونه امنیتی دسترسی REST را محدود کرده باشد. احراز هویت: کوکی وردپرس + هدر `X-WP-Nonce` یا هدر/فیلد `X-TPP-Token`.

آدرس پایه: `/wp-json/tpp/v1` (یا `/?rest_route=/tpp/v1`)

**احراز هویت:** کوکی وردپرس (با نونس) **یا** هدر `X-TPP-Token` (توکن بلندعمر اپ — بدون نیاز به نونس، مناسب ابزارهای خارجی/موبایل).

| متد | مسیر | شرح | قابلیت لازم |
|---|---|---|---|
| GET | `/ping` | سلامت/زمان/کاربر | — |
| POST | `/login` | ورود و دریافت توکن `{username,password}` | — |
| GET | `/bootstrap` | توکن+کاربر+قابلیت‌ها+`is_wp_admin`+اسکیمای فیلدها+تنظیمات+`version` (نسخه افزونه — برای به‌روزرسانی خودکار اپ) | — |
| POST | `/logout` | ابطال توکن (`{all:1}` = همه دستگاه‌ها) | دسترسی افزونه |
| GET | `/search` | جستجوی زنده: `?query=&page=&per_page=&group=1&sort=&order=&filters={json}&upd_from=&upd_to=` — بازه زمانی ویرایش به تاریخ میلادی `YYYY-MM-DD` (از ۱.۷.۰) | `tpp_view_services` |
| POST | `/services` | ثبت سرویس `{address:{},service:{},address_id?}` | `tpp_create_services` |
| GET | `/services/{id}` | سرویس + تاریخچه + سرویس‌های هم‌آدرس | `tpp_view_services` |
| PUT | `/services/{id}` | ویرایش `{service:{},address:{},base_version}` | `tpp_edit_services` |
| DELETE | `/services/{id}` | حذف | `tpp_delete_services` |
| GET | `/addresses/{id}` | آدرس + سرویس‌ها + تاریخچه کل | `tpp_view_services` |
| GET | `/addresses/suggest` | پیشنهاد آدرس `?query=` | `tpp_view_services` |
| GET | `/values` | **(۱.۹.۰)** مقادیر یکتای یک فیلد برای کشویی جستجوی اجاکسی: `?field=f_center_name&q=&limit=` → `{field,values:[...]}` — مقادیر خالی/صفر/AUTO حذف می‌شوند؛ فقط فیلدهای قابل جستجو و قابل مشاهده کاربر. **(۱.۹.۱)** `q` پر = جستجوی سراسری `LIKE %q%` روی کل جدول (کلاینت با تایپ کاربر همین را صدا می‌زند؛ دیگر فقط ۲۰۰ مقدار اول لودشده نیست) | `tpp_view_services` |
| GET | `/duplicates` | **(۱.۹.۰)** گروه‌های سرویس تکراری `?fields=f_phone,f_virtual_number&include_dismissed=1` → `{total,groups:[{key,ids,dismissed,shared:[{slug,label,value}],services:[{id,fields:{slug:val},...}]}],fields,selected,dismissed_count}` | **مدیر کل** (`manage_options`) |
| POST | `/duplicates/merge` | **(۱.۹.۰)** ادغام `{primary_id,ids:[...]}` — فیلدهای خالی اصلی پر می‌شود، تاریخچه‌ها به اصلی منتقل و بقیه حذف می‌شوند → `{status:'merged',primary_id,merged_ids,filled_fields,version}` | **مدیر کل** |
| POST | `/duplicates/dismiss` | **(۱.۹.۰)** علامت «باقی می‌ماند به حالت فعلی» `{ids:[...]}` | **مدیر کل** |
| POST | `/duplicates/restore` | **(۱.۹.۰)** بازگردانی گروه علامت‌خورده `{key:'12_45'}` یا همه `{key:'all'}` | **مدیر کل** |
| GET | `/history` | تاریخچه `?action=&entity=&user_id=&conflict=&page=` | `tpp_view_history` |
| POST | `/history/delete` | حذف تکی/گروهی رکوردهای تاریخچه `{ids:[...]}` | `tpp_delete_history` |
| POST | `/history/{id}/restore` | بازگردانی مقادیر قبلی | `tpp_edit_services` |
| POST | `/sync` | همگام‌سازی batch آفلاین `{ops:[{op_id,kind,payload,base_version}]}` — kindها: `service.create|service.update|service.delete|history.delete` | دسترسی افزونه |
| POST | `/import/preview` | آپلود اکسل (multipart: file) — پاسخ شامل `mapped_count`، `unmapped_headers` و `empty_rows` (۱.۸.۰) | `tpp_import` |
| POST | `/import/analyze` | تحلیل تشابه‌ها `{session_id,mapping}` → `{duplicates:[{row,address_id,label,reasons,confidence}]}` — با هیچ ستون نگاشت‌شده خطای `tpp_no_mapping`؛ **(۱.۸.۱)** mapping خالی/غیرآرایه‌ای → fallback به نگاشت خودکار نشست | `tpp_import` |
| POST | `/import/commit` | ثبت نهایی `{session_id,mapping,mode,match_key,dup_mode,dup_rows}` — گزارش شامل `empty_rows`؛ مقادیر کلید بی‌ارزش (خالی/0/خط تیره) در تطبیق شرکت نمی‌کنند (۱.۸.۰)؛ **(۱.۸.۱)** mapping خالی → fallback به نگاشت نشست (رفع خطای «هیچ ستونی به فیلد نگاشت نشده است» بعد از مرحله تشابه‌ها که جدول نگاشت از DOM حذف می‌شد) | `tpp_import` |
| GET | `/export/xlsx` | خروجی اکسل `?query=&filters=` | `tpp_view_services` **یا** `tpp_export` (۱.۶.۰ — برای همه کاربران صفحه سرویس‌ها) |
| GET | `/export/pdf` | خروجی PDF سرور (TCPDF با فونت فارسی تعبیه‌شده) `?query=&filters=` | `tpp_view_services` **یا** `tpp_export` |
| GET | `/export/print` | صفحه چاپ مرورگر (HTML راست‌چین + window.print خودکار) | `tpp_view_services` **یا** `tpp_export` |
| GET | `/backup/sql` | پشتیبان کامل SQL همه جداول + تنظیمات | `tpp_manage_settings` |
| GET | `/backup/zip` | پشتیبان کامل ZIP (SQL + JSON + فایل‌های افزونه) | `tpp_manage_settings` |
| GET | `/template` | فایل نمونه اکسل (همیشه به‌روز) | دسترسی افزونه |
| GET | `/backup` | پشتیبان کامل JSON | `tpp_manage_settings` |
| POST | `/restore` | بازیابی `{json}` | `tpp_manage_settings` |
| GET/POST | `/fields` | فهرست / افزودن فیلد | view / `tpp_manage_fields` |
| PUT/DELETE | `/fields/{id}` | ویرایش / حذف فیلد | `tpp_manage_fields` |
| POST | `/fields/reorder` | تغییر ترتیب `{group,order:[ids]}` | `tpp_manage_fields` |
| GET/POST | `/roles` | فهرست / ساخت نقش | `tpp_manage_roles` |
| PUT/DELETE | `/roles/{slug}` | ویرایش / حذف نقش | `tpp_manage_roles` |
| GET/PUT | `/settings` | تنظیمات | `tpp_manage_settings` |
| GET | `/stats` | آمار | `tpp_view_services` |
| GET | `/sms/status` | وضعیت پنل پیامک + موجودی باقیمانده (`?fresh=1` بدون کش) | `tpp_send_sms` |
| POST | `/sms/send` | ارسال پیامک `{service_id,template_id,mobile,text}` | `tpp_send_sms` |
| GET | `/sms/templates` | فهرست قالب‌های پیامک | `tpp_send_sms` |
| POST | `/sms/templates` | ایجاد قالب `{title,body}` | `tpp_manage_settings` |
| PUT/DELETE | `/sms/templates/{id}` | ویرایش / حذف قالب | `tpp_manage_settings` |
| GET | `/sms/log` | گزارش ارسال‌ها `?per_page=` | `tpp_manage_settings` |

### نمونه: ثبت سرویس از یک اسکریپت خارجی

```bash
curl -X POST https://site.com/wp-json/tpp/v1/services \
  -H "X-TPP-Token: tpp_xxxx" -H "Content-Type: application/json" \
  -d '{"address":{"f_full_address":"تهران، خیابان آزادی"},"service":{"f_owner_name":"علی","f_phone":"02144"}}'
```

### نمونه: همگام‌سازی آفلاین (همان چیزی که اپ داخلی استفاده می‌کند)

```json
POST /tpp/v1/sync
{
  "ops": [
    { "op_id": "op-xyz-1", "kind": "service.create",
      "payload": { "address": {...}, "service": {...} } },
    { "op_id": "op-xyz-2", "kind": "service.update",
      "payload": { "id": 12, "service": { "f_phone": "02199" } },
      "base_version": 3 }
  ]
}
```
پاسخ هر عملیات: `{op_id, status: created|updated|deleted|error|duplicate, id?, version?, conflict?, data?}` — ارسال دوباره همان `op_id` نتیجه قبلی را برمی‌گرداند (بدون ثبت مجدد).

---

## ۳. نکات مهم

1. **دسترسی فیلد**: اگر `user_id` به search/get بدهید، فیلدهای مخفی آن کاربر از خروجی حذف می‌شوند — این رفتار امنیتی است؛ در افزونه خودتان هم آن را رعایت کنید.
2. **تاریخچه**: با حذف سرویس، تاریخچه آن سرویس آبشاری حذف می‌شود (رفتار پیش‌فرض از ۱.۴.۰). برای حذف دستی رکوردهای تاریخچه از `TPP_History::delete_entries(array $ids)` استفاده کنید (خروجی = تعداد رکوردهای واقعاً حذف‌شده).
3. **فیلدهای داینامیک**: نام ستون‌ها را از `tpp()->fields()->all()` بخوانید؛ هرگز نام فیلد را hardcode نکنید به‌جز فیلدهای پیش‌فرض.
4. **ترتیب کانونی فیلدها (۱.۸.۰)**: `TPP_Fields::form_order()` ترتیب مرجع (فرم ثبت سرویس: ۱۱ فیلد «اطلاعات اصلی» → آدرس → سایر) را برمی‌گرداند — فایل نمونه اکسل، خروجی اکسل/PDF/چاپ و فهرست فیلدهای ایمپورت از همین تابع استفاده می‌کنند؛ `TPP_Fields::apply_canonical_order_v180()` همین ترتیب را روی `sort_order` دیتابیس می‌نویسد (در ارتقا و بعد از restore اجرا می‌شود)؛ فیلدهای سفارشی ترتیب نسبی خود را حفظ می‌کنند.
5. **ایمپورت اکسل**: عنوان ستون‌ها با نشانگر اجباری (« *»، «(اجباری)»، «الزامی»، «required») مطابقت داده می‌شوند — این نشانگرها در `TPP_Import::norm_header()` پاک‌سازی می‌شوند تا فایل نمونه صددرصد رفت‌وبرگشت کند.
6. **توکن‌ها**: `X-TPP-Token` فقط از طریق `/login` یا `/bootstrap` صادر می‌شود؛ با تغییر رمز کاربر باطل می‌شود.
7. **ثابت‌های دیتابیس جداگانه** (`TPP_DB_*`): اگر فعال باشند، همه کوئری‌های افزونه روی اتصال دوم اجرا می‌شوند — کد شما هم از طریق `TPP_DB` باید به داده دسترسی داشته باشد.
8. **اعتبارسنجی فیلدهای الزامی (۱.۶.۰)**: `TPP_Services::create()` و `update()` فیلدهای اجباری (پرچم `is_required`) را برای فیلدهای قابل مشاهده کاربر بررسی می‌کنند — **به‌جز source=import** (داده قدیمی ناقص نباید رد شود). برای دور زدن در کد خودتان `args['skip_required'] = true` بدهید. فیلدهای Subnet/Gateway/SBC اجباریِ شرطی فقط در فرم (سمت کلاینت) و صرفاً در حالت «آیپی استاتیک» اعمال می‌شوند؛ مقدار ذخیره‌شده `f_sip_ip` برابر رشته «DHCP» یا خودِ آدرس آیپی استاتیک است.
9. **نتیجه عملیات در کلاینت (۱.۶.۰)**: `TPP.offline.enqueue()` پس از اعمال آنلاین، نتیجه سرور را در `op.result` قرار می‌دهد (`{status:'created'|'updated'|'error', message?, data?}`) تا فرم بدون ریدایرکت بتواند موفقیت/خطا را نشان دهد و شناسه واقعی سرویس تازه ثبت‌شده را بگیرد.
10. **موتور کش آفلاین (۱.۹.۲)**: `TPP.offline.cacheAll()` حالا مقاوم است — نوشتن دسته‌ای (یک تراکنش IDB برای هر صفحه)، کاهش خودکار `per_page` (۵۰۰→۲۰۰→۱۰۰→۵۰) در صورت خطای HTTP و یادآوری اندازه موفق در `kv:offline_cache_state.perPage`، تلاش مجدد خودکار با backoff (۳۰s→۳۰۰s) تا کامل شدن، و گارد اجرای همزمان (فراخوانی دوم همان Promise جاری را برمی‌گرداند). وضعیت با `TPP.offline.cacheStats()` قابل خواندن است: `{cached,total,complete,syncing,error,ts,perPage,lastSync}` و تغییراتش با رویداد `TPP.offline.on('cache', fn)` به UI اعلام می‌شود. `TPP.offline.valuesLocal(slug,q,limit)` معادل آفلاین endpoint `/values` است (مقادیر یکتای یک فیلد از کل سرویس‌های ذخیره‌شده دستگاه با نرمال‌سازی فارسی) — کامبوباکس‌های فیلتر در حالت آفلاین از همان تجربه جستجوی سراسری استفاده می‌کنند.
11. **تاریخچه تجمیعی روزانه (۱.۱۰.۰)**: `TPP_History::record()` همه تغییرات یک موجودیت توسط یک کاربر در یک روز در یک رکورد جمع می‌کند (فرمت changes نسخه ۲: `{"fmt":2,"day":"Y-m-d","events":[{"t":"H:i:s","a":"update","s":"online","y":"service"|"address","c":{slug:{old,new}}}]}`). پارامتر `addr_changes`/`addr_action` رویداد تغییر آدرس را در همان رکورد سرویس ثبت می‌کند (به‌جای رکورد جداگانه). برای رکورد جدا در هر ذخیره، تنظیم `history_daily` را خاموش کنید یا `no_aggregate => true` بدهید. رکوردهای فرمت قدیمی (نقشه تغییرات) همان‌طور خوانده می‌شوند؛ `TPP_History::filter_changes()` با هر دو فرمت کار می‌کند.
12. **گزارش فعالیت (۱.۱۰.۰)**: `TPP_Activity::log_view($user_id, $service_id)` و `TPP_Activity::log_search($user_id, $query, $filters, $results)` ثبت می‌کنند (با ادغام بازدید پیوسته ۱۵ دقیقه‌ای/جستجوی ۵ دقیقه‌ای). گزارش‌ها: `TPP_Activity::views_log($args, $page, $per)` و `searches_log(...)` (خروجی: `{total, rows, users}`)؛ پاک‌سازی دوره‌ای با `TPP_Activity::cleanup()` (تنظیمات `view_history_days`/`search_history_days`/`history_days` — ۰ = نامحدود).
13. **پشتیبان/بازیابی (۱.۱۰.۰)**: `TPP_Export::backup($flags)` فرمت ۲ با انتخاب محتوا (`with_history`, `with_activity`, `with_sms_log`)؛ `restore($json)` درج دسته‌ای + خروجی خلاصه `{status, summary:{tables, warnings, errors, elapsed_ms}}`؛ `sql_dump()` مستقل از پیشوند ({{PFX}} + PREPARE — قابل اجرا روی هر سروری در phpMyAdmin). آپلود تکه‌ای پشتیبان از مسیرهای REST `restore/begin` → `restore/chunk` → `restore/finish` (برای فایل‌های بزرگ، مستقل از محدودیت آپلود سرور).
14. **قابلیت `tpp_view_activity`**: مشاهده گزارش فعالیت (بازدید/جستجو/تغییرات) — از ۱.۱۲.۰ **به‌طور پیش‌فرض همه نقش‌ها** دارند؛ از «نقش‌ها و دسترسی‌ها» قابل محدودسازی است.
15. **تشخیص تناقض تکراری‌ها (۱.۱۱.۰)**: `TPP_Services::find_duplicates()` هر گروه را با `conflicts` (فیلدهای متناقض + مقادیر همه اعضا)، `complementary` (فیلدهای مکمل)، `status` (clean|conflict) و `recommended_primary` (غنی‌ترین رکورد) برمی‌گرداند؛ `merge_duplicates($primary, $ids, ['pick' => [slug => src_id]])` مقدار برنده فیلدهای متناقض را تعیین می‌کند؛ `merge_safe_duplicates($keys)` همه گروه‌های بدون تناقض را یک‌جا ادغام می‌کند.
16. **گزارش یکپارچه فعالیت (۱.۱۱.۰)**: `TPP_Activity::unified_log($args, $page, $per)` فید واحد تغییرات/بازدید/جستجو/پیامک با فیلتر user/type/action/service_id/q/from/to؛ `TPP_Activity::stats()` آمار بازه‌ها + برترین‌ها؛ `TPP_Activity::export_csv($args)` خروجی CSV با BOM. تجمیع جستجوهای در حال تایپ با تنظیم `search_dedupe_seconds` (پیش‌فرض ۱۵ ثانیه — ۰ = خاموش).
17. **توکن API و مستندات زنده (۱.۱۱.۰)**: `POST api/tokens {label}` توکن جدید می‌سازد (همان سازوکار هش‌شده نشست‌ها)، `GET api/tokens` فهرست می‌دهد، `DELETE api/tokens {idx}` ابطال می‌کند؛ `GET api/docs` کاتالوگ مستندات JSON و `GET api/docs?format=md` نسخه Markdown. `GET users` (با قابلیت مدیریت نقش‌ها) کاربران دارای دسترسی + نقش‌های tpp آن‌ها را برای اتصال‌های خارجی برمی‌گرداند.

18. **پیشرفت دایری سرویس (۱.۱۲.۰)**: `TPP_Progress::steps()` ۱۶ مرحله و `TPP_Progress::failures()` ۴ خرابی (los/phone/internet/other) را برمی‌گرداند؛ `TPP_Progress::sanitize($input)` ورودی `{steps:[…], failure:…}` را اعتبارسنجی/مرتب می‌کند؛ `TPP_Progress::summary($row)` خلاصه JSON (steps/failure/done/total/pct/status/last_label) می‌سازد. در `TPP_Services::create()/update()` کلید `progress` را بدهید؛ تغییرات با برچسب فارسی در تاریخچه (کلیدهای `_progress_steps`/`_progress_failure`) ثبت می‌شوند. فیلتر جستجو: `progress_status` (none|progress|done|fail|fail_los|fail_phone|fail_internet|fail_other) و `progress_step` + `progress_step_state` (done|todo). ستون‌های دیتابیس: `progress_steps` (JSON) / `progress_done` / `progress_failure` / `progress_updated_at` روی جدول services. `tpp()->progress()->stats()` آمار داشبورد و `TPP_Progress::ensure_columns()` برای سازگاری نصب‌های قدیمی.
19. **منطق آبشاری مراحل (۱.۱۳.۰)**: `TPP_Progress::apply($input, $old_row)` منبع حقیقت آبشار است — ورودی `{steps:[…], skipped:[…], failure:…, reset_skips:bool}` + رکورد فعلی؛ خروجی `{steps, steps_array, done, failure, excluded, excluded_array}`. قواعد: تیک مرحله N = تیک خودکار همه مراحل قبل از N؛ برداشتن تیک مرحله در حالی که مرحله بعدی تیک دارد = ثبت در `progress_excluded` («ردشده توسط کاربر» — آبشار از آن می‌پرد)؛ تیک دوباره = رفع ردشدگی؛ برداشتن آخرین مرحله = عقب‌گرد طبیعی. `TPP_Progress::steps_change_text($old, $new, $old_excl, $new_excl)` شرح فارسی تغییرات (برای تاریخچه) می‌سازد. ستون جدید: `progress_excluded` (JSON) روی جدول services — در خروجی سرویس با کلید `progress.excluded` دیده می‌شود.
20. **تغییر گروهی/تکی (۱.۱۳.۰)**: `TPP_Services::apply_bulk(array $ids, array $opts, array $args)` — `$opts`: `mode` (up_to|add|remove|clear) + `step`/`steps` + `skipped_policy` (keep|reset) + `failure` (null=بدون تغییر، ''=رفع، los|phone|internet|other) + `service` ([slug=>value] — فقط فیلدهای قابل مشاهده کاربر) + `dry_run`. خروجی: `{status:'done', results:[{id, status: applied|unchanged|not_found|error, progress}], summary:{total, applied, unchanged, not_found, error}}`. هر تغییر در تاریخچه با `source` بخصوص و علامت `_bulk_edit` ثبت می‌شود. **تکرارناپذیر**: با `args['op_id']` نتیجه در `op_log` ذخیره و تکرار همان نتیجه برمی‌گردد (مثل create/update). REST: `POST tpp/v1/services/bulk` با بدنه `{ids:[…], progress:{…}, service:{…}}`. آفلاین: op از نوع `service.bulk` در `/sync` پذیرفته می‌شود.
21. **ایمپورت دایری (۱.۱۳.۰)**: شبه‌ستون‌های «پیشرفت دایری» و «خرابی اعلام‌شده» در فایل اکسل به‌صورت خودکار نگاشت می‌شوند (`TPP_Import::PROGRESS_FIELD`/`FAILURE_FIELD`)؛ مقدار مراحل: عدد ۱..۱۶ (فارسی/لاتین)، درصد، کلید مرحله (`inet_connected`)، برچسب کامل یا جزئی («اینترنت متصل»)، «کامل»/«همه»/«100%»، «هیچ»/«0»، فهرست با کاما؛ مقدار خرابی: کلید یا برچسب (LOS/قطع تلفن/…) و «رفع»/«حل» = پاک‌کردن. هم در ایجاد سرویس جدید و هم به‌روزرسانی سرویس موجود اعمال می‌شود. قالب نمونه (`TPP_Export::template()`) این دو ستون را دارد.
22. **قابلیت `tpp_quick_edit` (۱.۱۳.۱)**: «ویرایش سریع» (دکمه‌های ⚡ و 🚀 + چک‌باکس انتخاب کارت‌ها برای تغییر گروهی) قابلیت دسترسی مستقل دارد و **به‌طور پیش‌فرض فقط مدیر کل سایت** (کاربر وردپرس با `manage_options` — مسیر `TPP_Capabilities::user_caps()` همه قابلیت‌ها را به او می‌دهد) آن را دارد؛ **همه نقش‌های tpp_ حتی `tpp_manager` به‌طور پیش‌فرض خاموش‌اند**. گره‌خورده به این قابلیت: مسیر REST `POST services/bulk` (متد `TPP_REST::perm_quick()`) و عملیات آفلاین `service.bulk` در `/sync`. اعطا: «نقش‌ها و دسترسی‌ها» یا `TPP_Capabilities::set_role_caps($slug, array('tpp_quick_edit' => true), $fields)`. نکته کلاینت: `canQuick()` در tpp-app.js عمداً از میان‌بر `isManager` استفاده نمی‌کند (is_manager شامل دارندگان `tpp_manage_roles` است که ویرایش سریع ندارند) — فقط `caps` سرور ملاک است.
23. **گارد ریسپانسیو موبایل (۱.۱۳.۲)**: در `pwa/assets/css/app.css` ردیف `#prog-step-wrap` (فیلتر «مرحله» در پنل فیلترهای سرویس‌ها) به کانتینر flex شکستنی با `flex-basis:100%` تبدیل شد و همه `select`های `.prog-filter` سقف `max-width:100%` گرفتند — علت: نام بلند مراحل دایری، عرض ذاتی کشو را تا ~۵۳۰px بزرگ می‌کرد که در موبایل از کادر بیرون می‌زد و صفحه را ۱۸۰px لغزان می‌کرد (پس‌زمینه تیره «منوی نصف صفحه» القا می‌شد). گارد سراسری: `html { overflow-x: hidden }` — **عمداً فقط روی `html` و نه `body`**؛ اگر روی body هم باشد، body خودش ظرف اسکرل می‌شود (overflow-y محاسبه‌شده auto) و `window.scrollTo`/اسکرول برنامه‌ای اپ و sticky نوار بالا رفتار سابق را از دست می‌دهند. قاعده برای توسعه آینده: هر عنصر جدیدی با متن بلند (select/nowrap/کامبوباکس) باید `max-width:100%` داشته باشد.
24. **چند خرابی همزمان (۱.۱۴.۰)**: خرابی‌های سرویس آرایه‌اند — ستون جدید `progress_failures` (JSON، مثل `["internet","other"]`) روی جدول services؛ ستون قدیمی `progress_failure` (VARCHAR) برای سازگاری با فیلترها/نمایش‌های قبلی حفظ و همیشه با **اولین** عضو آرایه هم‌گام می‌شود. `TPP_Progress::parse_failure_list($raw)` آرایه/JSON/کاما را می‌پذیرد؛ `TPP_Progress::failures_of($row)` از ستون JSON می‌خواند و در نبودش از ستون تکی (داده قدیمی)؛ `resolve_input_failures` ورودی REST را می‌سازد: کلید `progress.failures` (آرایه — خالی = رفع همه) + legacy `progress.failure`. خروجی `summary()` شامل `failures` + `failures_labels` + `failure`/`failure_label` (اولین) است؛ `status_label($status, $failures)` رشته یا آرایه می‌گیرد. فیلتر `status_where('fail_los')` حالا `progress_failures LIKE '%"los"%' OR progress_failure = 'los'` است. مهاجرت: `TPP_Progress::backfill_failures()` (در ارتقا، یک‌بار) رکوردهای قدیمی را از ستون تکی پر می‌کند. در apply_bulk کلید `failures` (آرایه) جایگزین/مکمل `failure` شده؛ sync آفلاین هر دو را منتقل می‌کند.
25. **گزارش کار (۱.۱۴.۰)**: کلاس `TPP_Workreport` + جدول `work_reports` (user_id, report_date DATE, service_id, content, sort — کلید user_day). قلم‌ها: `TPP_Workreport::add($user_id, $date, $content, $service_id)`، `add_from_activity($user_id, 'view'|'change', $row_id, $date)` (ردیف بازدید/تاریخچه همان کاربر → قلم استاندارد)، `build_line($service_id, $prefix)` (قالب «{prefix} (آدرس کامل، نام خیابان یا بلوک، شماره پلاک، شماره واحد)، آخرین وضعیت پیشرفت دایری سرویس، اقدامات باقیمانده از پیشرفت دایری سرویس، خرابی اعلام شده»)، `day()`/`days_with_reports()`/`users_facet()` + ویرایش/حذف فقط توسط مالک. REST: `GET workreport?date=&user_id=` (کاربر دیگر فقط با قابلیت `tpp_view_activity`، فقط-مشاهده)، `POST workreport {date, content|service_id+prefix}`، `POST workreport/from_activity`، `POST workreport/line` (پیش‌نمایش بدون ثبت)، `PUT/DELETE workreport/{id}`. نکته: action رکورد تاریخچه تجمیعی روز = **اولین** رویداد آن روز (`$merged['events'][0]['a']`)؛ بنابراین سرویسی که امروز ایجاد و سپس ویرایش شده، در فید روز با action=create می‌آید و قلم «تحویل سرویس» می‌سازد — مطابق قصد «ایجاد سرویس → تحویل».
26. **پشتیبان‌های ذخیره‌شده روی سرور (۱.۱۵.۰)**: کلاس ایستا `TPP_Backup` (دسترسی `tpp()->backup()`) پشتیبان‌های سروری را مدیریت می‌کند — `TPP_Backup::create($context, $user_id)` پشتیبان کامل JSON (with_history=1 + کلید اطلاعات `_tpp_stored`) می‌سازد و در `TPP_PLUGIN_DIR/backups/` ذخیره می‌کند (fallback خودکار به `uploads/tpp-backups` اگر پوشه افزونه فقط-خواندن باشد)؛ متادیتا در فایل جانبی `*.meta.json` نگه داشته می‌شود تا فهرست بدون بازکردن فایل‌های بزرگ ساخته شود. `items()` فهرست جدید→قدیم، `item_path($filename)` اعتبارسنجی امن نام (الگوی `tpp-backup-[A-Za-z0-9-]+\.json` — بدون پیمایش مسیر)، `restore_item($filename)` بازگردانی یک‌کلیکی (همان `TPP_Export::restore()`)، `delete_item()`، `download_item()`، `prune($keep)` هرس نسخه‌های قدیمی و `info()` وضعیت محل ذخیره. در `TPP_Import::commit()` قبل از پردازش ردیف‌ها، اگر تنظیم `import_auto_backup` (پیش‌فرض ۱) روشن باشد `TPP_Backup::create('import', $user_id)` اجرا می‌شود؛ **شکست پشتیبان = توقف ایمپورت** با خطای `tpp_backup_failed` (زنجیره اطمینان) — متادیتای پشتیبان در `report.backup` به کلاینت برمی‌گردد و بنر سبز گزارش ایمپورت را می‌سازد. تنظیمات: `import_auto_backup` (روشن/خاموش) + `auto_backup_keep` (پیش‌فرض ۱۰؛ ۰ = نامحدود) — هر دو در sanitize و settings_get پوشیده شده‌اند. REST: `GET backup/list`، `POST backup/stored` (پشتیبان دستی)، `POST backup/stored/restore {filename}`، `POST backup/stored/delete {filename}`، `GET backup/stored/download?filename=` (همگی perm `tpp_manage_settings`). امنیت: نام فایل غیرقابل حدس (توکن ۸ کاراکتری) + `.htaccess` deny + index.html خالی در پوشه؛ بسته ZIP (`TPP_Export::backup_zip()`) عمداً پوشه backups را مستثنی می‌کند تا بسته‌های تودرتو ساخته نشود.
27. **ساعت تهران و تقویم شمسی (۱.۱۸.۰)**: کلاس ایستا `TPP_Date` (فایل `includes/class-tpp-date.php`) تنها مرجع زمان افزونه است — هیچ `current_time()` زنده‌ای در includes باقی نمانده. متدها: `now()`/`today()`/`his()` (قالب‌های «Y-m-d H:i:s»/«Y-m-d»/«H:i:s» به دیوار تهران)، `fmt($format)`، `offset()` (افست واقعی از PHP — ایران از ۱۴۰۱/2022 بدون ساعت تابستانی با +03:30 ثابت)، `ts()` (معادل current_time('timestamp'))، `to_jalali($gy,$gm,$gd)`/`to_gregorian()` (پورت وفادار الگوریتم jalaali — همان JS اپ؛ تقسیم با intdiv/trunc)، `jalali($dt,$with_time)` (رشته ثبت‌شده → «۱۴۰۵/۰۶/۳۰ — ۱۰:۳۰» با ارقام فارسی؛ انتهای ISO با Z/±HH:MM خودکار به تهران تبدیل می‌شود)، `jalali_date()`/`jalali_now()` و `fa_num()`/`en_num()`/`has_fa_digits()`. **قاعده توسعه:** هرجای افزونه زمان می‌خواهید فقط `TPP_Date::now()` (نه current_time/date) و برای نمایش فقط `TPP_Date::jalali()` — ستون‌های دیتابیس همیشه رشته «Y-m-d H:i:s» دیوار تهران می‌مانند. سمت کلاینت: `tehranTodayIso()`/`nowTehranSql()` در tpp-app.js (Intl.DateTimeFormat با timeZone:'Asia/Tehran') همان خروجی سرور را می‌سازند تا آفلاین/آنلاین هم‌ارز باشند. اعداد نمایش تاریخ‌ها فارسی‌اند؛ `TPP_Date::en_num()` برای معکوس.
28. **اصلاح اعداد فارسی/عربی (۱.۱۸.۰)**: کلاس ایستا `TPP_Numfix` (فایل `includes/class-tpp-numfix.php`) — `TPP_Numfix::run($dry_run, $user_id)` همه ستون‌های متنی فیلدهای service/address + ستون `content` جدول work_reports را اسکن می‌کند (کوئری LIKE با ۲۰ نویسه ۰-۹/٠-٩، سپس راستی‌آزمایی `en_num()` قبل از UPDATE — idempotent). خروجی: `{dry_run, backup, columns[{table_label,label,slug,rows,chars,samples}], rows, chars, ran_at, ran_at_jalali}`. **زنجیره اطمینان مثل ایمپورت:** در اجرای واقعی اول `TPP_Backup::create('numfix', $user_id)` گرفته می‌شود و شکست آن = `WP_Error('tpp_backup_failed')` بدون هیچ تغییری. REST: `POST numbers/fix` (perm `tpp_manage_settings`؛ پارامتر `dry_run` فقط می‌شمارد). UI: بخش «🔢 اصلاح اعداد فارسی به انگلیسی» در نمای تنظیمات — دکمه «پیش‌نمایش (بدون تغییر)» و دکمه اجرا با تأیید؛ بعد از اجرا دکمه «♻️ بازگردانی پشتیبان همین‌جا» با filename از پاسخ. زمینه پشتیبان «numfix» در فهرست پشتیبان‌های سرور با برچسب «خودکار (قبل از اصلاح اعداد)» نمایش داده می‌شود.
29. **دسته‌بندی پروژه‌ها و تگ‌ها (۱.۱۹.۰)**: کلاس ایستا `TPP_Categories` (فایل `includes/class-tpp-categories.php`) + جدول `categories` (kind: 'category'|'tag', label, sort_order). روی جدول services دو ستون: `category_id` (BIGINT، ۰ = بدون دسته، ایندکس idx_category_id) و `service_tags` (JSON آرایه شناسه تگ‌ها مثل `[3,7]`). متدها: `categories()/tags()/all($kind)/get($id)`، `add(['kind','label'])`، `update($id,['label','sort_order'])`، `delete($id)` (دسته در حال استفاده → WP_Error `tpp_in_use`؛ تگ → `strip_tag_from_services` سپس حذف)، `usage_count($id)`، `parse_tags_input($raw)` (آرایه/JSON/کاما/`[{id}]`)، `tags_of_row($row)`، `tags_json($ids)`، `shape($row)` (→ `category:{id,label}|null` + `tags:[{id,label}]` در خروجی سرویس)، `apply_to_payload($data, $old_row, &$changes)` (درج/آپدیت + تغییرات تاریخچه `_category`/`_tags`)، `resolve_labels($kind, $text, $auto_create)` (برچسب→شناسه برای ایمپورت؛ ساخت خودکار ناموجودها)، `filter_where($args,'s')` (شرط جستجو: `category` + `tags` با تطبیق «هرکدام»). **اجباری‌بودن**: `TPP_Services::create()/update()` وقتی `TPP_Categories::any_category_defined()` و source≠import باشد، دسته خالی را با `tpp_required` رد می‌کنند (ایمپورت معاف — فایل قدیمی). REST: `GET categories` (perm_access — برای فرم/فیلتر همه)، `POST categories` + `PUT/DELETE categories/{id}` (perm جدید `tpp_manage_categories` — پیش‌فرض فقط مدیر کل؛ روی نقش‌های ذخیره‌شده با merge_new_caps خاموش ثبت می‌شود). پشتیبان/بازیابی: جدول categories + ستون‌ها کامل (restore خودکار ensure می‌کند). ایمپورت: شبه‌ستون‌های `__category`/`__tags` (برچسب‌ها با کاما) + `categories_created`/`tags_created` در گزارش commit.
30. **گزارش کار ۱.۱۹.۰ — متن ساده + بازه‌ای + فید + تقویم**: `TPP_Workreport::build_line()` قالب ساده‌شده: «{اقدام} (آدرس) ، دایری سرویس تا مرحله (X) ، مراحل باقیمانده بعدی از مرحله (Y) ، خرابی اعلام‌شده: Z» — بدون شمارش/درصد (progress_line/remaining_line/failure_line خالی‌ها را حذف می‌کنند). `TPP_Workreport::range($user_id, $from, $to)` گزارش بازه‌ای با گروه‌بندی روزانه (`days:[{date, items}]` + `day_counts` + `total_items`) — REST: `GET workreport?from=&to=`. `TPP_Workreport::actions()` کاتالوگ اقدامات گروه‌بندی‌شده (عمومی/مراحل دایری/خرابی) برای کشویی «اقدام انجام‌شده» در افزودن دستی. `TPP_Activity::workreport_feed($user_id, $date)` فید روز **بدون جستجوها** (تغییر+بازدید+پیامک) با `svc` آدرس‌دار (batch: سرویس‌ها+آدرس‌ها) — در پاسخ تک‌روز workreport با کلید `feed` (برای همه کاربران، بدون نیاز به tpp_view_activity). `TPP_Activity::activity_days($user_id, $from, $to)` روزهای دارای فعالیت (REST `activity/days`) و `retention_info()` (days = کوتاه‌ترین بازه فعال، ۰=نامحدود) برای اخطار نگهداشت. **پاک‌سازی بهینه**: `TPP_Activity::cleanup($only=[])` فقط جدول‌های داده‌شده؛ `delete_older_than` حذف تکه‌ای `DELETE … LIMIT 5000` (تا ۱۰۰ دسته/اجرا — قفل کوتاه) و `maybe_cleanup` هر **۱۵ دقیقه** (ثابت `CLEANUP_CHECK_EVERY`).
31. **بازبینی (۱.۲۰.۰)**: کلاس ایستا `TPP_Review` (فایل `includes/class-tpp-review.php`، دسترسی `tpp()->review()`) + جدول `installer_changes` (user_id, user_name — نام کاربر در لحظه ثبت، service_id, action: create|update|delete, source, before_json/after_json — snapshot کامل `{service:row, address:row|null}`, summary, status: pending|kept|reverted, reviewed_by, reviewed_at). ردیابی در `TPP_Services::create()/update()/delete()/apply_bulk()` فراخوانی می‌شود و فقط برای کاربران غیرمدیرِ دارای دسترسی ثبت/ویرایش (`TPP_Review::should_track()` — is_manager معاف است)؛ عملیات‌های source=review/revert ردیابی نمی‌شوند. تصمیم‌ها: `TPP_Review::keep($id,$user)` فقط علامت می‌زند؛ `revert($id,$user)` snapshot قبل را اعمال می‌کند — create → حذف سرویس (با حذف آبشاری تاریخچه)، update → بازنویسی ستون‌های واقعی سرویس+آدرس (فقط ستون‌های موجود جدول — `restorable_columns()`) با محافظ `updated_at`/`version` (اگر سرویس بعد از تغییر دوباره ویرایش شده باشد رد می‌شود — `tpp_newer_changes`)، delete → درج مجدد ردیف با همان id؛ هر بازگردانی رویداد `restore` در تاریخچه با source=review ثبت می‌کند. `changes($args)` گروه‌بندی روزانه + فیلتر user/status + غنی‌سازی آدرس/شماره مجازی؛ `queue()` سرویس‌های دسته ارجاع؛ `assign($service_id,$category,$tags,$user)` از `services()->update(..., source='review')` استفاده می‌کند (تاریخچه می‌سازد، ردیابی نمی‌شود). پاک‌سازی: در `TPP_Activity::cleanup()` هدف `installer_changes` با همان `history_days`. REST: `review/queue|assign|changes|keep|revert` (perm های `tpp_review_queue`/`tpp_review_installer`).
32. **دسته ارجاع پیش‌فرض + منوی سطح‌قابلیت (۱.۲۰.۰)**: `TPP_Categories::REVIEW_LABEL` («ثبت جهت بازبینی و ویرایش یا تأیید مدیریت») + ستون `is_review` روی جدول categories؛ `seed_review_category()` (idempotent — در activate/ارتقا؛ اگر برچسب هم‌نام موجود باشد علامت‌گذاری می‌شود)، `review_category_id()` و گارد حذف (`tpp_review_cat`). پاسخ REST `categories` برای هر آیتم `is_review` برمی‌گرداند. در PWA: منوها با ویژگی `data-cap="cap1,cap2"` (منطق «یا») در `index.html` و حلقه پنهان‌سازی در `applyBootstrap()` (tpp-app.js) بر اساس caps سرور کنترل می‌شوند — الگوی افزودن منوی گیت‌شده: `<a data-cap="tpp_x">` + route در tpp-app.js + view جدید. قالب تاریخ شمسی سمت اپ (`isoToJal`) **ارقام فارسی** برمی‌گرداند — هرگاه با regex ارقام لاتین تجزیه می‌کنید اول `faToEnDigits()` بزنید (باگ تقویم ۱.۲۰.۰ همین بود).
33. **تغییر فیلدها + بروزآوری دیتابیس (۱.۲۱.۰)**: شش فیلد پیش‌فرض (f_internet_status/f_phone_status/f_wifi24_name/f_wifi24_pass/f_wifi5_name/f_wifi5_pass) بازنشسته شدند — `TPP_Fields::RETIRED_LABELS` برچسب‌های رسمی‌شان را نگه می‌دارد و `retire_fields_v1210()` (در maybe_upgrade) تعریفشان را از tpp_fields حذف و slugها را در `tpp_seed_skip` ثبت می‌کند؛ **ستون فیزیکی و داده‌ها حفظ می‌شوند** تا «بروزآوری دیتابیس». فیلد جدید `f_misc_notes` («توضیحات متفرقه» — textarea، جستجوپذیر) جایگزین شد (seed در نصب جدید + seed_missing_fields). موتور بروزآوری: `TPP_Fields::orphan_columns()` (ستون‌های جدول services/addresses که نه ستون سیستمی‌اند و نه فیلد فعال — `protected_columns()` فهرست ثابت‌ها) و `db_update_run($user_id)` — انتقال قالب‌بندی‌شده «🔹 «برچسب»: مقدار» به f_misc_notes هر سرویس (گروه آدرس با join از address_id)، سپس `ALTER TABLE … DROP COLUMN`. REST: `POST tools/db-update` (perm_settings) — اول `TPP_Backup::create('pre_db_update', …)` (شکست پشتیبان = توقف بدون تغییر) و پاسخ شامل گزارش جدول‌ها + `orphan_after` + متادیتای پشتیبان؛ خلاصه اجرا در option `tpp_last_db_update`. **قاعده توسعه:** بعد از حذف هر فیلد پیش‌فرض، slug آن را به RETIRED_LABELS اضافه کنید تا ستونش یتیم شناخته شود و برچسب فارسی در انتقال حفظ شود.
34. **تایید خودکار بازبینی + تنظیم (۱.۲۱.۰)**: تنظیم جدید `review_auto_days` (پیش‌فرض ۷؛ ۰ = غیرفعال؛ clamp ۰..۳۶۵۰ در sanitize + settings_get). `TPP_Review::auto_approve_expired()` (هوک روی کرون روزانه `tpp_daily_cleanup` در TPP_Plugin::boot) تغییرات pending قدیمی‌تر از cutoff را `kept` می‌زند با `reviewed_by=0` — قرارداد نمایش: `reviewed_by=0 && status=kept && reviewed_at` → برچسب «تایید خودکار سیستم» در `TPP_Review::changes()`. **استثنا:** سرویس موجود که `category_id` فعلی‌اش == `TPP_Categories::review_category_id()` است رد می‌شود (تا بازبینی دستی). `TPP_Services::create()` هم اگر دسته ارسال نشده باشد و دسته‌بندی تعریف شده باشد، سرویس جدید را با دسته بازبینی ثبت می‌کند (پیش‌فرض دوطرفه با فرم اپ).
35. **کامبوباکس آجاکسی دسته/تگ + جستجو/صفحه‌بندی categories (۱.۲۱.۰)**: `GET categories` با `kind=category|tag` شکل جدید `{items, total, page, per_page}` می‌دهد (q جستجوی بخشی از عنوان با نرمال‌سازی ارقام؛ per_page ≤ ۱۰۰)؛ بدون kind همان شکل کامل قدیمی `{categories, tags}` — سازگاری کامل با کدهای قدیمی. کلاینت فرم: `bindCatTagCombos()` + `tppComboList()` در tpp-views-form.js (debounce ۳۰۰ms + cancel با seq + انتخاب با mousedown قبل از blur؛ آفلاین: فیلتر روی state.cats). مقدار انتخاب‌ها در hidden inputها (`#svc-category` شناسه دسته، `#svc-tags-ids` JSON آرایه) نگه داشته می‌شود و saveService از همان‌ها می‌خواند. نمای «دسته‌بندی پروژه‌ها» هم با همین شکل، فرم‌های افزودن بالای فهرست + جستجوی زنده + صفحه‌بندی سرور-سمت (۲۰/صفحه) دارد.
36. **دو تقویم مجزای گزارش کار + خروجی با فیلتر کامل (۱.۲۱.۰)**: `GET workreport/days` (perm_access؛ ?from&to&user_id) → `[{date, count}]` سبک از `TPP_Workreport::report_days()` (GROUP BY report_date) — منبع تقویم «روزهای دارای گزارش کار». در اپ `calHtml({kind})` دو حالت دارد (report/activity — هایلایت‌ها و legend مجزا) و `renderRepCal()`/`renderActCal()` با ناوبری ماه مستقل (کش `repDaysCache`/`actDaysCache` به تفکیک user:month). **باگ ریشه‌ای تقویم ۱.۲۰.۰:** `bindCal()` در renderCalendar بدون `onPick` صدا زده می‌شد → کلیک روز بی‌صدا خطا می‌داد — هنگام افزودن تقویم جدید حتماً onPick را پاس دهید. خروجی‌ها: `TPP_REST::export_args()` حالا `category`/`tags` را هم می‌خواند و کلاینت با `collectSearchParams()` (tpp-app.js) همان پارامترهای doSearch را به export/xlsx|pdf|print می‌فرستد — هر فیلتر جدید جستجو را هم به این دو نقطه اضافه کنید.

