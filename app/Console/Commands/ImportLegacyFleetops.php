<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\VehicleType;

class ImportLegacyFleetops extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:import-legacy-fleetops {sql_path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import employees and vehicles from a legacy SQL dump file into the current database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sqlPath = $this->argument('sql_path');

        if (!file_exists($sqlPath)) {
            $this->error("Error: SQL file not found at: {$sqlPath}");
            return Command::FAILURE;
        }

        $this->info("Starting data migration from {$sqlPath}...");

        // 1. Ensure vehicle types exist
        $this->info("Checking vehicle types...");
        VehicleType::firstOrCreate(['id' => 1], [
            'name' => 'Motorcycle',
            'name_ar' => 'سيكل / دراجة نارية',
            'company_id' => 1
        ]);
        VehicleType::firstOrCreate(['id' => 2], [
            'name' => 'Small Car',
            'name_ar' => 'سيارة صغيرة',
            'company_id' => 1
        ]);
        $this->info("✓ Vehicle types ready.");

        // 2. Truncate current employees and vehicles to prevent duplicate keys
        $this->info("Clearing existing employees and vehicles from database...");
        
        $connectionType = DB::connection()->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($connectionType === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        DB::table('employees')->delete();
        DB::table('vehicles')->delete();
        $this->info("✓ Tables cleared.");

        // Helper to determine vehicle type
        $getVehicleTypeId = function ($make, $model) {
            $str = mb_strtolower($make . ' ' . $model);
            if (
                strpos($str, 'بجاج') !== false || 
                strpos($str, 'boxer') !== false || 
                strpos($str, 'بوكسر') !== false || 
                strpos($str, 'bike') !== false || 
                strpos($str, 'motorcycle') !== false || 
                strpos($str, 'سيكل') !== false || 
                strpos($str, 'دراجة') !== false
            ) {
                return 1; // Motorcycle
            }
            return 2; // Car
        };

        // Helper to clean null/empty values
        $cleanVal = function ($val) {
            $val = trim($val);
            if ($val === 'NULL' || $val === 'null' || $val === '') {
                return null;
            }
            return trim($val, "' ");
        };

        // Parse file
        $fp = fopen($sqlPath, 'r');
        $inTable = null; // 'employees' or 'vehicles'
        $sqlBuffer = '';

        $importedEmployees = 0;
        $importedVehicles = 0;

        while (($line = fgets($fp)) !== false) {
            if (strpos($line, "INSERT INTO `employees`") !== false) {
                $inTable = 'employees';
                $sqlBuffer = $line;
                continue;
            }
            if (strpos($line, "INSERT INTO `vehicles`") !== false) {
                $inTable = 'vehicles';
                $sqlBuffer = $line;
                continue;
            }

            if ($inTable) {
                $sqlBuffer .= $line;
                if (strpos($line, ';') !== false) {
                    // End of insert statement
                    $startPos = strpos($sqlBuffer, "VALUES");
                    if ($startPos !== false) {
                        $valuesStr = substr($sqlBuffer, $startPos + 6);
                        $valuesStr = trim($valuesStr);
                        $valuesStr = rtrim($valuesStr, ';');

                        // Match balanced parenthesis
                        preg_match_all('/\(([^)]+)\)/s', $valuesStr, $matches);

                        if ($inTable === 'employees') {
                            // Extract column list
                            preg_match('/`employees` \(([^)]+)\)/', $sqlBuffer, $colMatch);
                            $cols = array_map(function($c) { return trim($c, '` '); }, explode(',', $colMatch[1]));

                            foreach ($matches[1] as $tuple) {
                                $row = str_getcsv($tuple, ',', "'", '\\');
                                $insertData = [];
                                foreach ($cols as $idx => $colName) {
                                    if (isset($row[$idx])) {
                                        $insertData[$colName] = $cleanVal($row[$idx]);
                                    }
                                }
                                
                                foreach ($insertData as $k => $v) {
                                    if ($v === '0000-00-00') {
                                        $insertData[$k] = null;
                                    }
                                }

                                DB::table('employees')->insert($insertData);
                                $importedEmployees++;
                            }
                        } elseif ($inTable === 'vehicles') {
                            // Extract column list
                            preg_match('/`vehicles` \(([^)]+)\)/', $sqlBuffer, $colMatch);
                            $cols = array_map(function($c) { return trim($c, '` '); }, explode(',', $colMatch[1]));

                            foreach ($matches[1] as $tuple) {
                                $row = str_getcsv($tuple, ',', "'", '\\');
                                $insertData = [];
                                foreach ($cols as $idx => $colName) {
                                    if (isset($row[$idx])) {
                                        $insertData[$colName] = $cleanVal($row[$idx]);
                                    }
                                }
                                
                                foreach ($insertData as $k => $v) {
                                    if ($v === '0000-00-00') {
                                        $insertData[$k] = null;
                                    }
                                }

                                // Determine and inject vehicle_type_id
                                $make = $insertData['make'] ?? '';
                                $model = $insertData['model'] ?? '';
                                $insertData['vehicle_type_id'] = $getVehicleTypeId($make, $model);

                                DB::table('vehicles')->insert($insertData);
                                $importedVehicles++;
                            }
                        }
                    }
                    $inTable = null;
                    $sqlBuffer = '';
                }
            }
        }
        fclose($fp);

        if ($connectionType === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        $this->info("Migration finished successfully!");
        $this->info("✓ Imported Employees: {$importedEmployees}");
        $this->info("✓ Imported Vehicles: {$importedVehicles}");

        return Command::SUCCESS;
    }
}
