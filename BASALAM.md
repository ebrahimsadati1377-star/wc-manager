# یکپارچه‌سازی باسلام با WC Manager

این ماژول، WooCommerce را منبع اصلی اطلاعات محصول نگه می‌دارد و محصولات را از WC Manager به غرفه باسلام همگام می‌کند.

## راه‌اندازی

1. نسخه جدید WC Manager را Deploy کنید.
2. با کاربر مدیر وارد پنل شوید و از منوی **تنظیمات باسلام** وارد `basalam.php` شوید.
3. `Vendor ID` غرفه را وارد کنید.
4. یکی از روش‌های احراز هویت را انتخاب کنید:
   - Personal Access Token
   - Client Credentials (پیشنهادی برای ارتباط Server-to-Server)
5. Scopeهای موردنیاز محصول را تنظیم کنید؛ مقدار پیش‌فرض:
   - `vendor.product.read`
   - `vendor.product.write`
6. روی **تست اتصال** بزنید.
7. در همان صفحه، دسته‌های WooCommerce را به دسته‌های باسلام نگاشت کنید.
8. از منوی **سینک باسلام** برای ارسال/به‌روزرسانی محصولات استفاده کنید.

## سیاست داده

- WooCommerce منبع اصلی نام، توضیح، SKU، قیمت، موجودی و variationها است.
- WC Manager شناسه‌های متناظر WooCommerce و باسلام را در جداول mapping محلی نگه می‌دارد.
- سینک idempotent است؛ اگر داده Woo از آخرین سینک تغییر نکرده باشد، درخواست تکراری skip می‌شود مگر Force Sync اجرا شود.
- جدول‌های mapping در اولین استفاده با `CREATE TABLE IF NOT EXISTS` ایجاد می‌شوند.

## تنظیمات مهم

- `basalam_price_multiplier`: ضریب تبدیل قیمت Woo به باسلام. اگر واحد یکسان است `1`؛ اگر Woo تومان و API مقصد ریال باشد `10`.
- `basalam_weight_multiplier`: ضریب تبدیل وزن Woo به واحد موردنیاز باسلام. پیش‌فرض `1000`.
- `basalam_preparation_days`: زمان آماده‌سازی پیش‌فرض.
- `basalam_default_package_weight`: وزن بسته‌بندی پیش‌فرض.
- `basalam_unmanaged_stock`: موجودی‌ای که برای محصول Woo با Manage Stock خاموش ولی وضعیت instock ارسال می‌شود.
- `basalam_sync_images`: هنگام ایجاد محصول، تصاویر Woo را روی باسلام آپلود می‌کند.
- `basalam_max_images`: سقف تصاویر منتقل‌شده برای هر محصول.

## محصولات متغیر

در ایجاد اولیه محصول variable، variationهای Woo همراه محصول به باسلام ارسال می‌شوند. سپس mapping variationها ابتدا با SKU و در صورت نیاز با امضای ویژگی/مقدار انجام می‌شود.

برای محصولی که قبلاً در باسلام ساخته شده، قیمت/موجودی/SKU variationهای mapped با API رسمی باسلام به‌روزرسانی می‌شود. ایجاد یا حذف variation جدید روی یک محصول موجود به‌صورت خودکار حدس زده نمی‌شود؛ اگر variation جدیدی mapping نداشته باشد، سینک وضعیت هشدار می‌گیرد تا رفتار اشتباه روی محصول زنده رخ ندهد.

## تصاویر و امنیت

تصاویر Woo ابتدا به فایل موقت سرور دانلود و سپس با Upload API باسلام ارسال می‌شوند. برای کاهش ریسک SSRF:

- فقط HTTP/HTTPS پذیرفته می‌شود.
- فقط پورت 80/443 پذیرفته می‌شود.
- IPهای private/reserved رد می‌شوند.
- DNS به IP عمومی validate‌شده pin می‌شود.
- redirect برای دانلود تصویر دنبال نمی‌شود.
- سقف داخلی دانلود هر تصویر 12MB است.

## ChatGPT API

OpenAPI: `https://manage.bajistyle.ir/openapi-chatgpt.yaml`

Actionهای باسلام:

- `listBasalamProducts`
- `getBasalamProduct`
- `syncWooProductToBasalam`
- `syncWooProductsToBasalamBatch` (حداکثر 20 محصول در هر درخواست)

توکن باسلام مستقیماً در اختیار ChatGPT قرار نمی‌گیرد؛ ChatGPT فقط با Bearer Token مستقل WC Manager به API مدیریت وصل می‌شود.

## محدوده نسخه فعلی

این نسخه روی مدیریت کاتالوگ تمرکز دارد:

- محصول ساده و متغیر
- قیمت و موجودی
- SKU
- دسته‌بندی و mapping
- تصاویر هنگام ایجاد اولیه
- mapping محصول و variation
- سینک تک‌محصول و batch محدود
- API خواندن محصولات باسلام برای ChatGPT

موارد زیر برای فاز بعدی باقی مانده‌اند:

- Discount API مستقل برای حفظ مفهوم Regular/Sale Price باسلام
- Webhook و سفارش‌های باسلام و کم‌کردن موجودی Woo پس از فروش باسلام
- Shipping Service
- mapping خودکار attributeهای اجباری دسته‌های باسلام
- ایجاد/حذف variation روی محصول باسلامی که قبلاً ساخته شده است
