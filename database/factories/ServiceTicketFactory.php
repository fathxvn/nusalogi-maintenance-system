<?php

namespace Database\Factories;

use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceTicket> */
class ServiceTicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_number' => 'TKT-'.Str::ulid(),
            'kind' => 'incident',
            'channel' => 'phone',
            'contact_label' => 'Customer PIC',
            'description' => 'Camera offline',
            'created_by' => User::factory()->state(['role' => 'admin']),
            'received_at' => now(),
            'created_at' => now(),
            'status' => 'new',
            'coverage_disposition' => 'unknown',
            'lock_version' => 0,
        ];
    }
}
