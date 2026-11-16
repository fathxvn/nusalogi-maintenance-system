<?php

namespace Database\Factories;

use App\Models\MaterialUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaterialUsage> */
class MaterialUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_description' => 'Kabel jaringan', 'unit_code' => 'meter', 'quantity' => '1.000',
            'movement_kind' => 'consume', 'adjusts_usage_id' => null, 'supply_source' => 'company',
            'disposition' => 'covered', 'recorded_by' => User::factory()->state(['role' => 'teknisi']),
            'recorded_at' => now(), 'reason' => null,
        ];
    }
}
