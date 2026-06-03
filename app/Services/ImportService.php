<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Imports\EmployeeImportConfig;
use App\Imports\VehicleImportConfig;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportService
{
    /**
     * Get the config class for an entity type.
     */
    public function getConfig(string $entityType): ?string
    {
        return match ($entityType) {
            'employees' => EmployeeImportConfig::class,
            'vehicles'  => VehicleImportConfig::class,
            default     => null,
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
     * Get the required/optional field definitions for an entity.
     */
    public function getFields(string $entityType): array
    {
        $configClass = $this->getConfig($entityType);
        if (!$configClass) return [];
        return $configClass::fields();
    }

    /**
     * Parse an uploaded Excel file and return column headers + first N rows.
     * This is for the column mapping step.
     */
    public function parseFile(string $filePath, int $previewRows = 5): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

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
            if ($count >= $previewRows) break;
            $rowData = [];
            foreach ($headers as $h) {
                $rowData[$h['column']] = trim((string) ($row[$h['column']] ?? ''));
            }
            // Skip completely empty rows
            if (implode('', $rowData) === '') continue;
            $preview[] = $rowData;
            $count++;
        }

        // Total data rows (excluding header)
        $totalRows = 0;
        foreach ($rows as $row) {
            $vals = array_map(fn($v) => trim((string) ($v ?? '')), $row);
            if (implode('', $vals) !== '') $totalRows++;
        }

        return [
            'headers'    => $headers,
            'preview'    => $preview,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Validate and preview mapped data before import.
     * $mapping = ['excel_column' => 'system_field', ...]
     */
    public function previewMapped(string $filePath, string $entityType, array $mapping): array
    {
        $configClass = $this->getConfig($entityType);
        if (!$configClass) return ['error' => 'Invalid entity type'];

        $rules = $configClass::validationRules();
        $fields = $configClass::fields();
        $defaults = $configClass::defaults();

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (empty($rows)) return ['rows' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0];

        // Skip header
        array_shift($rows);

        // Reverse mapping: system_field => excel_column
        $reverseMap = array_flip($mapping);

        $result = [];
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $rowIndex => $row) {
            // Build mapped row
            $mapped = [];
            foreach ($fields as $field) {
                $key = $field['key'];
                if (isset($reverseMap[$key])) {
                    $excelCol = $reverseMap[$key];
                    $val = trim((string) ($row[$excelCol] ?? ''));

                    // Automatically normalize date fields to YYYY-MM-DD
                    if ($field['type'] === 'date' && $val !== '') {
                        $val = $this->normalizeDate($val);
                    }

                    $mapped[$key] = $val !== '' ? $val : ($defaults[$key] ?? null);
                } else {
                    $mapped[$key] = $defaults[$key] ?? null;
                }
            }

            // Skip empty rows
            $nonEmpty = array_filter($mapped, fn($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) continue;

            // Validate
            $validator = Validator::make($mapped, $rules);
            $errors = $validator->fails() ? $validator->errors()->toArray() : [];

            if (empty($errors)) {
                $valid++;
            } else {
                $invalid++;
            }

            $result[] = [
                'row_number' => $rowIndex + 1,
                'data'       => $mapped,
                'errors'     => $errors,
                'is_valid'   => empty($errors),
            ];
        }

        return [
            'rows'    => $result,
            'total'   => count($result),
            'valid'   => $valid,
            'invalid' => $invalid,
        ];
    }

    /**
     * Execute the actual import.
     * $skipRows = array of row_numbers to skip.
     * Tracks: imported (new), skipped_duplicate (existing), failed.
     */
    public function executeImport(ImportLog $importLog, array $previewData, array $skipRows = []): ImportLog
    {
        $configClass = $this->getConfig($importLog->entity_type);
        $modelClass = $configClass::modelClass();
        $uniqueKeys = $configClass::uniqueKeys();
        $defaults = $configClass::defaults();

        $imported = 0;
        $skippedDuplicate = 0;
        $failed = 0;
        $errors = [];

        foreach ($previewData as $row) {
            // Skip if user marked this row
            if (in_array($row['row_number'], $skipRows)) continue;

            // Skip invalid rows
            if (!$row['is_valid']) {
                $failed++;
                $errors[] = [
                    'row' => $row['row_number'],
                    'errors' => $row['errors'],
                ];
                continue;
            }

            try {
                $data = $row['data'];

                // Apply defaults for missing values
                foreach ($defaults as $k => $v) {
                    if (!isset($data[$k]) || $data[$k] === '' || $data[$k] === null) {
                        $data[$k] = $v;
                    }
                }

                // Remove null values
                $data = array_filter($data, fn($v) => $v !== null && $v !== '');

                // Check for existing record by unique keys (including soft-deleted ones)
                $uniqueData = [];
                foreach ($uniqueKeys as $uk) {
                    if (isset($data[$uk])) {
                        $uniqueData[$uk] = $data[$uk];
                    }
                }

                if (!empty($uniqueData)) {
                    $query = $modelClass::query();
                    if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass))) {
                        $query->withTrashed();
                    }
                    if (in_array(\App\Traits\BelongsToCompany::class, class_uses_recursive($modelClass))) {
                        $query->withoutGlobalScope('company');
                    }
                    $existing = $query->where($uniqueData)->first();
                    if ($existing) {
                        $currentCompanyId = app()->bound('current_company_id') ? app('current_company_id') : null;
                        if ($currentCompanyId && $existing->company_id != $currentCompanyId) {
                            $failed++;
                            $errors[] = [
                                'row'    => $row['row_number'],
                                'errors' => ['exception' => ["هذا السجل مسجل بالفعل لشركة أخرى ولا يمكن تكراره"]],
                            ];
                            continue;
                        }

                        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass)) && $existing->trashed()) {
                            // Restore soft-deleted record and update it with the new Excel data
                            $existing->restore();
                            $existing->update($data);
                            $imported++;
                        } else {
                            // Active record already exists — skip (don't overwrite)
                            $skippedDuplicate++;
                        }
                        continue;
                    }
                }

                // Create new record
                $modelClass::create($data);
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'row'    => $row['row_number'],
                    'errors' => ['exception' => [$e->getMessage()]],
                ];
            }
        }

        $importLog->update([
            'rows_total'             => count($previewData),
            'rows_imported'          => $imported,
            'rows_failed'            => $failed,
            'rows_skipped_duplicate' => $skippedDuplicate,
            'errors'                 => $errors,
            'status'                 => $failed > 0 && $imported === 0 && $skippedDuplicate === 0
                ? 'failed' : 'completed',
        ]);

        return $importLog;
    }

    /**
     * Generate a blank Excel template for an entity type.
     */
    public function generateTemplate(string $entityType): ?string
    {
        $configClass = $this->getConfig($entityType);
        if (!$configClass) return null;

        $fields = $configClass::fields();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('Template');
        $sheet->setShowGridlines(true);

        // Styling definitions
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F497D'], // Slate Blue
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];

        $dataStyle = [
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9D9D9'],
                ],
            ],
        ];

        // Write headers and apply column configuration
        $col = 1;
        foreach ($fields as $field) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $cell = $sheet->getCell($colLetter . '1');
            $cell->setValue($field['label']);

            // Auto-width with minimum width padding
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);

            // Add dropdown data validation for enum fields (applied to rows 2 to 200)
            $type = $field['type'];
            if (str_starts_with($type, 'enum:')) {
                $optionsStr = str_replace('enum:', '', $type);
                $translatedPrompt = "الرجاء الاختيار من: " . str_replace(',', ' أو ', $optionsStr);

                for ($r = 2; $r <= 200; $r++) {
                    $validation = $sheet->getCell($colLetter . $r)->getDataValidation();
                    $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $validation->setErrorTitle('قيمة غير صالحة');
                    $validation->setError("الرجاء اختيار قيمة من القائمة المتاحة ({$optionsStr})");
                    $validation->setPromptTitle('القيم المقبولة');
                    $validation->setPrompt($translatedPrompt);
                    $validation->setFormula1('"' . $optionsStr . '"');
                }

                // Add header cell comment explaining options
                $sheet->getComment($colLetter . '1')->getText()->createTextRun("الخيارات المتاحة: " . str_replace(',', ' | ', $optionsStr));
            }

            $col++;
        }

        // Apply gridlines and defaults to template rows
        for ($r = 2; $r <= 200; $r++) {
            $sheet->getStyle("A{$r}:" . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($fields)) . "{$r}")
                ->applyFromArray($dataStyle);
        }

        // Write sample row (row 2)
        $col = 1;
        foreach ($fields as $field) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sample = match ($field['type']) {
                'numeric'  => '0.000',
                'integer'  => '0',
                'date'     => '2026-01-01',
                'string'   => '',
                default    => str_contains($field['type'], 'enum:')
                    ? explode(',', str_replace('enum:', '', $field['type']))[0]
                    : '',
            };
            $sheet->getCell($colLetter . '2')->setValue($sample);

            // Apply specific number formatting
            if ($field['type'] === 'numeric') {
                $sheet->getStyle($colLetter . '2:'.$colLetter.'200')->getNumberFormat()->setFormatCode('#,##0.000');
            } elseif ($field['type'] === 'integer') {
                $sheet->getStyle($colLetter . '2:'.$colLetter.'200')->getNumberFormat()->setFormatCode('#,##0');
            } elseif ($field['key'] === 'civil_id' || $field['key'] === 'phone') {
                $sheet->getStyle($colLetter . '2:'.$colLetter.'200')->getNumberFormat()->setFormatCode('@'); // Treat as text
            }

            $col++;
        }

        // Apply header styles and row heights
        $sheet->getRowDimension('1')->setRowHeight(35);
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($fields)) . '1')
            ->applyFromArray($headerStyle);

        // Adjust column dimension autoSize to evaluate after writing data
        $col = 1;
        foreach ($fields as $field) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            $col++;
        }

        $filename = "template_{$entityType}_" . now()->format('Ymd') . '.xlsx';
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path("imports/{$filename}");

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    /**
     * Normalize date string to YYYY-MM-DD.
     */
    private function normalizeDate(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '';

        // 1. Check if it's already in YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val;
        }

        // 2. Check if it's a numeric Excel serial date (e.g., 45678)
        if (is_numeric($val) && (int)$val > 10000 && (int)$val < 100000) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((int)$val);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                // fallback
            }
        }

        // 3. Check for DD/MM/YYYY or D/M/YYYY or DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $val, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }

        // 4. Try standard PHP parsing
        try {
            $timestamp = strtotime(str_replace('/', '-', $val));
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        } catch (\Throwable $e) {
            // fallback
        }

        return $val;
    }
}
