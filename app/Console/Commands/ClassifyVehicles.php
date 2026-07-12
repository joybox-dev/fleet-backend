<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Vehicle;
use App\Models\VehicleType;

class ClassifyVehicles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:classify-vehicles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set vehicle_type_id for existing vehicles based on their make and model';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Ensure vehicle types exist in DB table directly
        if (!DB::table('vehicle_types')->where('id', 1)->exists()) {
            DB::table('vehicle_types')->insert([
                'id' => 1,
                'name' => 'Motorcycle',
                'name_ar' => 'سيكل / دراجة نارية',
                'company_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (!DB::table('vehicle_types')->where('id', 2)->exists()) {
            DB::table('vehicle_types')->insert([
                'id' => 2,
                'name' => 'Small Car',
                'name_ar' => 'سيارة صغيرة',
                'company_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $vehicles = DB::table('vehicles')->get();
        $updated = 0;

        foreach ($vehicles as $vehicle) {
            $str = mb_strtolower($vehicle->make . ' ' . $vehicle->model);
            if (
                strpos($str, 'بجاج') !== false || 
                strpos($str, 'boxer') !== false || 
                strpos($str, 'بوكسر') !== false || 
                strpos($str, 'bike') !== false || 
                strpos($str, 'motorcycle') !== false || 
                strpos($str, 'سيكل') !== false || 
                strpos($str, 'دراجة') !== false
            ) {
                $typeId = 1; // Motorcycle
            } else {
                $typeId = 2; // Car
            }

            if ((int)$vehicle->vehicle_type_id !== $typeId) {
                DB::table('vehicles')->where('id', $vehicle->id)->update(['vehicle_type_id' => $typeId]);
                $updated++;
            }
        }

        $this->info("✓ Vehicle classification complete. Updated {$updated} vehicles.");
        return Command::SUCCESS;
    }
}
