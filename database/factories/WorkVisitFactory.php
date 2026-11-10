<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkVisit> */
class WorkVisitFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now('Asia/Jakarta')
            ->addDay()
            ->setTime(9, 0)
            ->utc();

        return [
            'visit_no' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(2),
            'zone_name' => 'Asia/Jakarta',
            'status' => 'draft',
            'previous_visit_id' => null,
            'reason' => null,
            'created_by' => User::factory()->state([
                'role' => 'koordinator',
            ]),
            'created_at' => CarbonImmutable::now('UTC'),
            'lock_version' => 0,
        ];
    }
}
