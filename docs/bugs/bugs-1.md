Viewed BelongsToCompany.php:1-70
Viewed VehicleController.php:1-155
Searched for "last_oil_change_km"
Viewed 2026_04_30_085249_create_vehicles_table.php:1-56
Searched for "class VehicleAssignment"
Viewed VehicleAssignment.php:1-24
Viewed Employee.php:1-99

إليك تحليل عميق وشامل لـ **4 ثغرات وأخطاء برمجية حرجة (Bugs & Architectural Issues)** قمت برصدها في بنية النظام الخلفي (Backend) والتي تؤثر مباشرة على الأمان، عزل الشركات (Multi-Tenancy Isolation)، وتكامل البيانات التاريخية (Audit Trails).

يمكنك نسخ هذه الملاحظات ووضعها في ملف نصي لتوثيقها أو لنقوم بإصلاحها معاً خطوة بخطوة:

---

##### HI gemini im writing some comment for you so you can understand me better

### 1. تسريب وقفل البيانات بين الشركات (SaaS Uniqueness Leakage & Conflicts)
#### 🔍 المشكلة:
في نظام متعدد الشركات (SaaS)، يجب أن تكون الحقول فريدة **على مستوى الشركة الواحدة فقط وليس على مستوى قاعدة البيانات كاملة**.
في الكود الحالي، جميع عمليات التحقق الفريدة (`unique`) في الـ Controllers تستخدم القاعدة العامة لـ Laravel، مما يؤدي إلى:
1. **حجب الأسماء المتشابهة:** إذا قامت "الشركة أ" بإنشاء عميل باسم "Talabat"، لن تتمكن "الشركة ب" من تسمية أي عميل لديها باسم "Talabat" وستظهر لها رسالة خطأ بأن الاسم مستخدم مسبقاً!
2. **تسريب غير مباشر للبيانات:** معرفة الشركات بوجود سجلات لشركات أخرى بمجرد محاولة إدخال نفس رقم العقد أو الهاتف.

#### 📁 الأماكن المتأثرة:
* **`ClientController.php` (الأسطر 27، 30، 31، 32):**
  ```php
  'name' => 'required|string|max:255|unique:clients,name', // يمنع تشابه الأسماء بين الشركات
  'phone' => '...|unique:clients,phone',
  'email' => '...|unique:clients,email',
  ```
* **`ContractController.php` (السطر 27):**
  ```php
  'contract_number' => 'required|string|unique:contracts,contract_number', // يمنع تشابه أرقام العقود
  ```
* **`EmployeeController.php` (السطر 35، 36):**
  ```php
  'civil_id' => '...|unique:employees,civil_id', // الرقم المدني للسائق
  'phone' => '...|unique:employees,phone',
  ```

#### 🛠️ الحل المقترح:
يجب تقييد قاعدة الفرادة (`unique`) بالـ `company_id` الخاص بالشركة الحالية باستخدام `Rule::unique`:
```php
use Illuminate\Validation\Rule;

$companyId = app('current_company_id');

$validated = $request->validate([
    'contract_number' => [
        'required',
        'string',
        Rule::unique('contracts', 'contract_number')->where('company_id', $companyId)
    ],
]);
```

---

### 2. فقدان السجلات التاريخية وانهيار النظام عند الحذف (Eloquent Soft-Deletes & Broken Relationships)
#### 🔍 المشكلة:
النظام يستخدم الحذف المؤقت (`SoftDeletes`) في الموديلات الرئيسية مثل الموظفين (`Employee`) والمركبات (`Vehicle`) والعقود (`Contract`).
عند حذف موظف أو سيارة:
1. **انهيار الواجهة الأمامية:** السجلات التاريخية مثل السجل اليومي (`DailyLog`) أو المخالفات لمركبة محذوفة ستُرجع القيمة `null` للعلاقة. وعندما تحاول الواجهة الأمامية قراءة `log.vehicle.plate_number` أو `log.employee.name` سينهار النظام كاملاً بـ Javascript Error: `Cannot read properties of null`.
2. **تخريب التقارير المحاسبية والمالية:** ستختفي أسماء السائقين أو أرقام السيارات المحذوفة من جميع تقارير الرواتب والمخالفات السابقة، مما يدمر سلامة البيانات التاريخية للتدقيق المالي (Audit Trail).

#### 📁 الأماكن المتأثرة:
الموديلات التاريخية التي ترتبط بعلاقة `belongsTo` مع موديلات تدعم الـ `SoftDeletes`:
* **`DailyLog.php` (السجل اليومي)** ويرتبط بالموظف والسيارة والعقد.
* **`Violation.php` (المخالفات)** وترتبط بالموظف والسيارة.
* **`MaintenanceRecord.php` (الصيانة)** وترتبط بالسيارة والموظف المسؤول.
* **`VehicleAssignment.php` (تعيين السيارات)**.

#### 🛠️ الحل المقترح:
يجب إضافة التابع `withTrashed()` لتعريفات العلاقات في الموديلات التاريخية لكي يتم جلب البيانات التاريخية حتى لو تم حذف السائق أو السيارة مؤقتاً:
```php
// داخل موديل DailyLog.php
public function employee(): BelongsTo
{
    return $this->belongsTo(Employee::class)->withTrashed();
}

public function vehicle(): BelongsTo
{
    return $this->belongsTo(Vehicle::class)->withTrashed();
}
```

---

### 3. ثغرة أمنية محتملة عند تشغيل الأوامر بالخلفية (Security Leakage in BelongsToCompany Scope)
#### 🔍 المشكلة:
في سمة العزل المحاسبي `BelongsToCompany.php` (السطر 31):
```php
if ($companyId) {
    $query->where($query->getModel()->getTable() . '.company_id', $companyId);
}
```
إذا تم استدعاء أي كود للاستعلام في مكان لا يمر عبر الـ Middleware الخاص بتحديد الشركة (مثل Console Command، أو Job في الخلفية لم يتم تمرير معرف الشركة له بشكل صحيح، أو ثغرة برمجية نسيت تحديد الشركة)، فإن الكود سيتخطى شرط الفلترة `if ($companyId)` بالكامل ويقوم بجلب وعرض بيانات **جميع الشركات** بلا استثناء!

#### 🛠️ الحل المقترح:
الوضع الأكثر أماناً (Defensive Programming) هو إجبار النظام على الفلترة بقيمة غير موجودة (مثل `0` أو `null`) ليرجع استعلاماً فارغاً بشكل آمن بدلاً من إرجاع كامل قاعدة البيانات إذا لم يتم ربط الشركة:
```php
static::addGlobalScope('company', function (Builder $query) {
    // إذا لم يكن المرفق سوبر أدمن، نفرض الشركة أو نلغي النتائج
    $companyId = app()->bound('current_company_id') ? app('current_company_id') : 0; 
    
    $query->where(
        $query->getModel()->getTable() . '.company_id',
        $companyId
    );
});
```

---

### 4. ضعف التحقق من قراءة العداد في التحديث (`Odometer Consistency Bug`)
#### 🔍 المشكلة:
في جدول السجل اليومي `DailyLogController.php` عند الإضافة (`store`):
يتم التحقق بشكل صحيح من أن عداد النهاية أكبر من أو يساوي عداد البداية:
```php
'odometer_end' => 'nullable|integer|min:0|gte:odometer_start',
```
ولكن عند التحديث (`update`):
تم إهمال هذا التحقق تماماً! يمكن لأي مستخدم تعديل سجل قديم وإدخال قيمة لـ `odometer_end` أصغر من `odometer_start` مما يتسبب في حساب مسافات سالبة وضرب لوحة تحليلات الصيانة واستهلاك الوقود للمركبة.

#### 📁 الأماكن المتأثرة:
* **`DailyLogController.php` (السطر 101-102)** في دالة الـ `update`.

#### 🛠️ الحل المقترح:
إضافة فحص التناسق عند التحديث بشرط قراءة قيم البداية والنهاية المرسلة أو المحفوظة في قاعدة البيانات.
-----------------------------------------------------------------------------
#### this by me cousotmer told me to change this 
التقييمات يجب ان تصبح اكثر مرونة يعني المعيار يتم اضافته للموظف مباشرة قد يكون كل موظف  له معياره الخاص ولكن عند 
مثال 
موظف 1 
اضفنا له معيار السرعة 
في حال قمنا باضافة تقييم جديد يجب ان يظهر المعيار السابق مع امكانية حذفه وعدم اختياره والسماح باضافة معيار جديد 
-----------------------------------------------------------------------------

#### this is also coustomer told me about it and we already did it 
بروفايل الموظف 

اضافة عدد الطلبات اليومية الشهرية 
-----------------------------------------------------------------------------
#### this is also coustomer told me about it and we already did it 
### 5. عدم مطابقة تقسيمات الطلبات مع العدد الإجمالي (Orders Count Mathematical Inconsistency)

#### 🔍 المشكلة:
لا توجد حماية برمجية (Validation) تضمن مطابقة تقسيمات الطلبات مع العدد الإجمالي المدخل.
فمثلاً، إذا قام المستخدم بإدخال البيانات التالية:
* عدد الطلبات الإجمالي = 20
* طلبات أونلاين = 12
* طلبات كاش = 5
يقبل النظام السجل بالرغم من وجود **3 طلبات مفقودة** لم يتم تحديد نوعها، مما يضرب تقارير التدقيق المالي ومطابقة المحفظة النقدية للسائق لاحقاً.

#### 📁 الأماكن المتأثرة:
* **`DailyLogController.php`** في دالتي `store` و `update`.

#### 🛠️ الحل البرمجي المقترح (Laravel Validator):
إضافة تحقق مخصص بعد التحقق الأولي (After Validation Hook) لمطابقة المجموع:
```php
$validator->after(function ($validator) use ($request) {
    $total = (int) $request->input('orders_count', 0);
    $online = (int) $request->input('orders_online', 0);
    $cash = (int) $request->input('orders_cash', 0);

    if (($online + $cash) !== $total) {
        $validator->errors()->add('orders_count', 'مجموع طلبات الكاش والأونلاين يجب أن يساوي عدد الطلبات الإجمالي.');
    }
});
```
-----------------------------------------------------------------------------
#### this is also coustomer told me about it and i guess you did it now 
في حساب الرواتب الامور صحيحة ولكن احيانا بكون في خطا مثلا السائق انجز 50 طلب وانا سجلت 40 
في حال رجعت عدلت على السجل اليومي لليوم الي فيه خطا  مابقدر اعمل حساب جديد للرواتب 
-----------------------------------------------------------------------------
#### now this i don't know its situation 
في الرواتب يجب انشاء فاتورة لكل موظف فلازم يكون في زر مخصص  لانشاء فاتورة براتب الموظف 
-----------------------------------------------------------------------------
#### now this i don't know its situation 
واجهة السجل اليومي سيئة يجب ان تكون افضل بداية فلاتر على التاريخ 
جعل السجل يتم وضعه ضمن فئات أي كل عقد فئة لسهولة الفصل وترتيب المعلومات من الاحدث 
يجب اضافة بحث على اسم الموظف و العقد وحتى السيارة 
-----------------------------------------------------------------------------
#### now this i don't know its situation 
في انشاء مخالفة ممنوع تحديد موظف 
فقط تحديد التاريخ وتحديد السيارة يجب على النظام اظهار الموظف الذي كان يمتلك هذه السيارة 
في حال كان الموظف محذوف من النظام  يظهر غير معروف وحقل 
على السائق؟
يكون بشكل تلقائي  لا على الشركة 
ولا يمكن تغييره 
في حال كان الموظف غير نشط 
الامر نفسه يطبق 
اما في حال الموظف نشط 
فحقل على السائق؟
يكون اختياري 
------------------------------------------------------------------------------
#### now this it has more detail i can give to you 
اضافة تارغت للعقد في صفحة انشاء عقود