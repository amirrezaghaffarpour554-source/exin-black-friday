# گزارش تست و شواهد نسخهٔ ۱٫۰٫۰

این گزارش حاصل اجرای واقعی همین مأموریت برای تحویل به **Staging** است. هیچ تستی روی `digiexin.com` اجرا نشده و هیچ داده یا تنظیم Production تغییر نکرده است. مجموعه‌های نهایی زیر صفر شکست ثبت‌شده دارند؛ موارد محیط مقصد در پایان گزارش آمده‌اند.

## محیط اجرا

| مؤلفه | نسخه و وضعیت اجراشده |
| --- | --- |
| WordPress / WooCommerce | 6.9.4 / 10.3.0 واقعی |
| PHP یکپارچه | 8.3.31 |
| PHP دامنه و Syntax | 8.1.34 و 8.3.36 |
| MariaDB | 11.4.13، دیتابیس مستقل `exin_test` |
| سفارش | HPOS فعال |
| Elementor | 3.34.0 واقعی؛ ذخیره و بازسازی صفحه بررسی شد |
| مرورگر | Chromium و Playwright 1.62.1؛ دسکتاپ 1440×1000 و موبایل 390×844 |
| کش | حافظهٔ هر درخواست وردپرس؛ Redis و CDN فعال نبودند |
| Schema | شمارهٔ `2`؛ ارتقای `1 → 2` با حفظ Snapshot بررسی شد |

WooCommerce 11.3.0 در آماده‌سازی محیط نیازمند WordPress 7.0 بود؛ سازگاری آن با این محیط ادعا نمی‌شود. حداقل‌های فنی PHP 8.1، وردپرس 6.5 و ووکامرس 8.2 جایگزین ماتریس اجراشدهٔ بالا نیستند؛ تمام ترکیب‌های نسخه‌ها اجرا نشده‌اند.

## نتیجه‌های نهایی

| مجموعه | تعداد واقعی | نتیجه و شاهد |
| --- | --- | --- |
| Unit مستقل دامنه | ۱۷ سناریو و ۳٬۲۰۷ assertion در هر نسخهٔ PHP | دو اجرای موفق روی 8.1 و 8.3؛ [JSON](reports/unit-static.json) |
| یکپارچهٔ اصلی | ۳۸ سناریو، ۱۵۰ assertion | ۳۸ موفق؛ [JSON](reports/integration.json) |
| Retry، Upgrade، Pagination و Retention | ۲۸ بررسی | ۲۸ موفق؛ [JSON](reports/retry.json) |
| Elementor واقعی | ۲۰ بررسی | ۲۰ موفق؛ [JSON](reports/elementor.json) |
| چرخهٔ نصب و حذف | ۱۹ بررسی | ۲ وابستگی، ۱۰ فعال‌سازی/ارتقا/حفظ داده، ۷ حذف تمیز؛ [JSON](reports/lifecycle.json) |
| Checkout واقعی Store API | ۱۵ بررسی | ۱۵ موفق؛ [JSON](reports/store-api.json) |
| مرورگر مدیریت | ۱۸ بررسی | ۱۸ موفق، بدون استثنای JavaScript؛ [JSON](reports/browser.json) |
| نصب ZIP استخراج‌شده | ۱۷ بررسی | ۱۵ نصب و عملیات + ۲ نبود وابستگی؛ [JSON](reports/package-install.json) |
| PHP Syntax | ۴۳ فایل در هر نسخه | PHP 8.1 و 8.3 بدون خطای نحوی |
| WPCS | ۳۰ فایل PHP اجرایی | WordPress standard، صفر خطا و صفر هشدار |
| Syntax سایر ابزارها | ۳ JavaScript و ۴ Shell | موفق |
| بسته‌بندی | CRC، ریشه، تطبیق سورس، حذف فایل توسعه و تکرارپذیری | `tests/test_package.py` |

«سناریو»، «assertion» و «بررسی» با هم جمع نشده‌اند. گزارش‌های JSON فاقد اطلاعات واقعی مشتری و Credential هستند.

## دامنهٔ بررسی‌شده

محاسبات مالی شامل کف برابر پایه، ورودی نامعتبر، اعشار و مبالغ بزرگ، Overflow، سه سیاست گردکردن، خطی و تصاعدی، یک روز، روز اول و آخر، تهران و DST هستند. بردارهای مرجع با محاسبات مستقل صحیح Python مقایسه می‌شوند.

تست واقعی ووکامرس شامل Simple و Variation، Sale قبلی و زمان‌بندی‌شده، قیمت پویا، Job دیررس و تکراری، توقف و ادامه، نسخهٔ قدیمی، قفل در اتصال مستقل و از دست رفتن آن، Intent قطع‌شده، خرابی Audit، Restore و حفظ تغییر دستی است. Worker واقعی Action Scheduler برای کف روز آخر، پایان طبیعی و Retry اجرا شده است. بودجه و موعد Retry در برابر Watchdog، Callback تکراری، قطع Worker و خرابی Audit تست شده‌اند. Retention، شمارش Removed و Upgrade نیز بررسی شده‌اند.

Classic checkout با `WC_Checkout::create_order` سفارش واقعی ساخته است. تغییر روزانهٔ قیمت میان محاسبهٔ سبد و ایجاد سفارش، انتساب قلم منتخب را حذف نمی‌کند و مبلغ ذخیره‌شده را بازنویسی نمی‌کند. گزارش HPOS، سفارش چندقلمی و مختلط، Failed/Cancelled، بازگشت تکراری و Refund واقعی بررسی شده‌اند.

Store API از Controller واقعی ووکامرس و `rest_do_request` استفاده می‌کند: قیمت سبد، عدم افشای دادهٔ خصوصی، Flat Rate در حدنصاب، کوپن واقعی و حذف آن، تغییر تعداد، Checkout با **BACS آفلاین آزمایشی**، Metadata، کاهش استاندارد موجودی و شمارش یکتای سفارش تأیید شده‌اند. این اجرا شامل رابط مرورگری Blocks یا درگاه آنلاین نیست.

مرورگر مدیریت، REST در پیوند یکتای پیش‌فرض، ذخیره تنظیمات، جست‌وجوی SKU، هفت قیمت پیش‌نمایش، افزودن، ویرایش کف و Featured، شروع کمپین با زمان گذشته، توقف، ادامه، قفل مالی، عیب‌یابی و موبایل را اجرا می‌کند. ثبت محصول در گردش اسکریپت‌شده حدود **۱٫۵۴ ثانیه** طول کشید؛ این اندازه‌گیری زمان کار انسان نیست.

ZIP در وردپرس تازه و پیشوند دیتابیس جدا نصب شده است. Reflection مسیر کلاس را بررسی کرده تا اجرای کد از ZIP استخراج‌شده، مستقل از Mount سورس، ثابت شود. فعال‌سازی، HPOS، افزودن، اعمال قیمت، شورت‌کد، Restore تکراری، حفظ موجودی، غیرفعال‌سازی/فعال‌سازی و نبود WooCommerce/Elementor بررسی شده‌اند. تست حذف در هر اجرا پیشوند تازه می‌سازد؛ دادهٔ مالکیت‌دار قبلی پاک نمی‌شود.

## Benchmark واقعی

| معیار | ۱۰۰ محصول | ۱٬۰۰۰ محصول |
| --- | --- | --- |
| ساخت Fixture | ۳٫۸۲۲۷ ثانیه | ۴۱٫۲۶۱۴ ثانیه |
| اعمال قیمت همهٔ محصولات | ۲٫۱۱۹۶ ثانیه | ۲۵٫۲۷۵۶ ثانیه |
| Batch با اندازهٔ ۲۵ | ۴ | ۴۰ |
| کندترین Batch | ۰٫۵۴۱۸ ثانیه | ۱٫۳۸۴۴ ثانیه |
| SQL اعمال قیمت | ۱۱٬۰۷۵ | ۱۱۰٬۷۴۱ |
| Query صفحهٔ نخست / SQL | ۴٫۸ ms / ۴ | ۴۷٫۲ ms / ۴ |
| رندر شورت‌کد و قالب / SQL | ۱۵٫۰ ms / ۴ | ۱۶٫۴ ms / ۵ |
| پاسخ HTTP مستقل | ۱۶۲٫۴ ms، کد 200 | ۳۲۱٫۹ ms، کد 200 |
| حجم HTML | ۱۲۹٬۰۷۶ بایت | ۱۲۹٬۰۷۹ بایت |
| اوج حافظهٔ فرایند Fixture/Batch | ۱۳۶٬۱۱۸٬۲۷۲ بایت | ۱۴۸٬۷۰۱٬۱۸۴ بایت |

[JSON کامل Benchmark](reports/benchmark.json) تأیید قیمت و نام محصول منتخب را هم ثبت می‌کند. صفحه در هر دو حالت **۱۲ کارت و Pagination** دارد. Query و رندر CLI از حافظهٔ گرم همان فرایند استفاده می‌کنند؛ HTTP درخواست مستقل با کش حافظهٔ تازه و Buffer دیتابیس گرم است. این زمان شامل Asset، LCP، هزار کارت هم‌زمان یا تأخیر اینترنت نیست. Batch شامل CRUD، قفل و ثبت پایدار است؛ هزینهٔ انتظار صف جداست. بار SQL باید روی منابع Staging ارزیابی شود.

## اجرای مجدد

تست‌های دیتابیس فقط در Runtime محلی اسکریپت‌ها و به‌ترتیب اجرا شوند؛ Suiteهای تغییر‌دهندهٔ کمپین جاری هم‌زمان اجرا نشوند. Docker و Compose، PHP 8.1+، Composer، Python 3.9+ و برای مرورگر Node/Playwright و Chromium لازم‌اند. Imageها به Digest پین شده‌اند؛ Credentialها فقط Fixture محلی هستند.

```sh
composer install
composer lint
php tests/unit/run.php
bash scripts/test-runtime.sh up
bash scripts/test-runtime.sh wp eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/retry-backoff.php
bash scripts/test-runtime.sh test
bash scripts/test-runtime.sh elementor
bash scripts/test-runtime.sh wp eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/elementor.php
bash scripts/test-lifecycle.sh
bash scripts/test-store-api.sh
npm ci
CHROMIUM_PATH=/usr/bin/chromium npm run test:browser
bash scripts/test-runtime.sh wp eval-file /var/www/html/wp-content/plugins/exin-black-friday/tests/integration/benchmark.php
python3 tests/test_package.py
bash scripts/test-package-install.sh work/dist/exin-black-friday-v1.0.0.zip
```

در این مأموریت PHP از Docker اجرا شد. دسترسی Composer به `api.github.com` محدود بود؛ همان ابزارهای قفل‌شدهٔ `composer.lock` از Git رسمی در Reference مشخص خوانده و PHPCS واقعی اجرا شد. وابستگی توسعه در ZIP نیست. نام فایل PSR-4 عمداً از Sniff نام فایل استثنا و Capability ووکامرس به Ruleset شناسانده شده است. Ignoreهای محدود SQL فقط برای جدول‌های خود افزونه، خواندن مالی تازه و Queryهای محدود و مستند هستند.

## موارد مشاهده‌شده و مرز اعتبار

Elementor 3.34.0 روی PHP 8.3 یک Deprecated در `includes/api.php:176` ثبت می‌کند. در نصب تازهٔ CLI، Notice مربوط به ترجمهٔ زودهنگام WooCommerce و `HTTP_HOST` وردپرس نیز دیده شد؛ Assertionها موفق بودند. Fatal یا Deprecated منتسب به EXIN در اجرای نهایی ثبت نشد.

خرید مرورگری کامل با درگاه آنلاین و بازگشت واقعی پرداخت، قالب و CSS مقصد، Woodmart، Redis، CDN، افزونه‌های تخفیف و تقسیم بستهٔ واقعی، Cron میزبان، آزمون نفوذ مستقل و تمام ترکیب‌های نسخه‌ها در مقصد اجرا نشده‌اند. [چک‌لیست انتشار](release-checklist.md) باید در Staging مشابه Production تکمیل شود. کنترل عمومی CDN و شناسایی همهٔ موتورهای قیمت پویا ارائه نمی‌شود؛ [محدودیت‌ها](limitations.md) مرز دقیق را توضیح می‌دهد.
