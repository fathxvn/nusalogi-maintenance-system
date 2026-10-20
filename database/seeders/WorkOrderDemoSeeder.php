<?php

namespace Database\Seeders;

use App\Models\CustomerSite;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WorkOrderDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $site = CustomerSite::firstOrFail();

            $creator = User::firstOrCreate(
                ['email' => 'admin.demo@example.test'],
                [
                    'name' => 'Admin Demo',
                    'password' => Hash::make('DemoOnly123!'),
                ]
            );

            $technician1 = User::firstOrCreate(
                ['email' => 'budi.demo@example.test'],
                [
                    'name' => 'Budi Testing',
                    'password' => Hash::make('DemoOnly123!'),
                ]
            );

            $technician2 = User::firstOrCreate(
                ['email' => 'andi.demo@example.test'],
                [
                    'name' => 'Andi Testing',
                    'password' => Hash::make('DemoOnly123!'),
                ]
            );

            $workOrder = WorkOrder::firstOrCreate(
                ['wo_number' => 'WO-TEST-001'],
                [
                    'customer_site_id' => $site->id,
                    'created_by' => $creator->id,
                    'title' => 'Maintenance CCTV Kantor Bekasi',
                    'description' => 'Pemeriksaan CCTV tidak menampilkan gambar.',
                    'service_type' => 'maintenance',
                    'category' => 'cctv',
                    'priority' => 'medium',
                ]
            );

            $workOrder->technicians()->syncWithoutDetaching([
                $technician1->id,
                $technician2->id,
            ]);
        });
    }
}
