# 🏛️ Extractable Double-Entry Accounting Architecture
> **A Blueprint for building a world-class, modular, and portable ERP Ledger in Laravel.**

Your instinct is **100% correct**. The number one reason ERP/Accounting implementations fail or become impossible to maintain is **tight coupling**. 

If your `journal_entries` table directly references an `employee_id` or `vehicle_id`, or if your operational controllers directly insert debit/credit lines, you have created a "spaghetti monolith." You can never extract the accounting system, and any change in operations will break your ledger.

Here is how we will architect a **completely decoupled, extractable, and ultra-robust double-entry engine** that you can drop into *any* future Laravel project.

---

## 🧩 1. The Decoupled Architecture Model

To make the Accounting module portable, it must live in its own isolated domain (like a local Laravel Package or a dedicated `app/Modules/Accounting` namespace). It knows *nothing* about employees, trucks, or daily logs. It only knows about **Accounts, Journals, and Money**.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        OPERATIONAL DOMAIN (FleetOps)                   │
│   • Employees       • Vehicles       • Daily Logs      • Violations    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    │ Dispatches Domain Events
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        THE ACCOUNTING BRIDGE                           │
│   Translates business events into standardized Ledger Vouchers         │
│   (e.g. EmployeePaidEvent ➔ Debit: Salary Expense, Credit: Bank)        │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    │ Calls Accounting API
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                     PORTABLE ACCOUNTING ENGINE (ERP Core)              │
│   • Chart of Accounts    • Journal Entries    • Double-Entry Rules     │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 🗄️ 2. The Core Portable Database Schema

The core engine requires only three main tables. None of these tables contain any fields related to FleetOps (no `employee_id`, `vehicle_id`, `client_id`). Instead, we use a generic `reference_type` and `reference_id` (Polymorphic Relation) for audit trails.

### A. The `accounts` Table (Chart of Accounts)
```sql
CREATE TABLE accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,  -- For multi-tenancy
    parent_id BIGINT UNSIGNED NULL,       -- Tree hierarchy self-ref
    code VARCHAR(50) NOT NULL,            -- e.g. "1101", "5102"
    name VARCHAR(255) NOT NULL,           -- English name
    name_ar VARCHAR(255) NOT NULL,        -- Arabic name
    type ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    is_group BOOLEAN DEFAULT FALSE,       -- Groups cannot hold direct journal entries
    currency VARCHAR(3) DEFAULT 'KWD',    -- Base currency
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES accounts(id) ON DELETE SET NULL,
    UNIQUE(company_id, code)              -- Scoped unique key
);
```

### B. The `journal_entries` Table (Ledger Headers)
```sql
CREATE TABLE journal_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    entry_number VARCHAR(100) NOT NULL,   -- e.g., "JV-2026-0001"
    entry_date DATE NOT NULL,
    description TEXT,
    status ENUM('draft', 'posted', 'reversed') DEFAULT 'draft',
    
    -- POLYMORPHIC AUDIT TRAIL: Links to ANY external model (decoupled!)
    reference_type VARCHAR(255) NULL,     -- e.g. 'App\Models\PayrollSlip' or 'App\Models\Violation'
    reference_id BIGINT UNSIGNED NULL,
    
    posted_by BIGINT UNSIGNED NULL,
    posted_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### C. The `journal_entry_lines` Table (Debit/Credit lines)
```sql
CREATE TABLE journal_entry_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    
    -- Multi-currency Support
    amount DECIMAL(15, 3) NOT NULL,       -- Foreign transaction amount
    currency VARCHAR(3) NOT NULL,         -- e.g. 'USD', 'KWD'
    exchange_rate DECIMAL(15, 6) DEFAULT 1.000000,
    
    -- Base currency (Calculated & audited)
    debit DECIMAL(15, 3) DEFAULT 0.000,   -- Positive values in base currency
    credit DECIMAL(15, 3) DEFAULT 0.000,  -- Positive values in base currency
    
    description VARCHAR(255) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);
```

---

## ⚙️ 3. The Double-Entry Business Rules (Immutability)

To make this engine incredibly secure and bulletproof, we will enforce three strict programming guardrails:
1. **Mathematical Zero-Sum Check:** A Journal Entry cannot be saved or changed to `posted` status unless `SUM(debit) == SUM(credit)` down to the exact decimal value.
2. **Immutable Ledger Postings:** Once a Journal Entry is marked as `posted`, it is **locked forever**. It cannot be edited, deleted, or updated. If there is a mistake, you must generate a new entry to **reverse** it. This is standard auditing practice (IFRS and GAAP compliant).
3. **No Group Transactions:** Direct entries can only be written to child/leaf accounts, never to parent/group accounts (e.g. you can post to "Cash Box A", but not directly to the parent "Assets" folder).

---

## 🌁 4. How the "Accounting Bridge" Works (Decoupled Integration)

To connect FleetOps entities to the Ledger without coupling them, we write a **Bridge Layer**. 

### Example: Charging a Violation to a Driver

1. **Step 1:** Operational controller creates the violation:
   ```php
   // Operational domain is completely isolated from accounting code
   $violation = Violation::create([
       'employee_id' => $driverId,
       'amount' => 50.000,
       'violation_date' => now(),
   ]);
   
   // Dispatch a domain event!
   event(new ViolationRecorded($violation));
   ```

2. **Step 2:** The Accounting Bridge listener catches the event and maps it:
   ```php
   class PostViolationToLedger
   {
       protected $accountingService;
   
       public function handle(ViolationRecorded $event)
       {
           $violation = $event->violation;
           
           // We ask the Accounting Service to create a Journal Entry
           $this->accountingService->createJournalEntry([
               'entry_date' => $violation->violation_date,
               'description' => "Traffic violation fine charged to driver: " . $violation->employee->name,
               'reference' => $violation, // Polymorphic binding
               'lines' => [
                   [
                       'account_code' => '1202', // Accounts Receivable - Drivers
                       'debit' => $violation->amount,
                   ],
                   [
                       'account_code' => '5301', // Traffic Violations Expense Account
                       'credit' => $violation->amount,
                   ]
               ]
           ]);
       }
   }
   ```

---

## 🎯 5. The Path to a Great Win

By deferring Sprint 4 (Accounting) until operations are rock-solid, we guarantee two things:
1. **Operational Completeness:** We get the flexible imports, soft deletes, dashboard warnings, and date-driven driver lookups working perfectly. We will know *exactly* what actions happen in our application.
2. **ERP Excellence:** Once the operational flows are clean, we will build this isolated Accounting engine as a separate module. It will be a masterpiece that you can package up and reuse in **every business application** you build for the rest of your career!
