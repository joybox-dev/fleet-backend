# 📋 FleetOps ERP Master Phase Checklist

This checklist tracks our progress through the **15-Phase Strategic Roadmap**. Sprints 1, 2, and 2.5 have been consolidated into **Phases 1 through 10 (100% Completed & Verified)**. Phase 11 has also been audited and verified as **100% Completed & Verified**. Remaining tasks are grouped into **Phases 12 through 15**.

---

## 🟢 PART 1: COMPLETED PHASES (Phases 1 - 11)

- [x] **Phase 1: البنية التحتية متعددة الشركات** (Multi-Company Architecture & Tenant Isolation)
  - [x] 17 models company-scoped
  - [x] Company isolation global queries
  - [x] Super admin company gating dashboard
- [x] **Phase 2: نظام ترقيم وإدارة الموظفين الأساسي** (Employee Core Module & Auto-Numbering)
  - [x] Employee CRUD operations
  - [x] Automatic incremental `EMP-XXXX` numbering
- [x] **Phase 3: نظام الإجازات والغياب** (Absences & Leaves Management & Approvals)
  - [x] Absence records creation & calendar tracking
  - [x] Approved leave deduction mapping to payroll slips
- [x] **Phase 4: إدارة ضمانات السائقين** (Driver Guarantees CRUD & Returns)
  - [x] Guarantees UI list, cards, held/returned states
  - [x] Safe return confirmations and transaction tracking
- [x] **Phase 5: إدارة مصاريف وأصول المركبات** (Vehicle Expenses & Asset Trackers)
  - [x] Vehicle expenses page with category icons (fuel, tires, etc.)
  - [x] Expensing and maintenance category distribution
- [x] **Phase 6: نظام السلف والأقساط** (Salary Advances & Auto-Installments System)
  - [x] Automatic installment calculator (amount ÷ monthly installment = months)
  - [x] Salary advance progress bars & history on profile
- [x] **Phase 7: تسجيل المخالفات مع رفع الصور** (Traffic Violations with Media Uploads)
  - [x] Violation creation with live photo attachment via `uploadApi`
  - [x] Fullscreen image overlay previewer on list views
- [x] **Phase 8: لوحة التحكم التشغيلية وحجم عقود التشغيل** (Operations Dashboard & Contract Capacity)
  - [x] Dashboard showcasing contract available, active, assigned, leave, and deficit drivers
  - [x] Batch-optimized queries preventing N+1 database hits
- [x] **Phase 9: ملف التعريف الموحد للموظف وتجديد المستندات** (Employee Profile Unified Hub)
  - [x] Integration of advances, guarantees, custody, documents, and balance details in profile page
  - [x] Document expiry stepper indicators (valid, expiring, expired)
- [x] **Phase 10: السيناريو الشامل للفحص ومزامنة الرواتب** (Master E2E Scenario & Recalculation Engine)
  - [x] Register model observers to automatically recalculate draft payroll slips on daily log modifications
  - [x] Fully passed 100% green backend Master E2E feature testing suite
- [x] **Phase 11: الحماية والأمان وعزل البيانات التراكمي وتدقيق السجلات اليومية (Critical Bug Fixes)**
  - [x] **Task 11.1 (Bug 1.1): Pay Type Update Fix**: Fully implemented in `EmployeeController` and frontend `EmployeeEditor`
  - [x] **Task 11.2 (Bug 1.5): Cash Settled & Over-Settlement Protection**: Display cumulative pending cash and validate settlements <= pending cash
  - [x] **Task 11.3 (Bug 1.6): Retroactive Recalculation UI Integration**: Recalculate payroll slips automatically when daily logs are modified
  - [x] **Task 11.4 (Bugs #1 & #3): SaaS Uniqueness Scoping & Queue Context Guard**: Multi-company uniqueness checks and defensive queue scoping
  - [x] **Task 11.5 (Bug #2): Soft-Delete Relation Safeguards**: `withTrashed()` eager loaded on all relationship methods
  - [x] **Task 11.6 (Bug #4 & #5): Daily Log Odometer & Math Constraints**: validation of `orders_online + orders_cash == orders_count` and `odometer_end >= odometer_start`

---

## 🟡 PART 2: ACTIVE TO-GO PHASES (Phases 12 - 15)

### [x] Phase 12: Advanced Operations, Dynamic Evaluations & Daily Log UI
- [x] **Task 12.1: Customizable Evaluation Criteria per Employee**
  - [x] Dynamic evaluations where criteria can be added/removed on-the-fly per employee
- [x] **Task 12.2: Employee Profile Completed Order Counters**
  - [x] Display daily and monthly completed orders count directly on profile tab
- [x] **Task 12.3: Flexible Onboarding Steppers (HR)**
  - [x] Differentiate Local Transfer vs. Abroad Hiring checklist flows
- [x] **Task 12.4: Expiry Warnings Threshold (30 Days) & Archive Profile**
  - [x] Reduce warnings threshold from 60 days to 30 days
  - [x] Rename guarantees profile tab to "أرشيف السائق / ملف السائق" for scanned uploads
- [x] **Task 12.5: Chronological Daily Log Operational Panel**
  - [x] Worker, vehicle, contract search filters with chronological sorting and renamed labels
- [x] **Task 12.6: Cash Settlement UI Redesign (Proposed & Approved Option 1)**
  - [x] Present proposals and implement the approved cash settlement UI based on employee pending cash
- [ ] **Task 12.7: Future Evaluations System Expansion (Reminder Note)**
  - [ ] Awaiting user feedback on additional criteria / custom evaluation rules


### [/] Phase 13: Operational Intelligence, Target Commission & Auto-Violations
- [x] **Task 13.1: Date & Vehicle Driven Auto-Violations Assignment**
  - [x] Select Date + Vehicle, automatically assign the driver from vehicle assignments table
- [x] **Task 13.2: Target-Based Driver Commission Engine**
  - [x] Base rate under target, premium commission rate for orders exceeding target limit
- [x] **Task 13.3: Contract Revenue Metrics**
  - [x] Expected revenue and target driver counts on contract creation, matching against physical numbers
- [ ] **Task 13.4: Odometer Photos & 4000km Oil Change Warnings**
  - [ ] Live photo odometer upload requirements, and oil change alerts on dashboard
- [ ] **Task 13.5: Digital Handover Protocols**
  - [ ] Printable custody handover agreement with 4-side photo documentation

### [ ] Phase 14: Cost Center Allocations, Fleet Analytics, Garage Ratings & Tasks
- [ ] **Task 14.1: Supervisor Cost Center Allocation**
  - [ ] Proportional supervisor salary allocation over operational contracts
- [ ] **Task 14.2: Maintenance Delays & Workshop Quality Metrics**
  - [ ] Repair expected duration trackers, garage delays, and ratings
- [ ] **Task 14.3: Internal Admin Task Planner**
  - [ ] Administrative tasks lists on dashboard with standard states
- [ ] **Task 14.4: Dashboard Smart Period Comparisons**
  - [ ] Period selectors (Daily, Weekly, Monthly, Yearly filters) on dashboard metrics
- [ ] **Task 14.5: Printable Salary Payment Receipt Vouchers**
  - [ ] PDF export of monthly payroll slip for physical signature

### [ ] Phase 15: The ERP Capstone — Integrated Native Accounting & Salary Invoicing (Deferred)
- [ ] **Task 15.1: Chart of Accounts (شجرة الحسابات)**
- [ ] **Task 15.2: Multi-Currency Journal Entries (القيود اليومية)**
- [ ] **Task 15.3: General Ledger (دفتر الأستاذ)**
- [ ] **Task 15.4: Client Invoices & Salary Invoicing Integration**
- [ ] **Task 15.5: AR/AP Payments & Financial Reports**
