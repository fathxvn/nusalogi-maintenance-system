<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VisitBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisitBooking> */
class VisitBookingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'technician_id' => User::factory()->state([
                'role' => 'teknisi',
            ]),
            'status' => 'confirmed',
            'confirmed_by' => User::factory()->state([
                'role' => 'koordinator',
            ]),
            'confirmed_at' => CarbonImmutable::now('UTC'),
            'actual_start_at' => null,
            'actual_end_at' => null,
            'ended_reason' => null,
        ];
    }
}