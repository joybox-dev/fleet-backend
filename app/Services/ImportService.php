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

                // Check for existing record by unique keys
                $uniqueData = [];
                foreach ($uniqueKeys as $uk) {
                    if (isset($data[$uk])) {
                        $uniqueData[$uk] = $data[$uk];
                    }
                }

                if (!empty($uniqueData)) {
                    $existing = $modelClass::where($uniqueData)->first();
                    if ($existing) {
                        // Record already exists — skip (don't overwrite)
                        $skippedDuplicate++;
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

        // Write headers
        $col = 1;
        foreach ($fields as $field) {
            $cell = $sheet->getCellByColumnAndRow($col, 1);
            $cell->setValue($field['label']);
            $cell->getStyle()->getFont()->setBold(true);

            // Auto-width
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);

            // Add note for required fields
            if ($field['required']) {
                $cell->getStyle()->getFont()->getColor()->setRGB('CC0000');
            }

            $col++;
        }

        // Write sample row
        $col = 1;
        foreach ($fields as $field) {
            $sample = match ($field['type']) {
                'numeric'  => '0.000',
                'integer'  => '0',
                'date'     => '2026-01-01',
                'string'   => '',
                default    => str_contains($field['type'], 'enum:')
                    ? explode(',', str_replace('enum:', '', $field['type']))[0]
                    : '',
            };
            $sheet->getCellByColumnAndRow($col, 2)->setValue($sample);
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
}
