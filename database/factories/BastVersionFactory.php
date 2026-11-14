<?php

namespace Database\Factories;

use App\Models\BastVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BastVersion> */
class BastVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['version_no' => 1, 'status' => 'draft', 'lock_version' => 0];
    }
}
