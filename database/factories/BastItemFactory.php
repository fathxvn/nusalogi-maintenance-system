<?php

namespace Database\Factories;

use App\Models\BastItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BastItem> */
class BastItemFactory extends Factory
{
    public function definition(): array
    {
        return ['ordinal' => 1];
    }
}
