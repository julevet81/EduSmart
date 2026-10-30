# دليل التشغيل

## 1. النشر لأول مرة
1. خادم بـ PHP 8.2+ (الامتدادات: mbstring, intl, bcmath, sodium, pdo_mysql) وMySQL 8 أو PostgreSQL، وخلفه Nginx/Apache يشير إلى `public/`.
2. `composer install --no-dev --optimize-autoloader`
3. انسخ `.env.production.example` إلى `.env` واملأ الأسرار، ثم `php artisan key:generate`.
4. `php artisan migrate --force`
5. `php artisan tenants:sync-roles` (بعد أي تحديث يضيف صلاحيات جديدة).
6. `php artisan platform:create-admin you@domain.dz "الاسم"` ثم فعّل التحقق الثنائي لحسابك من `POST /api/v1/platform/auth/two-factor/setup` (مطلوب للدخول بصفة الدعم).
7. `php artisan config:cache && php artisan route:cache && php artisan event:cache`
8. `php artisan app:preflight` — يجب ألا يظهر أي `FAIL`. شغّله بعد كل نشر (`--strict` يعامل التحذيرات كأخطاء).

## 2. المجدول (cron) — سطر واحد
```
* * * * * cd /var/www/edusmart && php artisan schedule:run >> /dev/null 2>&1
```
المهام المسجلة في `routes/console.php`:

| الأمر | التوقيت | الوظيفة |
|---|---|---|
| `messages:dispatch` | كل دقيقة | إرسال الرسائل في الطابور (SMS/WhatsApp/بريد) |
| `messages:alerts` | 08:00 | تنبيهات الغياب وتذكيرات السداد (مرة أسبوعياً لكل حالة) |
| `subscriptions:sync` | 00:10 | تحديث تسمية حالة الاشتراك (الوصول نفسه يُحسب من التواريخ) |
| `auth:prune-refresh-tokens` | 03:30 | حذف رموز التجديد التي انتهت |
| `db:backup` | 02:30 | نسخ احتياطي، فقط إن كان `BACKUP_ENABLED=true` |

التوقيت يتبع `APP_TIMEZONE` (اضبطه على `Africa/Algiers`). للعرض: `php artisan schedule:list`.

## 3. النسخ الاحتياطي والاسترجاع
```
php artisan db:backup            # ينشئ ملف gzip ويحذف ما تجاوز مدة الاحتفاظ
php artisan db:restore storage/app/backups/<file> --force
```
- الملفات في `storage/app/backups` بصلاحية 0600، ومعها ملف `.sha256` يتحقق منه الاسترجاع.
- مع `BACKUP_PASSPHRASE` تُشفَّر النسخ (XChaCha20-Poly1305). **من فقد العبارة فقد النسخ**، فاحفظها خارج الخادم.
- الاحتفاظ: `BACKUP_KEEP_DAYS` (14) مع بقاء أحدث `BACKUP_KEEP_MIN` (3) دائماً.
- **انسخ المجلد إلى مكان آخر** (جهاز أو تخزين سحابي). نسخة على القرص نفسه ليست نسخة احتياطية.
- يحتاج MySQL إلى `mysqldump` و`mysql` في PATH (أو `MYSQLDUMP_PATH` / `MYSQL_CLIENT_PATH`). كلمة المرور تمر عبر متغير بيئة لا عبر سطر الأوامر.
- **جرّب الاسترجاع على قاعدة اختبار مرة كل شهر**؛ نسخة لم تُجرَّب لا يُعتمد عليها.
- بعد استرجاع نسخة قديمة شغّل `php artisan migrate --force`.

## 4. توثيق الـ API
`php artisan api:openapi` يكتب `docs/openapi.json` (OpenAPI 3.1) من المسارات المسجلة، فلا يتأخر عن الكود. يتضمن لكل مسار: الطريقة، المعاملات، الصلاحية المطلوبة (`x-permission`)، ترويسة `X-Tenant`، المصادقة، وحقول الطلب حيث يستعمل المتحكم FormRequest. استورده في Postman أو Swagger UI أو مولّد عملاء. الحقول المتحقَّق منها داخل المتحكم مباشرة لا تظهر. يُنتج CI الملف في كل دفعة كـ artifact.

## 5. الأمان في الإنتاج
- ترويسات أمان على كل رد (`nosniff`، `no-store`، `frame-ancestors none`، وHSTS عبر https).
- CORS: عيّن `CORS_ALLOWED_ORIGINS` بأصول الواجهة فقط. ممنوع `*`.
- خلف موازن/وكيل: عيّن `TRUSTED_PROXIES` وإلا ظهر عنوان الوكيل في حدود المعدّل وسجل التدقيق.
- لا SQLite، ولا `APP_DEBUG=true`، ولا `CACHE_STORE=array` (تُخزَّن فيه حدود المعدّل وتحديات التحقق الثنائي).
- غيّر `MESSAGING_*_DRIVER` من `log` إلى مزوّد حقيقي.

## 6. المراقبة
- `GET /up` فحص الحياة لموازن الحمل.
- سجل الأخطاء في `storage/logs`؛ اجعل `LOG_LEVEL=warning`.
- راقب فشل `db:backup` (يرجع كود خروج 1 ويسجل الخطأ).

## 7. بيانات التجربة (للتطوير فقط)
```
php artisan migrate:fresh --seed --seeder=DemoSeeder
```
ينشئ مشرف منصة `root@demo.test` وثلاث مؤسسات (`ecole-nour` باشتراك مدفوع، `centre-amal` في التجربة، `ecole-expiree` للقراءة فقط) بكلمة مرور واحدة هي `Demo-Passw0rd!2026`. حسابات كل مؤسسة: `owner@` و`manager@` و`secretary@` و`accountant@` و`teacher@` و`parent@` و`student@` متبوعة بـ `<slug>.demo`. يرفض العمل في الإنتاج.
