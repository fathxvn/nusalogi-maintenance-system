<?php

namespace Database\Factories;

use App\Models\PrivateEvidenceFile;
use App\Models\ServiceTicket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketAttachment> */
class TicketAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['ticket_id' => ServiceTicket::factory(), 'evidence_file_id' => PrivateEvidenceFile::factory(),
            'purpose' => 'complaint', 'attached_by' => User::factory()->state(['role' => 'admin']), 'attached_at' => now()];
    }
}
