# گزارش تست و شواهد نسخهٔ ۱٫۰٫۱

نتیجهٔ اجرای ایزوله در ۲۰۲۶/۱۱/۱۷ برای تحویل به بازبینی و Staging است. هیچ اتصال، کپی دیتابیس یا تغییر Production انجام نشده است. تست‌ها از دادهٔ ساختگی استفاده می‌کنند. «Passed» فقط به اجرای ثبت‌شده اشاره دارد؛ آماده‌بودن انتشار روی فروشگاه واقعی باید جدا تأیید شود.

## محیط اجراشده

| مؤلفه | نسخهٔ واقعی |
| --- | --- |
| WordPress / WooCommerce | 7.1.4 / 11.3.0 |
| PHP / MariaDB | 8.2.35 / 11.8.6 |
| سفارش | HPOS فعال |
| Elementor | 3.34.0 رسمی |
| مرورگر | Chromium / Playwright 1.62.1؛ دسکتاپ 1440×1000 و موبایل 390×844 |
| وب و کش | HTTP محلی PHP؛ کش حافظهٔ درخواست وردپرس؛ Redis فعال نبود |
| Schema | `3`؛ ارتقا از ZIP واقعی ۱٫۰٫۰ با Schema `2` |

Docker daemon در این محیط در دسترس نبود؛ اجرای واقعی با باینری PHP استخراج‌شده از تصویر رسمی، سورس رسمی WordPress/WooCommerce و MariaDB مستقل در فضای RAM خصوصی انجام شد. این اجرا Nginx یا Redis را شبیه‌سازی یا تأیید نمی‌کند. [نسخه‌ها و Hash منابع](reports/v1.0.1/runtime.json) و [شرح اجرای جایگزین](https://github.com/amirrezaghaffarpour554-source/exin-black-friday/blob/codex/exin-black-friday-1.0.1/scripts/runtime/README.md) ثبت شده‌اند.

## Passed

| گروه | نتیجهٔ واقعی | شاهد |
| --- | --- | --- |
| دامنهٔ قیمت و زمان | ۱۷ سناریو، ۳٬۲۰۷ assertion | [JSON](reports/v1.0.1/unit-static.json) |
| تاریخ شمسی و پول PHP | ۱۰ سناریو، ۳٬۴۶۰ assertion | [JSON](reports/v1.0.1/unit-static.json) |
| تقویم/پول JavaScript | ۶ سناریو، ۳٬۴۴۲ assertion؛ ۴۸۲ بردار مستقل ICU | [JSON](reports/v1.0.1/unit-static.json) |
| محاسبات سقف ارسال | ۱٬۵۰۷ بررسی | [JSON](reports/v1.0.1/unit-static.json) |
| رگرسیون مالی/بازیابی/امنیت پایه | ۳۹ سناریو، ۱۵۴ assertion | [JSON](reports/v1.0.1/integration.json) |
| Retry، Pagination، Retention و Schema | ۲۸ بررسی | [JSON](reports/v1.0.1/retry.json) |
| پیش‌نویس اتمیک، Migration و گزارش HPOS | ۱۲ سناریو، ۸۱ assertion | [JSON](reports/v1.0.1/core.json) |
| ارسال واقعی Woo، مالیات و کش Session | ۱۴ سناریو، ۵۲ assertion | [JSON](reports/v1.0.1/shipping.json) |
| Picker، موجودی، رتبه‌بندی و قالب Woo | ۱۴ سناریو، ۷۲ assertion | [JSON](reports/v1.0.1/catalog.json) |
| مرورگر مدیریت با WordPress واقعی | ۳۸ بررسی؛ بدون استثنای JavaScript | [JSON](reports/v1.0.1/admin-wordpress.json) |
| مرورگر مدیریت با REST ساختگی | ۴۲ بررسی؛ مستقل از تست واقعی بالا | [JSON](reports/v1.0.1/admin-mocked-browser.json) |
| تقویم و Cursor در Chromium | ۲۳ بررسی؛ اجرای ۲۰۲۶/۱۱/۱۶ | [JSON](reports/v1.0.1/helper-browser.json) |
| Checkout از Controller واقعی Store API | ۱۹ بررسی؛ BACS آفلاین آزمایشی | [JSON](reports/v1.0.1/store-api.json) |
| ارتقا از ZIP واقعی ۱٫۰٫۰ | ۲۹ بررسی در دو فرایند PHP مستقل | [JSON](reports/v1.0.1/upgrade.json) |
| Elementor واقعی و صفحهٔ ذخیره‌شده | ۲۰ بررسی | [JSON](reports/v1.0.1/elementor.json) |
| کیفیت سورس | Syntax: ۶۳ PHP، ۹ JS، ۱۱ Shell، ۷ Python؛ WPCS روی ۴۱ فایل اجرایی با صفر خطا/هشدار | [JSON](reports/v1.0.1/quality.json) |

سناریو، assertion و بررسی با هم جمع نشده‌اند. گزارش‌های [آرشیو ۱٫۰٫۰](reports/v1.0.0/README.md) شاهد نسخهٔ جدید نیستند. Benchmark قبلی ۱۰۰/۱۰۰۰ محصول در این انتشار تکرار نشده است.

## دامنهٔ نتیجه‌ها

تست مالی، کف روز آخر، گردکردن دقیق، محصول ساده/Variation، Sale قبلی، Job دیررس و تکراری، قفل اتصال، Intent نیمه‌تمام، Retry محدود و Restore پس از تغییر بیرونی را پوشش می‌دهد. خرابی Audit باعث بازگشت کامل پیش‌نویس می‌شود. قطع واقعی اتصال پیش از UPDATE دوم نیز تست شد: ردیف اول برگشت، ردیف دوم خارج از تراکنش بازاجرا نشد و نسخه/قیمت/موجودی حفظ شدند. ذخیرهٔ اتمیک به `wpdb` استاندارد و InnoDB نیاز دارد؛ [محدودیت درایور جایگزین](limitations.md) ثبت شده است.

تست ارسال، حداقل صفر همراه با قلم واقعی کمپین، حدنصاب پس از کوپن، تغییر تعداد/حذف، یک سقف مشترک چند Package، نرخ جایگزین، روش حذف‌شده و کش واقعی Session ووکامرس را بررسی می‌کند. Fixture مالیات ابتدا وجود نرخ واقعی ۱۰٪ را تأیید می‌کند: پایهٔ ارسال ۲۰ و مالیات ۲، پس از یارانه به سهم مشتری ۵ و مالیات ۰٫۵ می‌رسد. روش/پایه/مالیات نامطمئن بدون یارانه می‌ماند.

تست مرورگر واقعی با Cookie و nonce وردپرس، بدون Mock اجرا شد: تقویم شمسی و ساعت ۲۴ساعته، Autosave، تب‌ها، فیلتر هم‌زمان دسته/برند/ویژگی، ۲۸ محصول در دو Batch برابر ۲۵+۳، کف مستقل تومان/ریال، Variation، Drag & Drop، سیاست گزارش سفارشی، رد فعال‌سازی بدون تأیید و توقف/ادامه. مقدار Precision که WordPress به‌شکل رشته می‌فرستد نیز اصلاح شد. PHP حفظ قیمت و موجودی هر ۳۱ محصول Fixture را تأیید کرد. زمان گردش خودکار تنظیم اصلی ۴٫۱۲۶ ثانیه و ثبت گروه ۹٫۰۵۷ ثانیه بود؛ این زمان کار انسان یا Benchmark فروشگاه واقعی نیست. تصاویر دسکتاپ و موبایل بازبینی شدند؛ RTL خوانا و بدون سرریز افقی صفحه است.

Checkout شامل سفارش مختلط، قیمت ذخیره‌شدهٔ ثابت، انتساب تاریخی، Refund و HPOS است. Store API اتمام موجودی پس از ورود به سبد را با خطای واقعی Checkout رد کرد و مبلغ سفارش Pending را تغییر نداد؛ سپس با بازیابی موجودی از CRUD استاندارد، خرید BACS آزمایشی کامل شد. این اجرا رابط مرورگری Blocks یا درگاه آنلاین نیست.

ارتقا کد واقعی ZIP قبلی را بارگذاری و دادهٔ مالکیت‌دار، Intent قطع‌شده و سفارش HPOS می‌سازد؛ فرایند بعدی نسخهٔ جدید را اجرا می‌کند. Snapshot/expected/intent/retry_state، قیمت/موجودی و سفارش حفظ می‌شوند؛ Restore و تکرار آن نیز بررسی شدند. برگهٔ واقعی `21861` و تنظیمات فروشگاه تغییر نکرده‌اند.

## بستهٔ نهایی

ZIP نصب‌پذیر، SHA256 و Manifest در [پروندهٔ انتشار ۱٫۰٫۱](https://github.com/amirrezaghaffarpour554-source/exin-black-friday/tree/codex/exin-black-friday-1.0.1/releases/v1.0.1) قرار دارند. ساخت تکرارپذیر، CRC، ریشهٔ یکتا، تطبیق بایت‌به‌بایت با سورس و حذف فایل‌های توسعه با `tests/test_package.py` بررسی می‌شوند.

شاهد نصب **همان ZIP نهایی** شامل Hash آرشیو، تطبیق همهٔ فایل‌های استخراج‌شده، Reflection مسیر کلاس، فعال‌سازی، Schema/HPOS و غیرفعال‌سازی/فعال‌سازی مجدد در [package-install.json](https://github.com/amirrezaghaffarpour554-source/exin-black-friday/blob/codex/exin-black-friday-1.0.1/releases/v1.0.1/package-install.json) کنار بسته ثبت می‌شود. این شاهد خارج از ZIP نگه‌داری می‌شود تا ثبت Hash، خود بسته را تغییر ندهد.

## Failed و Not Run

- **Failed در اجرای نهایی ثبت‌شده: صفر.** خطاهای کشف‌شدهٔ Precision رابط مدیریت، اتصال مجدد تراکنش و Fixture مالیات اصلاح و بررسی مرتبط اجرا شد.
- **Not Run:** قالب/افزونهٔ واقعی Woodmart و WP Rocket، Redis، Nginx، CDN/Full Page Cache مقصد، درگاه آنلاین، رابط مرورگری خرید Blocks، Cron میزبان، کپی کامل فروشگاه و انتشار Production.
- Benchmark تازهٔ ۱۰۰/۱۰۰۰ محصول، همهٔ ترکیب‌های نسخه‌ها و چرخهٔ کامل Uninstall نسخهٔ جدید اجرا نشده‌اند. موارد مقصد در [چک‌لیست انتشار](release-checklist.md) باز می‌مانند.

Elementor 3.34.0 در `includes/api.php:176` یک Deprecated مربوط به تبدیل false به array روی PHP 8.2 دارد. Notice ترجمهٔ زودهنگام WooCommerce و `HTTP_HOST` در CLI نیز دیده شد. در Fixture قطع عمدی اتصال، Noticeهای مورد انتظار escaping دیتابیس ثبت شدند. این موارد با خطای افزونه در گردش عادی یکسان شمرده نشده‌اند؛ Fatal غیرمنتظرهٔ EXIN در اجراهای نهایی ثبت نشد.

## اجرای مجدد ضروری

تست‌های تغییر‌دهندهٔ کمپین یا لینک نسخهٔ افزونه را سریالی و فقط در محیط ساختگی اجرا کنید. مسیر معمول به Docker/Compose، Composer و Node/Chromium نیاز دارد؛ این مسیر با اجرای Native ثبت‌شده اشتباه نشود.

```sh
bash scripts/test-v101-runtime.sh up
composer test:unit
composer test:integration
EXIN_WP_BASE_URL=http://localhost:8891 npm run test:browser:wordpress
bash scripts/test-v101-runtime.sh upgrade
python3 tests/test_package.py
```

دستور کاربر در پایان، تمرکز بر بررسی‌های ضروری و پرهیز از گسترش تست بود؛ پس از اصلاح خطاهای واقعی، ماتریس اختیاری یا Benchmark تازه اجرا نشد. تأیید نهایی سایت واقعی و استقرار، فاز جداگانه با تأیید مدیر است.
