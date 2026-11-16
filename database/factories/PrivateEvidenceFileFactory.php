<?php

namespace Database\Factories;

use App\Models\PrivateEvidenceFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PrivateEvidenceFile> */
class PrivateEvidenceFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'storage_disk' => 'evidence', 'storage_key' => 'objects/'.Str::uuid(),
            'original_name' => 'evidence.pdf', 'media_type' => 'application/pdf',
            'size_bytes' => 0, 'sha256' => hash('sha256', ''),
            'uploaded_by' => User::factory(), 'uploaded_at' => now(), 'state' => 'staged',
        ];
    }
}
