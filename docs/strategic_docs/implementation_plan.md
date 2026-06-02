# خطة عمل: نظام الاستيراد المشترك والمزامن للموظفين والمركبات (Combined Import Engine)

تأتي هذه الخطة استجابةً لطلب إضافة ميزة استيراد موحد يعالج ملف إكسل واحد يحتوي على بيانات السائق وبيانات السيارة الخاصة به معاً، بحيث يتم إنشاء ملف الموظف وملف المركبة في نفس اللحظة وتعيين المركبة للموظف تلقائياً (Assign) لتوفير الوقت والجهد التشغيلي عند التأسيس.

---

## 🙋‍♂️ مراجعة العميل المطلوبة (User Review Required)

> [!IMPORTANT]
> **1. معالجة تداخل أسماء الحقول (Field Key Collisions):**
> نظراً لأن الموظف والمركبة يمتلكان حقولاً بنفس الاسم بقاعدة البيانات (مثل `status` و `notes`)، سنقوم بإنشاء صنف تهيئة استيراد مشترك ومقترن (`CombinedImportConfig`) يقوم بدمج الحقول مع تمييزها ببادئة واضحة:
> * حقول الموظف تبدأ بـ `employee_` (مثال: `employee_name`, `employee_number`).
> * حقول المركبة تبدأ بـ `vehicle_` (مثال: `vehicle_plate_number`, `vehicle_status`).
>
> **2. إسناد عقد التشغيل الإلزامي للتعيين (Contract Assignment):**
> تطلب قاعدة البيانات في جدول `vehicle_assignments` وجود معرف عقد تشغيل فعال (`contract_id`) بشكل إجباري لكل عملية تعيين.
> **الحل المقترح:** سنقوم بإضافة قائمة منسدلة أنيقة في واجهة الاستيراد عند اختيار "الاستيراد المشترك" لتمكين المستخدم من **اختيار العقد الافتراضي** الذي سيتم إسناد كافة التعيينات المستوردة إليه تلقائياً، دون تعقيد ملف الإكسل بكتابة معرفات العقود.

---

## 🔎 الأسئلة المفتوحة (Open Questions)

> [!NOTE]
> * **هل يجب السماح باختيار عقد تشغيل مختلف لكل صف في ملف الإكسل؟**
>   * *التوصية:* نوصي بالاعتماد على **العقد الافتراضي الموحد للملف بالكامل** والذي يتم اختياره من الواجهة كخطوة أولى، لأن الاستيراد الجماعي عادةً ما يكون لدفعة سائقين تابعين لعقد تشغيل واحد (مثل عقد طلبات أو عقد جاهز). إذا لزم الأمر، يمكننا إضافة حقل اختياري لربط العقد بالاسم في الإكسل، ولكن اختيار عقد افتراضي من الواجهة هو الأسرع والأكثر أماناً لمنع أخطاء الحفظ.

---

## 🛠️ التغييرات المقترحة (Proposed Changes)

---

### 🟢 الجزء الأول: الباكيند (Laravel Backend)

#### [NEW] [CombinedImportConfig.php](file:///e:/Projects%20Analysis%20&%20Infos/fleet/cars_fleet_backend/app/Imports/CombinedImportConfig.php)
* **إنشاء هيكل ربط موحد للموظف والمركبة معاً:**
  * دمج مصفوفات الحقول وقواعد التحقق (Validation Rules) والقيم الافتراضية (Defaults) من `EmployeeImportConfig` و `VehicleImportConfig`.
  * تطبيق البادئات `employee_` و `vehicle_` لمنع التداخل.
  * إضافة حقل `contract_id` لتمثيل العقد المربوط بالتعيين.
  * هيكل الحقول المدمجة المقترحة:
    ```php
    public static function fields(): array {
        return [
            // حقول الموظف
            ['key' => 'employee_name', 'label' => 'اسم الموظف (إنجليزي)', 'required' => true, 'type' => 'string'],
            ['key' => 'employee_name_ar', 'label' => 'اسم الموظف (عربي)', 'required' => false, 'type' => 'string'],
            ['key' => 'employee_number', 'label' => 'رقم الموظف (EMP-XXX)', 'required' => true, 'type' => 'string'],
            ['key' => 'employee_pay_type', 'label' => 'نظام الدفع للموظف', 'required' => true, 'type' => 'enum:fixed,per_order,hybrid'],
            ['key' => 'employee_official_salary', 'label' => 'الراتب الرسمي للموظف', 'required' => true, 'type' => 'numeric'],
            // حقول المركبة
            ['key' => 'vehicle_plate_number', 'label' => 'رقم لوحة المركبة', 'required' => true, 'type' => 'string'],
            ['key' => 'vehicle_make', 'label' => 'الشركة المصنعة للمركبة', 'required' => false, 'type' => 'string'],
            ['key' => 'vehicle_model', 'label' => 'موديل المركبة', 'required' => false, 'type' => 'string'],
            ['key' => 'vehicle_year', 'label' => 'سنة صنع المركبة', 'required' => false, 'type' => 'integer'],
            ['key' => 'vehicle_status', 'label' => 'حالة المركبة', 'required' => false, 'type' => 'enum:working,available,maintenance,idle'],
        ];
    }
    ```

#### [MODIFY] [ImportService.php](file:///e:/Projects%20Analysis%20&%20Infos/fleet/cars_fleet_backend/app/Services/ImportService.php)
* **تحديث محرك الاستيراد لدعم الكيان المشترك:**
  * تسجيل الكيان الجديد باسم `combined` في دالة `getConfig()` ودالة `entityTypes()`.
  * **تعديل دالة `executeImport` لمعالجة التثبيت المشترك والتعيين:**
    ```php
    if ($importLog->entity_type === 'combined') {
        // 1. استخلاص بيانات الموظف وإنشاؤه (إذا لم يكن مكرراً)
        // 2. استخلاص بيانات المركبة وإنشاؤها (إذا لم تكن مكررة)
        // 3. إنشاء سجل التعيين النشط (VehicleAssignment) وربط الموظف بالمركبة والعقد المحدد
    }
    ```

#### [MODIFY] [ImportController.php](file:///e:/Projects%20Analysis%20&%20Infos/fleet/cars_fleet_backend/app/Http/Controllers/Api/ImportController.php)
* **دعم التحقق والتمرير للكيان المشترك:**
  * تحديث قواعد التحقق (Validation) في دوال `upload` و `preview` و `confirm` لتسمح بقيمة `combined` كـ `entity_type`.
  * استقبال معامل `contract_id` الإضافي من الطلب وتمريره إلى وظيفة الطابور الخلفية `ProcessImportJob`.

#### [MODIFY] [ProcessImportJob.php](file:///e:/Projects%20Analysis%20&%20Infos/fleet/cars_fleet_backend/app/Jobs/ProcessImportJob.php)
* **استقبل وتمرير هوية العقد للوظيفة التشغيلية:**
  * إضافة معامل `$contractId` لـ `__construct` وحفظه في خصائص الوظيفة.
  * تمرير معرف العقد كمعامل إضافي عند استدعاء `ImportService->executeImport()`.

---

### 🔵 الجزء الثاني: الفرونت إند (React UI)

#### [MODIFY] [ImportPage.jsx](file:///e:/Projects%20Analysis%20&%20Infos/fleet/cars_fleet_frontend/src/pages/import/ImportPage.jsx)
* **تتطوير شاشة الاستيراد لتشمل الاستيراد المدمج:**
  * جلب قائمة العقود النشطة المتاحة للشركة وتخزينها في حالة `contracts`.
  * في خطوة اختيار نوع البيانات (Step 0):
    * عرض زر الكيان المشترك **"الموظفين والمركبات معاً 👥+🚗"**.
    * في حال اختيار الكيان المشترك، يتم عرض قائمة منسدلة إجبارية لاختيار **العقد التشغيلي المستهدف**.
  * تمرير `contract_id` المختار في طلبات الرفع والمعاينة والتأكيد الموجهة للسيرفر.

---

## 📈 خطة التحقق والضمان (Verification Plan)

### الاختبارات العملية واليدوية (Manual & Integration Testing)
1. **تحميل القالب المشترك:** التحقق من أن تحميل قالب الكيان المشترك يولّد ملف إكسل يحتوي على كافة أعمدة السائق والسيارة بلون مميز للحقول الإلزامية.
2. **ربط الأعمدة وتصحيح الأخطاء:** رفع ملف يحتوي على سائقين وسيارات وتأكيد قدرة واجهة ربط الأعمدة على التمييز بين الحقول وقبول الربط.
3. **التثبيت التلقائي والمزامن:** تأكيد أن إنهاء الاستيراد يقوم بنجاح بإنشاء الموظف في جدول `employees` والمركبة في جدول `vehicles` وسجل التعيين النشط في جدول `vehicle_assignments` المربوط بالعقد المختار بلمسة واحدة.
