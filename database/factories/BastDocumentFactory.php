<?php

namespace Database\Factories;

use App\Models\BastDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BastDocument> */
class BastDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['document_number' => 'BAST-'.Str::ulid(), 'created_by' => User::factory()->state(['role' => 'koordinator']), 'created_at' => now(), 'acceptance_context_ref' => 'test-context'];
    }
}
