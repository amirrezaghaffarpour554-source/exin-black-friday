# بستهٔ ارزیابی Staging نسخهٔ ۱٫۰٫۰

فایل `exin-black-friday-v1.0.0.zip` بستهٔ نصب وردپرس است؛ یک پوشهٔ ریشهٔ `exin-black-friday` دارد و تست‌ها، ابزار توسعه و Credential داخل آن نیستند. ZIP خودکار سورس GitHub برای بارگذاری مستقیم در وردپرس طراحی نشده است.

تطبیق فایل دانلودشده:

```sh
sha256sum -c SHA256SUMS
```

`manifest.json` هش تمام فایل‌های داخل بسته را ثبت می‌کند. ساخت مجدد از سورس:

```sh
python3 scripts/build.py
python3 tests/test_package.py
```

ابتدا روی Staging پشتیبان‌گیری‌شده با WooCommerce فعال نصب کنید. [راهنمای نصب](../docs/installation-fa.md)، [نتیجهٔ تست‌ها](../docs/testing.md)، [محدودیت‌ها](../docs/limitations.md) و [چک‌لیست پیش از Production](../docs/release-checklist.md) همراه سورس هستند. روی سایت زنده انتشار انجام نشده است.
