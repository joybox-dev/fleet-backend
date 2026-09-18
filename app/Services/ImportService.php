<?php

namespace App\Services;

use App\Imports\EmployeeImportConfig;
use App\Imports\VehicleImportConfig;
use App\Models\ImportLog;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel import of employees and vehicles: read the file, map its columns, show what each row
 * would do, then do it.
 *
 * The client's first two imports lost every row they carried (106 employees, then 1), for two
 * reasons this class now guards against. A value the template called optional was mandatory in
 * the table, and the failure reached the screen as a raw SQL error; and the template's own empty,
 * formatted rows were counted as 198 failed records, because defaults were laid over a row before
 * anyone asked whether the row said anything. So: a row is judged on what the FILE says, a blank
 * row is not a record, and no database message is ever shown to the person importing.
 */
class ImportService
{
    /** What a row will do, decided against the records the company already has. */
    public const ACTION_CREATE = 'create';

    public const ACTION_RESTORE = 'restore';

    public const ACTION_UPDATE = 'update';

    public const ACTION_UNCHANGED = 'unchanged';

    public const ACTION_DUPLICATE = 'duplicate';

    /** Arabic-Indic and Persian digits, and the Arabic decimal mark, as a spreadsheet may hold them. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.',
    ];

    /** The words people write for a coded value. The code itself is always accepted too. */
    private const ALIASES = [
        'role_category' => ['سائق' => 'driver', 'إداري' => 'admin', 'اداري' => 'admin'],
        'gender' => ['ذكر' => 'male', 'أنثى' => 'female', 'انثى' => 'female', 'انثي' => 'female'],
        'employee_type' => ['استقدام' => 'overseas', 'تحويل محلي' => 'local_transfer', 'تحويل' => 'local_transfer', 'محلي' => 'local_transfer'],
        'pay_type' => ['ثابت' => 'fixed', 'بالطلب' => 'per_order', 'هجين' => 'hybrid'],
        'ownership_type' => ['ملك' => 'owned', 'مستأجرة' => 'rented', 'مستأجر' => 'rented', 'إيجار' => 'rented', 'ايجار' => 'rented', 'تقسيط' => 'installment'],
        'status' => [
            'نشط' => 'active', 'تجربة' => 'probation', 'إجازة' => 'on_leave', 'اجازة' => 'on_leave', 'غير نشط' => 'inactive',
            'يعمل' => 'working', 'تعمل' => 'working', 'متاح' => 'available', 'متاحة' => 'available',
            'صيانة' => 'maintenance', 'خامل' => 'idle', 'خاملة' => 'idle',
        ],
    ];

    /**
     * Get the config class for an entity type.
     */
    public function getConfig(string $entityType): ?string
    {
        return match ($entityType) {
            'employees' => EmployeeImportConfig::class,
            'vehicles' => VehicleImportConfig::class,
            default => null,
        };
    }

    /**
     * Get available entity types for the UI.
     */
    public function entityTypes(): array
    {
        return [
            ['key' => 'employees', 'label' => 'الموظفين', 'icon' => '👥'],
            ['key' => 'vehicles',  'label' => 'المركبات', 'icon' => '🚗'],
        ];
    }

    /**
     * Get the required/optional field definitions for an entity. The field a record is recognised
     * by is marked `identifier`: it is the only column an update has to carry.
     */
    public function getFields(string $entityType): array
    {
        $configClass = $this->getConfig($entityType);
        if (! $configClass) {
            return [];
        }

        $identifiers = $configClass::uniqueKeys();

        return array_map(
            fn (array $field) => $field + ['identifier' => in_array($field['key'], $identifiers, true)],
            $configClass::fields()
        );
    }

    /**
     * Parse an uploaded Excel file and return column headers + first N rows.
     * This is for the column mapping step.
     */
    public function parseFile(string $filePath, int $previewRows = 5): array
    {
        $rows = $this->readActiveSheet($filePath, true);

        if (empty($rows)) {
            return ['headers' => [], 'preview' => [], 'total_rows' => 0];
        }

        // First row = headers
        $headerRow = array_shift($rows);
        $headers = [];
        foreach ($headerRow as $col => $value) {
            $val = trim((string) $value);
            if ($val !== '') {
                $headers[] = ['column' => $col, 'label' => $val];
            }
        }

        // Preview rows
        $preview = [];
        $count = 0;
        foreach ($rows as $row) {
            if ($count >= $previewRows) {
                break;
            }
            $rowData = [];
            foreach ($headers as $h) {
                $rowData[$h['column']] = trim((string) ($row[$h['column']] ?? ''));
            }
            // Skip completely empty rows
            if (implode('', $rowData) === '') {
                continue;
            }
            $preview[] = $rowData;
            $count++;
        }

        // Total data rows (excluding header)
        $totalRows = 0;
        foreach ($rows as $row) {
            $vals = array_map(fn ($v) => trim((string) ($v ?? '')), $row);
            if (implode('', $vals) !== '') {
                $totalRows++;
            }
        }

        return [
            'headers' => $headers,
            'preview' => $preview,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Validate and preview mapped data before import.
     * $mapping = ['excel_column' => 'system_field', ...]
     *
     * Each row comes back with what it would do (`action`): create a record, restore a deleted
     * one, update an existing one (with the list of `changes`), leave it unchanged, or be skipped
     * as a duplicate when the run only adds new records.
     */
    public function previewMapped(string $filePath, string $entityType, array $mapping, string $mode = ImportLog::MODE_CREATE): array
    {
        $configClass = $this->getConfig($entityType);
        if (! $configClass) {
            return ['error' => 'Invalid entity type'];
        }

        $fields = collect($configClass::fields())->keyBy('key');
        $rules = $configClass::validationRules();
        $defaults = $configClass::defaults();
        $labels = $fields->map(fn (array $field) => $field['label'])->all();
        $companyId = $this->companyId();

        // Raw cell values, not formatted ones: a formatted 61,420 is not an integer, and a date
        // formatted by the sheet's locale cannot be told from its mirror image. Raw, a number is a
        // number and a date is an Excel serial, which normalizeDate() reads exactly.
        $sheetRows = $this->readActiveSheet($filePath, false);
        $empty = ['rows' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0, 'actions' => $this->countActions([])];
        if (empty($sheetRows)) {
            return $empty;
        }

        // Pass 1 — what the file says, under the file's own row numbers. A row that carries no
        // text at all is not a record: that is every formatted-but-empty line of the template, and
        // a sample line left in place.
        $fieldColumns = array_flip($mapping);
        $headerRow = array_key_first($sheetRows);
        $read = [];
        foreach ($sheetRows as $excelRow => $cells) {
            if ($excelRow === $headerRow) {
                continue;
            }
            $provided = [];
            foreach ($fields as $key => $field) {
                if (! isset($fieldColumns[$key])) {
                    continue;
                }
                $value = $this->clean($cells[$fieldColumns[$key]] ?? null, $key, $field['type']);
                if ($value !== '') {
                    $provided[$key] = $value;
                }
            }
            if ($this->carriesText($provided, $fields)) {
                $read[$excelRow] = $provided;
            }
        }
        if ($read === []) {
            return $empty;
        }

        // Pass 2 — who is already on file, asked once per column rather than once per row.
        $keyField = $configClass::uniqueKeys()[0];
        $onFile = $this->recordsBy($configClass, $keyField, array_column($read, $keyField), $companyId, true);
        $holders = [];
        foreach ($configClass::uniqueWithinCompany() as $uniqueField) {
            $holders[$uniqueField] = $this->recordsBy($configClass, $uniqueField, array_column($read, $uniqueField), $companyId, false);
        }

        $result = [];
        $seen = [];
        foreach ($read as $excelRow => $provided) {
            [$resolved, $errors] = $configClass::resolve($provided, $companyId);

            $keyValue = (string) ($provided[$keyField] ?? '');
            $existing = $keyValue === '' ? null : ($onFile[$this->fold($keyValue)] ?? null);

            $action = match (true) {
                $existing === null => self::ACTION_CREATE,
                $this->isTrashed($existing) => self::ACTION_RESTORE,
                $mode === ImportLog::MODE_UPSERT => self::ACTION_UPDATE,
                default => self::ACTION_DUPLICATE,
            };

            // An update is judged on what the file says about the record and nothing else: a
            // default belongs to a new record, and a required field the file leaves out is
            // already on file.
            if ($action === self::ACTION_UPDATE) {
                $data = $resolved;
                $rowRules = array_intersect_key($rules, $provided);
            } else {
                $data = [];
                foreach ($fields->keys() as $key) {
                    $data[$key] = $provided[$key] ?? ($defaults[$key] ?? null);
                }
                $data = array_merge(array_diff_key($data, array_diff_key($provided, $resolved)), $resolved);
                $rowRules = $rules;
            }

            $validator = Validator::make($provided + ($action === self::ACTION_UPDATE ? [] : $data), $rowRules, $this->messages($fields), $labels);
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[$field] = array_merge($errors[$field] ?? [], $messages);
            }

            // A file that only updates a few columns may name a record that was deleted. Importing
            // it means bringing it back, which takes a whole record — and «الاسم مطلوب» alone would
            // not tell anybody why a person they know is on file is being asked for his name.
            if ($action === self::ACTION_RESTORE && $errors !== []) {
                $errors['_record'] = ['هذا السجل محذوف من النظام؛ استيراده يعني استرجاعه، ولذلك يحتاج كل الحقول الإلزامية (أو استرجعه من شاشته أولاً).'];
            }

            // The same record twice in one file: the second says something the first did not, and
            // nobody can know which was meant.
            foreach (array_merge([$keyField], $configClass::uniqueWithinCompany()) as $uniqueField) {
                $value = (string) ($provided[$uniqueField] ?? '');
                if ($value === '') {
                    continue;
                }
                $folded = $this->fold($value);
                if (isset($seen[$uniqueField][$folded])) {
                    $errors[$uniqueField][] = "«{$labels[$uniqueField]}» مكرر داخل الملف — ورد قبل ذلك في الصف {$seen[$uniqueField][$folded]}.";
                } else {
                    $seen[$uniqueField][$folded] = $excelRow;
                }

                $holder = $holders[$uniqueField][$folded] ?? null;
                if ($uniqueField !== $keyField && $holder && (! $existing || $holder->getKey() !== $existing->getKey())) {
                    $name = $holder->getAttribute($configClass::displayField());
                    $errors[$uniqueField][] = "«{$labels[$uniqueField]}» مسجَّل في النظام لسجل آخر: {$name}.";
                }
            }

            $changes = $action === self::ACTION_UPDATE && $errors === []
                ? $this->changes($existing, $resolved, $fields, $labels, $configClass, $companyId)
                : [];
            if ($action === self::ACTION_UPDATE && $errors === [] && $changes === []) {
                $action = self::ACTION_UNCHANGED;
            }

            $result[] = [
                'row_number' => $excelRow,
                'data' => $data,
                'shown' => $provided,
                'provided' => array_keys($resolved),
                'action' => $action,
                'changes' => $changes,
                'errors' => $errors,
                'is_valid' => $errors === [],
            ];
        }

        $valid = count(array_filter($result, fn (array $row) => $row['is_valid']));

        return [
            'rows' => $result,
            'total' => count($result),
            'valid' => $valid,
            'invalid' => count($result) - $valid,
            'actions' => $this->countActions($result),
        ];
    }

    /**
     * Execute the actual import.
     * $skipRows = array of row_numbers to skip.
     * Tracks: imported (new or restored), updated, skipped_duplicate (existing, left alone), failed.
     *
     * What a row does is decided again here, against the records as they stand at write time —
     * the preview's answer may be minutes old.
     */
    public function executeImport(ImportLog $importLog, array $previewData, array $skipRows = [], string $mode = ImportLog::MODE_CREATE): ImportLog
    {
        $configClass = $this->getConfig($importLog->entity_type);
        $modelClass = $configClass::modelClass();
        $uniqueKeys = $configClass::uniqueKeys();
        $defaults = $configClass::defaults();
        $companyId = $this->companyId() ?: (int) $importLog->company_id;

        $imported = 0;
        $updated = 0;
        $skippedDuplicate = 0;
        $failed = 0;
        $errors = [];

        foreach ($previewData as $row) {
            // Skip if user marked this row
            if (in_array($row['row_number'], $skipRows)) {
                continue;
            }

            // Skip invalid rows
            if (! $row['is_valid']) {
                $failed++;
                $errors[] = [
                    'row' => $row['row_number'],
                    'errors' => $row['errors'],
                ];

                continue;
            }

            try {
                $data = $row['data'];

                // The same number or plate in ANOTHER company is that company's business: the
                // forms check uniqueness per company, and so does this.
                $uniqueData = Arr::only(array_filter($data, fn ($v) => $v !== null && $v !== ''), $uniqueKeys);
                $existing = $uniqueData === [] ? null : $this->whereFolded($this->query($modelClass, $companyId, true), $uniqueData)
                    ->get()
                    // An active record wins over a deleted one carrying the same key.
                    ->sortBy(fn (Model $record) => $this->isTrashed($record) ? 1 : 0)
                    ->first();

                if ($existing && ! $this->isTrashed($existing)) {
                    if ($mode !== ImportLog::MODE_UPSERT) {
                        // Active record already exists — skip (don't overwrite)
                        $skippedDuplicate++;

                        continue;
                    }

                    // Only the cells the file filled in. An empty cell erases nothing, and a
                    // default never lands on a record that already exists.
                    $filled = array_filter(
                        Arr::only($data, $row['provided'] ?? array_keys($data)),
                        fn ($v) => $v !== null && $v !== ''
                    );
                    $existing->fill(Arr::except($filled, $uniqueKeys));

                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    } else {
                        $skippedDuplicate++;
                    }

                    continue;
                }

                // Apply defaults for missing values
                foreach ($defaults as $k => $v) {
                    if (! isset($data[$k]) || $data[$k] === '' || $data[$k] === null) {
                        $data[$k] = $v;
                    }
                }

                // Remove null values
                $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

                if ($existing) {
                    // Restore soft-deleted record and update it with the new Excel data
                    $existing->restore();
                    $existing->update($data);
                } else {
                    $modelClass::create($data);
                }
                $imported++;
            } catch (\Throwable $e) {
                // The cause goes to the log for whoever maintains the system. The person importing
                // gets a sentence they can act on, never a SQL statement.
                report($e);
                $failed++;
                $errors[] = [
                    'row' => $row['row_number'],
                    'errors' => ['exception' => ['تعذّر حفظ هذا الصف بسبب خطأ غير متوقع — سُجِّل للمراجعة الفنية، وبقية الصفوف لم تتأثر.']],
                ];
            }
        }

        $importLog->update([
            'mode' => $mode,
            'rows_total' => count($previewData),
            'rows_imported' => $imported,
            'rows_updated' => $updated,
            'rows_failed' => $failed,
            'rows_skipped_duplicate' => $skippedDuplicate,
            'errors' => $errors,
            'status' => $failed > 0 && $imported === 0 && $updated === 0 && $skippedDuplicate === 0
                ? 'failed' : 'completed',
        ]);

        return $importLog;
    }

    /**
     * Generate a blank Excel template for an entity type: a data sheet with a dropdown on every
     * coded column, and a second sheet that says what each column takes.
     */
    public function generateTemplate(string $entityType): ?string
    {
        $configClass = $this->getConfig($entityType);
        if (! $configClass) {
            return null;
        }

        $fields = $configClass::fields();
        $lastColumn = Coordinate::stringFromColumnIndex(count($fields));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('البيانات');
        $sheet->freezePane('A2');

        $header = fn (string $fill) => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];

        foreach ($fields as $index => $field) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $range = "{$column}2:{$column}200";

            // A required column is the darker one and carries a star, so it reads without colour too.
            $sheet->setCellValue("{$column}1", $field['label'].($field['required'] ? ' *' : ''));
            $sheet->getStyle("{$column}1")->applyFromArray($header($field['required'] ? '0B5F50' : '4A5A66'));
            $sheet->getColumnDimension($column)->setWidth(max(16, mb_strlen($field['label']) + 8));
            $sheet->getComment("{$column}1")->getText()->createTextRun($this->formatHint($field));

            if (str_starts_with($field['type'], 'enum:')) {
                $options = Str::after($field['type'], 'enum:');
                // One validation object for the whole column, rather than one per cell.
                $validation = new DataValidation;
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('قيمة غير صالحة')
                    ->setError("اختر قيمة من القائمة: {$options}")
                    ->setFormula1('"'.$options.'"');
                $sheet->setDataValidation($range, $validation);
            }

            $sheet->getStyle($range)->getNumberFormat()->setFormatCode(match ($field['type']) {
                'numeric' => '0.000',
                'integer' => '0',
                'date' => 'yyyy-mm-dd',
                // Text, so a civil id keeps its twelve digits and a phone keeps its leading zero.
                default => NumberFormat::FORMAT_TEXT,
            });
        }

        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->getStyle("A2:{$lastColumn}200")->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9']]],
        ]);

        $this->writeInstructions($spreadsheet->createSheet(), $fields, $configClass::uniqueKeys()[0]);
        $spreadsheet->setActiveSheetIndex(0);

        $filename = "template_{$entityType}_".now()->format('Ymd').'.xlsx';
        $path = Storage::disk('local')->path("imports/{$filename}");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * The active sheet's rows, keyed by the file's own row numbers and column letters.
     *
     * A workbook holds its sheets and its sheets hold the workbook, so PHP never frees one on its
     * own. Imports run inside a queue worker that lives for days; every file it read stayed in its
     * memory. The rows are copied out and the workbook is taken apart before anything else happens.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readActiveSheet(string $filePath, bool $formatted): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, $formatted, true);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * The second sheet of the template: the rules of the import and one line per column.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function writeInstructions(Worksheet $sheet, array $fields, string $keyField): void
    {
        $sheet->setTitle('تعليمات');
        $sheet->setRightToLeft(true);

        $keyLabel = collect($fields)->firstWhere('key', $keyField)['label'] ?? $keyField;
        $notes = [
            'اكتب البيانات في ورقة «البيانات»، صفاً لكل سجل، ابتداءً من الصف 2. لا تغيّر صف العناوين.',
            'الأعمدة ذات النجمة (*) إلزامية لكل سجل جديد. الصف الفارغ يُتجاهل ولا يُعدّ خطأ.',
            "«{$keyLabel}» هو ما يُعرف به السجل. في وضع «إضافة وتحديث» يكفي هذا العمود مع الأعمدة المراد تحديثها.",
            'في التحديث: الخانة الفارغة لا تمسح شيئاً — تُكتب فقط الخانات المعبّأة.',
            'التاريخ: 2026-01-31 أو 31/01/2026. الأرقام بالأرقام الإنجليزية أو العربية.',
        ];
        foreach ($notes as $index => $note) {
            $sheet->setCellValue('A'.($index + 1), $note);
            $sheet->mergeCells('A'.($index + 1).':C'.($index + 1));
        }

        $top = count($notes) + 2;
        $sheet->fromArray(['العمود', 'إلزامي؟', 'ما يُكتب فيه'], null, "A{$top}");
        $sheet->getStyle("A{$top}:C{$top}")->getFont()->setBold(true);
        foreach ($fields as $index => $field) {
            $sheet->fromArray(
                [$field['label'], $field['required'] ? 'نعم' : 'لا', $this->formatHint($field)],
                null,
                'A'.($top + 1 + $index)
            );
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(90);
    }

    /**
     * What a column takes, in a sentence — the header's comment and the instructions sheet.
     *
     * @param  array<string, mixed>  $field
     */
    private function formatHint(array $field): string
    {
        if (str_starts_with($field['type'], 'enum:')) {
            $options = explode(',', Str::after($field['type'], 'enum:'));
            $words = array_keys(array_intersect(self::ALIASES[$field['key']] ?? [], $options));

            return 'إحدى القيم: '.implode(' | ', $options).($words ? ' — أو بالعربية: '.implode('، ', $words) : '');
        }

        return match ($field['type']) {
            'numeric' => 'رقم، مثال: 120.500',
            'integer' => 'عدد صحيح، مثال: 150',
            'date' => 'تاريخ، مثال: 2026-01-31',
            default => 'نص',
        };
    }

    /**
     * A cell as the importer reads it: trimmed, with Western digits, and — by the column's type —
     * a plain number, an ISO date, or the code behind an Arabic word.
     */
    private function clean(mixed $raw, string $key, string $type): string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        // A whole number held as a float must not come out as 2.87051500123E+11.
        if (is_float($raw) && floor($raw) === $raw && abs($raw) < 1e15) {
            $raw = number_format($raw, 0, '.', '');
        }

        $value = trim(strtr((string) ($raw ?? ''), self::DIGITS));
        if ($value === '') {
            return '';
        }

        if ($type === 'date') {
            return $this->normalizeDate($value);
        }
        if (in_array($type, ['numeric', 'integer'], true)) {
            return str_replace([',', ' ', '٬'], '', $value);
        }
        if (str_starts_with($type, 'enum:')) {
            $folded = mb_strtolower($value);

            return self::ALIASES[$key][$folded] ?? $folded;
        }

        return $value;
    }

    /**
     * Whether a row says anything. Numbers, dates and dropdown values alone do not make a record —
     * that is what a formatted-but-empty line or a leftover sample line holds.
     *
     * @param  array<string, string>  $provided
     * @param  Collection<string, array<string, mixed>>  $fields
     */
    private function carriesText(array $provided, Collection $fields): bool
    {
        foreach ($provided as $key => $value) {
            if ($fields[$key]['type'] === 'string') {
                return true;
            }
        }

        return false;
    }

    /**
     * The company's records holding any of the given values in a column, keyed by the folded value.
     *
     * @param  array<int, string>  $values
     * @return array<string, Model>
     */
    private function recordsBy(string $configClass, string $column, array $values, int $companyId, bool $withTrashed): array
    {
        $values = array_values(array_unique(array_filter($values, fn ($v) => $v !== null && $v !== '')));
        if ($values === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $this->query($configClass::modelClass(), $companyId, $withTrashed)
                ->whereIn(DB::raw("LOWER({$column})"), array_map(fn ($value) => $this->fold((string) $value), $chunk))
                ->get()
                // An active record wins over a deleted one carrying the same value.
                ->sortBy(fn (Model $record) => $this->isTrashed($record) ? 0 : 1)
                ->each(function (Model $record) use (&$found, $column) {
                    $found[$this->fold((string) $record->getAttribute($column))] = $record;
                });
        }

        return $found;
    }

    /**
     * The model's query inside one company, whatever company the request is bound to.
     */
    private function query(string $modelClass, int $companyId, bool $withTrashed)
    {
        $query = $modelClass::query();
        if (in_array(BelongsToCompany::class, class_uses_recursive($modelClass), true)) {
            $query->withoutGlobalScope('company');
        }
        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query->where('company_id', $companyId);
    }

    /**
     * «ta099» and «TA099» are one employee. MySQL's collation already says so; spelling it out
     * keeps every database — the test suite's SQLite included — giving the same answer. The
     * column names come from the import configs, never from the file.
     *
     * @param  array<string, mixed>  $values  column => value
     */
    private function whereFolded($query, array $values)
    {
        foreach ($values as $column => $value) {
            $query->whereRaw("LOWER({$column}) = ?", [$this->fold((string) $value)]);
        }

        return $query;
    }

    /**
     * What an update would change on a record: only the cells the file filled in, and only where
     * they differ from what is on file.
     *
     * @param  array<string, mixed>  $resolved
     * @param  Collection<string, array<string, mixed>>  $fields
     * @param  array<string, string>  $labels
     * @return array<int, array{field: string, label: string, from: ?string, to: string}>
     */
    private function changes(Model $existing, array $resolved, Collection $fields, array $labels, string $configClass, int $companyId): array
    {
        $changes = [];
        foreach (Arr::except($resolved, $configClass::uniqueKeys()) as $key => $new) {
            // A resolved key (vehicle_type_id) is not one of the file's columns; it is a number.
            $type = $fields->get($key)['type'] ?? (Str::endsWith($key, '_id') ? 'integer' : 'string');

            $current = $existing->getAttribute($key);
            $current = match (true) {
                $current instanceof \DateTimeInterface => $current->format('Y-m-d'),
                $type === 'date' && $current => substr((string) $current, 0, 10),
                default => $current,
            };

            $same = match (true) {
                $current === null || $current === '' => false,
                in_array($type, ['numeric', 'integer'], true) => abs((float) $current - (float) $new) < 0.0005,
                default => trim((string) $current) === trim((string) $new),
            };
            if ($same) {
                continue;
            }

            $show = fn ($value) => $value === null || $value === ''
                ? null
                : (string) ($configClass::display($key, $value, $companyId) ?? $value);

            $changes[] = [
                'field' => $key,
                'label' => $labels[$key] ?? $labels[Str::beforeLast($key, '_id')] ?? $key,
                'from' => $show($current),
                'to' => (string) $show($new),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countActions(array $rows): array
    {
        $counts = array_fill_keys([self::ACTION_CREATE, self::ACTION_RESTORE, self::ACTION_UPDATE, self::ACTION_UNCHANGED, self::ACTION_DUPLICATE], 0);
        foreach ($rows as $row) {
            if ($row['is_valid']) {
                $counts[$row['action']]++;
            }
        }

        return $counts;
    }

    /**
     * The validator's sentences, in the language of the person reading them.
     *
     * @param  Collection<string, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function messages(Collection $fields): array
    {
        $messages = [
            'required' => '«:attribute» مطلوب.',
            'string' => '«:attribute» يجب أن يكون نصاً.',
            'numeric' => '«:attribute» يجب أن يكون رقماً، مثال: 120.500',
            'integer' => '«:attribute» يجب أن يكون عدداً صحيحاً.',
            'date' => '«:attribute» ليس تاريخاً مفهوماً — اكتبه 2026-01-31 أو 31/01/2026.',
            'max.string' => '«:attribute» أطول من :max حرفاً.',
            'max.numeric' => '«:attribute» أكبر من :max.',
            'min.numeric' => '«:attribute» أقل من :min.',
        ];
        foreach ($fields as $key => $field) {
            if (str_starts_with($field['type'], 'enum:')) {
                $messages["{$key}.in"] = '«:attribute» قيمته غير مقبولة — '.$this->formatHint($field).'.';
            }
        }

        return $messages;
    }

    private function companyId(): int
    {
        return app()->bound('current_company_id') ? (int) app('current_company_id') : 0;
    }

    private function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
    }

    /** Two spellings of one key: case and surrounding space do not make a different record. */
    private function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Normalize date string to YYYY-MM-DD.
     */
    private function normalizeDate(string $val): string
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }

        // 1. Check if it's already in YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val;
        }

        // 2. Check if it's a numeric Excel serial date (e.g., 45678)
        if (is_numeric($val) && (int) $val > 10000 && (int) $val < 100000) {
            try {
                return ExcelDate::excelToDateTimeObject((int) $val)->format('Y-m-d');
            } catch (\Throwable $e) {
                // fallback
            }
        }

        // 3. Check for DD/MM/YYYY or D/M/YYYY or DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $val, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);

            return "{$matches[3]}-{$month}-{$day}";
        }

        // 4. Try standard PHP parsing
        $timestamp = strtotime(str_replace('/', '-', $val));
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return $val;
    }
}
