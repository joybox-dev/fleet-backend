# خطة نقل التارغت لمستوى الموظف واحتساب العمولات التراكمية التاريخية

تحديث البنية التحتية للنظام لنقل فكرة **التارغت والعمولات الأساسية والمميزة** من مستوى **العقد** لتصبح في **ملف الموظف (السائق) مباشرة**، مع معالجة الاحتساب التشغيلي ليكون تراكمياً ومرتباً زمنياً (Chronological) على مدار الشهر وتوزيع تكاليفه ومصاريفه على العقود بدقة متناهية بالمليم.

---

## 🙋‍♂️ مراجعة العميل المطلوبة (User Review Required)

> [!IMPORTANT]
> **مقاربة الاحتساب التراكمي المحاسبي الدقيق:**
> للتأكد من أن السائق (مثلاً أحمد) يتم احتساب عمولته بناءً على تسلسل تاريخي دقيق، قمنا ببناء آلية استعلام تسلسلي للمبيعات اليومية (Daily Logs) مرتبة باليوم والمستند، وتخزين مصاريف العمولة في كل لوق باسم `driver_commission`. هذا سيجعل تقارير أرباح العقود دقيقة تماماً ومطابقة لسيناريو العقد X والعقد Y بالقرش الواحد.

---

## 🔎 الأسئلة المفتوحة (Open Questions)

* لا توجد أسئلة حالياً، فالسيناريو الحسابي الذي قمت بشرحه واضح جداً ومكتمل الأركان رياضياً ومحاسبياً.

---

## 🛠️ التغييرات المقترحة (Proposed Changes)

### 1️⃣ قاعدة البيانات والهجرات (Database & Migrations)

#### [NEW] [2026_05_30_160000_add_target_fields_to_employees_and_daily_logs.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/database/migrations/2026_05_30_160000_add_target_fields_to_employees_and_daily_logs.php)
* إضافة حقول التارغت إلى جدول الموظفين `employees`:
  * `target_orders_monthly` (integer, nullable) - التارغت المستهدف.
  * `base_commission_rate` (decimal: 8,3, nullable) - العمولة الأساسية.
  * `premium_commission_rate` (decimal: 8,3, nullable) - العمولة المميزة.
* إضافة حقل حساب التكاليف المباشرة لجدول `daily_logs`:
  * `driver_commission` (decimal: 8,3) - قيمة بونص السائق المحسوبة لهذا اليوم تحديداً وتعتبر تكلفة مباشرة على العقد.

---

### 2️⃣ موديولات النظام الخلفية (Backend Models & Controllers)

#### [MODIFY] [Employee.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Models/Employee.php)
* إضافة الحقول الجديدة إلى `$fillable` لتمكين الإدخال الآمن.
* إضافة قوالب الصب `$casts` ليكون التارغت عدداً صحيحاً والعمولات أرقاماً عشرية بدقة 3 خانات.

#### [MODIFY] [DailyLog.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Models/DailyLog.php)
* إضافة `driver_commission` إلى `$fillable` والـ `$casts`.

#### [MODIFY] [EmployeeController.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Http/Controllers/Api/EmployeeController.php)
* تحديث شروط التحقق (Validation) في دالتي الحفظ (`store`) والتحديث (`update`) لاستيعاب وإلزام حقول التارغت والعمولات عند اختيار نوع الدفع بالطلب أو المختلط.

#### [MODIFY] [ContractController.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Http/Controllers/Api/ContractController.php)
* إبقاء حقول العقود فقط على:
  * `expected_monthly_revenue` (الإيراد الشهري المتوقع).
  * `target_driver_count` (عدد السائقين المستهدف).
  * إزالة أو تعطيل التحقق من الحقول القديمة التي انتقلت للموظف.

#### [MODIFY] [DailyLogController.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Http/Controllers/Api/DailyLogController.php)
* عند حفظ أو تحديث أو حذف أي لوق يومي، نقوم باستدعاء دالة تحديث عمولات اليوم النشط وإعادة احتساب عمولات الشهر للسائق المعني بشكل تسلسلي زمني فوري.

#### [MODIFY] [PayrollController.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Http/Controllers/Api/PayrollController.php)
* إعادة بناء خوارزمية الاحتساب لتكون مرتبة باليوم وتراكمية:
  * جلب السجلات اليومية للشهر مرتبة زمنياً: `orderBy('log_date')->orderBy('id')`.
  * حلقة تكرار لحساب تراكم الطلبات `running_orders` وتطبيق سعر العمولة المميزة فور تخطي التارغت المسجل ببروفايل السائق.
  * تخزين الناتج الفردي في حقل `driver_commission` للوق، وجمع الإجمالي كـ `orders_bonus` في الراتب.

#### [MODIFY] [ReportController.php](file:///c:/Users/eamen/Herd/fleet/fleet-backend/app/Http/Controllers/Api/ReportController.php)
* تحديث تقرير أرباح العقود (Contract Profitability) وتقرير أرباح المركبات ليقوما بجمع تكاليف السائقين المباشرة من حقل `driver_commission` المسجل باللوقات بدلاً من الطريقة التقريبية السابقة. هذا يحقق سيناريو العقد X والعقد Y بدقة 100%.

---

### 3️⃣ الواجهة الأمامية (Frontend Components)

#### [MODIFY] [EmployeeWizard.jsx](file:///c:/Users/eamen/Herd/fleet/fleet-frontend/src/pages/employees/EmployeeWizard.jsx)
* إدراج مدخلات التارغت والعمولة الأساسية والمميزة في الخطوة 2 (التوظيف والراتب) عند اختيار نوع دفع `per_order` أو `hybrid`.

#### [MODIFY] [EmployeeEditor.jsx](file:///c:/Users/eamen/Herd/fleet/fleet-frontend/src/pages/employees/EmployeeEditor.jsx)
* إدراج مدخلات التارغت والعمولات عند تعديل بيانات السائق في الخطوة 2.

#### [MODIFY] [EmployeeProfile.jsx](file:///c:/Users/eamen/Herd/fleet/fleet-frontend/src/pages/employees/EmployeeProfile.jsx)
* تحديث بطاقة التارغت الزجاجية لتجلب البيانات مباشرة من ملف الموظف نفسه وليس من العقد.

#### [MODIFY] [ContractList.jsx](file:///c:/Users/eamen/Herd/fleet/fleet-frontend/src/pages/contracts/ContractList.jsx)
* إزالة مدخلات عمولات التارغت من نموذج إضافة وتعديل العقود، والإبقاء فقط على حقل الإيراد المتوقع وحقل عدد السائقين المستهدف.

---

## 📈 خطة التحقق والضمان (Verification Plan)

### الاختبارات البرمجية المؤتمتة (Automated Feature Tests)
* إعادة صياغة اختبار E2E العملاق `MasterScenarioE2ETest.php` ليكون متوافقاً مع الحقول الجديدة والسيناريو الذي ذكرته:
  * إسناد التارغت 10 لأحمد في بروفايله مع بونص 0.200 أساسي و0.300 مميز.
  * محاكاة 5 طلبات على العقد X، ثم 5 على العقد Y، ثم 5 على العقد X.
  * مطابقة تكاليف العقد X وعقد Y وتأكيد بقاء الاختبار باللون الأخضر.
